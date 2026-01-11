<?php
/**
 * Plugin Name: PushRelay - Push Notifications
 * Plugin URI: https://pushrelay.com
 * Description: The most powerful push notifications plugin for WordPress. WooCommerce integration, advanced segmentation, automated campaigns, and real-time analytics.
 * Version: 1.7.0
 * Author: PushRelay
 * Author URI: https://pushrelay.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: pushrelay
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 8.5
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// CRITICAL: Suppress ALL errors for AJAX requests BEFORE anything else
// Guard: Only run if DOING_AJAX is defined and true, and output buffering functions exist
if (defined('DOING_AJAX') && DOING_AJAX && function_exists('ob_get_level')) {
    // Clean any previous output (with safety check)
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    // Start fresh output buffer
    ob_start();
    
    // Suppress all errors for clean JSON response
    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_error_reporting -- Required for clean AJAX JSON responses
    error_reporting(0);
    // phpcs:disable Squiz.PHP.DiscouragedFunctions.Discouraged, WordPress.PHP.IniSet.display_errors_Disallowed -- Required for clean AJAX JSON responses
    @ini_set('display_errors', '0');
    @ini_set('log_errors', '1');
    // phpcs:enable
}

// Start output buffering to prevent header issues
if (!ob_get_level()) {
    ob_start();
}

// PHP 8+ Compatibility: Suppress deprecated warnings in production
if (!defined('WP_DEBUG') || !WP_DEBUG) {
    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_error_reporting -- PHP 8+ compatibility
    error_reporting(22519); // E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING
    // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged, WordPress.PHP.IniSet.display_errors_Disallowed -- Production error suppression
    @ini_set('display_errors', '0');
}

// Clean output buffer for AJAX requests before sending response
add_action('shutdown', function() {
    if (defined('DOING_AJAX') && DOING_AJAX) {
        // Get the output
        $output = '';
        while (ob_get_level()) {
            $output = ob_get_clean() . $output;
        }
        
        // If it looks like JSON, output it clean
        if (strpos(trim($output), '{') === 0 || strpos(trim($output), '[') === 0) {
            // Find the JSON part
            $json_start = strpos($output, '{');
            if ($json_start === false) {
                $json_start = strpos($output, '[');
            }
            
            if ($json_start !== false) {
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON passthrough for AJAX responses
                echo substr($output, $json_start);
                return;
            }
        }
        
        // Otherwise output as is
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Buffer passthrough
        echo $output;
    }
}, 0);

// Define plugin constants
define('PUSHRELAY_VERSION', '1.7.0');
define('PUSHRELAY_DB_VERSION', '1.6.0');
define('PUSHRELAY_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('PUSHRELAY_PLUGIN_URL', plugin_dir_url(__FILE__));
define('PUSHRELAY_PLUGIN_BASENAME', plugin_basename(__FILE__));
define('PUSHRELAY_PLUGIN_FILE', __FILE__);

/**
 * Main PushRelay Plugin Class
 */
class PushRelay_Plugin {
    
    private static $instance = null;
    private $api_client = null;
    
    /**
     * Singleton instance
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
        $this->includes();
        $this->init_hooks();
    }
    
    /**
     * Include required files
     */
    private function includes() {
        // Core classes
        require_once PUSHRELAY_PLUGIN_DIR . 'includes/class-api-client.php';
        require_once PUSHRELAY_PLUGIN_DIR . 'includes/class-debug-logger.php';
        require_once PUSHRELAY_PLUGIN_DIR . 'includes/class-service-worker.php';
        require_once PUSHRELAY_PLUGIN_DIR . 'includes/class-health-check.php';
        
        // Admin classes
        if (is_admin()) {
            require_once PUSHRELAY_PLUGIN_DIR . 'includes/class-admin.php';
            require_once PUSHRELAY_PLUGIN_DIR . 'includes/class-support-tickets.php';
        }
        
        // Feature classes
        require_once PUSHRELAY_PLUGIN_DIR . 'includes/class-frontend.php';
        require_once PUSHRELAY_PLUGIN_DIR . 'includes/class-campaigns.php';
        require_once PUSHRELAY_PLUGIN_DIR . 'includes/class-subscribers.php';
        require_once PUSHRELAY_PLUGIN_DIR . 'includes/class-analytics.php';
        require_once PUSHRELAY_PLUGIN_DIR . 'includes/class-segmentation.php';
        require_once PUSHRELAY_PLUGIN_DIR . 'includes/class-shortcodes.php';
        
        // WooCommerce integration
        if (class_exists('WooCommerce')) {
            require_once PUSHRELAY_PLUGIN_DIR . 'includes/class-woocommerce.php';
        }
    }
    
