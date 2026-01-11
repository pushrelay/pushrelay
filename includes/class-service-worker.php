<?php
/**
 * Service Worker Management Class
 * 
 * Handles automatic generation, installation and management of the service worker.
 * No FTP needed - everything is automated!
 * 
 * @package PushRelay
 * @since 1.6.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class PushRelay_Service_Worker
 * 
 * Manages service worker file generation, serving, and registration
 */
class PushRelay_Service_Worker {
    
    /**
     * Service worker filename
     * 
     * @var string
     */
    const SW_FILENAME = 'pushrelay-sw.js';
    
    /**
     * Manifest filename
     * 
     * @var string
     */
    const MANIFEST_FILENAME = 'pushrelay-manifest.json';
    
    /**
     * Query variable for service worker routing
     * 
     * @var string
     */
    const QUERY_VAR = 'pushrelay_sw';
    
    /**
     * Constructor - Initialize hooks and actions
     */
    public function __construct() {
        // Register rewrite rules for virtual file serving (priority 1 - early)
        add_action('init', array($this, 'register_rewrite_rules'), 1);
        add_filter('query_vars', array($this, 'add_query_vars'));
        
        // Handle service worker requests (priority 1 - very early in template_redirect)
        add_action('template_redirect', array($this, 'serve_service_worker'), 1);
        
        // Also try parse_request as earlier hook
        add_action('parse_request', array($this, 'parse_request_handler'));
        
        // Settings update hook
        add_action('pushrelay_settings_updated', array($this, 'regenerate_service_worker'));
        
        // AJAX handlers for admin
        add_action('wp_ajax_pushrelay_test_sw', array($this, 'ajax_test_service_worker'));
        add_action('wp_ajax_pushrelay_regenerate_sw', array($this, 'ajax_regenerate_service_worker'));
        
        // Generate service worker on init if needed
        add_action('init', array($this, 'maybe_generate_service_worker'), 20);
        
        // Add manifest link and service worker meta to head
        add_action('wp_head', array($this, 'add_manifest_link'));
        add_action('wp_head', array($this, 'add_service_worker_meta'), 5);
        
        // REST API endpoint as additional fallback
        add_action('rest_api_init', array($this, 'register_rest_routes'));
    }
    
    /**
     * Register rewrite rules for service worker and manifest files
     * 
     * This allows WordPress to handle requests for .js and .json files
     * that would otherwise return 404 from the web server
     */
    public function register_rewrite_rules() {
        // Rule for service worker file at root
        add_rewrite_rule(
            '^' . preg_quote(self::SW_FILENAME, '/') . '$',
            'index.php?' . self::QUERY_VAR . '=sw',
            'top'
        );
        
        // Rule for manifest file at root
        add_rewrite_rule(
            '^' . preg_quote(self::MANIFEST_FILENAME, '/') . '$',
            'index.php?' . self::QUERY_VAR . '=manifest',
            'top'
        );
        
        // Check if rules need flushing
        $this->maybe_flush_rewrite_rules();
    }
    
    /**
     * Check if rewrite rules need to be flushed
     * 
     * Only flush once per plugin version to avoid performance impact
     */
    private function maybe_flush_rewrite_rules() {
        $flushed_version = get_option('pushrelay_rewrite_rules_flushed', '');
        
        if ($flushed_version !== PUSHRELAY_VERSION) {
            flush_rewrite_rules(false);
            update_option('pushrelay_rewrite_rules_flushed', PUSHRELAY_VERSION);
        }
    }
    
    /**
     * Add custom query variables to WordPress
     * 
     * @param array $vars Existing query variables
     * @return array Modified query variables
     */
    public function add_query_vars($vars) {
        $vars[] = self::QUERY_VAR;
        return $vars;
    }
    
