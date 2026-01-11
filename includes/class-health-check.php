<?php
/**
 * Health Check Class
 * 
 * Monitors API connectivity, service worker status, and overall plugin health
 * 
 * @package PushRelay
 * @since 1.6.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class PushRelay_Health_Check {
    
    /**
     * Constructor
     */
    public function __construct() {
        // Register cron job
        add_action('pushrelay_health_check', array($this, 'run_health_check'));
        
        // AJAX handlers
        add_action('wp_ajax_pushrelay_run_health_check', array($this, 'ajax_run_health_check'));
        add_action('wp_ajax_pushrelay_get_health_status', array($this, 'ajax_get_health_status'));
        add_action('wp_ajax_pushrelay_fix_issues', array($this, 'ajax_fix_issues'));
    }
    
    /**
     * Run complete health check
     */
    /**
     * Run all checks (alias for run_health_check)
     */
    public function run_all_checks() {
        return $this->run_health_check();
    }

    public function run_health_check() {
        $results = array(
            'timestamp' => current_time('mysql'),
            'checks' => array(
                'api_connection' => $this->check_api_connection(),
                'api_key_valid' => $this->check_api_key(),
                'website_configured' => $this->check_website_configuration(),
                'service_worker' => $this->check_service_worker(),
                'database_tables' => $this->check_database_tables(),
                'cron_jobs' => $this->check_cron_jobs(),
                'permissions' => $this->check_permissions(),
                'ssl' => $this->check_ssl(),
                'php_version' => $this->check_php_version(),
                'wordpress_version' => $this->check_wordpress_version(),
            ),
            'overall_status' => 'unknown'
        );
        
        // Calculate overall status
        $results['overall_status'] = $this->calculate_overall_status($results['checks']);
        
        // Save results
        update_option('pushrelay_health_check_results', $results, false);
        
        // Log if there are issues
        if ($results['overall_status'] === 'error') {
            $failed_checks = array_filter($results['checks'], function($check) {
                return $check['status'] === 'error';
            });
            
            PushRelay_Debug_Logger::log(
                /* translators: %d: Number of critical issues */
                sprintf(__('Health check failed: %d critical issues found', 'pushrelay'), count($failed_checks)),
                'error',
                array('failed_checks' => array_keys($failed_checks))
            );
        } elseif ($results['overall_status'] === 'warning') {
            PushRelay_Debug_Logger::log(
                __('Health check completed with warnings', 'pushrelay'),
                'warning'
            );
        } else {
            PushRelay_Debug_Logger::log(
                __('Health check completed successfully', 'pushrelay'),
                'success'
            );
        }
        
        return $results;
    }
    
    /**
     * Check API connection
     */
    private function check_api_connection() {
        $api = pushrelay()->get_api_client();
        $test = $api->test_connection();
        
        if ($test['success']) {
            return array(
                'status' => 'success',
                'message' => __('API connection is working', 'pushrelay'),
                'details' => array(
                    'response_time' => 'Good'
                )
            );
        }
        
        return array(
            'status' => 'error',
            'message' => __('Cannot connect to PushRelay API', 'pushrelay'),
            'details' => array(
                'error' => $test['message']
            )
        );
    }
    
    /**
     * Check API key validity
     */
    private function check_api_key() {
        $settings = get_option('pushrelay_settings', array());
        
        if (empty($settings['api_key'])) {
            return array(
                'status' => 'error',
                'message' => __('API key is not configured', 'pushrelay'),
                'details' => array()
            );
        }
        
        // Try to get user info to validate key
        $api = pushrelay()->get_api_client();
        $user = $api->get_user();
        
        if (is_wp_error($user)) {
            return array(
                'status' => 'error',
                'message' => __('API key is invalid or expired', 'pushrelay'),
                'details' => array(
                    'error' => $user->get_error_message()
                )
            );
        }
        
        return array(
            'status' => 'success',
            'message' => __('API key is valid', 'pushrelay'),
            'details' => array(
                'email' => isset($user['data']['email']) ? $user['data']['email'] : 'N/A',
                'plan' => isset($user['data']['billing']['plan_id']) ? $user['data']['billing']['plan_id'] : 'N/A'
            )
        );
    }
    
    /**
     * Check website configuration
     */
    private function check_website_configuration() {
        $settings = get_option('pushrelay_settings', array());
        
        if (empty($settings['website_id'])) {
            return array(
                'status' => 'error',
                'message' => __('Website ID is not configured', 'pushrelay'),
                'details' => array()
            );
        }
        
        if (empty($settings['pixel_key'])) {
            return array(
                'status' => 'warning',
                'message' => __('Pixel key is not configured', 'pushrelay'),
                'details' => array()
            );
        }
        
        // Verify website exists in API
        $api = pushrelay()->get_api_client();
        $website = $api->get_website($settings['website_id']);
        
        if (is_wp_error($website)) {
            return array(
                'status' => 'error',
                'message' => __('Website not found in PushRelay account', 'pushrelay'),
                'details' => array(
                    'website_id' => $settings['website_id'],
                    'error' => $website->get_error_message()
                )
            );
        }
        
        return array(
            'status' => 'success',
            'message' => __('Website is properly configured', 'pushrelay'),
            'details' => array(
                'website_id' => $settings['website_id'],
                'website_name' => isset($website['data']['name']) ? $website['data']['name'] : 'N/A',
                'total_subscribers' => isset($website['data']['total_subscribers']) ? $website['data']['total_subscribers'] : 0
            )
        );
    }
    
    /**
     * Check service worker
     */
    private function check_service_worker() {
        $sw = new PushRelay_Service_Worker();
        $status = $sw->get_status();
        
        if (!$status['configured']) {
            return array(
                'status' => 'error',
                'message' => __('Service worker is not configured', 'pushrelay'),
                'details' => array()
            );
        }
        
        if (!$status['exists']) {
            return array(
                'status' => 'warning',
                'message' => __('Service worker file does not exist', 'pushrelay'),
                'details' => array(
                    'expected_path' => $status['path']
                )
            );
        }
        
        if (!$status['up_to_date']) {
            return array(
                'status' => 'warning',
                'message' => __('Service worker needs to be regenerated', 'pushrelay'),
                'details' => array(
                    'current_version' => $status['version'],
                    'expected_version' => PUSHRELAY_VERSION
                )
            );
        }
        
        // Test accessibility
        $test = $sw->test_service_worker();
        
        if (!$test['success']) {
            return array(
                'status' => 'error',
                'message' => __('Service worker is not accessible', 'pushrelay'),
                'details' => array(
                    'url' => $status['url'],
                    'error' => $test['message']
                )
            );
        }
        
        return array(
            'status' => 'success',
            'message' => __('Service worker is working properly', 'pushrelay'),
            'details' => array(
                'url' => $status['url'],
                'location' => $status['location'],
                'version' => $status['version']
            )
        );
    }
    
    /**
     * Check database tables
     */
    private function check_database_tables() {
        global $wpdb;
        
        $required_tables = array(
            $wpdb->prefix . 'pushrelay_queue',
            $wpdb->prefix . 'pushrelay_api_logs',
            $wpdb->prefix . 'pushrelay_tickets',
            $wpdb->prefix . 'pushrelay_segments'
        );
        
        $missing_tables = array();
        
        foreach ($required_tables as $table) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Checking table existence
            $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
            if ($exists !== $table) {
                $missing_tables[] = $table;
            }
        }
        
        if (!empty($missing_tables)) {
            return array(
                'status' => 'error',
                'message' => __('Some database tables are missing', 'pushrelay'),
                'details' => array(
                    'missing_tables' => $missing_tables
                )
            );
        }
        
        return array(
            'status' => 'success',
            'message' => __('All database tables exist', 'pushrelay'),
            'details' => array(
                'tables' => $required_tables
            )
        );
    }
    
    /**
     * Check cron jobs
     */
    private function check_cron_jobs() {
        $required_crons = array(
            'pushrelay_health_check' => 'hourly',
            'pushrelay_process_queue' => 'every_5_minutes'
        );
        
        $missing_crons = array();
        
        foreach ($required_crons as $hook => $schedule) {
            if (!wp_next_scheduled($hook)) {
                $missing_crons[] = $hook;
            }
        }
        
        if (!empty($missing_crons)) {
            return array(
                'status' => 'warning',
                'message' => __('Some cron jobs are not scheduled', 'pushrelay'),
                'details' => array(
                    'missing_crons' => $missing_crons
                )
            );
        }
        
        // Check if WP-Cron is working
        if (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) {
            return array(
                'status' => 'warning',
                'message' => __('WP-Cron is disabled. Make sure you have a system cron configured.', 'pushrelay'),
                'details' => array()
            );
        }
        
        return array(
            'status' => 'success',
            'message' => __('All cron jobs are scheduled', 'pushrelay'),
            'details' => array(
                'cron_jobs' => array_keys($required_crons)
            )
        );
    }
    
    /**
     * Check file permissions
     */
    private function check_permissions() {
        $issues = array();
        
        // Check root directory writability
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Checking permissions
        if (!is_writable(ABSPATH)) {
            $issues[] = __('Root directory is not writable (service worker cannot be created)', 'pushrelay');
        }
        
        // Check uploads directory
        $upload_dir = wp_upload_dir();
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Checking permissions
        if (!is_writable($upload_dir['basedir'])) {
            $issues[] = __('Uploads directory is not writable', 'pushrelay');
        }
        
        if (!empty($issues)) {
            return array(
                'status' => 'warning',
                'message' => __('Some permission issues detected', 'pushrelay'),
                'details' => array(
                    'issues' => $issues
                )
            );
        }
        
        return array(
            'status' => 'success',
            'message' => __('File permissions are correct', 'pushrelay'),
            'details' => array()
        );
    }
    
    /**
     * Check SSL
     */
    private function check_ssl() {
        if (!is_ssl()) {
            return array(
                'status' => 'warning',
                'message' => __('Your site is not using HTTPS. Push notifications require HTTPS.', 'pushrelay'),
                'details' => array(
                    'site_url' => get_site_url()
                )
            );
        }
        
        return array(
            'status' => 'success',
            'message' => __('Site is using HTTPS', 'pushrelay'),
            'details' => array()
        );
    }
    
    /**
     * Check PHP version
     */
    private function check_php_version() {
        $min_version = '7.4';
        $current_version = PHP_VERSION;
        
        if (version_compare($current_version, $min_version, '<')) {
            return array(
                'status' => 'error',
                /* translators: 1: Required PHP version, 2: Current PHP version */
                'message' => sprintf(__('PHP version %1$s is required. You are running %2$s', 'pushrelay'), $min_version, $current_version),
                'details' => array(
                    'current_version' => $current_version,
                    'required_version' => $min_version
                )
            );
        }
        
        if (version_compare($current_version, '8.0', '<')) {
            return array(
                'status' => 'warning',
                /* translators: %s: Current PHP version */
                'message' => sprintf(__('PHP %s is good, but PHP 8.0+ is recommended', 'pushrelay'), $current_version),
                'details' => array(
                    'current_version' => $current_version
                )
            );
        }
        
        return array(
            'status' => 'success',
            /* translators: %s: Current PHP version */
            'message' => sprintf(__('PHP version %s is great!', 'pushrelay'), $current_version),
            'details' => array(
                'current_version' => $current_version
            )
        );
    }
    
    /**
     * Check WordPress version
     */
    private function check_wordpress_version() {
        $min_version = '5.8';
        $current_version = get_bloginfo('version');
        
        if (version_compare($current_version, $min_version, '<')) {
            return array(
                'status' => 'error',
                /* translators: 1: Required WordPress version, 2: Current WordPress version */
                'message' => sprintf(__('WordPress version %1$s is required. You are running %2$s', 'pushrelay'), $min_version, $current_version),
                'details' => array(
                    'current_version' => $current_version,
                    'required_version' => $min_version
                )
            );
        }
        
        return array(
            'status' => 'success',
            /* translators: %s: Current WordPress version */
            'message' => sprintf(__('WordPress version %s is compatible', 'pushrelay'), $current_version),
            'details' => array(
                'current_version' => $current_version
            )
        );
    }
    
    /**
     * Calculate overall status
     */
    private function calculate_overall_status($checks) {
        $has_error = false;
        $has_warning = false;
        
        foreach ($checks as $check) {
            if ($check['status'] === 'error') {
                $has_error = true;
            } elseif ($check['status'] === 'warning') {
                $has_warning = true;
            }
        }
        
        if ($has_error) {
            return 'error';
        }
        
        if ($has_warning) {
            return 'warning';
        }
        
        return 'success';
    }
    
    /**
     * Get last health check results
     */
    public function get_last_results() {
        $results = get_option('pushrelay_health_check_results', array());
        
        if (empty($results)) {
            return array(
                'status' => 'unknown',
                'message' => __('Health check has not been run yet', 'pushrelay'),
                'timestamp' => null
            );
        }
        
        return $results;
    }
    
    /**
     * Get health score (0-100)
     */
    public function get_health_score() {
        $results = $this->get_last_results();
        
        if (empty($results['checks'])) {
            return 0;
        }
        
        $total_checks = count($results['checks']);
        $passed_checks = 0;
        $partial_checks = 0;
        
        foreach ($results['checks'] as $check) {
            if ($check['status'] === 'success') {
                $passed_checks++;
            } elseif ($check['status'] === 'warning') {
                $partial_checks++;
            }
        }
        
        // Calculate score: success = 100%, warning = 50%, error = 0%
        $score = $total_checks > 0 ? (($passed_checks + ($partial_checks * 0.5)) / $total_checks) * 100 : 0;
        
        return round($score, 1);
    }
    
    /**
     * AJAX: Run health check
     */
    public function ajax_run_health_check() {
        check_ajax_referer('pushrelay_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'pushrelay')));
        }
        
        $results = $this->run_health_check();
        $score = $this->get_health_score();
        
        wp_send_json_success(array(
            'results' => $results,
            'score' => $score,
            'message' => __('Health check completed', 'pushrelay')
        ));
    }
    
    /**
     * AJAX: Get health status
     */
    public function ajax_get_health_status() {
        check_ajax_referer('pushrelay_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'pushrelay')));
        }
        
        $results = $this->get_last_results();
        $score = $this->get_health_score();
        
        wp_send_json_success(array(
            'results' => $results,
            'score' => $score
        ));
    }
    
    /**
     * Get quick status for dashboard widget
     * Runs fresh check if no cached results exist
     */
    public function get_quick_status() {
        $results = $this->get_last_results();
        
        // If no cached results, run a fresh check
        $status_value = isset($results['status']) ? $results['status'] : 'unknown';
        if (empty($results) || !isset($results['checks']) || $status_value === 'unknown') {
            $results = $this->run_health_check();
        }
        
        $score = $this->get_health_score();
        
        $status = array(
            'overall' => isset($results['overall_status']) ? $results['overall_status'] : 'unknown',
            'score' => $score,
            'timestamp' => isset($results['timestamp']) ? $results['timestamp'] : null,
            'critical_issues' => 0,
            'warnings' => 0
        );
        
        if (isset($results['checks'])) {
            foreach ($results['checks'] as $check) {
                if (isset($check['status']) && $check['status'] === 'error') {
                    $status['critical_issues']++;
                } elseif (isset($check['status']) && $check['status'] === 'warning') {
                    $status['warnings']++;
                }
            }
        }
        
        return $status;
    }
    
    /**
     * Fix common issues automatically
     */
    public function auto_fix_issues() {
        $fixed = array();
        $failed = array();
        
        // Try to regenerate service worker
        $sw = new PushRelay_Service_Worker();
        if ($sw->generate_service_worker()) {
            $fixed[] = __('Service worker regenerated', 'pushrelay');
        } else {
            $failed[] = __('Could not regenerate service worker', 'pushrelay');
        }
        
        // Reschedule cron jobs
        if (!wp_next_scheduled('pushrelay_health_check')) {
            wp_schedule_event(time(), 'hourly', 'pushrelay_health_check');
            $fixed[] = __('Health check cron rescheduled', 'pushrelay');
        }
        
        if (!wp_next_scheduled('pushrelay_process_queue')) {
            wp_schedule_event(time(), 'every_5_minutes', 'pushrelay_process_queue');
            $fixed[] = __('Queue processor cron rescheduled', 'pushrelay');
        }
        
        // Recreate database tables - call create_tables directly, NOT activate
        // (activate() sets a redirect transient that would send user to wizard)
        $plugin = pushrelay();
        if (method_exists($plugin, 'create_tables')) {
            $plugin->create_tables();
            $fixed[] = __('Database tables verified', 'pushrelay');
        }
        
        return array(
            'fixed' => $fixed,
            'failed' => $failed
        );
    }
    
    /**
     * AJAX: Fix issues
     */
    public function ajax_fix_issues() {
        check_ajax_referer('pushrelay_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'pushrelay')));
        }
        
        // Clear the cron check transient to force re-scheduling
        delete_transient('pushrelay_cron_check');
        
        // Run auto fix
        $fix_results = $this->auto_fix_issues();
        
        // Re-run health check to get updated results
        $results = $this->run_health_check();
        $score = $this->get_health_score();
        
        wp_send_json_success(array(
            'results' => $results,
            'score' => $score,
            'fixed' => $fix_results['fixed'],
            'failed' => $fix_results['failed'],
            'message' => __('Issues fixed successfully', 'pushrelay')
        ));
    }
    
    /**
     * Run comprehensive self-test diagnostics
     * 
     * This method performs a complete diagnostic check of all plugin systems.
     * Output is structured for machine-readability and can be called by:
     * - Health Check page
     * - Support tooling
     * - Future WP-CLI integration
     * 
     * @return array Structured diagnostic results
     */
    public function run_diagnostics() {
        $start_time = microtime( true );
        $settings = get_option( 'pushrelay_settings', array() );
        
        $diagnostics = array(
            'timestamp'     => current_time( 'mysql' ),
            'plugin_version' => defined( 'PUSHRELAY_VERSION' ) ? PUSHRELAY_VERSION : 'unknown',
            'php_version'   => PHP_VERSION,
            'wp_version'    => get_bloginfo( 'version' ),
            'tests'         => array(),
            'summary'       => array(
                'total'   => 0,
                'passed'  => 0,
                'failed'  => 0,
                'skipped' => 0,
            ),
        );
        
        // Test 1: API Authentication
        $diagnostics['tests']['api_authentication'] = $this->diagnostic_test_api_auth();
        
        // Test 2: Website Availability
        $diagnostics['tests']['website_availability'] = $this->diagnostic_test_websites();
        
        // Test 3: Subscriber Statistics Endpoint
        $diagnostics['tests']['subscriber_statistics'] = $this->diagnostic_test_subscriber_stats( $settings );
        
        // Test 4: Database Tables
        $diagnostics['tests']['database_tables'] = $this->diagnostic_test_database();
        
        // Test 5: Cron Jobs
        $diagnostics['tests']['cron_jobs'] = $this->diagnostic_test_cron();
        
        // Test 6: Service Worker Endpoint
        $diagnostics['tests']['service_worker'] = $this->diagnostic_test_service_worker();
        
        // Calculate summary
        foreach ( $diagnostics['tests'] as $test ) {
            $diagnostics['summary']['total']++;
            switch ( $test['status'] ) {
                case 'pass':
                    $diagnostics['summary']['passed']++;
                    break;
                case 'fail':
                    $diagnostics['summary']['failed']++;
                    break;
                case 'skip':
                    $diagnostics['summary']['skipped']++;
                    break;
            }
        }
        
        $diagnostics['execution_time'] = round( microtime( true ) - $start_time, 3 );
        $diagnostics['overall_status'] = $diagnostics['summary']['failed'] > 0 ? 'fail' : 'pass';
        
        return $diagnostics;
    }
    
    /**
     * Export diagnostic data for support purposes
     * 
     * Returns a structured, machine-readable array suitable for:
     * - Support ticket attachment
     * - WP-CLI output (future)
     * - JSON export
     * 
     * All sensitive data (API keys, tokens) is automatically redacted.
     * 
     * @return array Structured diagnostic export
     */
    public function export_diagnostics() {
        $diagnostics = $this->run_diagnostics();
        $settings = get_option( 'pushrelay_settings', array() );
        
        // Get last API errors (sanitized)
        $recent_errors = $this->get_recent_api_errors( 5 );
        
        $export = array(
            'export_version'   => '1.0',
            'exported_at'      => current_time( 'c' ),
            
            // Environment
            'environment' => array(
                'plugin_version'   => defined( 'PUSHRELAY_VERSION' ) ? PUSHRELAY_VERSION : 'unknown',
                'wordpress_version' => get_bloginfo( 'version' ),
                'php_version'      => PHP_VERSION,
                'mysql_version'    => $this->get_mysql_version(),
                'server_software'  => isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : 'unknown',
                'is_multisite'     => is_multisite(),
                'site_url'         => get_site_url(),
                'is_ssl'           => is_ssl(),
            ),
            
            // Configuration (no secrets)
            'configuration' => array(
                'website_id'         => isset( $settings['website_id'] ) ? absint( $settings['website_id'] ) : 0,
                'api_key_configured' => ! empty( $settings['api_key'] ),
                'debug_mode'         => ! empty( $settings['debug_mode'] ),
                'auto_notifications' => ! empty( $settings['auto_notifications'] ),
                'woocommerce_enabled' => ! empty( $settings['woocommerce_enabled'] ),
            ),
            
            // Health status
            'health' => array(
                'overall_status' => $diagnostics['overall_status'],
                'tests_passed'   => $diagnostics['summary']['passed'],
                'tests_failed'   => $diagnostics['summary']['failed'],
                'tests_skipped'  => $diagnostics['summary']['skipped'],
            ),
            
            // Test details
            'tests' => $diagnostics['tests'],
            
            // Recent errors (sanitized - no tokens/keys)
            'recent_api_errors' => $recent_errors,
            
            // Cron status
            'cron' => array(
                'health_check_scheduled' => (bool) wp_next_scheduled( 'pushrelay_health_check' ),
                'queue_processing_scheduled' => (bool) wp_next_scheduled( 'pushrelay_process_queue' ),
                'next_health_check' => wp_next_scheduled( 'pushrelay_health_check' ) 
                    ? gmdate( 'Y-m-d H:i:s', wp_next_scheduled( 'pushrelay_health_check' ) ) 
                    : null,
                'next_queue_process' => wp_next_scheduled( 'pushrelay_process_queue' )
                    ? gmdate( 'Y-m-d H:i:s', wp_next_scheduled( 'pushrelay_process_queue' ) )
                    : null,
            ),
            
            // Execution info
            'execution_time' => $diagnostics['execution_time'],
        );
        
        return $export;
    }
    
    /**
     * Get recent API errors from logs (sanitized)
     * 
     * @param int $limit Number of errors to retrieve
     * @return array Recent errors with sensitive data redacted
     */
    private function get_recent_api_errors( $limit = 5 ) {
        $logs = PushRelay_Debug_Logger::get_logs( 100, PushRelay_Debug_Logger::LEVEL_ERROR );
        
        // Filter to API-related errors only
        $api_errors = array();
        foreach ( $logs as $log ) {
            if ( isset( $log['message'] ) && stripos( $log['message'], 'api' ) !== false ) {
                $api_errors[] = array(
                    'timestamp' => isset( $log['timestamp'] ) ? $log['timestamp'] : '',
                    'message'   => isset( $log['message'] ) ? $log['message'] : '',
                    // Exclude context to avoid any sensitive data leakage
                );
                
                if ( count( $api_errors ) >= $limit ) {
                    break;
                }
            }
        }
        
        return $api_errors;
    }
    
    /**
     * Get MySQL version
     * 
     * @return string MySQL version
     */
    private function get_mysql_version() {
        global $wpdb;
        
        $version = $wpdb->get_var( 'SELECT VERSION()' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        return $version ? $version : 'unknown';
    }
    
    /**
     * Test API authentication (GET /user)
     * 
     * @return array Test result
     */
    private function diagnostic_test_api_auth() {
        $result = array(
            'name'    => 'API Authentication',
            'status'  => 'fail',
            'message' => '',
            'details' => array(),
        );
        
        try {
            $api = pushrelay()->get_api_client();
            $user = $api->get_user();
            
            if ( is_wp_error( $user ) ) {
                $result['message'] = $user->get_error_message();
                $result['details']['error_code'] = $user->get_error_code();
            } elseif ( ! empty( $user['email'] ) ) {
                $result['status'] = 'pass';
                $result['message'] = 'Authenticated successfully';
                $result['details']['email'] = $user['email'];
            } else {
                $result['message'] = 'Invalid response from API';
            }
        } catch ( Exception $e ) {
            $result['message'] = $e->getMessage();
        }
        
        return $result;
    }
    
    /**
     * Test website availability (GET /websites)
     * 
     * @return array Test result
     */
    private function diagnostic_test_websites() {
        $result = array(
            'name'    => 'Website Availability',
            'status'  => 'fail',
            'message' => '',
            'details' => array(),
        );
        
        try {
            $api = pushrelay()->get_api_client();
            $websites = $api->get_websites( 1, 10 );
            
            if ( is_wp_error( $websites ) ) {
                $result['message'] = $websites->get_error_message();
            } elseif ( isset( $websites['data'] ) && is_array( $websites['data'] ) ) {
                $result['status'] = 'pass';
                $result['message'] = sprintf( 'Found %d website(s)', count( $websites['data'] ) );
                $result['details']['count'] = count( $websites['data'] );
                
                $settings = get_option( 'pushrelay_settings', array() );
                $configured_id = isset( $settings['website_id'] ) ? absint( $settings['website_id'] ) : 0;
                $result['details']['configured_website_id'] = $configured_id;
                
                // Check if configured website exists
                if ( $configured_id > 0 ) {
                    $found = false;
                    foreach ( $websites['data'] as $site ) {
                        if ( isset( $site['website_id'] ) && absint( $site['website_id'] ) === $configured_id ) {
                            $found = true;
                            break;
                        }
                    }
                    $result['details']['configured_website_found'] = $found;
                }
            } else {
                $result['message'] = 'No websites found';
            }
        } catch ( Exception $e ) {
            $result['message'] = $e->getMessage();
        }
        
        return $result;
    }
    
    /**
     * Test subscriber statistics endpoint with valid date range
     * 
     * @param array $settings Plugin settings
     * @return array Test result
     */
    private function diagnostic_test_subscriber_stats( $settings ) {
        $result = array(
            'name'    => 'Subscriber Statistics',
            'status'  => 'skip',
            'message' => '',
            'details' => array(),
        );
        
        $website_id = isset( $settings['website_id'] ) ? absint( $settings['website_id'] ) : 0;
        
        if ( $website_id === 0 ) {
            $result['message'] = 'Skipped: No website configured';
            return $result;
        }
        
        try {
            $api = pushrelay()->get_api_client();
            
            // Use last 7 days for test
            $end_date = gmdate( 'Y-m-d' );
            $start_date = gmdate( 'Y-m-d', strtotime( '-7 days' ) );
            
            $stats = $api->get_subscriber_statistics( $website_id, $start_date, $end_date, 'overview' );
            
            if ( is_wp_error( $stats ) ) {
                $result['status'] = 'fail';
                $result['message'] = $stats->get_error_message();
            } else {
                $result['status'] = 'pass';
                $result['message'] = 'Statistics endpoint responsive';
                $result['details']['date_range'] = $start_date . ' to ' . $end_date;
                $result['details']['has_data'] = ! empty( $stats );
            }
        } catch ( Exception $e ) {
            $result['status'] = 'fail';
            $result['message'] = $e->getMessage();
        }
        
        return $result;
    }
    
    /**
     * Test database tables exist
     * 
     * @return array Test result
     */
    private function diagnostic_test_database() {
        global $wpdb;
        
        $result = array(
            'name'    => 'Database Tables',
            'status'  => 'pass',
            'message' => '',
            'details' => array(),
        );
        
        $required_tables = array(
            'pushrelay_api_logs',
            'pushrelay_queue',
            'pushrelay_campaigns_local',
            'pushrelay_tickets',
            'pushrelay_segments',
        );
        
        $missing = array();
        $existing = array();
        
        foreach ( $required_tables as $table ) {
            $full_name = $wpdb->prefix . $table;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $full_name ) ) === $full_name;
            
            if ( $exists ) {
                $existing[] = $table;
            } else {
                $missing[] = $table;
            }
        }
        
        $result['details']['existing'] = $existing;
        $result['details']['missing'] = $missing;
        
        if ( ! empty( $missing ) ) {
            $result['status'] = 'fail';
            $result['message'] = sprintf( 'Missing tables: %s', implode( ', ', $missing ) );
        } else {
            $result['message'] = sprintf( 'All %d tables exist', count( $existing ) );
        }
        
        return $result;
    }
    
    /**
     * Test cron jobs are scheduled
     * 
     * @return array Test result
     */
    private function diagnostic_test_cron() {
        $result = array(
            'name'    => 'Cron Jobs',
            'status'  => 'pass',
            'message' => '',
            'details' => array(),
        );
        
        $required_crons = array(
            'pushrelay_health_check',
            'pushrelay_process_queue',
        );
        
        $scheduled = array();
        $missing = array();
        
        foreach ( $required_crons as $cron_hook ) {
            $next_run = wp_next_scheduled( $cron_hook );
            
            if ( $next_run ) {
                $scheduled[ $cron_hook ] = array(
                    'next_run' => gmdate( 'Y-m-d H:i:s', $next_run ),
                    'timestamp' => $next_run,
                );
            } else {
                $missing[] = $cron_hook;
            }
        }
        
        $result['details']['scheduled'] = $scheduled;
        $result['details']['missing'] = $missing;
        
        if ( ! empty( $missing ) ) {
            $result['status'] = 'fail';
            $result['message'] = sprintf( 'Missing cron jobs: %s', implode( ', ', $missing ) );
        } else {
            $result['message'] = sprintf( 'All %d cron jobs scheduled', count( $scheduled ) );
        }
        
        return $result;
    }
    
    /**
     * Test service worker endpoint is reachable
     * 
     * @return array Test result
     */
    private function diagnostic_test_service_worker() {
        $result = array(
            'name'    => 'Service Worker',
            'status'  => 'fail',
            'message' => '',
            'details' => array(),
        );
        
        // Try REST endpoint first (more reliable)
        $rest_url = rest_url( 'pushrelay/v1/service-worker' );
        $result['details']['rest_url'] = $rest_url;
        
        $response = wp_remote_get( $rest_url, array(
            'timeout' => 10,
            'sslverify' => false,
        ) );
        
        if ( is_wp_error( $response ) ) {
            $result['message'] = 'REST endpoint unreachable: ' . $response->get_error_message();
            
            // Try direct URL as fallback
            $direct_url = trailingslashit( get_site_url() ) . 'pushrelay-sw.js';
            $result['details']['direct_url'] = $direct_url;
            
            $direct_response = wp_remote_get( $direct_url, array(
                'timeout' => 10,
                'sslverify' => false,
            ) );
            
            if ( ! is_wp_error( $direct_response ) ) {
                $code = wp_remote_retrieve_response_code( $direct_response );
                if ( $code === 200 ) {
                    $result['status'] = 'pass';
                    $result['message'] = 'Service worker accessible via direct URL';
                }
            }
        } else {
            $code = wp_remote_retrieve_response_code( $response );
            $result['details']['status_code'] = $code;
            
            if ( $code === 200 ) {
                $result['status'] = 'pass';
                $result['message'] = 'Service worker endpoint responsive';
                
                // Verify content type
                $content_type = wp_remote_retrieve_header( $response, 'content-type' );
                $result['details']['content_type'] = $content_type;
            } else {
                $result['message'] = sprintf( 'Unexpected status code: %d', $code );
            }
        }
        
        return $result;
    }
}