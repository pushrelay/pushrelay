<?php
/**
 * Database Installation Script
 * Creates missing tables for PushRelay plugin
 * 
 * @package PushRelay
 * @since 1.6.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

function pushrelay_install_database_tables() {
    global $wpdb;
    
    $charset_collate = $wpdb->get_charset_collate();
    
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    
    // Table: wp_pushrelay_api_logs
    $table_api_logs = $wpdb->prefix . 'pushrelay_api_logs';
    $sql_api_logs = "CREATE TABLE IF NOT EXISTS $table_api_logs (
        id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        endpoint varchar(255) NOT NULL DEFAULT '',
        method varchar(10) NOT NULL DEFAULT 'GET',
        status_code int(11) DEFAULT NULL,
        request_data longtext,
        response_data longtext,
        error_message text DEFAULT NULL,
        execution_time float DEFAULT NULL,
        created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY endpoint (endpoint),
        KEY created_at (created_at),
        KEY status_code (status_code)
    ) $charset_collate;";
    
    dbDelta($sql_api_logs);
    
    // Table: wp_pushrelay_queue
    $table_queue = $wpdb->prefix . 'pushrelay_queue';
    $sql_queue = "CREATE TABLE IF NOT EXISTS $table_queue (
        id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        campaign_id bigint(20) UNSIGNED NOT NULL,
        subscriber_id bigint(20) UNSIGNED NOT NULL,
        website_id bigint(20) UNSIGNED NOT NULL,
        status varchar(20) NOT NULL DEFAULT 'pending',
        attempts int(11) NOT NULL DEFAULT 0,
        max_attempts int(11) NOT NULL DEFAULT 3,
        error_message text,
        scheduled_at datetime,
        sent_at datetime,
        created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY campaign_id (campaign_id),
        KEY subscriber_id (subscriber_id),
        KEY status (status),
        KEY scheduled_at (scheduled_at)
    ) $charset_collate;";
    
    dbDelta($sql_queue);
    
    // Table: wp_pushrelay_campaigns_local
    $table_campaigns = $wpdb->prefix . 'pushrelay_campaigns_local';
    $sql_campaigns = "CREATE TABLE IF NOT EXISTS $table_campaigns (
        id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        campaign_id bigint(20) UNSIGNED NOT NULL,
        website_id bigint(20) UNSIGNED NOT NULL,
        user_id bigint(20) UNSIGNED NOT NULL,
        name varchar(255) NOT NULL,
        status varchar(20) NOT NULL DEFAULT 'draft',
        total_subscribers int(11) NOT NULL DEFAULT 0,
        total_sent int(11) NOT NULL DEFAULT 0,
        total_displayed int(11) NOT NULL DEFAULT 0,
        total_clicked int(11) NOT NULL DEFAULT 0,
        total_closed int(11) NOT NULL DEFAULT 0,
        created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY campaign_id (campaign_id),
        KEY website_id (website_id),
        KEY status (status)
    ) $charset_collate;";
    
    dbDelta($sql_campaigns);
    
    // Update version
    update_option('pushrelay_db_version', '1.6.0');
    
    return true;
}

// Auto-run on activation
register_activation_hook(__FILE__, 'pushrelay_install_database_tables');

// Manual run function
function pushrelay_manual_install_tables() {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('Unauthorized access', 'pushrelay'));
    }
    
    $result = pushrelay_install_database_tables();
    
    if ($result) {
        wp_safe_redirect(add_query_arg(array(
            'page' => 'pushrelay-settings',
            'message' => 'tables_created'
        ), admin_url('admin.php')));
        exit;
    } else {
        wp_die(esc_html__('Error creating database tables', 'pushrelay'));
    }
}

// Add admin notice if tables don't exist
add_action('admin_notices', function() {
    global $wpdb;
    
    $table_api_logs = $wpdb->prefix . 'pushrelay_api_logs';
    $table_queue = $wpdb->prefix . 'pushrelay_queue';
    
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $api_logs_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_api_logs)) === $table_api_logs;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $queue_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_queue)) === $table_queue;
    
    if (!$api_logs_exists || !$queue_exists) {
        ?>
        <div class="notice notice-warning is-dismissible">
            <p>
                <strong><?php esc_html_e('PushRelay Database Tables Missing', 'pushrelay'); ?></strong><br>
                <?php esc_html_e('Some database tables are missing. Click the button below to create them.', 'pushrelay'); ?>
            </p>
            <p>
                <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=pushrelay_install_tables'), 'pushrelay_install_tables')); ?>" class="button button-primary">
                    <?php esc_html_e('Create Database Tables', 'pushrelay'); ?>
                </a>
            </p>
        </div>
        <?php
    }
});

// Handle manual installation
add_action('admin_post_pushrelay_install_tables', function() {
    check_admin_referer('pushrelay_install_tables');
    pushrelay_manual_install_tables();
});