    /**
     * Initialize WordPress hooks
     * 
     * Note: Activation/deactivation hooks are registered at file scope
     * for safety (see end of file). This ensures they work even if
     * class instantiation fails.
     */
    private function init_hooks() {
        add_action('init', array($this, 'load_textdomain'), 1);
        add_action('init', array($this, 'init'));
        add_action('admin_init', array($this, 'check_requirements'));
    }
    
    /**
     * Load plugin textdomain
     * 
     * Note: Since WordPress 4.6, translations are automatically loaded
     * from translate.wordpress.org when available.
     */
    public function load_textdomain() {
        // WordPress 4.6+ handles translations automatically from translate.wordpress.org
        // This is kept for backward compatibility with custom translations
        // phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound
        load_plugin_textdomain('pushrelay', false, dirname(PUSHRELAY_PLUGIN_BASENAME) . '/languages');
    }
    
    /**
     * Check plugin requirements
     */
    public function check_requirements() {
        // Check PHP version
        if (version_compare(PHP_VERSION, '7.4', '<')) {
            add_action('admin_notices', function() {
                echo '<div class="notice notice-error"><p>';
                echo esc_html__('PushRelay requires PHP 7.4 or higher. Please upgrade your PHP version.', 'pushrelay');
                echo '</p></div>';
            });
            return false;
        }
        
        // Check WordPress version
        if (version_compare(get_bloginfo('version'), '5.8', '<')) {
            add_action('admin_notices', function() {
                echo '<div class="notice notice-error"><p>';
                echo esc_html__('PushRelay requires WordPress 5.8 or higher. Please update WordPress.', 'pushrelay');
                echo '</p></div>';
            });
            return false;
        }
        
        return true;
    }
    
    /**
     * Initialize plugin
     */
    public function init() {
        // Ensure cron jobs are scheduled (in case they were cleared)
        $this->ensure_cron_jobs();
        
        // Initialize admin interface
        if (is_admin()) {
            new PushRelay_Admin();
            new PushRelay_Support_Tickets();
        }
        
        // Initialize frontend
        new PushRelay_Frontend();
        new PushRelay_Service_Worker();
        new PushRelay_Campaigns();
        new PushRelay_Subscribers();
        new PushRelay_Analytics();
        new PushRelay_Segmentation();
        new PushRelay_Shortcodes();
        new PushRelay_Health_Check();
        
        // Initialize WooCommerce integration
        if (class_exists('WooCommerce')) {
            $settings = get_option('pushrelay_settings', array());
            if (!empty($settings['woocommerce_enabled'])) {
                new PushRelay_WooCommerce();
            }
        }
    }
    
    /**
     * Ensure cron jobs are scheduled
     * This runs on every page load but only schedules if not already scheduled
     */
    private function ensure_cron_jobs() {
        // On admin pages, always check cron jobs (no transient delay)
        // On frontend, check once per hour to avoid performance impact
        if (!is_admin()) {
            $last_check = get_transient('pushrelay_cron_check');
            if ($last_check) {
                return;
            }
            // Set transient for 1 hour for frontend
            set_transient('pushrelay_cron_check', true, HOUR_IN_SECONDS);
        }
        
        $crons_fixed = false;
        
        // Schedule health check cron if not scheduled
        if (!wp_next_scheduled('pushrelay_health_check')) {
            wp_schedule_event(time(), 'hourly', 'pushrelay_health_check');
            PushRelay_Debug_Logger::log('Health check cron job rescheduled', 'info');
            $crons_fixed = true;
        }
        
        // Schedule queue processor if not scheduled
        if (!wp_next_scheduled('pushrelay_process_queue')) {
            wp_schedule_event(time(), 'every_5_minutes', 'pushrelay_process_queue');
            PushRelay_Debug_Logger::log('Queue processor cron job rescheduled', 'info');
            $crons_fixed = true;
        }
        
        // If we fixed crons, clear cached health check results so dashboard shows accurate info
        if ($crons_fixed) {
            delete_option('pushrelay_health_check_results');
        }
    }
    
