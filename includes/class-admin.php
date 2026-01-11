<?php
/**
 * Admin Class
 * 
 * Handles all admin interface functionality, menus, and settings
 * 
 * @package PushRelay
 * @since 1.6.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class PushRelay_Admin {
    
    /**
     * Constructor
     */
    public function __construct() {
        // Suppress database errors during AJAX to prevent JSON parsing issues
        if (defined('DOING_AJAX') && DOING_AJAX) {
            global $wpdb;
            if ($wpdb) {
                $wpdb->hide_errors();
                $wpdb->suppress_errors(true);
            }
        }
        
        // Clean output buffer for AJAX requests FIRST
        add_action('admin_init', array($this, 'clean_ajax_output'), 1);
        
        // Auto-create missing tables on admin init (runs early)
        add_action('admin_init', array($this, 'auto_create_missing_tables'), 5);
        
        add_action('admin_menu', array($this, 'add_menu_pages'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('admin_enqueue_scripts', array($this, 'dequeue_conflicting_scripts'), 999); // Late priority
        add_action('admin_print_scripts', array($this, 'dequeue_conflicting_scripts'), 999); // Even later
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_notices', array($this, 'admin_notices'));
        add_action('admin_init', array($this, 'handle_manual_table_creation'));
        add_action('admin_footer', array($this, 'add_dashboard_widget'));
        
        // AJAX handlers
        add_action('wp_ajax_pushrelay_save_settings', array($this, 'ajax_save_settings'));
        add_action('wp_ajax_pushrelay_detect_website', array($this, 'ajax_detect_website'));
        add_action('wp_ajax_pushrelay_test_connection', array($this, 'ajax_test_connection'));
        add_action('wp_ajax_pushrelay_get_chart_data', array($this, 'ajax_get_chart_data'));
        add_action('wp_ajax_pushrelay_export_analytics', array($this, 'ajax_export_analytics'));
        add_action('wp_ajax_pushrelay_create_campaign', array($this, 'ajax_create_campaign'));
        // Note: pushrelay_create_ticket is handled by PushRelay_Support_Tickets class
    }
    
    /**
     * Auto-create missing database tables
     * Runs on every admin page load to ensure tables exist
     */
    public function auto_create_missing_tables() {
        global $wpdb;
        
        // Only run once per page load
        static $checked = false;
        if ($checked) {
            return;
        }
        $checked = true;
        
        // Suppress database errors during check (this is a background repair mechanism)
        $suppress_errors = $wpdb->suppress_errors( true );
        
        // Check if any required table is missing
        $required_tables = array(
            'pushrelay_api_logs',
            'pushrelay_queue',
            'pushrelay_campaigns_local',
            'pushrelay_tickets',
            'pushrelay_segments'
        );
        
        $missing_tables = array();
        foreach ($required_tables as $table) {
            $full_table_name = $wpdb->prefix . $table;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Checking table existence
            $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $full_table_name ) ) === $full_table_name;
            if (!$exists) {
                $missing_tables[] = $table;
            }
        }
        
        // Restore error suppression state
        $wpdb->suppress_errors( $suppress_errors );
        
        // If any table is missing, try to create all tables
        if (!empty($missing_tables)) {
            $this->force_create_all_tables();
        }
    }
    
    /**
     * Force create all database tables using dbDelta
     */
    public function force_create_all_tables() {
        global $wpdb;
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        
        $charset_collate = $wpdb->get_charset_collate();
        
        // Use dbDelta for reliable table creation
        $sql = array();
        
        // Table: pushrelay_api_logs
        $sql[] = "CREATE TABLE {$wpdb->prefix}pushrelay_api_logs (
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
        $sql[] = "CREATE TABLE {$wpdb->prefix}pushrelay_queue (
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
        $sql[] = "CREATE TABLE {$wpdb->prefix}pushrelay_campaigns_local (
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
        $sql[] = "CREATE TABLE {$wpdb->prefix}pushrelay_tickets (
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
        $sql[] = "CREATE TABLE {$wpdb->prefix}pushrelay_segments (
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
        foreach ($sql as $query) {
            dbDelta($query);
        }
        
        // Update option to indicate tables were created
        update_option('pushrelay_tables_auto_created', current_time('mysql'));
        
        return true;
    }
    
    /**
     * Clean output buffer for AJAX requests
     */
    public function clean_ajax_output() {
        if (defined('DOING_AJAX') && DOING_AJAX) {
            // Remove all previous output
            while (ob_get_level()) {
                ob_end_clean();
            }
            
            // Start fresh
            ob_start();
            
            // Suppress errors during AJAX to prevent JSON corruption
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_error_reporting -- Required for clean AJAX responses
            error_reporting(0);
            // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- Required for clean AJAX responses
            @ini_set('display_errors', '0');
        }
    }
    
    /**
     * Add admin menu pages
     */
    public function add_menu_pages() {
        // Main menu
        add_menu_page(
            __('PushRelay', 'pushrelay'),
            __('PushRelay', 'pushrelay'),
            'manage_options',
            'pushrelay',
            array($this, 'dashboard_page'),
            'dashicons-megaphone',
            30
        );
        
        // Dashboard
        add_submenu_page(
            'pushrelay',
            __('Dashboard', 'pushrelay'),
            __('Dashboard', 'pushrelay'),
            'manage_options',
            'pushrelay',
            array($this, 'dashboard_page')
        );
        
        // Campaigns
        add_submenu_page(
            'pushrelay',
            __('Campaigns', 'pushrelay'),
            __('Campaigns', 'pushrelay'),
            'manage_options',
            'pushrelay-campaigns',
            array($this, 'campaigns_page')
        );
        
        // Subscribers
        add_submenu_page(
            'pushrelay',
            __('Subscribers', 'pushrelay'),
            __('Subscribers', 'pushrelay'),
            'manage_options',
            'pushrelay-subscribers',
            array($this, 'subscribers_page')
        );
        
        // Analytics
        add_submenu_page(
            'pushrelay',
            __('Analytics', 'pushrelay'),
            __('Analytics', 'pushrelay'),
            'manage_options',
            'pushrelay-analytics',
            array($this, 'analytics_page')
        );
        
        // WooCommerce (only if WooCommerce is active)
        if (class_exists('WooCommerce')) {
            add_submenu_page(
                'pushrelay',
                __('WooCommerce', 'pushrelay'),
                __('WooCommerce', 'pushrelay'),
                'manage_options',
                'pushrelay-woocommerce',
                array($this, 'woocommerce_page')
            );
        }
        
        // Health Check
        add_submenu_page(
            'pushrelay',
            __('Health Check', 'pushrelay'),
            __('Health Check', 'pushrelay'),
            'manage_options',
            'pushrelay-health',
            array($this, 'health_check_page')
        );
        
        // Support
        add_submenu_page(
            'pushrelay',
            __('Support', 'pushrelay'),
            __('Support', 'pushrelay'),
            'manage_options',
            'pushrelay-support',
            array($this, 'support_page')
        );
        
        // Settings
        add_submenu_page(
            'pushrelay',
            __('Settings', 'pushrelay'),
            __('Settings', 'pushrelay'),
            'manage_options',
            'pushrelay-settings',
            array($this, 'settings_page')
        );
        
        // Setup Wizard (hidden from menu)
        add_submenu_page(
            null,
            __('Setup Wizard', 'pushrelay'),
            __('Setup Wizard', 'pushrelay'),
            'manage_options',
            'pushrelay-setup',
            array($this, 'setup_wizard_page')
        );
    }
    
    /**
     * Enqueue admin scripts and styles
     */
    public function enqueue_scripts($hook) {
        // Guard against null $hook for PHP 8.1+ compatibility
        if ( empty( $hook ) || ! is_string( $hook ) ) {
            return;
        }
        
        // Only load on PushRelay pages
        if (strpos($hook, 'pushrelay') === false && strpos($hook, 'setup') === false) {
            return;
        }
        
        // Dequeue wp-codemirror to prevent AJAX conflicts
        // wp-codemirror has a global ajaxComplete handler that expects data to be a string
        wp_dequeue_script('wp-codemirror');
        wp_dequeue_script('code-editor');
        wp_dequeue_style('wp-codemirror');
        wp_dequeue_style('code-editor');
        
        // Enqueue WordPress media uploader
        wp_enqueue_media();
        
        // Admin CSS
        wp_enqueue_style(
            'pushrelay-admin',
            PUSHRELAY_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            PUSHRELAY_VERSION
        );
        
        // Chart.js for analytics
        // Chart.js - bundled locally for WordPress.org compliance
        wp_enqueue_script(
            'chartjs',
            PUSHRELAY_PLUGIN_URL . 'assets/js/chart.min.js',
            array(),
            '4.4.0',
            true
        );
        
        // Admin JS
        wp_enqueue_script(
            'pushrelay-admin',
            PUSHRELAY_PLUGIN_URL . 'assets/js/admin.js',
            array('jquery', 'chartjs'),
            PUSHRELAY_VERSION,
            true
        );
        
        // Localize script - ALWAYS localize even if page doesn't match
        wp_localize_script('pushrelay-admin', 'pushrelayAdmin', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('pushrelay_admin_nonce'),
            'pluginUrl' => PUSHRELAY_PLUGIN_URL,
            'strings' => array(
                'confirm_delete' => __('Are you sure you want to delete this?', 'pushrelay'),
                'sending' => __('Sending...', 'pushrelay'),
                'sent' => __('Sent!', 'pushrelay'),
                'error' => __('Error', 'pushrelay'),
                'success' => __('Success', 'pushrelay'),
                'saving' => __('Saving...', 'pushrelay'),
                'saved' => __('Saved!', 'pushrelay'),
                'testing' => __('Testing...', 'pushrelay'),
                'loading' => __('Loading...', 'pushrelay'),
                'regenerating' => __('Regenerating...', 'pushrelay'),
                'detecting' => __('Detecting...', 'pushrelay'),
            ),
        ));
        
        // Add inline script to remove any global AJAX handlers that might conflict
        wp_add_inline_script('pushrelay-admin', '
            // Remove global AJAX handlers that expect string data (fixes wp-codemirror conflict)
            if (typeof jQuery !== "undefined") {
                jQuery(document).off("ajaxComplete");
                jQuery(document).off("ajaxSend");
            }
        ', 'before');
    }
    
    /**
     * Dequeue conflicting scripts on PushRelay pages (runs at late priority)
     * wp-codemirror has a global ajaxComplete handler that breaks FormData AJAX requests
     */
    public function dequeue_conflicting_scripts($hook = '') {
        // Get current screen
        $screen = get_current_screen();
        $is_pushrelay_page = false;
        
        if ($screen && strpos($screen->id, 'pushrelay') !== false) {
            $is_pushrelay_page = true;
        } elseif (!empty($hook) && (strpos($hook, 'pushrelay') !== false || strpos($hook, 'setup') !== false)) {
            $is_pushrelay_page = true;
        }
        
        if (!$is_pushrelay_page) {
            return;
        }
        
        // Dequeue wp-codemirror and code-editor to prevent AJAX conflicts
        wp_dequeue_script('wp-codemirror');
        wp_dequeue_script('code-editor');
        wp_dequeue_script('csslint');
        wp_dequeue_script('jshint');
        wp_dequeue_script('jsonlint');
        wp_dequeue_script('htmlhint');
        wp_dequeue_style('wp-codemirror');
        wp_dequeue_style('code-editor');
    }
    
    /**
     * Register plugin settings
     */
    public function register_settings() {
        register_setting(
            'pushrelay_settings',
            'pushrelay_settings',
            array($this, 'sanitize_settings')
        );
    }
    
    /**
     * Sanitize settings
     */
    public function sanitize_settings($input) {
        $sanitized = array();
        
        // String fields
        if (isset($input['api_key'])) {
            $sanitized['api_key'] = sanitize_text_field($input['api_key']);
        }
        
        if (isset($input['website_id'])) {
            $sanitized['website_id'] = absint($input['website_id']);
        }
        
        if (isset($input['pixel_key'])) {
            $sanitized['pixel_key'] = sanitize_text_field($input['pixel_key']);
        }
        
        // Boolean fields - handle both true/false and '1'/'0' values
        if (array_key_exists('auto_notifications', $input)) {
            $sanitized['auto_notifications'] = filter_var($input['auto_notifications'], FILTER_VALIDATE_BOOLEAN);
        }
        
        if (array_key_exists('debug_mode', $input)) {
            $sanitized['debug_mode'] = filter_var($input['debug_mode'], FILTER_VALIDATE_BOOLEAN);
        }
        
        if (array_key_exists('setup_completed', $input)) {
            $sanitized['setup_completed'] = filter_var($input['setup_completed'], FILTER_VALIDATE_BOOLEAN);
            
            // Record the time when setup was completed (for health check grace period)
            $old_settings = get_option('pushrelay_settings', array());
            if ($sanitized['setup_completed'] && empty($old_settings['setup_completed'])) {
                // Setup just completed - record the time
                update_option('pushrelay_setup_completed_time', time());
            }
        }
        
        if (array_key_exists('woocommerce_enabled', $input)) {
            $sanitized['woocommerce_enabled'] = filter_var($input['woocommerce_enabled'], FILTER_VALIDATE_BOOLEAN);
        }
        
        if (array_key_exists('health_check_enabled', $input)) {
            $sanitized['health_check_enabled'] = filter_var($input['health_check_enabled'], FILTER_VALIDATE_BOOLEAN);
        }
        
        // Array fields - notification_types
        if (array_key_exists('notification_types', $input)) {
            if (is_array($input['notification_types'])) {
                $sanitized['notification_types'] = array_map('sanitize_text_field', $input['notification_types']);
            } else {
                $sanitized['notification_types'] = array();
            }
        }
        
        // Trigger service worker regeneration if credentials changed
        $old_settings_check = get_option('pushrelay_settings', array());
        if (isset($sanitized['website_id'], $sanitized['pixel_key']) &&
            ($sanitized['website_id'] !== ($old_settings_check['website_id'] ?? '') ||
             $sanitized['pixel_key'] !== ($old_settings_check['pixel_key'] ?? ''))) {
            do_action('pushrelay_settings_updated');
        }
        
        return $sanitized;
    }
    
    /**
     * Verify that all required tables exist
     * Called on every admin page load for PushRelay pages
     */
    private function verify_tables_exist() {
        global $wpdb;
        
        // Only check once per page load
        static $checked = false;
        if ($checked) {
            return;
        }
        $checked = true;
        
        $required_tables = array(
            'pushrelay_api_logs',
            'pushrelay_queue',
            'pushrelay_campaigns_local',
            'pushrelay_tickets',
            'pushrelay_segments'
        );
        
        $missing_tables = array();
        
        foreach ($required_tables as $table) {
            $full_table_name = $wpdb->prefix . $table;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Checking table existence
            $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $full_table_name ) );
            
            if (!$exists) {
                $missing_tables[] = $table;
            }
        }
        
        // If any tables are missing, set a transient
        if (!empty($missing_tables)) {
            set_transient('pushrelay_tables_missing', true, HOUR_IN_SECONDS);
            set_transient('pushrelay_tables_missing_list', $missing_tables, HOUR_IN_SECONDS);
        } else {
            // All tables exist, clear any error transients
            delete_transient('pushrelay_tables_missing');
            delete_transient('pushrelay_tables_missing_list');
            delete_transient('pushrelay_tables_creation_failed');
        }
    }
    
    /**
     * Handle manual table creation from admin notice
     */
    public function handle_manual_table_creation() {
        if (!isset($_GET['action']) || $_GET['action'] !== 'create_tables') {
            return;
        }
        
        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'pushrelay_create_tables')) {
            wp_die(esc_html__('Security check failed', 'pushrelay'));
        }
        
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to perform this action', 'pushrelay'));
        }
        
        // Call the plugin's create_tables method
        $plugin = pushrelay();
        $reflection = new ReflectionClass($plugin);
        $method = $reflection->getMethod('create_tables');
        $method->setAccessible(true);
        $result = $method->invoke($plugin);
        
        // Clear the failed transients
        delete_transient('pushrelay_tables_creation_failed');
        delete_transient('pushrelay_tables_missing');
        delete_transient('pushrelay_tables_missing_list');
        
        // Verify tables were actually created
        global $wpdb;
        $required_tables = array(
            'pushrelay_api_logs',
            'pushrelay_queue',
            'pushrelay_campaigns_local',
            'pushrelay_tickets',
            'pushrelay_segments'
        );
        
        $created_count = 0;
        foreach ($required_tables as $table) {
            $full_table_name = $wpdb->prefix . $table;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Checking table existence
            if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $full_table_name ) ) ) {
                $created_count++;
            }
        }
        
        // Redirect back with success message
        if ($created_count === count($required_tables)) {
            wp_safe_redirect(add_query_arg(array(
                'page' => 'pushrelay',
                'tables_created' => '1'
            ), admin_url('admin.php')));
        } else {
            // Some tables still missing - show error
            set_transient('pushrelay_tables_creation_partial', $created_count, 300);
            wp_safe_redirect(add_query_arg(array(
                'page' => 'pushrelay',
                'tables_partial' => '1'
            ), admin_url('admin.php')));
        }
        exit;
    }
    
    /**
     * Display admin notices
     */
    public function admin_notices() {
        $screen = get_current_screen();
        
        if (!$screen || strpos($screen->id, 'pushrelay') === false) {
            return;
        }
        
        // IMPORTANT: Check if tables exist on EVERY page load
        $this->verify_tables_exist();
        
        // Show success message if tables were just created
        if (isset($_GET['tables_created']) && $_GET['tables_created'] === '1') {
            $created_list = get_option('pushrelay_tables_created_list', array());
            $setup_url = admin_url('admin.php?page=pushrelay-setup');
            ?>
            <div class="notice notice-success is-dismissible">
                <h3 style="margin-top: 0.5em;">✅ <?php esc_html_e('Success!', 'pushrelay'); ?></h3>
                <p>
                    <?php esc_html_e('All 5 database tables have been created successfully! You can now use PushRelay without any errors.', 'pushrelay'); ?>
                </p>
                <p>
                    <strong><?php esc_html_e('Next Step:', 'pushrelay'); ?></strong>
                    <?php esc_html_e('Run the Setup Wizard to configure your PushRelay plugin.', 'pushrelay'); ?>
                </p>
                <p>
                    <a href="<?php echo esc_url($setup_url); ?>" class="button button-primary button-hero">
                        🚀 <?php esc_html_e('Start Setup Wizard Now', 'pushrelay'); ?>
                    </a>
                </p>
            </div>
            <?php
            // Clear the transient now that tables are created
            delete_transient('pushrelay_tables_missing');
            
            // Check if user is not configured, redirect to wizard
            $settings = get_option('pushrelay_settings', array());
            $is_configured = !empty($settings['setup_completed']) || 
                            (!empty($settings['api_key']) && !empty($settings['website_id']) && !empty($settings['pixel_key']));
            
            if (!$is_configured) {
                // Use JavaScript redirect as fallback since headers may already be sent
                ?>
                <script type="text/javascript">
                    setTimeout(function() {
                        window.location.href = '<?php echo esc_js($setup_url); ?>';
                    }, 3000); // Redirect after 3 seconds
                </script>
                <?php
            }
            return;
        }
        
        // Show partial creation warning
        if (isset($_GET['tables_partial']) && $_GET['tables_partial'] === '1') {
            $created_count = get_transient('pushrelay_tables_creation_partial');
            ?>
            <div class="notice notice-warning">
                <p>
                    <strong>⚠️ <?php esc_html_e('Partial Success', 'pushrelay'); ?></strong><br>
                    <?php 
                    printf(
                        /* translators: %d: Number of tables created */
                        esc_html__('Created %d out of 5 tables. Some tables could not be created automatically. You may need to contact your hosting provider to create tables manually.', 'pushrelay'),
                        intval($created_count)
                    );
                    ?>
                </p>
                <p>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=pushrelay-settings&tab=advanced#database-status')); ?>" class="button">
                        <?php esc_html_e('View Detailed Status', 'pushrelay'); ?>
                    </a>
                </p>
            </div>
            <?php
            return;
        }
        
        // Check if tables are missing
        if (get_transient('pushrelay_tables_missing')) {
            $missing_tables = get_transient('pushrelay_tables_missing_list');
            ?>
            <div class="notice notice-error" style="border-left-color: #dc3232;">
                <h3 style="margin-top: 0.5em;">⚠️ <?php esc_html_e('PushRelay Database Tables Missing!', 'pushrelay'); ?></h3>
                <p>
                    <?php esc_html_e('Your PushRelay plugin is missing required database tables. This will cause errors on this page.', 'pushrelay'); ?>
                </p>
                <?php if (!empty($missing_tables)) : ?>
                <p>
                    <strong><?php esc_html_e('Missing tables:', 'pushrelay'); ?></strong>
                    <code><?php echo esc_html(implode(', ', $missing_tables)); ?></code>
                </p>
                <?php endif; ?>
                <p>
                    <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=pushrelay&action=create_tables'), 'pushrelay_create_tables')); ?>" class="button button-primary button-hero" style="margin-right: 10px;">
                        🔧 <?php esc_html_e('Create Missing Tables Now', 'pushrelay'); ?>
                    </a>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=pushrelay&action=check_tables')); ?>" class="button button-secondary">
                        🔍 <?php esc_html_e('Check Table Status', 'pushrelay'); ?>
                    </a>
                </p>
                <p style="font-size: 12px; color: #666;">
                    <?php esc_html_e('Click "Create Missing Tables Now" and the plugin will automatically create all required database tables. This is safe and takes only a few seconds.', 'pushrelay'); ?>
                </p>
            </div>
            <?php
        }
        
        $settings = get_option('pushrelay_settings', array());
        
        // Show setup notice if not configured
        // Check if setup is completed OR if essential settings are present
        $is_configured = !empty($settings['setup_completed']) || 
                        (!empty($settings['api_key']) && !empty($settings['website_id']) && !empty($settings['pixel_key']));
        
        // Allow manual reset via URL parameter
        $reset_nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';
        if (isset($_GET['pushrelay_reset_setup']) && wp_verify_nonce($reset_nonce, 'pushrelay_reset_setup')) {
            $settings['setup_completed'] = false;
            update_option('pushrelay_settings', $settings);
            wp_safe_redirect(admin_url('admin.php?page=pushrelay-setup'));
            exit;
        }
        
        if (!$is_configured && $screen->id !== 'toplevel_page_pushrelay-setup') {
            ?>
            <div class="notice notice-warning">
                <p>
                    <strong><?php esc_html_e('PushRelay is not configured yet.', 'pushrelay'); ?></strong>
                    <?php
                    printf(
                        wp_kses(
                            /* translators: %s: setup wizard URL */
                            __('Please <a href="%s">run the setup wizard</a> to get started.', 'pushrelay'),
                            array('a' => array('href' => array()))
                        ),
                        esc_url(admin_url('admin.php?page=pushrelay-setup'))
                    );
                    ?>
                </p>
            </div>
            <?php
            return;
        }
        
        // Show SSL warning
        if (!is_ssl() && !empty($settings['api_key'])) {
            ?>
            <div class="notice notice-warning">
                <p>
                    <strong><?php esc_html_e('Your site is not using HTTPS.', 'pushrelay'); ?></strong>
                    <?php esc_html_e('Push notifications require HTTPS to work properly.', 'pushrelay'); ?>
                </p>
            </div>
            <?php
        }
        
        // Don't show health check warnings on the health page itself
        if (strpos($screen->id, 'pushrelay-health') !== false) {
            return;
        }
        
        // Don't show health warnings if API is not configured yet
        if (empty($settings['api_key']) || empty($settings['website_id'])) {
            return;
        }
        
        // Add a grace period - don't show health warnings until plugin has been configured for 1 hour
        $setup_completed_time = get_option('pushrelay_setup_completed_time', 0);
        if (empty($setup_completed_time)) {
            // Set it now if setup is completed but time wasn't recorded
            if (!empty($settings['setup_completed'])) {
                update_option('pushrelay_setup_completed_time', time());
            }
            return;
        }
        
        // Don't show health warnings in the first hour after setup completion
        if ((time() - $setup_completed_time) < HOUR_IN_SECONDS) {
            return;
        }
        
        // Show health check warnings
        $health_check = new PushRelay_Health_Check();
        $quick_status = $health_check->get_quick_status();
        
        if ($quick_status['critical_issues'] > 0) {
            ?>
            <div class="notice notice-error">
                <p>
                    <strong><?php esc_html_e('PushRelay Health Check Alert!', 'pushrelay'); ?></strong>
                    <?php
                    printf(
                        wp_kses(
                            /* translators: 1: number of critical issues, 2: health check URL */
                            _n(
                                '%1$d critical issue detected. <a href="%2$s">View details</a>',
                                '%1$d critical issues detected. <a href="%2$s">View details</a>',
                                intval($quick_status['critical_issues']),
                                'pushrelay'
                            ),
                            array('a' => array('href' => array()))
                        ),
                        intval($quick_status['critical_issues']),
                        esc_url(admin_url('admin.php?page=pushrelay-health'))
                    );
                    ?>
                </p>
            </div>
            <?php
        }
    }
    
    /**
     * Dashboard page
     */
    public function dashboard_page() {
        $settings = get_option('pushrelay_settings', array());
        
        // Check if setup is complete
        $is_configured = !empty($settings['setup_completed']) || 
                        (!empty($settings['api_key']) && !empty($settings['website_id']) && !empty($settings['pixel_key']));
        
        // If not configured, redirect to setup wizard
        if (!$is_configured) {
            $setup_url = admin_url('admin.php?page=pushrelay-setup');
            
            // Try regular redirect first
            if (!headers_sent()) {
                wp_safe_redirect($setup_url);
                exit;
            }
            
            // If headers already sent, use JavaScript redirect with message
            ?>
            <div class="wrap">
                <h1><?php esc_html_e('PushRelay Dashboard', 'pushrelay'); ?></h1>
                <div class="notice notice-info" style="padding: 20px; margin-top: 20px;">
                    <h2><?php esc_html_e('Setup Required', 'pushrelay'); ?></h2>
                    <p><?php esc_html_e('PushRelay needs to be configured before you can use it.', 'pushrelay'); ?></p>
                    <p>
                        <a href="<?php echo esc_url($setup_url); ?>" class="button button-primary button-hero">
                            <?php esc_html_e('Start Setup Wizard', 'pushrelay'); ?>
                        </a>
                    </p>
                    <p style="margin-top: 15px;">
                        <em><?php esc_html_e('You will be redirected automatically in 3 seconds...', 'pushrelay'); ?></em>
                    </p>
                </div>
            </div>
            <script type="text/javascript">
                setTimeout(function() {
                    window.location.href = '<?php echo esc_js($setup_url); ?>';
                }, 3000);
            </script>
            <?php
            return;
        }
        
        require_once PUSHRELAY_PLUGIN_DIR . 'views/dashboard.php';
    }
    
    /**
     * Campaigns page
     */
    public function campaigns_page() {
        require_once PUSHRELAY_PLUGIN_DIR . 'views/campaigns.php';
    }
    
    /**
     * Subscribers page
     */
    public function subscribers_page() {
        require_once PUSHRELAY_PLUGIN_DIR . 'views/subscribers.php';
    }
    
    /**
     * Analytics page
     */
    public function analytics_page() {
        require_once PUSHRELAY_PLUGIN_DIR . 'views/analytics.php';
    }
    
    /**
     * WooCommerce page
     */
    public function woocommerce_page() {
        require_once PUSHRELAY_PLUGIN_DIR . 'views/woocommerce.php';
    }
    
    /**
     * Health Check page
     */
    public function health_check_page() {
        require_once PUSHRELAY_PLUGIN_DIR . 'views/health-check.php';
    }
    
    /**
     * Support page
     */
    public function support_page() {
        require_once PUSHRELAY_PLUGIN_DIR . 'views/support.php';
    }
    
    /**
     * Settings page
     */
    public function settings_page() {
        require_once PUSHRELAY_PLUGIN_DIR . 'views/settings.php';
    }
    
    /**
     * Setup Wizard page
     */
    public function setup_wizard_page() {
        require_once PUSHRELAY_PLUGIN_DIR . 'views/setup-wizard.php';
    }
    
    /**
     * Add dashboard widget
     */
    public function add_dashboard_widget() {
        $screen = get_current_screen();
        
        if ($screen && $screen->id === 'dashboard') {
            wp_add_dashboard_widget(
                'pushrelay_dashboard_widget',
                __('PushRelay Statistics', 'pushrelay'),
                array($this, 'render_dashboard_widget')
            );
        }
    }
    
    /**
     * Render dashboard widget
     */
    public function render_dashboard_widget() {
        $settings = get_option('pushrelay_settings', array());
        
        if (empty($settings['api_key'])) {
            ?>
            <p>
                <?php
                printf(
                    wp_kses(
                        /* translators: %s: settings URL */
                        __('Please <a href="%s">configure PushRelay</a> to see statistics.', 'pushrelay'),
                        array('a' => array('href' => array()))
                    ),
                    esc_url(admin_url('admin.php?page=pushrelay-settings'))
                );
                ?>
            </p>
            <?php
            return;
        }
        
        $api = pushrelay()->get_api_client();
        
        // Get quick stats
        $websites = $api->get_websites(1, 1);
        $campaigns = $api->get_campaigns(1, 1);
        $subscribers = $api->get_subscribers(1, 1);
        
        $total_websites = !is_wp_error($websites) && isset($websites['meta']['total']) ? $websites['meta']['total'] : 0;
        $total_campaigns = !is_wp_error($campaigns) && isset($campaigns['meta']['total']) ? $campaigns['meta']['total'] : 0;
        $total_subscribers = !is_wp_error($subscribers) && isset($subscribers['meta']['total']) ? $subscribers['meta']['total'] : 0;
        
        // Get health status
        $health_check = new PushRelay_Health_Check();
        $health_score = $health_check->get_health_score();
        
        ?>
        <div class="pushrelay-dashboard-widget">
            <div class="pushrelay-stats-row">
                <div class="pushrelay-stat">
                    <span class="pushrelay-stat-label"><?php esc_html_e('Subscribers', 'pushrelay'); ?></span>
                    <span class="pushrelay-stat-value"><?php echo esc_html(number_format($total_subscribers)); ?></span>
                </div>
                <div class="pushrelay-stat">
                    <span class="pushrelay-stat-label"><?php esc_html_e('Campaigns', 'pushrelay'); ?></span>
                    <span class="pushrelay-stat-value"><?php echo esc_html(number_format($total_campaigns)); ?></span>
                </div>
                <div class="pushrelay-stat">
                    <span class="pushrelay-stat-label"><?php esc_html_e('Health Score', 'pushrelay'); ?></span>
                    <span class="pushrelay-stat-value"><?php echo esc_html($health_score); ?>%</span>
                </div>
            </div>
            
            <p style="margin-top: 15px;">
                <a href="<?php echo esc_url(admin_url('admin.php?page=pushrelay')); ?>" class="button button-primary">
                    <?php esc_html_e('View Full Dashboard', 'pushrelay'); ?>
                </a>
            </p>
        </div>
        <style>
            .pushrelay-dashboard-widget .pushrelay-stats-row {
                display: flex;
                gap: 15px;
                margin-bottom: 10px;
            }
            .pushrelay-dashboard-widget .pushrelay-stat {
                flex: 1;
                text-align: center;
                padding: 15px;
                background: #f5f5f5;
                border-radius: 4px;
            }
            .pushrelay-dashboard-widget .pushrelay-stat-label {
                display: block;
                font-size: 12px;
                color: #666;
                margin-bottom: 5px;
            }
            .pushrelay-dashboard-widget .pushrelay-stat-value {
                display: block;
                font-size: 24px;
                font-weight: bold;
                color: #333;
            }
        </style>
        <?php
    }
    
    /**
     * AJAX: Save settings
     */
    public function ajax_save_settings() {
        // Suppress ALL errors including database errors
        global $wpdb;
        if ($wpdb) {
            $wpdb->hide_errors();
            $wpdb->suppress_errors(true);
        }
        
        // Verify nonce
        $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
        if (!wp_verify_nonce($nonce, 'pushrelay_admin_nonce')) {
            wp_send_json_error(array('message' => __('Security check failed', 'pushrelay')));
            return;
        }
        
        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'pushrelay')));
            return;
        }
        
        // Get settings - support both formats:
        // 1. From wizard: settings[key] -> $_POST['settings']
        // 2. From settings form: pushrelay_settings[key] -> $_POST['pushrelay_settings']
        $new_settings = array();
        
        if (isset($_POST['pushrelay_settings']) && is_array($_POST['pushrelay_settings'])) {
            // From settings form
            $new_settings = $_POST['pushrelay_settings']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        } elseif (isset($_POST['settings']) && is_array($_POST['settings'])) {
            // From wizard
            $new_settings = $_POST['settings']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        }
        
        // Handle checkbox fields that might not be sent when unchecked (only for form submissions)
        if (isset($_POST['pushrelay_settings'])) {
            $checkbox_fields = array('auto_notifications', 'woocommerce_enabled', 'debug_mode', 'setup_completed', 'health_check_enabled');
            foreach ($checkbox_fields as $field) {
                if (!isset($new_settings[$field])) {
                    $new_settings[$field] = false;
                } else {
                    $new_settings[$field] = true;
                }
            }
            
            // Handle notification_types array
            if (!isset($new_settings['notification_types']) || !is_array($new_settings['notification_types'])) {
                $new_settings['notification_types'] = array();
            }
        }
        
        // Get existing settings
        $existing_settings = get_option('pushrelay_settings', array());
        
        // Merge new with existing (new values override existing)
        $merged_settings = array_merge($existing_settings, $new_settings);
        
        // Sanitize
        $sanitized = $this->sanitize_settings($merged_settings);
        
        // Save merged settings
        $saved = update_option('pushrelay_settings', $sanitized);
        
        // Trigger service worker regeneration if website_id or pixel_key changed
        if (isset($new_settings['website_id']) || isset($new_settings['pixel_key'])) {
            do_action('pushrelay_settings_updated');
        }
        
        // Log for debugging
        PushRelay_Debug_Logger::log('Settings saved via AJAX. Keys: ' . implode(', ', array_keys($new_settings)), 'info');
        
        if ($saved !== false || !empty($sanitized)) {
            wp_send_json_success(array(
                'message' => __('Settings saved successfully', 'pushrelay'),
                'settings' => $sanitized,
                'saved_keys' => array_keys($new_settings)
            ));
        } else {
            wp_send_json_error(array(
                'message' => __('Failed to save settings', 'pushrelay'),
                'debug' => 'update_option returned false'
            ));
        }
    }
    
    /**
     * AJAX: Get all websites (for wizard)
     */
    public function ajax_detect_website() {
        // Suppress ALL output during AJAX
        global $wpdb;
        $wpdb->hide_errors();
        $wpdb->suppress_errors(true);
        
        check_ajax_referer('pushrelay_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'pushrelay')));
        }
        
        $api = pushrelay()->get_api_client();
        
        // Get all websites
        $websites_response = $api->get_websites(1, 100);
        
        if (is_wp_error($websites_response)) {
            wp_send_json_error(array(
                'message' => $websites_response->get_error_message(),
                'websites' => array()
            ));
        }
        
        // Return websites array
        if (!empty($websites_response['data'])) {
            wp_send_json_success(array(
                'websites' => $websites_response['data'],
                'message' => __('Websites loaded successfully', 'pushrelay')
            ));
        } else {
            wp_send_json_success(array(
                'websites' => array(),
                'message' => __('No websites found', 'pushrelay')
            ));
        }
    }
    
    /**
     * AJAX: Test API connection
     */
    public function ajax_test_connection() {
        check_ajax_referer('pushrelay_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'pushrelay')));
        }
        
        $api = pushrelay()->get_api_client();
        $result = $api->test_connection();
        
        if (!$result['success']) {
            wp_send_json_error($result);
        }
        
        wp_send_json_success($result);
    }
    
    /**
     * AJAX: Get chart data for analytics
     */
    public function ajax_get_chart_data() {
        // Verify nonce - accept both nonce names for compatibility
        $post_nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
        if (!wp_verify_nonce($post_nonce, 'pushrelay_nonce') && 
            !wp_verify_nonce($post_nonce, 'pushrelay_admin_nonce')) {
            wp_send_json_error(array('message' => __('Security check failed', 'pushrelay')));
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'pushrelay')));
        }
        
        $settings = get_option('pushrelay_settings', array());
        $chart_type = isset($_POST['chart_type']) ? sanitize_text_field(wp_unslash($_POST['chart_type'])) : 'subscribers';
        $start_date = isset($_POST['start_date']) ? sanitize_text_field(wp_unslash($_POST['start_date'])) : gmdate('Y-m-d', strtotime('-30 days'));
        $end_date = isset($_POST['end_date']) ? sanitize_text_field(wp_unslash($_POST['end_date'])) : gmdate('Y-m-d');
        $website_id = isset($_POST['website_id']) ? absint($_POST['website_id']) : ($settings['website_id'] ?? 0);
        
        // Get analytics from API
        $api = pushrelay()->get_api_client();
        $analytics = $api->get_subscriber_statistics($website_id, $start_date, $end_date, $chart_type);
        
        if (is_wp_error($analytics)) {
            // Return empty chart data if API fails
            wp_send_json_success(array(
                'labels' => array(),
                'datasets' => array(
                    array(
                        'label' => ucfirst($chart_type),
                        'data' => array(),
                        'borderColor' => '#4CAF50',
                        'backgroundColor' => 'rgba(76, 175, 80, 0.1)',
                        'fill' => true
                    )
                )
            ));
            return;
        }
        
        // Format data for Chart.js
        $labels = array();
        $data = array();
        
        if (!empty($analytics['data'])) {
            foreach ($analytics['data'] as $item) {
                $labels[] = isset($item['date']) ? $item['date'] : '';
                $data[] = isset($item['count']) ? $item['count'] : 0;
            }
        }
        
        wp_send_json_success(array(
            'labels' => $labels,
            'datasets' => array(
                array(
                    'label' => ucfirst($chart_type),
                    'data' => $data,
                    'borderColor' => '#4CAF50',
                    'backgroundColor' => 'rgba(76, 175, 80, 0.1)',
                    'fill' => true
                )
            )
        ));
    }
    
    /**
     * AJAX: Export analytics data
     */
    public function ajax_export_analytics() {
        // Verify nonce
        $nonce = isset($_GET['nonce']) ? sanitize_text_field(wp_unslash($_GET['nonce'])) : '';
        if (!wp_verify_nonce($nonce, 'pushrelay_export') && 
            !wp_verify_nonce($nonce, 'pushrelay_admin_nonce')) {
            wp_die(esc_html__('Security check failed', 'pushrelay'));
        }
        
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions', 'pushrelay'));
        }
        
        $settings = get_option('pushrelay_settings', array());
        $start_date = isset($_GET['start_date']) ? sanitize_text_field(wp_unslash($_GET['start_date'])) : gmdate('Y-m-d', strtotime('-30 days'));
        $end_date = isset($_GET['end_date']) ? sanitize_text_field(wp_unslash($_GET['end_date'])) : gmdate('Y-m-d');
        $website_id = isset($_GET['website_id']) ? absint($_GET['website_id']) : ($settings['website_id'] ?? 0);
        
        // Get analytics from API
        $api = pushrelay()->get_api_client();
        $analytics = $api->get_subscriber_statistics($website_id, $start_date, $end_date, 'overview');
        
        // Generate CSV
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="pushrelay-analytics-' . gmdate('Y-m-d') . '.csv"');
        
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Required for CSV output
        $output = fopen('php://output', 'w');
        fputcsv($output, array('Date', 'Subscribers', 'Displayed', 'Clicked', 'Closed'));
        
        if (!is_wp_error($analytics) && !empty($analytics['data'])) {
            foreach ($analytics['data'] as $item) {
                fputcsv($output, array(
                    $item['date'] ?? '',
                    $item['subscribers'] ?? 0,
                    $item['displayed'] ?? 0,
                    $item['clicked'] ?? 0,
                    $item['closed'] ?? 0
                ));
            }
        }
        
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Required for CSV output
        fclose($output);
        exit;
    }
    
    /**
     * AJAX: Create campaign
     */
    public function ajax_create_campaign() {
        check_ajax_referer('pushrelay_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'pushrelay')));
        }
        
        $campaign_data = isset($_POST['campaign']) ? $_POST['campaign'] : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        
        // Debug logging
        if (defined('WP_DEBUG') && WP_DEBUG) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log, WordPress.PHP.DevelopmentFunctions.error_log_print_r -- Debug logging only when WP_DEBUG is enabled
            error_log('PushRelay: Campaign data received: ' . print_r($campaign_data, true));
        }
        
        if (empty($campaign_data['name']) || empty($campaign_data['title'])) {
            wp_send_json_error(array('message' => __('Campaign name and title are required', 'pushrelay')));
        }
        
        $settings = get_option('pushrelay_settings', array());
        
        if (empty($settings['website_id'])) {
            wp_send_json_error(array('message' => __('Website not configured. Please go to Settings and select a website.', 'pushrelay')));
        }
        
        // Sanitize campaign data
        $sanitized = array(
            'name' => sanitize_text_field($campaign_data['name']),
            'title' => sanitize_text_field($campaign_data['title']),
            'description' => sanitize_textarea_field($campaign_data['description'] ?? ''),
            'website_id' => absint($settings['website_id']),
            'send' => true, // Always send immediately for now
        );
        
        // Add optional URL
        if (!empty($campaign_data['url'])) {
            $sanitized['url'] = esc_url_raw($campaign_data['url']);
        }
        
        // Add image URL if provided
        if (!empty($campaign_data['image_url'])) {
            $sanitized['image_url'] = esc_url_raw($campaign_data['image_url']);
        }
        
        // Debug logging
        if (defined('WP_DEBUG') && WP_DEBUG) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log, WordPress.PHP.DevelopmentFunctions.error_log_print_r -- Debug logging only when WP_DEBUG is enabled
            error_log('PushRelay: Sanitized campaign data: ' . print_r($sanitized, true));
        }
        
        // Send to API
        $api = pushrelay()->get_api_client();
        $result = $api->create_campaign($sanitized);
        
        // Debug logging
        if (defined('WP_DEBUG') && WP_DEBUG) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log, WordPress.PHP.DevelopmentFunctions.error_log_print_r -- Debug logging only when WP_DEBUG is enabled
            error_log('PushRelay: API result: ' . print_r($result, true));
        }
        
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }
        
        wp_send_json_success(array(
            'message' => __('Campaign created and sent successfully!', 'pushrelay'),
            'campaign' => $result['data'] ?? array()
        ));
    }
}