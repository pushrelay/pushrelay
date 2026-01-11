<?php
/**
 * Debug Logger Class
 * 
 * Handles all debug logging and error tracking for the plugin
 * 
 * @package PushRelay
 * @since 1.6.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class PushRelay_Debug_Logger {
    
    /**
     * Singleton instance
     */
    private static $instance = null;
    
    /**
     * Log levels
     */
    const LEVEL_ERROR = 'error';
    const LEVEL_WARNING = 'warning';
    const LEVEL_NOTICE = 'notice';
    const LEVEL_INFO = 'info';
    const LEVEL_SUCCESS = 'success';
    const LEVEL_DEBUG = 'debug';
    
    /**
     * Maximum log entries to keep (reduced from 1000 to prevent unbounded growth)
     */
    const MAX_LOG_ENTRIES = 500;
    
    /**
     * Sensitive keys that must never be logged
     * 
     * @var array
     */
    private static $sensitive_keys = array(
        'api_key',
        'api_secret',
        'password',
        'token',
        'authorization',
        'bearer',
        'secret',
        'credential',
        'private_key',
        'access_token',
        'refresh_token',
    );
    
    /**
     * Get singleton instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    private function __construct() {
        // Register AJAX handlers
        add_action('wp_ajax_pushrelay_get_logs', array($this, 'ajax_get_logs'));
        add_action('wp_ajax_pushrelay_clear_logs', array($this, 'ajax_clear_logs'));
        add_action('wp_ajax_pushrelay_export_logs', array($this, 'ajax_export_logs'));
    }
    
    /**
     * Log a message
     * 
     * @param string $message Message to log
     * @param string $level Log level
     * @param array $context Additional context
     */
    public static function log($message, $level = self::LEVEL_INFO, $context = array()) {
        $settings = get_option('pushrelay_settings', array());
        
        // Only log if debug mode is enabled, or if it's an error/warning/notice
        if (empty($settings['debug_mode']) && !in_array($level, array(self::LEVEL_ERROR, self::LEVEL_WARNING, self::LEVEL_NOTICE), true)) {
            return;
        }
        
        $log_entry = array(
            'timestamp' => current_time('mysql'),
            'level' => sanitize_text_field($level),
            'message' => sanitize_text_field($message),
            'context' => self::sanitize_context($context),
            'user_id' => get_current_user_id(),
            'ip' => self::get_client_ip(),
        );
        
        // Get existing logs
        $logs = get_option('pushrelay_debug_logs', array());
        
        // Add new entry at the beginning
        array_unshift($logs, $log_entry);
        
        // Keep only MAX_LOG_ENTRIES
        if (count($logs) > self::MAX_LOG_ENTRIES) {
            $logs = array_slice($logs, 0, self::MAX_LOG_ENTRIES);
        }
        
        // Save logs
        update_option('pushrelay_debug_logs', $logs, false);
        
        // Also log to WordPress debug.log if WP_DEBUG is enabled
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf('[PushRelay] [%s] %s', strtoupper($level), $message)); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        }
        
        // Trigger action for external logging systems
        do_action('pushrelay_log_entry', $log_entry);
    }
    
    /**
     * Sanitize context data and filter sensitive information
     * 
     * @param array $context Context data to sanitize
     * @return array Sanitized context with sensitive data redacted
     */
    private static function sanitize_context($context) {
        if (empty($context) || !is_array($context)) {
            return array();
        }
        
        $sanitized = array();
        
        foreach ($context as $key => $value) {
            $key = sanitize_key($key);
            
            // Check if this key contains sensitive data
            if ( self::is_sensitive_key( $key ) ) {
                $sanitized[$key] = '[REDACTED]';
                continue;
            }
            
            if (is_array($value)) {
                $sanitized[$key] = self::sanitize_context($value);
            } elseif (is_string($value)) {
                // Redact if value looks like a token/key
                if ( self::looks_like_sensitive_value( $value ) ) {
                    $sanitized[$key] = '[REDACTED]';
                } else {
                    $sanitized[$key] = sanitize_text_field($value);
                }
            } elseif (is_numeric($value)) {
                $sanitized[$key] = $value;
            } elseif (is_bool($value)) {
                $sanitized[$key] = $value;
            } else {
                $sanitized[$key] = sanitize_text_field((string) $value);
            }
        }
        
        return $sanitized;
    }
    
    /**
     * Check if a key name indicates sensitive data
     * 
     * @param string $key Key name to check
     * @return bool True if key is sensitive
     */
    private static function is_sensitive_key( $key ) {
        $key_lower = strtolower( $key );
        foreach ( self::$sensitive_keys as $sensitive ) {
            if ( strpos( $key_lower, $sensitive ) !== false ) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * Check if a value looks like sensitive data (token, key, etc.)
     * 
     * @param string $value Value to check
     * @return bool True if value looks sensitive
     */
    private static function looks_like_sensitive_value( $value ) {
        // Skip short values
        if ( strlen( $value ) < 20 ) {
            return false;
        }
        
        // Check for Bearer token pattern
        if ( stripos( $value, 'Bearer ' ) === 0 ) {
            return true;
        }
        
        // Check for long alphanumeric strings that look like API keys/tokens
        if ( preg_match( '/^[a-zA-Z0-9_\-]{32,}$/', $value ) ) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Get client IP address
     */
    private static function get_client_ip() {
        $ip = '';
        
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = sanitize_text_field(wp_unslash($_SERVER['HTTP_CLIENT_IP']));
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = sanitize_text_field(wp_unslash($_SERVER['HTTP_X_FORWARDED_FOR']));
        } elseif (!empty($_SERVER['REMOTE_ADDR'])) {
            $ip = sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']));
        }
        
        // Validate IP
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
        
        return '';
    }
    
    /**
     * Get all logs
     * 
     * @param int $limit Number of logs to retrieve
     * @param string $level Filter by level
     * @return array
     */
    public static function get_logs($limit = 100, $level = '') {
        $logs = get_option('pushrelay_debug_logs', array());
        
        // Filter by level if specified
        if (!empty($level)) {
            $logs = array_filter($logs, function($log) use ($level) {
                return isset($log['level']) && $log['level'] === $level;
            });
        }
        
        // Limit results
        if ($limit > 0) {
            $logs = array_slice($logs, 0, absint($limit));
        }
        
        return $logs;
    }
    
    /**
     * Clear all logs
     */
    public static function clear_logs() {
        delete_option('pushrelay_debug_logs');
        self::log('Debug logs cleared', self::LEVEL_INFO);
        return true;
    }
    
    /**
     * Get log statistics
     */
    public static function get_statistics() {
        $logs = get_option('pushrelay_debug_logs', array());
        
        $stats = array(
            'total' => count($logs),
            'error' => 0,
            'warning' => 0,
            'info' => 0,
            'success' => 0,
            'debug' => 0,
            'last_24h' => 0,
        );
        
        $yesterday = strtotime('-24 hours');
        
        foreach ($logs as $log) {
            // Count by level
            if (isset($log['level'])) {
                $level = $log['level'];
                if (isset($stats[$level])) {
                    $stats[$level]++;
                }
            }
            
            // Count last 24 hours
            if (isset($log['timestamp'])) {
                $log_time = strtotime($log['timestamp']);
                if ($log_time >= $yesterday) {
                    $stats['last_24h']++;
                }
            }
        }
        
        return $stats;
    }
    
    /**
     * AJAX: Get logs
     */
    public function ajax_get_logs() {
        check_ajax_referer('pushrelay_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'pushrelay')));
        }
        
        $limit = isset($_POST['limit']) ? absint($_POST['limit']) : 100;
        $level = isset($_POST['level']) ? sanitize_text_field(wp_unslash($_POST['level'])) : '';
        
        $logs = self::get_logs($limit, $level);
        $stats = self::get_statistics();
        
        wp_send_json_success(array(
            'logs' => $logs,
            'stats' => $stats
        ));
    }
    
    /**
     * AJAX: Clear logs
     */
    public function ajax_clear_logs() {
        check_ajax_referer('pushrelay_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'pushrelay')));
        }
        
        self::clear_logs();
        
        wp_send_json_success(array(
            'message' => __('All logs cleared successfully', 'pushrelay')
        ));
    }
    
    /**
     * AJAX: Export logs
     */
    public function ajax_export_logs() {
        check_ajax_referer('pushrelay_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'pushrelay')));
        }
        
        $logs = self::get_logs(0); // Get all logs
        $export_data = self::prepare_export_data($logs);
        
        wp_send_json_success(array(
            'data' => $export_data,
            'filename' => 'pushrelay-logs-' . gmdate('Y-m-d-H-i-s') . '.json'
        ));
    }
    
    /**
     * Prepare logs for export
     */
    private static function prepare_export_data($logs) {
        $export = array(
            'exported_at' => current_time('mysql'),
            'site_url' => get_site_url(),
            'plugin_version' => PUSHRELAY_VERSION,
            'wordpress_version' => get_bloginfo('version'),
            'php_version' => PHP_VERSION,
            'total_logs' => count($logs),
            'logs' => $logs
        );
        
        return $export;
    }
    
    /**
     * Export logs to file
     */
    public static function export_to_file($format = 'json') {
        $logs = self::get_logs(0);
        $export_data = self::prepare_export_data($logs);
        
        if ($format === 'json') {
            $content = wp_json_encode($export_data, JSON_PRETTY_PRINT);
            $mime_type = 'application/json';
            $extension = 'json';
        } else {
            // CSV format
            $content = self::convert_to_csv($logs);
            $mime_type = 'text/csv';
            $extension = 'csv';
        }
        
        $filename = 'pushrelay-logs-' . gmdate('Y-m-d-H-i-s') . '.' . $extension;
        
        // Set headers for download
        header('Content-Type: ' . $mime_type);
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        exit;
    }
    
    /**
     * Convert logs to CSV format
     */
    private static function convert_to_csv($logs) {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Using php://temp stream for CSV generation
        $output = fopen('php://temp', 'r+');
        
        // Add headers
        fputcsv($output, array('Timestamp', 'Level', 'Message', 'User ID', 'IP Address'));
        
        // Add data
        foreach ($logs as $log) {
            fputcsv($output, array(
                isset($log['timestamp']) ? $log['timestamp'] : '',
                isset($log['level']) ? $log['level'] : '',
                isset($log['message']) ? $log['message'] : '',
                isset($log['user_id']) ? $log['user_id'] : '',
                isset($log['ip']) ? $log['ip'] : '',
            ));
        }
        
        rewind($output);
        $csv = stream_get_contents($output);
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing php://temp stream
        fclose($output);
        
        return $csv;
    }
    
    /**
     * Track if API log table has been verified this request
     * 
     * @var bool|null
     */
    private static $api_log_table_verified = null;
    
    /**
     * Log API request
     */
    public static function log_api_request($endpoint, $method, $status_code, $request_data = null, $response_data = null, $error = null, $execution_time = 0) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'pushrelay_api_logs';
        
        // Check if table exists (once per request) to avoid errors during activation
        if ( self::$api_log_table_verified === null ) {
            $suppress = $wpdb->suppress_errors( true );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            self::$api_log_table_verified = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) === $table_name;
            $wpdb->suppress_errors( $suppress );
        }
        
        // Skip insert if table doesn't exist yet (e.g., during activation)
        if ( ! self::$api_log_table_verified ) {
            return;
        }
        
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->insert(
            $table_name,
            array(
                'endpoint' => sanitize_text_field($endpoint),
                'method' => sanitize_text_field($method),
                'status_code' => absint($status_code),
                'request_data' => !empty($request_data) ? wp_json_encode($request_data) : null,
                'response_data' => !empty($response_data) ? wp_json_encode($response_data) : null,
                'error_message' => !empty($error) ? sanitize_text_field($error) : null,
                'execution_time' => floatval($execution_time),
                'created_at' => current_time('mysql'),
            ),
            array('%s', '%s', '%d', '%s', '%s', '%s', '%f', '%s')
        );
        
        // Classify log level based on status code
        // 2xx = no log (success), 400-404 = notice, 401/403 = warning, 429 = warning, 5xx = error
        $log_level = self::classify_api_log_level( $status_code, $error );
        
        // Only log if there's something worth logging
        if ( $log_level !== null ) {
            self::log(
                sprintf('API %s: %s %s - Status: %d', ucfirst($log_level), $method, $endpoint, $status_code),
                $log_level,
                array(
                    'error' => $error,
                    'status_code' => $status_code
                )
            );
        }
        
        // Clean old API logs (keep last 50)
        self::clean_old_api_logs();
    }
    
    /**
     * Classify API response into appropriate log level
     * 
     * @param int $status_code HTTP status code
     * @param string|null $error Error message if any
     * @return string|null Log level or null if no logging needed
     */
    private static function classify_api_log_level( $status_code, $error = null ) {
        // Network/timeout errors (status 0)
        if ( $status_code === 0 && ! empty( $error ) ) {
            return self::LEVEL_ERROR;
        }
        
        // 2xx success - no logging needed
        if ( $status_code >= 200 && $status_code < 300 ) {
            return null;
        }
        
        // 401 Unauthorized, 403 Forbidden - warning (auth issues)
        if ( $status_code === 401 || $status_code === 403 ) {
            return self::LEVEL_WARNING;
        }
        
        // 429 Too Many Requests - warning (rate limiting)
        if ( $status_code === 429 ) {
            return self::LEVEL_WARNING;
        }
        
        // 400-404 - notice (expected client errors, e.g., not found, bad request)
        if ( $status_code >= 400 && $status_code < 405 ) {
            return self::LEVEL_NOTICE;
        }
        
        // 405-499 - warning (other client errors)
        if ( $status_code >= 405 && $status_code < 500 ) {
            return self::LEVEL_WARNING;
        }
        
        // 5xx - error (server errors, actionable)
        if ( $status_code >= 500 ) {
            return self::LEVEL_ERROR;
        }
        
        // Unknown status - notice
        return self::LEVEL_NOTICE;
    }
    
    /**
     * Clean old API logs
     */
    private static function clean_old_api_logs() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'pushrelay_api_logs';
        
        // Keep only last 50 logs
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table_name} WHERE id NOT IN (
                    SELECT id FROM (
                        SELECT id FROM {$table_name} ORDER BY created_at DESC LIMIT %d
                    ) AS temp
                )",
                50
            )
        );
    }
    
    /**
     * Get API logs
     */
    public static function get_api_logs($limit = 50) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'pushrelay_api_logs';
        
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table_name} ORDER BY created_at DESC LIMIT %d",
                absint($limit)
            ),
            ARRAY_A
        );
        
        return $results ? $results : array();
    }
    
    /**
     * Get API statistics
     */
    public static function get_api_statistics() {
        global $wpdb;
        
        // Table name uses $wpdb->prefix which is trusted
        $table_name = $wpdb->prefix . 'pushrelay_api_logs';
        
        $stats = array(
            'total_requests' => 0,
            'successful_requests' => 0,
            'failed_requests' => 0,
            'average_response_time' => 0,
            'last_24h' => 0,
        );
        
        // Verify table exists before querying
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );
        if ( $table_exists !== $table_name ) {
            return $stats;
        }
        
        // Total requests - use prepare with placeholder for table (as identifier check)
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $total = $wpdb->get_var(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name built from $wpdb->prefix
            "SELECT COUNT(*) FROM `{$table_name}`"
        );
        $stats['total_requests'] = $total ? intval($total) : 0;
        
        // Successful requests (2xx status codes)
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $successful = $wpdb->get_var(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name built from $wpdb->prefix
                "SELECT COUNT(*) FROM `{$table_name}` WHERE status_code >= %d AND status_code < %d",
                200,
                300
            )
        );
        $stats['successful_requests'] = $successful ? intval($successful) : 0;
        
        // Failed requests (4xx and 5xx status codes)
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $failed = $wpdb->get_var(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name built from $wpdb->prefix
                "SELECT COUNT(*) FROM `{$table_name}` WHERE status_code >= %d",
                400
            )
        );
        $stats['failed_requests'] = $failed ? intval($failed) : 0;
        
        // Average response time
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $avg_time = $wpdb->get_var(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name built from $wpdb->prefix
            "SELECT AVG(execution_time) FROM `{$table_name}`"
        );
        $stats['average_response_time'] = $avg_time ? round(floatval($avg_time), 3) : 0;
        
        // Last 24 hours
        $yesterday = gmdate('Y-m-d H:i:s', strtotime('-24 hours'));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $last_24h = $wpdb->get_var(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name built from $wpdb->prefix
                "SELECT COUNT(*) FROM `{$table_name}` WHERE created_at >= %s",
                $yesterday
            )
        );
        $stats['last_24h'] = $last_24h ? intval($last_24h) : 0;
        
        return $stats;
    }
    
    /**
     * Get system information for debugging
     */
    public static function get_system_info() {
        global $wpdb;
        
        $theme = wp_get_theme();
        
        return array(
            'wordpress_version' => get_bloginfo('version'),
            'php_version' => PHP_VERSION,
            'mysql_version' => $wpdb->db_version(),
            'server_software' => isset($_SERVER['SERVER_SOFTWARE']) ? sanitize_text_field(wp_unslash($_SERVER['SERVER_SOFTWARE'])) : 'Unknown',
            'plugin_version' => PUSHRELAY_VERSION,
            'active_theme' => $theme->get('Name') . ' ' . $theme->get('Version'),
            'active_plugins' => self::get_active_plugins(),
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
            'upload_max_filesize' => ini_get('upload_max_filesize'),
            'post_max_size' => ini_get('post_max_size'),
            'curl_version' => function_exists('curl_version') ? curl_version()['version'] : 'Not available',
            'openssl_version' => OPENSSL_VERSION_TEXT,
            'site_url' => get_site_url(),
            'home_url' => get_home_url(),
            'is_ssl' => is_ssl(),
            'timezone' => wp_timezone_string(),
        );
    }
    
    /**
     * Get active plugins
     */
    private static function get_active_plugins() {
        $active_plugins = get_option('active_plugins', array());
        $plugins_info = array();
        
        foreach ($active_plugins as $plugin) {
            $plugin_data = get_plugin_data(WP_PLUGIN_DIR . '/' . $plugin);
            $plugins_info[] = $plugin_data['Name'] . ' ' . $plugin_data['Version'];
        }
        
        return $plugins_info;
    }
}