    /**
     * Get API client instance
     */
    public function get_api_client() {
        if (null === $this->api_client) {
            $this->api_client = new PushRelay_API_Client();
        }
        return $this->api_client;
    }
    
    /**
     * Get logger instance
     */
    public static function get_logger() {
        return PushRelay_Debug_Logger::get_instance();
    }
    
    /**
     * Plugin activation
     */
    public function activate() {
        // Create database tables
        $this->create_tables();
        
        // Set default options
        if (!get_option('pushrelay_settings')) {
            add_option('pushrelay_settings', array(
                'api_key' => '',
                'website_id' => '',
                'pixel_key' => '',
                'auto_notifications' => false,
                'notification_types' => array('post'),
                'debug_mode' => false,
                'setup_completed' => false,
                'woocommerce_enabled' => false,
                'health_check_enabled' => true,
            ));
        }
        
        // Create uploads directory for service worker
        $upload_dir = wp_upload_dir();
        $sw_dir = $upload_dir['basedir'] . '/pushrelay';
        if (!file_exists($sw_dir)) {
            wp_mkdir_p($sw_dir);
        }
        
        // Schedule health check cron
        if (!wp_next_scheduled('pushrelay_health_check')) {
            wp_schedule_event(time(), 'hourly', 'pushrelay_health_check');
        }
        
        // Schedule queue processor
        if (!wp_next_scheduled('pushrelay_process_queue')) {
            wp_schedule_event(time(), 'every_5_minutes', 'pushrelay_process_queue');
        }
        
        // Set version
        update_option('pushrelay_version', PUSHRELAY_VERSION);
        update_option('pushrelay_db_version', PUSHRELAY_DB_VERSION);
        
        // Log activation
        PushRelay_Debug_Logger::log('Plugin activated - Version ' . PUSHRELAY_VERSION, 'info');
        
        // Activate service worker (registers rewrite rules and flushes)
        PushRelay_Service_Worker::activate();
        
        // Redirect to setup wizard on first activation
        $settings = get_option('pushrelay_settings', array());
        if (empty($settings['setup_completed'])) {
            set_transient('pushrelay_activation_redirect', true, 30);
        }
    }
    
    /**
     * Plugin deactivation
     */
    public function deactivate() {
        // Clear scheduled cron jobs
        wp_clear_scheduled_hook('pushrelay_health_check');
        wp_clear_scheduled_hook('pushrelay_send_queued_notifications');
        wp_clear_scheduled_hook('pushrelay_process_queue');
        
        // Log deactivation
        PushRelay_Debug_Logger::log('Plugin deactivated', 'info');
        
        // Clean up service worker rewrite rules
        PushRelay_Service_Worker::deactivate();
    }
    
    /**
     * Create custom database tables using dbDelta for reliability
     */
    public function create_tables() {
        global $wpdb;
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        
        $charset_collate = $wpdb->get_charset_collate();
        $created_tables = array();
        
        // Use dbDelta for reliable table creation
        // Note: dbDelta requires specific formatting:
        // - Two spaces after PRIMARY KEY
        // - KEY not INDEX
        // - Each field on its own line
        
        $sql = array();
        
        // Table: pushrelay_api_logs
        $sql['pushrelay_api_logs'] = "CREATE TABLE {$wpdb->prefix}pushrelay_api_logs (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            endpoint varchar(255) NOT NULL DEFAULT '',
            method varchar(10) NOT NULL DEFAULT 'GET',
            status_code int(11) DEFAULT NULL,
            request_data longtext,
            response_data longtext,
            error_message text DEFAULT NULL,
            execution_time float DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY endpoint (endpoint),
            KEY created_at (created_at),
            KEY status_code (status_code)
        ) {$charset_collate};";
        