    /**
     * Handle service worker requests at parse_request stage
     * 
     * This is an earlier hook than template_redirect for catching requests
     * 
     * @param WP $wp WordPress environment instance
     */
    public function parse_request_handler($wp) {
        // Check REQUEST_URI for service worker request
        $request_uri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '';
        $request_path = strtok($request_uri, '?');
        $request_path = trim($request_path, '/');
        
        // Handle service worker request
        if ($request_path === self::SW_FILENAME || basename($request_path) === self::SW_FILENAME) {
            $this->output_service_worker();
            exit;
        }
        
        // Handle manifest request
        if ($request_path === self::MANIFEST_FILENAME || basename($request_path) === self::MANIFEST_FILENAME) {
            $this->output_manifest();
            exit;
        }
    }
    
    /**
     * Register REST API routes as fallback method
     */
    public function register_rest_routes() {
        register_rest_route('pushrelay/v1', '/service-worker', array(
            'methods' => 'GET',
            'callback' => array($this, 'rest_serve_service_worker'),
            'permission_callback' => '__return_true',
        ));
        
        register_rest_route('pushrelay/v1', '/manifest', array(
            'methods' => 'GET',
            'callback' => array($this, 'rest_serve_manifest'),
            'permission_callback' => '__return_true',
        ));
    }
    
    /**
     * REST API callback for service worker
     * 
     * @param WP_REST_Request $request REST request object
     * @return WP_REST_Response Response object
     */
    public function rest_serve_service_worker($request) {
        $settings = get_option('pushrelay_settings', array());
        
        if (empty($settings['website_id']) || empty($settings['pixel_key'])) {
            return new WP_REST_Response(
                '// PushRelay Service Worker - Not configured',
                200,
                array('Content-Type' => 'application/javascript; charset=utf-8')
            );
        }
        
        $sw_content = $this->generate_service_worker_content(
            $settings['website_id'],
            $settings['pixel_key']
        );
        
        return new WP_REST_Response(
            $sw_content,
            200,
            array(
                'Content-Type' => 'application/javascript; charset=utf-8',
                'Service-Worker-Allowed' => '/',
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
            )
        );
    }
    
    /**
     * REST API callback for manifest
     * 
     * @param WP_REST_Request $request REST request object
     * @return WP_REST_Response Response object
     */
    public function rest_serve_manifest($request) {
        $manifest_content = $this->generate_manifest_content();
        
        return new WP_REST_Response(
            $manifest_content,
            200,
            array(
                'Content-Type' => 'application/json; charset=utf-8',
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
            )
        );
    }
    
    /**
     * Serve service worker file via template redirect
     * 
     * This intercepts WordPress requests for the service worker
     */
    public function serve_service_worker() {
        // Check query variable first (from rewrite rules)
        $sw_request = get_query_var(self::QUERY_VAR);
        
        if ($sw_request === 'sw') {
            $this->output_service_worker();
            exit;
        }
        
        if ($sw_request === 'manifest') {
            $this->output_manifest();
            exit;
        }
        
        // Fallback: Check REQUEST_URI directly for cases where rewrite rules don't work
        $request_uri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '';
        $request_path = strtok($request_uri, '?');
        
        // Check if requesting service worker
        if (basename($request_path) === self::SW_FILENAME) {
            $this->output_service_worker();
            exit;
        }
        
        // Check if requesting manifest
        if (basename($request_path) === self::MANIFEST_FILENAME) {
            $this->output_manifest();
            exit;
        }
    }
    
