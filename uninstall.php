<?php
/**
 * Uninstall Script
 * 
 * Fired when the plugin is uninstalled
 * Removes all plugin data, tables, and options
 * 
 * @package PushRelay
 * @since 1.6.0
 */

// Exit if accessed directly or not uninstalling
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Security check - verify this is a legitimate uninstall
if (!current_user_can('activate_plugins')) {
    exit;
}

/**
 * Delete plugin options
 */
function pushrelay_delete_options() {
    delete_option('pushrelay_settings');
    delete_option('pushrelay_version');
    delete_option('pushrelay_db_version');
    delete_option('pushrelay_sw_location');
    delete_option('pushrelay_sw_version');
    delete_option('pushrelay_sw_path');
    delete_option('pushrelay_health_check_results');
    delete_option('pushrelay_debug_logs');
    delete_option('pushrelay_woo_settings');
    delete_option('pushrelay_tables_created');
    delete_option('pushrelay_tables_created_list');
    delete_option('pushrelay_tables_created_date');
    delete_option('pushrelay_rewrite_rules_flushed');
    delete_option('pushrelay_setup_completed_time');
    
    // Delete transients
    delete_transient('pushrelay_subscriber_count');
    delete_transient('pushrelay_all_subscribers');
    delete_transient('pushrelay_subscriber_count_shortcode');
    delete_transient('pushrelay_activation_redirect');
    delete_transient('pushrelay_upgraded_to_2_0_0');
    delete_transient('pushrelay_tables_missing');
    delete_transient('pushrelay_tables_missing_list');
    delete_transient('pushrelay_tables_creation_failed');
}

/**
 * Delete plugin database tables
 */
function pushrelay_delete_tables() {
    global $wpdb;
    
    $tables = array(
        $wpdb->prefix . 'pushrelay_queue',
        $wpdb->prefix . 'pushrelay_api_logs',
        $wpdb->prefix . 'pushrelay_campaigns_local',
        $wpdb->prefix . 'pushrelay_tickets',
        $wpdb->prefix . 'pushrelay_segments',
    );
    
    foreach ($tables as $table) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- DROP TABLE cannot use prepare()
        $wpdb->query("DROP TABLE IF EXISTS {$table}");
    }
}

/**
 * Delete user meta data
 */
function pushrelay_delete_user_meta() {
    global $wpdb;
    
    $meta_keys = array(
        '_pushrelay_cart_data',
        '_pushrelay_cart_last_update',
        '_pushrelay_cart_notification_sent',
        '_pushrelay_viewed_products',
    );
    
    foreach ($meta_keys as $meta_key) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->delete(
            $wpdb->usermeta,
            array('meta_key' => $meta_key),
            array('%s')
        );
    }
}

/**
 * Delete post meta data
 */
function pushrelay_delete_post_meta() {
    global $wpdb;
    
    $meta_keys = array(
        '_pushrelay_send_notification',
        '_pushrelay_notification_sent',
        '_pushrelay_notification_processing',
        '_pushrelay_campaign_id',
        '_pushrelay_was_out_of_stock',
        '_pushrelay_last_price',
    );
    
    foreach ($meta_keys as $meta_key) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->delete(
            $wpdb->postmeta,
            array('meta_key' => $meta_key),
            array('%s')
        );
    }
}

/**
 * Delete service worker and manifest files
 */
function pushrelay_delete_service_worker_files() {
    // Try to delete from root
    $root_sw = ABSPATH . 'pushrelay-sw.js';
    if (file_exists($root_sw)) {
        @unlink($root_sw); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.PHP.NoSilencedErrors.Discouraged
    }
    
    $root_manifest = ABSPATH . 'pushrelay-manifest.json';
    if (file_exists($root_manifest)) {
        @unlink($root_manifest); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.PHP.NoSilencedErrors.Discouraged
    }
    
    // Try to delete from uploads directory
    $upload_dir = wp_upload_dir();
    $uploads_sw = $upload_dir['basedir'] . '/pushrelay-sw.js';
    if (file_exists($uploads_sw)) {
        @unlink($uploads_sw); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.PHP.NoSilencedErrors.Discouraged
    }
    
    // Delete pushrelay directory in uploads
    $pushrelay_dir = $upload_dir['basedir'] . '/pushrelay';
    if (is_dir($pushrelay_dir)) {
        pushrelay_delete_directory($pushrelay_dir);
    }
}

/**
 * Recursively delete directory
 */
function pushrelay_delete_directory($dir) {
    if (!is_dir($dir)) {
        return false;
    }
    
    $files = array_diff(scandir($dir), array('.', '..'));
    
    foreach ($files as $file) {
        $path = $dir . '/' . $file;
        if (is_dir($path)) {
            pushrelay_delete_directory($path);
        } else {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.PHP.NoSilencedErrors.Discouraged -- Uninstall cleanup
            @unlink($path);
        }
    }
    
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir, WordPress.PHP.NoSilencedErrors.Discouraged -- Uninstall cleanup
    return @rmdir($dir);
}

/**
 * Clear scheduled cron events
 */
function pushrelay_clear_cron_jobs() {
    $cron_jobs = array(
        'pushrelay_health_check',
        'pushrelay_process_queue',
        'pushrelay_send_queued_notifications',
        'pushrelay_woo_check_abandoned_carts',
        'pushrelay_woo_check_price_drops',
    );
    
    foreach ($cron_jobs as $cron_job) {
        wp_clear_scheduled_hook($cron_job);
    }
}

/**
 * Delete capabilities (if any were added)
 */
function pushrelay_delete_capabilities() {
    // Get all roles
    global $wp_roles;
    
    if (!isset($wp_roles)) {
        $wp_roles = new WP_Roles();
    }
    
    $capabilities = array(
        'manage_pushrelay',
        'create_pushrelay_campaigns',
        'edit_pushrelay_campaigns',
        'delete_pushrelay_campaigns',
    );
    
    foreach ($wp_roles->roles as $role_name => $role) {
        foreach ($capabilities as $cap) {
            $wp_roles->remove_cap($role_name, $cap);
        }
    }
}

/**
 * Log uninstall action
 */
function pushrelay_log_uninstall() {
    // Create a simple log file
    $log_file = WP_CONTENT_DIR . '/pushrelay-uninstall.log';
    $log_message = sprintf(
        "[%s] PushRelay plugin uninstalled by user %s (ID: %d)\n",
        current_time('mysql'),
        wp_get_current_user()->user_login,
        get_current_user_id()
    );
    
    @file_put_contents($log_file, $log_message, FILE_APPEND); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged
}

/**
 * Main uninstall function
 */
function pushrelay_uninstall() {
    // Log the uninstall
    pushrelay_log_uninstall();
    
    // Delete options
    pushrelay_delete_options();
    
    // Delete database tables
    pushrelay_delete_tables();
    
    // Delete user meta
    pushrelay_delete_user_meta();
    
    // Delete post meta
    pushrelay_delete_post_meta();
    
    // Delete service worker files
    pushrelay_delete_service_worker_files();
    
    // Clear cron jobs
    pushrelay_clear_cron_jobs();
    
    // Delete capabilities
    pushrelay_delete_capabilities();
    
    // Flush rewrite rules
    flush_rewrite_rules();
}

// Run the uninstall
pushrelay_uninstall();
