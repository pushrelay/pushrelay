<?php
/**
 * Campaigns Class
 * 
 * Handles campaign creation, management, automated notifications,
 * and queue processing
 * 
 * @package PushRelay
 * @since 1.6.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class PushRelay_Campaigns {
    
    /**
     * Flag to prevent duplicate notifications within a single request
     * 
     * @var array
     */
    private static $processed_posts = array();
    
    /**
     * Flag to track if hooks have been registered
     * 
     * @var bool
     */
    private static $hooks_registered = false;
    
    /**
     * Constructor
     */
    public function __construct() {
        // Only register hooks ONCE to prevent duplicate notifications
        if (self::$hooks_registered) {
            return;
        }
        self::$hooks_registered = true;
        
        // Post publish hook for auto notifications
        // Using ONLY wp_after_insert_post - works for both Classic and Gutenberg editors
        // Priority 99 to run after other plugins have finished
        add_action('wp_after_insert_post', array($this, 'handle_post_publish'), 99, 4);
        
        // Queue processing
        add_action('pushrelay_process_queue', array($this, 'process_queue'));
        
        // AJAX handlers
        add_action('wp_ajax_pushrelay_create_campaign', array($this, 'ajax_create_campaign'));
        add_action('wp_ajax_pushrelay_send_campaign', array($this, 'ajax_send_campaign'));
        add_action('wp_ajax_pushrelay_delete_campaign', array($this, 'ajax_delete_campaign'));
        add_action('wp_ajax_pushrelay_get_campaign', array($this, 'ajax_get_campaign'));
        add_action('wp_ajax_pushrelay_preview_campaign', array($this, 'ajax_preview_campaign'));
        
        // Save post meta
        add_action('save_post', array($this, 'save_post_notification_meta'), 10, 2);
    }
    
    /**
     * Handle post publish event for auto notifications
     * Uses wp_after_insert_post which works for both Classic and Gutenberg editors
     * 
     * @param int     $post_id     Post ID
     * @param WP_Post $post        Post object
     * @param bool    $update      Whether this is an update
     * @param WP_Post|null $post_before Post object before the update (null for new posts)
     */
    public function handle_post_publish($post_id, $post, $update, $post_before) {
        // Skip if not publishing
        if ($post->post_status !== 'publish') {
            return;
        }
        
        // Skip if this is an update of an already published post
        if ($post_before && $post_before->post_status === 'publish') {
            return;
        }
        
        // Skip autosaves and revisions
        if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) {
            return;
        }
        
        // Skip REST API requests that duplicate the normal save
        if (defined('REST_REQUEST') && REST_REQUEST) {
            // Check if this is a duplicate REST request
            $rest_processed = get_transient('pushrelay_rest_' . $post_id);
            if ($rest_processed) {
                return;
            }
            set_transient('pushrelay_rest_' . $post_id, true, 30);
        }
        
        // Check if we already processed this post in this PHP request
        if (isset(self::$processed_posts[$post_id])) {
            PushRelay_Debug_Logger::log(
                'Skipping duplicate notification (already processed in this request)',
                'debug',
                array('post_id' => $post_id)
            );
            return;
        }
        
        // CRITICAL: Atomic check - get fresh meta value directly from database
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $notification_sent = $wpdb->get_var($wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_pushrelay_notification_sent' LIMIT 1",
            $post_id
        ));
        
        if ($notification_sent) {
            PushRelay_Debug_Logger::log(
                'Skipping notification (already sent - DB check)',
                'debug',
                array('post_id' => $post_id, 'sent_at' => $notification_sent)
            );
            return;
        }
        
        // Mark as processed in this request
        self::$processed_posts[$post_id] = true;
        
        // Process the notification
        $this->process_auto_notification($post);
    }
    
    /**
     * Process auto notification for a post
     * Shared logic used by both transition_post_status and wp_after_insert_post handlers
     */
    private function process_auto_notification($post) {
        // CRITICAL: Use a transient-based lock to prevent race conditions
        // This is more reliable than post meta for preventing duplicates
        $lock_key = 'pushrelay_sending_' . $post->ID;
        $lock_value = get_transient($lock_key);
        
        if ($lock_value) {
            PushRelay_Debug_Logger::log(
                'Notification blocked by transient lock',
                'debug',
                array('post_id' => $post->ID, 'lock_value' => $lock_value)
            );
            return;
        }
        
        // Set lock IMMEDIATELY (expires in 60 seconds)
        set_transient($lock_key, time(), 60);
        
        // Also check the post meta as a secondary check
        $notification_sent = get_post_meta($post->ID, '_pushrelay_notification_sent', true);
        if ($notification_sent) {
            PushRelay_Debug_Logger::log('Notification already sent (meta check)', 'debug');
            delete_transient($lock_key);
            return;
        }
        
        // Check processing flag as another layer
        $already_processing = get_post_meta($post->ID, '_pushrelay_notification_processing', true);
        if ($already_processing && (time() - intval($already_processing)) < 30) {
            // Only block if processing started less than 30 seconds ago
            PushRelay_Debug_Logger::log('Notification already being processed (meta check)', 'debug');
            delete_transient($lock_key);
            return;
        }
        
        // Set processing flag
        update_post_meta($post->ID, '_pushrelay_notification_processing', time());
        
        // Debug log entry
        PushRelay_Debug_Logger::log(
            'Processing auto notification for post',
            'info',
            array(
                'post_id' => $post->ID,
                'post_type' => $post->post_type,
                'post_title' => $post->post_title
            )
        );
        
        // Check if plugin is properly configured
        $settings = get_option('pushrelay_settings', array());
        
        if (empty($settings['api_key'])) {
            PushRelay_Debug_Logger::log('Auto notifications skipped: API key not configured', 'debug');
            delete_post_meta($post->ID, '_pushrelay_notification_processing');
            return;
        }
        
        if (empty($settings['website_id'])) {
            PushRelay_Debug_Logger::log('Auto notifications skipped: Website ID not configured', 'debug');
            delete_post_meta($post->ID, '_pushrelay_notification_processing');
            return;
        }
        
        // Check if auto notifications are enabled
        if (empty($settings['auto_notifications'])) {
            PushRelay_Debug_Logger::log('Auto notifications disabled in settings', 'debug');
            delete_post_meta($post->ID, '_pushrelay_notification_processing');
            delete_transient($lock_key);
            return;
        }
        
        // Check if this post type is enabled for notifications
        $notification_types = isset($settings['notification_types']) ? $settings['notification_types'] : array('post');
        if (!is_array($notification_types)) {
            $notification_types = array('post');
        }
        
        // Empty array means no post types selected
        if (empty($notification_types)) {
            PushRelay_Debug_Logger::log('No post types enabled for notifications', 'debug');
            delete_post_meta($post->ID, '_pushrelay_notification_processing');
            delete_transient($lock_key);
            return;
        }
        
        if (!in_array($post->post_type, $notification_types, true)) {
            PushRelay_Debug_Logger::log(
                'Post type not enabled for notifications',
                'debug',
                array('post_type' => $post->post_type, 'enabled_types' => $notification_types)
            );
            delete_post_meta($post->ID, '_pushrelay_notification_processing');
            delete_transient($lock_key);
            return;
        }
        
        // Check if notification is enabled for this specific post
        // Default to enabled if meta is not set (empty string) or explicitly set to '1'
        $send_notification = get_post_meta($post->ID, '_pushrelay_send_notification', true);
        if ($send_notification === '0') {
            // Explicitly disabled by user
            PushRelay_Debug_Logger::log('Notification disabled for this post by user', 'debug');
            delete_post_meta($post->ID, '_pushrelay_notification_processing');
            delete_transient($lock_key);
            return;
        }
        
        // Double-check notification wasn't already sent
        $notification_sent = get_post_meta($post->ID, '_pushrelay_notification_sent', true);
        if ($notification_sent) {
            PushRelay_Debug_Logger::log('Notification already sent for this post', 'debug');
            delete_post_meta($post->ID, '_pushrelay_notification_processing');
            delete_transient($lock_key);
            return;
        }
        
        // Send the notification immediately
        $result = $this->queue_post_notification($post);
        
        // Clean up processing flag and transient
        delete_post_meta($post->ID, '_pushrelay_notification_processing');
        delete_transient($lock_key);
        
        if ($result) {
            PushRelay_Debug_Logger::log(
                sprintf('Auto notification sent for post: %s (ID: %d)', $post->post_title, $post->ID),
                'success'
            );
        }
    }
    
    /**
     * Queue a post notification - sends immediately via API
     */
    public function queue_post_notification($post) {
        global $wpdb;
        
        $settings = get_option('pushrelay_settings', array());
        
        if (empty($settings['website_id'])) {
            PushRelay_Debug_Logger::log('Cannot send notification: website_id not configured', 'error');
            return false;
        }
        
        // CRITICAL: Use atomic database operation to prevent race conditions
        // Try to INSERT the meta - if it already exists, this will fail
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $inserted = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$wpdb->postmeta} (post_id, meta_key, meta_value) VALUES (%d, '_pushrelay_notification_sent', %s)",
            $post->ID,
            'sending_' . time()
        ));
        
        // If INSERT returned 0, the meta already exists (another process got there first)
        if ($inserted === 0) {
            // Double-check by reading the value
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_pushrelay_notification_sent' LIMIT 1",
                $post->ID
            ));
            
            if ($existing) {
                PushRelay_Debug_Logger::log(
                    'Notification blocked by atomic check - already exists',
                    'debug',
                    array('post_id' => $post->ID, 'existing_value' => $existing)
                );
                return false;
            }
            
            // If we get here, the INSERT IGNORE failed for another reason, try update_post_meta
            update_post_meta($post->ID, '_pushrelay_notification_sent', 'sending_' . time());
        }
        
        PushRelay_Debug_Logger::log(
            'Atomic lock acquired for notification',
            'debug',
            array('post_id' => $post->ID)
        );
        
        // Prepare campaign data
        $campaign_data = array(
            'website_id' => $settings['website_id'],
            /* translators: %s: Post title */
            'name' => sprintf(__('Auto: %s', 'pushrelay'), $post->post_title),
            'title' => $post->post_title,
            'description' => $this->get_post_excerpt($post),
            'url' => get_permalink($post->ID),
            'segment' => 'all',
            'send' => 1,  // Send immediately
        );
        
        // Add featured image if available (as file path for upload)
        if (has_post_thumbnail($post->ID)) {
            $image_id = get_post_thumbnail_id($post->ID);
            $image_path = get_attached_file($image_id);
            if ($image_path && file_exists($image_path)) {
                $campaign_data['image_path'] = $image_path;
            }
        }
        
        PushRelay_Debug_Logger::log(
            'Sending auto notification for post',
            'info',
            array('post_id' => $post->ID, 'post_title' => $post->post_title)
        );
        
        // Send via API immediately
        $api = pushrelay()->get_api_client();
        $result = $api->create_campaign($campaign_data);
        
        if (is_wp_error($result)) {
            PushRelay_Debug_Logger::log(
                'Failed to create auto notification campaign',
                'error',
                array(
                    'post_id' => $post->ID,
                    'error' => $result->get_error_message()
                )
            );
            // On failure, remove the "sending" flag so user can retry manually
            delete_post_meta($post->ID, '_pushrelay_notification_sent');
            return false;
        }
        
        // Update to actual sent timestamp (confirms success)
        update_post_meta($post->ID, '_pushrelay_notification_sent', time());
        
        // Store campaign ID if returned
        if (!empty($result['id'])) {
            update_post_meta($post->ID, '_pushrelay_campaign_id', $result['id']);
        }
        
        PushRelay_Debug_Logger::log(
            'Auto notification sent successfully',
            'success',
            array(
                'post_id' => $post->ID,
                'campaign_id' => $result['id'] ?? 'unknown'
            )
        );
        
        return true;
    }
    
    /**
     * Get post excerpt
     */
    private function get_post_excerpt($post) {
        if (!empty($post->post_excerpt)) {
            return wp_trim_words($post->post_excerpt, 20);
        }
        
        return wp_trim_words(strip_shortcodes($post->post_content), 20);
    }
    
    /**
     * Transient name for cron lock
     */
    const CRON_LOCK_TRANSIENT = 'pushrelay_queue_processing';
    
    /**
     * Maximum cron execution time in seconds before considered stuck
     */
    const CRON_MAX_EXECUTION_TIME = 300;
    
    /**
     * Process notification queue
     */
    public function process_queue() {
        global $wpdb;
        
        // Prevent overlapping executions
        $lock = get_transient( self::CRON_LOCK_TRANSIENT );
        if ( $lock !== false ) {
            // Check if lock is stale (stuck job)
            $lock_time = absint( $lock );
            if ( ( time() - $lock_time ) < self::CRON_MAX_EXECUTION_TIME ) {
                // Still within execution window - skip this run
                PushRelay_Debug_Logger::log(
                    'Queue processing skipped: previous job still running',
                    PushRelay_Debug_Logger::LEVEL_NOTICE
                );
                return;
            }
            // Lock is stale - job was stuck, log and continue
            PushRelay_Debug_Logger::log(
                'Queue processing: clearing stale lock from stuck job',
                PushRelay_Debug_Logger::LEVEL_WARNING,
                array( 'stale_lock_age' => time() - $lock_time )
            );
        }
        
        // Set lock with current timestamp
        set_transient( self::CRON_LOCK_TRANSIENT, time(), self::CRON_MAX_EXECUTION_TIME );
        
        try {
            $this->do_process_queue();
        } finally {
            // Always release lock when done
            delete_transient( self::CRON_LOCK_TRANSIENT );
        }
    }
    
    /**
     * Internal queue processing logic
     */
    private function do_process_queue() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'pushrelay_queue';
        
        // Check if table exists first
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) === $table_name;
        if ( ! $table_exists ) {
            return;
        }
        
        // Detect available columns for backward compatibility with older schemas
        $has_scheduled_at = $this->queue_table_has_column( 'scheduled_at' );
        $has_scheduled_time = $this->queue_table_has_column( 'scheduled_time' );
        
        // Build query based on available columns
        // Prioritize scheduled_at (current schema), fall back to scheduled_time (legacy), or no scheduling filter
        if ( $has_scheduled_at ) {
            $scheduled_filter = "AND (scheduled_at IS NULL OR scheduled_at <= %s)";
        } elseif ( $has_scheduled_time ) {
            $scheduled_filter = "AND (scheduled_time IS NULL OR scheduled_time <= %s)";
        } else {
            // No scheduling column - process all pending immediately
            $scheduled_filter = "AND 1=1";
        }
        
        // Get pending notifications (limit 10 per run)
        // Use suppress_errors to handle any remaining schema mismatches gracefully
        $suppress = $wpdb->suppress_errors( true );
        
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        if ( $has_scheduled_at || $has_scheduled_time ) {
            $notifications = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$table_name} 
                    WHERE status = 'pending' 
                    {$scheduled_filter}
                    ORDER BY created_at ASC 
                    LIMIT 10",
                    current_time('mysql')
                ),
                ARRAY_A
            );
        } else {
            // No datetime parameter needed
            $notifications = $wpdb->get_results(
                "SELECT * FROM {$table_name} 
                WHERE status = 'pending' 
                ORDER BY created_at ASC 
                LIMIT 10",
                ARRAY_A
            );
        }
        
        $wpdb->suppress_errors( $suppress );
        
        // Check for query errors
        if ( $wpdb->last_error ) {
            PushRelay_Debug_Logger::log(
                'Queue query encountered an issue (may indicate schema mismatch): ' . $wpdb->last_error,
                PushRelay_Debug_Logger::LEVEL_NOTICE,
                array( 'table' => $table_name )
            );
            return;
        }
        
        if (empty($notifications)) {
            return;
        }
        
        PushRelay_Debug_Logger::log(
            sprintf('Processing %d queued notifications', count($notifications)),
            'info'
        );
        
        foreach ($notifications as $notification) {
            $this->process_queued_notification($notification);
        }
    }
    
    /**
     * Check if queue table has a specific column
     * 
     * @param string $column_name Column name to check
     * @return bool True if column exists
     */
    private function queue_table_has_column( $column_name ) {
        global $wpdb;
        static $columns_cache = null;
        
        // Cache column list for this request
        if ( $columns_cache === null ) {
            $table_name = $wpdb->prefix . 'pushrelay_queue';
            $suppress = $wpdb->suppress_errors( true );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $columns = $wpdb->get_col( "SHOW COLUMNS FROM {$table_name}" );
            $wpdb->suppress_errors( $suppress );
            $columns_cache = is_array( $columns ) ? $columns : array();
        }
        
        return in_array( $column_name, $columns_cache, true );
    }
    
    /**
     * Process a single queued notification
     */
    private function process_queued_notification($notification) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'pushrelay_queue';
        $notification_id = absint($notification['id']);
        
        // Update status to processing
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->update(
            $table_name,
            array('status' => 'processing'),
            array('id' => $notification_id),
            array('%s'),
            array('%d')
        );
        
        // Decode campaign data - handle both column names for backward compatibility
        $campaign_data_raw = isset( $notification['campaign_data'] ) ? $notification['campaign_data'] : null;
        
        // If no campaign_data column, this queue row may be from a different schema version
        if ( $campaign_data_raw === null ) {
            PushRelay_Debug_Logger::log(
                'Queue notification missing campaign_data - possible schema mismatch',
                PushRelay_Debug_Logger::LEVEL_NOTICE,
                array( 'queue_id' => $notification_id )
            );
            // Mark as failed but don't retry - this is a data issue
            $this->mark_notification_failed($notification_id, __('Missing campaign data (schema mismatch)', 'pushrelay'));
            return;
        }
        
        $campaign_data = json_decode($campaign_data_raw, true);
        
        if (empty($campaign_data)) {
            $this->mark_notification_failed($notification_id, __('Invalid campaign data', 'pushrelay'));
            return;
        }
        
        // Send via API
        $api = pushrelay()->get_api_client();
        $result = $api->create_campaign($campaign_data);
        
        if (is_wp_error($result)) {
            $error_message = $result->get_error_message();
            
            // Check if we should retry - handle both column names
            $retry_count = 0;
            if ( isset( $notification['retry_count'] ) ) {
                $retry_count = absint( $notification['retry_count'] );
            } elseif ( isset( $notification['attempts'] ) ) {
                $retry_count = absint( $notification['attempts'] );
            }
            
            if ($retry_count < 3) {
                // Build update data based on available columns
                $update_data = array(
                    'status' => 'pending',
                    'error_message' => $error_message,
                );
                $update_format = array( '%s', '%s' );
                
                // Use correct column name for retry count
                if ( $this->queue_table_has_column( 'retry_count' ) ) {
                    $update_data['retry_count'] = $retry_count + 1;
                    $update_format[] = '%d';
                } elseif ( $this->queue_table_has_column( 'attempts' ) ) {
                    $update_data['attempts'] = $retry_count + 1;
                    $update_format[] = '%d';
                }
                
                // Use correct column name for scheduling
                if ( $this->queue_table_has_column( 'scheduled_time' ) ) {
                    $update_data['scheduled_time'] = gmdate('Y-m-d H:i:s', strtotime('+5 minutes'));
                    $update_format[] = '%s';
                } elseif ( $this->queue_table_has_column( 'scheduled_at' ) ) {
                    $update_data['scheduled_at'] = gmdate('Y-m-d H:i:s', strtotime('+5 minutes'));
                    $update_format[] = '%s';
                }
                
                // Retry later
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $wpdb->update(
                    $table_name,
                    $update_data,
                    array('id' => $notification_id),
                    $update_format,
                    array('%d')
                );
                
                PushRelay_Debug_Logger::log(
                    sprintf('Notification queued for retry (%d/3): %s', $retry_count + 1, $error_message),
                    'warning',
                    array('queue_id' => $notification_id)
                );
            } else {
                // Max retries reached
                $this->mark_notification_failed($notification_id, $error_message);
            }
            
            return;
        }
        
        // Mark as sent - use correct column name
        $sent_update = array( 'status' => 'sent' );
        $sent_format = array( '%s' );
        
        if ( $this->queue_table_has_column( 'sent_time' ) ) {
            $sent_update['sent_time'] = current_time('mysql');
            $sent_format[] = '%s';
        } elseif ( $this->queue_table_has_column( 'sent_at' ) ) {
            $sent_update['sent_at'] = current_time('mysql');
            $sent_format[] = '%s';
        }
        
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->update(
            $table_name,
            $sent_update,
            array('id' => $notification_id),
            $sent_format,
            array('%d')
        );
        
        // Update post meta if this was a post notification
        if ( isset( $notification['post_id'] ) && ! empty( $notification['post_id'] ) ) {
            $post_id = absint($notification['post_id']);
            update_post_meta($post_id, '_pushrelay_notification_sent', current_time('mysql'));
            
            if (isset($result['data']['id'])) {
                update_post_meta($post_id, '_pushrelay_campaign_id', $result['data']['id']);
            }
        }
        
        PushRelay_Debug_Logger::log(
            'Notification sent successfully',
            'success',
            array(
                'queue_id' => $notification_id,
                'campaign_id' => isset($result['data']['id']) ? $result['data']['id'] : null
            )
        );
    }
    
    /**
     * Mark notification as failed
     */
    private function mark_notification_failed($notification_id, $error_message) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'pushrelay_queue';
        
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->update(
            $table_name,
            array(
                'status' => 'failed',
                'error_message' => $error_message,
            ),
            array('id' => $notification_id),
            array('%s', '%s'),
            array('%d')
        );
        
        PushRelay_Debug_Logger::log(
            'Notification failed after max retries: ' . $error_message,
            'error',
            array('queue_id' => $notification_id)
        );
    }
    
    /**
     * Create campaign immediately (no queue)
     */
    public function create_campaign($data) {
        $api = pushrelay()->get_api_client();
        
        // Validate required fields
        if (empty($data['name']) || empty($data['title']) || empty($data['description'])) {
            return new WP_Error('missing_fields', __('Name, title, and description are required', 'pushrelay'));
        }
        
        // Add website_id if not provided
        $settings = get_option('pushrelay_settings', array());
        if (empty($data['website_id']) && !empty($settings['website_id'])) {
            $data['website_id'] = $settings['website_id'];
        }
        
        // Create campaign via API
        $result = $api->create_campaign($data);
        
        if (is_wp_error($result)) {
            PushRelay_Debug_Logger::log(
                'Failed to create campaign: ' . $result->get_error_message(),
                'error',
                array('campaign_data' => $data)
            );
            
            return $result;
        }
        
        // Invalidate campaign list cache so new campaign appears immediately
        $this->invalidate_campaigns_cache();
        
        PushRelay_Debug_Logger::log(
            'Campaign created successfully',
            'success',
            array('campaign_id' => isset($result['data']['id']) ? $result['data']['id'] : null)
        );
        
        return $result;
    }
    
    /**
     * Invalidate campaigns list cache
     * 
     * Called after create, update, or delete operations to ensure
     * the campaigns list reflects the latest data.
     */
    public function invalidate_campaigns_cache() {
        // Delete cache for all users (campaigns are shared)
        global $wpdb;
        
        // Delete all campaign cache transients
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                $wpdb->esc_like( '_transient_pushrelay_all_campaigns_' ) . '%'
            )
        );
        
        // Also delete timeout transients
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                $wpdb->esc_like( '_transient_timeout_pushrelay_all_campaigns_' ) . '%'
            )
        );
    }
    
    /**
     * Get queue statistics
     */
    public function get_queue_stats() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'pushrelay_queue';
        
        $stats = array(
            'total' => 0,
            'pending' => 0,
            'processing' => 0,
            'sent' => 0,
            'failed' => 0,
        );
        
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $results = $wpdb->get_results(
            "SELECT status, COUNT(*) as count FROM {$table_name} GROUP BY status",
            ARRAY_A
        );
        
        if ($results) {
            $total = 0;
            foreach ($results as $row) {
                $status = $row['status'];
                $count = absint($row['count']);
                
                if (isset($stats[$status])) {
                    $stats[$status] = $count;
                }
                
                $total += $count;
            }
            $stats['total'] = $total;
        }
        
        return $stats;
    }
    
    /**
     * Get recent queue items
     */
    public function get_recent_queue_items($limit = 10) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'pushrelay_queue';
        
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $items = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table_name} ORDER BY created_at DESC LIMIT %d",
                absint($limit)
            ),
            ARRAY_A
        );
        
        return $items ? $items : array();
    }
    
    /**
     * Clear old queue items
     */
    public function cleanup_queue($days = 30) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'pushrelay_queue';
        
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table_name} 
                WHERE status IN ('sent', 'failed') 
                AND created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
                absint($days)
            )
        );
        
        if ($deleted) {
            PushRelay_Debug_Logger::log(
                sprintf('Cleaned up %d old queue items', $deleted),
                'info'
            );
        }
        
        return $deleted;
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
        
        if (empty($campaign_data)) {
            wp_send_json_error(array('message' => __('No campaign data provided', 'pushrelay')));
        }
        
        // Handle image upload
        if (!empty($_FILES['campaign_image']) && isset($_FILES['campaign_image']['tmp_name']) && !empty($_FILES['campaign_image']['tmp_name'])) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- wp_handle_upload handles file validation
            $upload = $this->handle_image_upload($_FILES['campaign_image']);
            
            if (!is_wp_error($upload)) {
                $campaign_data['image_path'] = $upload['file'];
            }
        }
        
        $result = $this->create_campaign($campaign_data);
        
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }
        
        wp_send_json_success(array(
            'message' => __('Campaign created successfully', 'pushrelay'),
            'data' => $result
        ));
    }
    
    /**
     * AJAX: Send campaign
     */
    public function ajax_send_campaign() {
        check_ajax_referer('pushrelay_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'pushrelay')));
        }
        
        $campaign_id = isset($_POST['campaign_id']) ? absint($_POST['campaign_id']) : 0;
        
        if (!$campaign_id) {
            wp_send_json_error(array('message' => __('Invalid campaign ID', 'pushrelay')));
        }
        
        // Update campaign to send it
        $api = pushrelay()->get_api_client();
        $result = $api->update_campaign($campaign_id, array('send' => true));
        
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }
        
        // Invalidate campaign list cache so status change reflects immediately
        $this->invalidate_campaigns_cache();
        
        // Fetch updated campaign to get actual status
        $updated_campaign = $api->get_campaign($campaign_id);
        $campaign_status = 'sent'; // Default fallback
        
        if (!is_wp_error($updated_campaign)) {
            if (isset($updated_campaign['data']['status'])) {
                $campaign_status = sanitize_text_field($updated_campaign['data']['status']);
            } elseif (isset($updated_campaign['status'])) {
                $campaign_status = sanitize_text_field($updated_campaign['status']);
            }
        }
        
        wp_send_json_success(array(
            'message' => __('Campaign sent successfully', 'pushrelay'),
            'campaign_id' => $campaign_id,
            'status' => $campaign_status
        ));
    }
    
    /**
     * AJAX: Delete campaign
     */
    public function ajax_delete_campaign() {
        check_ajax_referer('pushrelay_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'pushrelay')));
        }
        
        $campaign_id = isset($_POST['campaign_id']) ? absint($_POST['campaign_id']) : 0;
        
        if (!$campaign_id) {
            wp_send_json_error(array('message' => __('Invalid campaign ID', 'pushrelay')));
        }
        
        $api = pushrelay()->get_api_client();
        $result = $api->delete_campaign($campaign_id);
        
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }
        
        // Invalidate campaign list cache so deletion reflects immediately
        $this->invalidate_campaigns_cache();
        
        wp_send_json_success(array(
            'message' => __('Campaign deleted successfully', 'pushrelay')
        ));
    }
    
    /**
     * AJAX: Get campaign
     */
    public function ajax_get_campaign() {
        check_ajax_referer('pushrelay_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'pushrelay')));
        }
        
        $campaign_id = isset($_POST['campaign_id']) ? absint($_POST['campaign_id']) : 0;
        
        if (!$campaign_id) {
            wp_send_json_error(array('message' => __('Invalid campaign ID', 'pushrelay')));
        }
        
        $api = pushrelay()->get_api_client();
        $result = $api->get_campaign($campaign_id);
        
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }
        
        wp_send_json_success(array(
            'campaign' => $result
        ));
    }
    
    /**
     * AJAX: Preview campaign
     */
    public function ajax_preview_campaign() {
        check_ajax_referer('pushrelay_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'pushrelay')));
        }
        
        $title = isset($_POST['title']) ? sanitize_text_field(wp_unslash($_POST['title'])) : '';
        $description = isset($_POST['description']) ? sanitize_textarea_field(wp_unslash($_POST['description'])) : '';
        $image_url = isset($_POST['image_url']) ? esc_url_raw(wp_unslash($_POST['image_url'])) : '';
        
        $preview_html = $this->generate_preview_html($title, $description, $image_url);
        
        wp_send_json_success(array(
            'preview' => $preview_html
        ));
    }
    
    /**
     * Generate preview HTML
     */
    private function generate_preview_html($title, $description, $image_url = '') {
        ob_start();
        ?>
        <div class="pushrelay-notification-preview">
            <?php if (!empty($image_url)): ?>
                <div class="pushrelay-notification-image">
                    <img src="<?php echo esc_url($image_url); ?>" alt="">
                </div>
            <?php endif; ?>
            
            <div class="pushrelay-notification-content">
                <div class="pushrelay-notification-title">
                    <?php echo esc_html($title); ?>
                </div>
                <div class="pushrelay-notification-description">
                    <?php echo esc_html($description); ?>
                </div>
            </div>
            
            <div class="pushrelay-notification-site">
                <?php echo esc_html(get_bloginfo('name')); ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Handle image upload
     */
    private function handle_image_upload($file) {
        if (!function_exists('wp_handle_upload')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        
        $upload_overrides = array('test_form' => false);
        $upload = wp_handle_upload($file, $upload_overrides);
        
        if (isset($upload['error'])) {
            return new WP_Error('upload_error', $upload['error']);
        }
        
        return $upload;
    }
    
    /**
     * Save post notification meta
     */
    public function save_post_notification_meta($post_id, $post) {
        // Check nonce
        if (!isset($_POST['pushrelay_post_notification_nonce']) || 
            !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['pushrelay_post_notification_nonce'])), 'pushrelay_post_notification')) {
            return;
        }
        
        // Check autosave
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        
        // Check permissions
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }
        
        // Save setting
        $send_notification = isset($_POST['pushrelay_send_notification']) ? '1' : '0';
        update_post_meta($post_id, '_pushrelay_send_notification', $send_notification);
    }
}