    /**
     * Output service worker content with proper HTTP headers
     */
    private function output_service_worker() {
        // Clean any previous output
        if (ob_get_level()) {
            ob_end_clean();
        }
        
        $settings = get_option('pushrelay_settings', array());
        
        // If not configured, return minimal service worker (not 404)
        if (empty($settings['website_id']) || empty($settings['pixel_key'])) {
            PushRelay_Debug_Logger::log('Service worker requested but not configured', 'warning');
            
            header('Content-Type: application/javascript; charset=utf-8');
            header('Service-Worker-Allowed: /');
            header('Cache-Control: no-cache, no-store, must-revalidate');
            header('X-Content-Type-Options: nosniff');
            
            echo "// PushRelay Service Worker - Not configured yet\n";
            echo "// Please complete the setup wizard in WordPress admin\n";
            echo "self.addEventListener('install', function(e) { self.skipWaiting(); });\n";
            echo "self.addEventListener('activate', function(e) { e.waitUntil(clients.claim()); });\n";
            exit;
        }
        
        $sw_content = $this->generate_service_worker_content(
            $settings['website_id'],
            $settings['pixel_key']
        );
        
        // Set proper headers for service worker
        header('Content-Type: application/javascript; charset=utf-8');
        header('Service-Worker-Allowed: /');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
        header('X-Content-Type-Options: nosniff');
        
        // Output service worker content
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JavaScript content
        echo $sw_content;
        
        PushRelay_Debug_Logger::log('Service worker served successfully via PHP', 'info');
        exit;
    }
    