        // Table: pushrelay_queue
        $sql['pushrelay_queue'] = "CREATE TABLE {$wpdb->prefix}pushrelay_queue (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            campaign_id bigint(20) UNSIGNED NOT NULL DEFAULT 0,
            subscriber_id bigint(20) UNSIGNED NOT NULL DEFAULT 0,
            website_id bigint(20) UNSIGNED NOT NULL DEFAULT 0,
            status varchar(20) NOT NULL DEFAULT 'pending',
            attempts int(11) NOT NULL DEFAULT 0,
            max_attempts int(11) NOT NULL DEFAULT 3,
            error_message text,
            scheduled_at datetime DEFAULT NULL,
            sent_at datetime DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY campaign_id (campaign_id),
            KEY subscriber_id (subscriber_id),
            KEY status (status),
            KEY scheduled_at (scheduled_at)
        ) {$charset_collate};";
        
        // Table: pushrelay_campaigns_local
        $sql['pushrelay_campaigns_local'] = "CREATE TABLE {$wpdb->prefix}pushrelay_campaigns_local (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            campaign_id bigint(20) UNSIGNED NOT NULL DEFAULT 0,
            website_id bigint(20) UNSIGNED NOT NULL DEFAULT 0,
            user_id bigint(20) UNSIGNED NOT NULL DEFAULT 0,
            name varchar(255) NOT NULL DEFAULT '',
            status varchar(20) NOT NULL DEFAULT 'draft',
            total_subscribers int(11) NOT NULL DEFAULT 0,
            total_sent int(11) NOT NULL DEFAULT 0,
            total_displayed int(11) NOT NULL DEFAULT 0,
            total_clicked int(11) NOT NULL DEFAULT 0,
            total_closed int(11) NOT NULL DEFAULT 0,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY campaign_id (campaign_id),
            KEY website_id (website_id),
            KEY user_id (user_id)
        ) {$charset_collate};";
        
        // Table: pushrelay_tickets
        $sql['pushrelay_tickets'] = "CREATE TABLE {$wpdb->prefix}pushrelay_tickets (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) NOT NULL DEFAULT 0,
            subject varchar(255) NOT NULL DEFAULT '',
            message longtext NOT NULL,
            priority varchar(20) NOT NULL DEFAULT 'medium',
            status varchar(20) NOT NULL DEFAULT 'open',
            ticket_id varchar(50) DEFAULT NULL,
            email_sent tinyint(1) DEFAULT 0,
            attachments longtext DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY user_id (user_id),
            KEY status (status),
            KEY created_at (created_at)
        ) {$charset_collate};";
        
        // Table: pushrelay_segments
        $sql['pushrelay_segments'] = "CREATE TABLE {$wpdb->prefix}pushrelay_segments (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL DEFAULT '',
            description text DEFAULT NULL,
            rules longtext DEFAULT NULL,
            subscriber_count int(11) DEFAULT 0,
            last_calculated datetime DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY name (name)
        ) {$charset_collate};";
        
        // Run dbDelta for each table
        foreach ($sql as $table_name => $query) {
            dbDelta($query);
            
            // Verify table was created
            $full_table_name = $wpdb->prefix . $table_name;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- SHOW TABLES cannot use prepare()
            $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$full_table_name}'");
            
            if ($table_exists) {
                $created_tables[] = $table_name;
            }
        }
        
        // Log results
        $total_expected = count($sql);
        $total_created = count($created_tables);
        
        PushRelay_Debug_Logger::log(
            sprintf('Database table creation: %d/%d tables created: %s', 
                $total_created, 
                $total_expected, 
                implode(', ', $created_tables)
            ), 
            'info'
        );
        
        // Store result
        update_option('pushrelay_db_version', PUSHRELAY_DB_VERSION);
        update_option('pushrelay_tables_created', $total_created === $total_expected);
        update_option('pushrelay_tables_created_list', $created_tables);
        update_option('pushrelay_tables_created_date', current_time('mysql'));
        
        return $total_created === $total_expected;
    }
    
    /**
     * Upgrade table schema - Fix old column names
     */
    private function upgrade_table_schema() {
        global $wpdb;
        
        $table_api_logs = $wpdb->prefix . 'pushrelay_api_logs';
        
        // Check if table exists
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- SHOW TABLES cannot use prepare()
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$table_api_logs}'");
        
        if (!$table_exists) {
            return; // Table doesn't exist, nothing to upgrade
        }
        
        // Check if old column 'response_code' exists
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $old_column_exists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
                WHERE TABLE_SCHEMA = %s 
                AND TABLE_NAME = %s 
                AND COLUMN_NAME = 'response_code'",
                DB_NAME,
                $table_api_logs
            )
        );
        
        // Check if new column 'status_code' exists
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $new_column_exists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
                WHERE TABLE_SCHEMA = %s 
                AND TABLE_NAME = %s 
                AND COLUMN_NAME = 'status_code'",
                DB_NAME,
                $table_api_logs
            )
        );
        
        // If old column exists but new doesn't, rename it
        if ($old_column_exists && !$new_column_exists) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- ALTER TABLE cannot use prepare()
            $wpdb->query("ALTER TABLE {$table_api_logs} CHANGE `response_code` `status_code` int(11) DEFAULT NULL");
            PushRelay_Debug_Logger::log('Upgraded api_logs table: renamed response_code to status_code', 'info');
        }
        
        // If neither exists, add status_code
        if (!$old_column_exists && !$new_column_exists) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- ALTER TABLE cannot use prepare()
            $wpdb->query("ALTER TABLE {$table_api_logs} ADD COLUMN `status_code` int(11) DEFAULT NULL AFTER `method`");
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- ALTER TABLE cannot use prepare()
            $wpdb->query("ALTER TABLE {$table_api_logs} ADD KEY `status_code` (`status_code`)");
            PushRelay_Debug_Logger::log('Upgraded api_logs table: added status_code column', 'info');
        }
        
        // Check for error_message column
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $error_message_exists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
                WHERE TABLE_SCHEMA = %s 
                AND TABLE_NAME = %s 
                AND COLUMN_NAME = 'error_message'",
                DB_NAME,
                $table_api_logs
            )
        );
        
        if (!$error_message_exists) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- ALTER TABLE cannot use prepare()
            $wpdb->query("ALTER TABLE {$table_api_logs} ADD COLUMN `error_message` text DEFAULT NULL AFTER `response_data`");
            PushRelay_Debug_Logger::log('Upgraded api_logs table: added error_message column', 'info');
        }
        
        // Remove old unnecessary columns if they exist
        $columns_to_remove = array('ip_address', 'user_id');
        foreach ($columns_to_remove as $col) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $col_exists = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
                    WHERE TABLE_SCHEMA = %s 
                    AND TABLE_NAME = %s 
                    AND COLUMN_NAME = %s",
                    DB_NAME,
                    $table_api_logs,
                    $col
                )
            );
            
            if ($col_exists) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- ALTER TABLE cannot use prepare()
                $wpdb->query("ALTER TABLE {$table_api_logs} DROP COLUMN `{$col}`");
                PushRelay_Debug_Logger::log("Upgraded api_logs table: removed {$col} column", 'info');
            }
        }
    }
}