    /**
     * Output manifest content with proper HTTP headers
     */
    private function output_manifest() {
        // Clean any previous output
        if (ob_get_level()) {
            ob_end_clean();
        }
        
        $manifest_content = $this->generate_manifest_content();
        
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('X-Content-Type-Options: nosniff');
        
        echo wp_json_encode($manifest_content, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }
    
    /**
     * Generate service worker JavaScript content
     * 
     * @param int    $website_id Website ID from PushRelay
     * @param string $pixel_key  Pixel key from PushRelay
     * @return string Service worker JavaScript code
     */
    private function generate_service_worker_content($website_id, $pixel_key) {
        $website_id = absint($website_id);
        $pixel_key = sanitize_text_field($pixel_key);
        
        $sw_content = "// PushRelay Service Worker\n";
        $sw_content .= "// Auto-generated by PushRelay WordPress Plugin\n";
        $sw_content .= "// Version: " . PUSHRELAY_VERSION . "\n";
        $sw_content .= "// Generated: " . current_time('mysql') . "\n\n";
        
        $sw_content .= "let website_id = " . $website_id . ";\n";
        $sw_content .= "let website_pixel_key = '" . esc_js($pixel_key) . "';\n";
        $sw_content .= 'importScripts("https://pushrelay.com/pixel_service_worker.js");' . "\n";
        
        return $sw_content;
    }
    
    /**
     * Generate manifest content array
     * 
     * @return array Manifest data
     */
    private function generate_manifest_content() {
        $site_name = get_bloginfo('name');
        $site_url = get_site_url();
        
        // Get site icon URL
        $icon_url = '';
        if (has_site_icon()) {
            $icon_url = get_site_icon_url(192);
        } else {
            $icon_url = PUSHRELAY_PLUGIN_URL . 'assets/images/icon-192x192.png';
        }
        
        $manifest = array(
            'name' => $site_name,
            'short_name' => $site_name,
            'start_url' => $site_url,
            'display' => 'standalone',
            'background_color' => '#ffffff',
            'theme_color' => '#0073aa',
            'icons' => array(
                array(
                    'src' => $icon_url,
                    'sizes' => '192x192',
                    'type' => 'image/png'
                )
            ),
            'gcm_sender_id' => '103953800507'
        );
        
        /**
         * Filter the manifest content
         * 
         * @param array $manifest Manifest data array
         */
        return apply_filters('pushrelay_manifest_content', $manifest);
    }
    
    /**
     * Maybe generate service worker file if needed
     * 
     * Called during init to ensure service worker is created
     */
    public function maybe_generate_service_worker() {
        $settings = get_option('pushrelay_settings', array());
        
        // Check if we have required settings
        if (empty($settings['website_id']) || empty($settings['pixel_key'])) {
            return false;
        }
        
        // Check if service worker file needs to be generated
        $sw_version = get_option('pushrelay_sw_version', '');
        
        if ($sw_version !== PUSHRELAY_VERSION) {
            $this->generate_service_worker();
        }
        
        return true;
    }
    
    /**
     * Generate and save service worker file to disk
     * 
     * Attempts to write the file to the WordPress root directory
     * for direct web server access. Falls back to virtual serving.
     * 
     * @return bool True if successful
     */
    public function generate_service_worker() {
        $settings = get_option('pushrelay_settings', array());
        
        if (empty($settings['website_id']) || empty($settings['pixel_key'])) {
            PushRelay_Debug_Logger::log('Cannot generate service worker: missing website_id or pixel_key', 'error');
            return false;
        }
        
        $sw_content = $this->generate_service_worker_content(
            $settings['website_id'],
            $settings['pixel_key']
        );
        
        // Try to write to root directory first
        $root_path = ABSPATH . self::SW_FILENAME;
        
        if ($this->write_service_worker_file($root_path, $sw_content)) {
            update_option('pushrelay_sw_location', 'root');
            update_option('pushrelay_sw_version', PUSHRELAY_VERSION);
            update_option('pushrelay_sw_path', $root_path);
            
            PushRelay_Debug_Logger::log('Service worker file created in root directory', 'success');
            
            // Also generate manifest
            $this->generate_manifest_file();
            
            return true;
        }
        
        // Fallback: try uploads directory
        $upload_dir = wp_upload_dir();
        $uploads_path = $upload_dir['basedir'] . '/' . self::SW_FILENAME;
        
        if ($this->write_service_worker_file($uploads_path, $sw_content)) {
            update_option('pushrelay_sw_location', 'uploads');
            update_option('pushrelay_sw_version', PUSHRELAY_VERSION);
            update_option('pushrelay_sw_path', $uploads_path);
            
            PushRelay_Debug_Logger::log('Service worker created in uploads directory (root not writable)', 'warning');
            
            return true;
        }
        
        // Even if file creation fails, mark as "virtual" - will be served via PHP
        update_option('pushrelay_sw_location', 'virtual');
        update_option('pushrelay_sw_version', PUSHRELAY_VERSION);
        update_option('pushrelay_sw_path', '');
        
        PushRelay_Debug_Logger::log('Service worker will be served virtually via PHP (no writable location found)', 'info');
        return true;
    }
    
    /**
     * Write service worker file to disk
     * 
     * @param string $path    Full path to write the file
     * @param string $content File content to write
     * @return bool True if file was written successfully
     */
    private function write_service_worker_file($path, $content) {
        $dir = dirname($path);
        
        // Check if directory is writable
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Checking before using WP_Filesystem
        if (!is_writable($dir)) {
            return false;
        }
        
        // Try WordPress filesystem first
        global $wp_filesystem;
        
        if (empty($wp_filesystem)) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            WP_Filesystem();
        }
        
        if ($wp_filesystem && $wp_filesystem->put_contents($path, $content, FS_CHMOD_FILE)) {
            return true;
        }
        
        // Fallback to native PHP file_put_contents
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged
        $result = @file_put_contents($path, $content);
        
        return ($result !== false);
    }
    
    /**
     * Generate manifest file in root directory
     * 
     * @return bool True if file was created successfully
     */
    private function generate_manifest_file() {
        $manifest_content = $this->generate_manifest_content();
        $manifest_json = wp_json_encode($manifest_content, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        
        $root_path = ABSPATH . self::MANIFEST_FILENAME;
        
        if ($this->write_service_worker_file($root_path, $manifest_json)) {
            PushRelay_Debug_Logger::log('Manifest file created successfully', 'success');
            return true;
        }
        
        return false;
    }
    
    /**
     * Regenerate service worker (callback for settings update)
     */
    public function regenerate_service_worker() {
        // Force regeneration by clearing version
        delete_option('pushrelay_sw_version');
        $this->generate_service_worker();
    }
    
    /**
     * Add manifest link to HTML head
     */
    public function add_manifest_link() {
        $settings = get_option('pushrelay_settings', array());
        
        if (empty($settings['website_id'])) {
            return;
        }
        
        $manifest_url = $this->get_manifest_url();
        
        echo '<link rel="manifest" href="' . esc_url($manifest_url) . '">' . "\n";
    }
    
    /**
     * Add meta tag with service worker URL for JavaScript
     */
    public function add_service_worker_meta() {
        $settings = get_option('pushrelay_settings', array());
        
        if (empty($settings['website_id'])) {
            return;
        }
        
        $sw_url = $this->get_service_worker_url();
        
        echo '<meta name="pushrelay-sw-url" content="' . esc_url($sw_url) . '">' . "\n";
    }
    
    /**
     * Get service worker URL
     * 
     * Returns the best available URL for the service worker
     * 
     * @return string Service worker URL
     */
    public function get_service_worker_url() {
        $location = get_option('pushrelay_sw_location', '');
        $sw_path = get_option('pushrelay_sw_path', '');
        
        // If physical file exists in root, use direct URL
        if ($location === 'root' && !empty($sw_path) && file_exists($sw_path)) {
            return trailingslashit(get_site_url()) . self::SW_FILENAME;
        }
        
        // If physical file exists in uploads
        if ($location === 'uploads' && !empty($sw_path) && file_exists($sw_path)) {
            $upload_dir = wp_upload_dir();
            return trailingslashit($upload_dir['baseurl']) . self::SW_FILENAME;
        }
        
        // Default: use WordPress routing (served via PHP)
        return trailingslashit(get_site_url()) . self::SW_FILENAME;
    }
    
    /**
     * Get manifest URL
     * 
     * @return string Manifest URL
     */
    public function get_manifest_url() {
        return trailingslashit(get_site_url()) . self::MANIFEST_FILENAME;
    }
    
    /**
     * Test service worker accessibility
     * 
     * @return array Test results with success status and message
     */
    public function test_service_worker() {
        $sw_url = $this->get_service_worker_url();
        
        $response = wp_remote_get($sw_url, array(
            'timeout' => 15,
            'sslverify' => false,
            'headers' => array(
                'Cache-Control' => 'no-cache',
            ),
        ));
        
        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'message' => $response->get_error_message(),
                'url' => $sw_url
            );
        }
        
        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $content_type = wp_remote_retrieve_header($response, 'content-type');
        
        // Check for configured service worker
        if ($code === 200 && strpos($body, 'website_id') !== false) {
            return array(
                'success' => true,
                'message' => __('Service worker is accessible and valid', 'pushrelay'),
                'url' => $sw_url,
                'content_type' => $content_type
            );
        }
        
        // Check if it's a minimal/unconfigured service worker
        if ($code === 200 && strpos($body, 'Not configured') !== false) {
            return array(
                'success' => false,
                'message' => __('Service worker is accessible but not configured. Please complete setup.', 'pushrelay'),
                'url' => $sw_url
            );
        }
        
        // Check if it's any valid JavaScript response
        if ($code === 200 && strpos($content_type, 'javascript') !== false) {
            return array(
                'success' => true,
                'message' => __('Service worker is accessible', 'pushrelay'),
                'url' => $sw_url
            );
        }
        