/**
 * Initialize the plugin
 */
function pushrelay() {
    return PushRelay_Plugin::get_instance();
}

/**
 * Plugin activation handler (file-scope for safety)
 * 
 * This MUST be registered at file scope, not inside a class constructor,
 * to ensure it runs even if class loading fails.
 * 
 * Uses direct class instantiation to avoid dependency on plugins_loaded.
 */
function pushrelay_activate() {
    // Direct instantiation - does not rely on pushrelay() or plugins_loaded
    $plugin = PushRelay_Plugin::get_instance();
    if ( $plugin && method_exists( $plugin, 'activate' ) ) {
        $plugin->activate();
    }
}

/**
 * Plugin deactivation handler (file-scope for safety)
 * 
 * Uses direct class instantiation to avoid dependency on plugins_loaded.
 */
function pushrelay_deactivate() {
    // Direct instantiation - does not rely on pushrelay() or plugins_loaded
    $plugin = PushRelay_Plugin::get_instance();
    if ( $plugin && method_exists( $plugin, 'deactivate' ) ) {
        $plugin->deactivate();
    }
}

// Register activation/deactivation hooks at FILE SCOPE (required for reliability)
register_activation_hook( PUSHRELAY_PLUGIN_FILE, 'pushrelay_activate' );
register_deactivation_hook( PUSHRELAY_PLUGIN_FILE, 'pushrelay_deactivate' );

// Start the plugin (original bootstrap - unchanged)
add_action('plugins_loaded', 'pushrelay', 1);

// Redirect to setup wizard after activation
add_action('admin_init', function() {
    if (get_transient('pushrelay_activation_redirect')) {
        delete_transient('pushrelay_activation_redirect');
        
        if (!isset($_GET['activate-multi'])) {
            wp_safe_redirect(admin_url('admin.php?page=pushrelay-setup'));
            exit;
        }
    }
});

// Add custom cron schedule for 5 minutes
add_filter('cron_schedules', function($schedules) {
    $schedules['every_5_minutes'] = array(
        'interval' => 300,
        // Translation is safe here as cron_schedules runs after init in normal operation
        // The display name is only used in admin UI, so late translation is acceptable
        'display'  => function_exists( '__' ) ? esc_html__('Every 5 Minutes', 'pushrelay') : 'Every 5 Minutes'
    );
    return $schedules;
});