        return array(
            'success' => false,
            'message' => sprintf(
                /* translators: %d: HTTP status code */
                __('Service worker returned status code: %d', 'pushrelay'),
                $code
            ),
            'url' => $sw_url,
            'status_code' => $code
        );
    }
    
    /**
     * AJAX handler: Test service worker
     */
    public function ajax_test_service_worker() {
        check_ajax_referer('pushrelay_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'pushrelay')));
        }
        
        $result = $this->test_service_worker();
        
        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }
    
    /**
     * AJAX handler: Regenerate service worker
     */
    public function ajax_regenerate_service_worker() {
        check_ajax_referer('pushrelay_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'pushrelay')));
        }
        
        // Force flush rewrite rules
        delete_option('pushrelay_rewrite_rules_flushed');
        flush_rewrite_rules(false);
        
        // Clear version to force regeneration
        delete_option('pushrelay_sw_version');
        
        if ($this->generate_service_worker()) {
            // Test the service worker
            $test_result = $this->test_service_worker();
            
            wp_send_json_success(array(
                'message' => __('Service worker regenerated successfully', 'pushrelay'),
                'test_result' => $test_result
            ));
        } else {
            wp_send_json_error(array(
                'message' => __('Failed to regenerate service worker', 'pushrelay')
            ));
        }
    }
    
    /**
     * Get service worker status information
     * 
     * @return array Status information
     */
    public function get_status() {
        $settings = get_option('pushrelay_settings', array());
        $sw_path = get_option('pushrelay_sw_path', '');
        $sw_location = get_option('pushrelay_sw_location', '');
        $sw_version = get_option('pushrelay_sw_version', '');
        
        $status = array(
            'configured' => !empty($settings['website_id']) && !empty($settings['pixel_key']),
            'exists' => $sw_location === 'virtual' || (!empty($sw_path) && file_exists($sw_path)),
            'location' => $sw_location,
            'version' => $sw_version,
            'url' => $this->get_service_worker_url(),
            'path' => $sw_path,
            'up_to_date' => $sw_version === PUSHRELAY_VERSION
        );
        
        return $status;
    }
    
    /**
     * Delete service worker files during uninstall
     */
    public function delete_service_worker() {
        $sw_path = get_option('pushrelay_sw_path', '');
        
        // Delete service worker file
        if (!empty($sw_path) && file_exists($sw_path)) {
            global $wp_filesystem;
            
            if (empty($wp_filesystem)) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
                WP_Filesystem();
            }
            
            if ($wp_filesystem) {
                $wp_filesystem->delete($sw_path);
            } else {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.PHP.NoSilencedErrors.Discouraged
                @unlink($sw_path);
            }
            
            PushRelay_Debug_Logger::log('Service worker file deleted', 'info');
        }
        
        // Delete manifest from root
        $manifest_path = ABSPATH . self::MANIFEST_FILENAME;
        if (file_exists($manifest_path)) {
            global $wp_filesystem;
            
            if (empty($wp_filesystem)) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
                WP_Filesystem();
            }
            
            if ($wp_filesystem) {
                $wp_filesystem->delete($manifest_path);
            } else {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.PHP.NoSilencedErrors.Discouraged
                @unlink($manifest_path);
            }
        }
        
        // Clear all related options
        delete_option('pushrelay_sw_location');
        delete_option('pushrelay_sw_version');
        delete_option('pushrelay_sw_path');
        delete_option('pushrelay_rewrite_rules_flushed');
    }
    
    /**
     * Flush rewrite rules on plugin activation
     * 
     * This should be called during plugin activation
     */
    public static function activate() {
        // Register rules first
        $instance = new self();
        $instance->register_rewrite_rules();
        
        // Force flush rewrite rules (with filesystem check)
        // Note: This writes to .htaccess on Apache. If filesystem is read-only,
        // it will fail silently but rewrite rules still work via query_vars.
        if ( function_exists( 'flush_rewrite_rules' ) ) {
            flush_rewrite_rules( false );
        }
        
        // Clear version to force regeneration
        delete_option('pushrelay_sw_version');
        delete_option('pushrelay_rewrite_rules_flushed');
        update_option('pushrelay_rewrite_rules_flushed', PUSHRELAY_VERSION);
    }
    
    /**
     * Clean up rewrite rules on plugin deactivation
     */
    public static function deactivate() {
        if ( function_exists( 'flush_rewrite_rules' ) ) {
            flush_rewrite_rules( false );
        }
        delete_option('pushrelay_rewrite_rules_flushed');
    }
}
