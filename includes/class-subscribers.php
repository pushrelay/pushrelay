<?php
/**
 * Subscribers Class
 * 
 * Handles subscriber management, filtering, bulk actions,
 * and custom parameter tracking
 * 
 * @package PushRelay
 * @since 1.6.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class PushRelay_Subscribers {
    
    /**
     * Constructor
     */
    public function __construct() {
        // AJAX handlers
        add_action('wp_ajax_pushrelay_get_subscribers', array($this, 'ajax_get_subscribers'));
        add_action('wp_ajax_pushrelay_get_subscriber', array($this, 'ajax_get_subscriber'));
        add_action('wp_ajax_pushrelay_update_subscriber', array($this, 'ajax_update_subscriber'));
        add_action('wp_ajax_pushrelay_delete_subscriber', array($this, 'ajax_delete_subscriber'));
        add_action('wp_ajax_pushrelay_bulk_delete_subscribers', array($this, 'ajax_bulk_delete_subscribers'));
        add_action('wp_ajax_pushrelay_export_subscribers', array($this, 'ajax_export_subscribers'));
        add_action('wp_ajax_pushrelay_get_subscriber_stats', array($this, 'ajax_get_subscriber_stats'));
        add_action('wp_ajax_pushrelay_filter_subscribers', array($this, 'ajax_filter_subscribers'));
    }
    
    /**
     * Get subscribers from API
     */
    public function get_subscribers($page = 1, $per_page = 25) {
        $api = pushrelay()->get_api_client();
        return $api->get_subscribers($page, $per_page);
    }
    
    /**
     * Get single subscriber
     */
    public function get_subscriber($subscriber_id) {
        $api = pushrelay()->get_api_client();
        return $api->get_subscriber($subscriber_id);
    }
    
    /**
     * Update subscriber custom parameters
     */
    public function update_subscriber($subscriber_id, $custom_parameters) {
        $api = pushrelay()->get_api_client();
        return $api->update_subscriber($subscriber_id, $custom_parameters);
    }
    
    /**
     * Delete subscriber
     */
    public function delete_subscriber($subscriber_id) {
        $api = pushrelay()->get_api_client();
        
        $result = $api->delete_subscriber($subscriber_id);
        
        if (!is_wp_error($result)) {
            PushRelay_Debug_Logger::log(
                sprintf('Subscriber deleted: %d', $subscriber_id),
                'info'
            );
        }
        
        return $result;
    }
    
    /**
     * Bulk delete subscribers
     */
    public function bulk_delete_subscribers($subscriber_ids) {
        if (empty($subscriber_ids) || !is_array($subscriber_ids)) {
            return new WP_Error('invalid_ids', __('Invalid subscriber IDs', 'pushrelay'));
        }
        
        $api = pushrelay()->get_api_client();
        $results = array(
            'success' => 0,
            'failed' => 0,
            'errors' => array(),
        );
        
        foreach ($subscriber_ids as $subscriber_id) {
            $result = $api->delete_subscriber(absint($subscriber_id));
            
            if (is_wp_error($result)) {
                $results['failed']++;
                $results['errors'][] = sprintf(
                    /* translators: 1: Subscriber ID, 2: Error message */
                    __('Failed to delete subscriber %1$d: %2$s', 'pushrelay'),
                    $subscriber_id,
                    $result->get_error_message()
                );
            } else {
                $results['success']++;
            }
        }
        
        PushRelay_Debug_Logger::log(
            sprintf('Bulk delete completed: %d success, %d failed', $results['success'], $results['failed']),
            $results['failed'] > 0 ? 'warning' : 'success'
        );
        
        return $results;
    }
    
    /**
     * Get subscriber statistics
     */
    public function get_statistics($website_id = null, $start_date = null, $end_date = null, $type = 'overview') {
        $settings = get_option('pushrelay_settings', array());
        
        if (empty($website_id)) {
            $website_id = isset($settings['website_id']) ? $settings['website_id'] : null;
        }
        
        if (empty($website_id)) {
            return new WP_Error('no_website', __('No website configured', 'pushrelay'));
        }
        
        // Default to last 30 days
        if (empty($start_date)) {
            $start_date = gmdate('Y-m-d', strtotime('-30 days'));
        }
        
        if (empty($end_date)) {
            $end_date = gmdate('Y-m-d');
        }
        
        $api = pushrelay()->get_api_client();
        return $api->get_subscriber_statistics($website_id, $start_date, $end_date, $type);
    }
    
    /**
     * Export subscribers to CSV
     */
    public function export_to_csv($subscribers) {
        if (empty($subscribers)) {
            return '';
        }
        
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Using php://temp stream for CSV generation
        $output = fopen('php://temp', 'r+');
        
        // Add headers
        $headers = array(
            __('ID', 'pushrelay'),
            __('Country', 'pushrelay'),
            __('City', 'pushrelay'),
            __('OS', 'pushrelay'),
            __('Browser', 'pushrelay'),
            __('Device Type', 'pushrelay'),
            __('Language', 'pushrelay'),
            __('Subscribed On', 'pushrelay'),
            __('Total Sent', 'pushrelay'),
            __('Total Displayed', 'pushrelay'),
            __('Total Clicked', 'pushrelay'),
            __('Date', 'pushrelay'),
        );
        
        fputcsv($output, $headers);
        
        // Add data
        foreach ($subscribers as $subscriber) {
            $row = array(
                isset($subscriber['id']) ? $subscriber['id'] : '',
                isset($subscriber['country_code']) ? $subscriber['country_code'] : '',
                isset($subscriber['city_name']) ? $subscriber['city_name'] : '',
                isset($subscriber['os_name']) ? $subscriber['os_name'] : '',
                isset($subscriber['browser_name']) ? $subscriber['browser_name'] : '',
                isset($subscriber['device_type']) ? $subscriber['device_type'] : '',
                isset($subscriber['browser_language']) ? $subscriber['browser_language'] : '',
                isset($subscriber['subscribed_on_url']) ? $subscriber['subscribed_on_url'] : '',
                isset($subscriber['total_sent_push_notifications']) ? $subscriber['total_sent_push_notifications'] : 0,
                isset($subscriber['total_displayed_push_notifications']) ? $subscriber['total_displayed_push_notifications'] : 0,
                isset($subscriber['total_clicked_push_notifications']) ? $subscriber['total_clicked_push_notifications'] : 0,
                isset($subscriber['datetime']) ? $subscriber['datetime'] : '',
            );
            
            fputcsv($output, $row);
        }
        
        rewind($output);
        $csv = stream_get_contents($output);
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing php://temp stream
        fclose($output);
        
        return $csv;
    }
    
    /**
     * Filter subscribers by criteria
     */
    public function filter_subscribers($filters) {
        // This is a local filter for display purposes
        // The actual filtering happens in the segmentation class
        
        $all_subscribers = $this->get_all_subscribers_cached();
        
        if (empty($all_subscribers) || empty($filters)) {
            return $all_subscribers;
        }
        
        $filtered = array_filter($all_subscribers, function($subscriber) use ($filters) {
            // Filter by country
            if (!empty($filters['country']) && 
                isset($subscriber['country_code']) && 
                $subscriber['country_code'] !== $filters['country']) {
                return false;
            }
            
            // Filter by device type
            if (!empty($filters['device_type']) && 
                isset($subscriber['device_type']) && 
                $subscriber['device_type'] !== $filters['device_type']) {
                return false;
            }
            
            // Filter by browser
            if (!empty($filters['browser']) && 
                isset($subscriber['browser_name']) && 
                $subscriber['browser_name'] !== $filters['browser']) {
                return false;
            }
            
            // Filter by OS
            if (!empty($filters['os']) && 
                isset($subscriber['os_name']) && 
                $subscriber['os_name'] !== $filters['os']) {
                return false;
            }
            
            return true;
        });
        
        return array_values($filtered);
    }
    
    /**
     * Get all subscribers with caching
     */
    private function get_all_subscribers_cached() {
        $cache_key = 'pushrelay_all_subscribers';
        $cached = get_transient($cache_key);
        
        if ($cached !== false) {
            return $cached;
        }
        
        $all_subscribers = array();
        $page = 1;
        $per_page = 100;
        
        do {
            $result = $this->get_subscribers($page, $per_page);
            
            if (is_wp_error($result) || empty($result['data'])) {
                break;
            }
            
            $all_subscribers = array_merge($all_subscribers, $result['data']);
            
            $total_pages = isset($result['meta']['total_pages']) ? $result['meta']['total_pages'] : 1;
            $page++;
            
        } while ($page <= $total_pages);
        
        // Cache for 5 minutes
        set_transient($cache_key, $all_subscribers, 5 * MINUTE_IN_SECONDS);
        
        return $all_subscribers;
    }
    
    /**
     * Get unique values for filtering
     */
    public function get_filter_options() {
        $subscribers = $this->get_all_subscribers_cached();
        
        $options = array(
            'countries' => array(),
            'cities' => array(),
            'devices' => array(),
            'browsers' => array(),
            'operating_systems' => array(),
            'languages' => array(),
        );
        
        foreach ($subscribers as $subscriber) {
            // Countries
            if (!empty($subscriber['country_code']) && 
                !in_array($subscriber['country_code'], $options['countries'], true)) {
                $options['countries'][] = $subscriber['country_code'];
            }
            
            // Cities
            if (!empty($subscriber['city_name']) && 
                !in_array($subscriber['city_name'], $options['cities'], true)) {
                $options['cities'][] = $subscriber['city_name'];
            }
            
            // Device types
            if (!empty($subscriber['device_type']) && 
                !in_array($subscriber['device_type'], $options['devices'], true)) {
                $options['devices'][] = $subscriber['device_type'];
            }
            
            // Browsers
            if (!empty($subscriber['browser_name']) && 
                !in_array($subscriber['browser_name'], $options['browsers'], true)) {
                $options['browsers'][] = $subscriber['browser_name'];
            }
            
            // Operating systems
            if (!empty($subscriber['os_name']) && 
                !in_array($subscriber['os_name'], $options['operating_systems'], true)) {
                $options['operating_systems'][] = $subscriber['os_name'];
            }
            
            // Languages
            if (!empty($subscriber['browser_language']) && 
                !in_array($subscriber['browser_language'], $options['languages'], true)) {
                $options['languages'][] = $subscriber['browser_language'];
            }
        }
        
        // Sort all arrays
        foreach ($options as $key => $values) {
            sort($options[$key]);
        }
        
        return $options;
    }
    
    /**
     * Get subscriber engagement score
     */
    public function calculate_engagement_score($subscriber) {
        $score = 0;
        
        $sent = isset($subscriber['total_sent_push_notifications']) ? absint($subscriber['total_sent_push_notifications']) : 0;
        $displayed = isset($subscriber['total_displayed_push_notifications']) ? absint($subscriber['total_displayed_push_notifications']) : 0;
        $clicked = isset($subscriber['total_clicked_push_notifications']) ? absint($subscriber['total_clicked_push_notifications']) : 0;
        
        if ($sent > 0) {
            // Display rate (0-50 points)
            $display_rate = ($displayed / $sent) * 50;
            $score += $display_rate;
            
            // Click rate (0-50 points)
            if ($displayed > 0) {
                $click_rate = ($clicked / $displayed) * 50;
                $score += $click_rate;
            }
        }
        
        return round($score, 1);
    }
    
    /**
     * Get subscriber segments
     */
    public function get_subscriber_segments($subscriber) {
        $segments = array();
        
        // Device-based segments
        if (isset($subscriber['device_type'])) {
            $segments[] = ucfirst($subscriber['device_type']) . ' ' . __('Users', 'pushrelay');
        }
        
        // Location-based segments
        if (isset($subscriber['country_code'])) {
            $segments[] = $subscriber['country_code'] . ' ' . __('Subscribers', 'pushrelay');
        }
        
        // Engagement-based segments
        $score = $this->calculate_engagement_score($subscriber);
        if ($score >= 70) {
            $segments[] = __('Highly Engaged', 'pushrelay');
        } elseif ($score >= 40) {
            $segments[] = __('Moderately Engaged', 'pushrelay');
        } else {
            $segments[] = __('Low Engagement', 'pushrelay');
        }
        
        return $segments;
    }
    
    /**
     * AJAX: Get subscribers
     */
    public function ajax_get_subscribers() {
        check_ajax_referer('pushrelay_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'pushrelay')));
        }
        
        $page = isset($_POST['page']) ? absint($_POST['page']) : 1;
        $per_page = isset($_POST['per_page']) ? absint($_POST['per_page']) : 25;
        
        $result = $this->get_subscribers($page, $per_page);
        
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }
        
        // Add engagement scores
        if (!empty($result['data'])) {
            foreach ($result['data'] as &$subscriber) {
                $subscriber['engagement_score'] = $this->calculate_engagement_score($subscriber);
                $subscriber['segments'] = $this->get_subscriber_segments($subscriber);
            }
        }
        
        wp_send_json_success($result);
    }
    
    /**
     * AJAX: Get subscriber
     */
    public function ajax_get_subscriber() {
        check_ajax_referer('pushrelay_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'pushrelay')));
        }
        
        $subscriber_id = isset($_POST['subscriber_id']) ? absint($_POST['subscriber_id']) : 0;
        
        if (!$subscriber_id) {
            wp_send_json_error(array('message' => __('Invalid subscriber ID', 'pushrelay')));
        }
        
        $result = $this->get_subscriber($subscriber_id);
        
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }
        
        // Add engagement score
        if (!empty($result['data'])) {
            $result['data']['engagement_score'] = $this->calculate_engagement_score($result['data']);
            $result['data']['segments'] = $this->get_subscriber_segments($result['data']);
        }
        
        wp_send_json_success($result);
    }
    
    /**
     * AJAX: Update subscriber
     */
    public function ajax_update_subscriber() {
        check_ajax_referer('pushrelay_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'pushrelay')));
        }
        
        $subscriber_id = isset($_POST['subscriber_id']) ? absint($_POST['subscriber_id']) : 0;
        $custom_parameters = isset($_POST['custom_parameters']) ? $_POST['custom_parameters'] : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        
        if (!$subscriber_id) {
            wp_send_json_error(array('message' => __('Invalid subscriber ID', 'pushrelay')));
        }
        
        $result = $this->update_subscriber($subscriber_id, $custom_parameters);
        
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }
        
        wp_send_json_success(array(
            'message' => __('Subscriber updated successfully', 'pushrelay')
        ));
    }
    
    /**
     * AJAX: Delete subscriber
     */
    public function ajax_delete_subscriber() {
        check_ajax_referer('pushrelay_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'pushrelay')));
        }
        
        $subscriber_id = isset($_POST['subscriber_id']) ? absint($_POST['subscriber_id']) : 0;
        
        if (!$subscriber_id) {
            wp_send_json_error(array('message' => __('Invalid subscriber ID', 'pushrelay')));
        }
        
        $result = $this->delete_subscriber($subscriber_id);
        
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }
        
        wp_send_json_success(array(
            'message' => __('Subscriber deleted successfully', 'pushrelay')
        ));
    }
    
    /**
     * AJAX: Bulk delete subscribers
     */
    public function ajax_bulk_delete_subscribers() {
        check_ajax_referer('pushrelay_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'pushrelay')));
        }
        
        $subscriber_ids = isset($_POST['subscriber_ids']) ? array_map('absint', $_POST['subscriber_ids']) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        
        if (empty($subscriber_ids)) {
            wp_send_json_error(array('message' => __('No subscribers selected', 'pushrelay')));
        }
        
        $results = $this->bulk_delete_subscribers($subscriber_ids);
        
        if (is_wp_error($results)) {
            wp_send_json_error(array('message' => $results->get_error_message()));
        }
        
        wp_send_json_success(array(
            'message' => sprintf(
                /* translators: 1: Number of successfully deleted subscribers, 2: Number of failed deletions */
                __('%1$d subscribers deleted successfully, %2$d failed', 'pushrelay'),
                $results['success'],
                $results['failed']
            ),
            'results' => $results
        ));
    }
    
    /**
     * AJAX: Export subscribers
     */
    public function ajax_export_subscribers() {
        check_ajax_referer('pushrelay_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'pushrelay')));
        }
        
        $subscribers = $this->get_all_subscribers_cached();
        
        if (empty($subscribers)) {
            wp_send_json_error(array('message' => __('No subscribers to export', 'pushrelay')));
        }
        
        $csv = $this->export_to_csv($subscribers);
        $filename = 'pushrelay-subscribers-' . gmdate('Y-m-d-H-i-s') . '.csv';
        
        wp_send_json_success(array(
            'csv' => base64_encode($csv), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
            'filename' => $filename
        ));
    }
    
    /**
     * AJAX: Get subscriber stats
     */
    public function ajax_get_subscriber_stats() {
        check_ajax_referer('pushrelay_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'pushrelay')));
        }
        
        $start_date = isset($_POST['start_date']) ? sanitize_text_field(wp_unslash($_POST['start_date'])) : null;
        $end_date = isset($_POST['end_date']) ? sanitize_text_field(wp_unslash($_POST['end_date'])) : null;
        $type = isset($_POST['type']) ? sanitize_text_field(wp_unslash($_POST['type'])) : 'overview';
        
        $result = $this->get_statistics(null, $start_date, $end_date, $type);
        
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }
        
        wp_send_json_success($result);
    }
    
    /**
     * AJAX: Filter subscribers
     */
    public function ajax_filter_subscribers() {
        check_ajax_referer('pushrelay_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'pushrelay')));
        }
        
        $filters = isset($_POST['filters']) ? $_POST['filters'] : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        
        $filtered = $this->filter_subscribers($filters);
        
        // Add engagement scores
        if (!empty($filtered)) {
            foreach ($filtered as &$subscriber) {
                $subscriber['engagement_score'] = $this->calculate_engagement_score($subscriber);
                $subscriber['segments'] = $this->get_subscriber_segments($subscriber);
            }
        }
        
        wp_send_json_success(array(
            'subscribers' => $filtered,
            'total' => count($filtered)
        ));
    }
    
    /**
     * Get subscriber statistics for dashboard
     * 
     * @return array Statistics array with active counts
     */
    public function get_subscriber_stats() {
        $api = pushrelay()->get_api_client();
        $settings = get_option('pushrelay_settings', array());
        
        $stats = array(
            'active_today' => 0,
            'active_week' => 0,
            'active_month' => 0
        );
        
        if (empty($settings['website_id'])) {
            return $stats;
        }
        
        // Get subscribers for stats calculation
        $subscribers_response = $api->get_subscribers(array('results_per_page' => 1000));
        
        if (is_wp_error($subscribers_response) || !isset($subscribers_response['data'])) {
            return $stats;
        }
        
        $now = current_time('timestamp');
        $today = gmdate('Y-m-d', $now);
        $week_ago = gmdate('Y-m-d', strtotime('-7 days', $now));
        $month_ago = gmdate('Y-m-d', strtotime('-30 days', $now));
        
        foreach ($subscribers_response['data'] as $subscriber) {
            $last_sent = isset($subscriber['last_sent_datetime']) && !empty($subscriber['last_sent_datetime']) 
                        ? $subscriber['last_sent_datetime'] 
                        : '';
            
            if (!empty($last_sent)) {
                $sent_date = gmdate('Y-m-d', strtotime($last_sent));
                
                if ($sent_date === $today) {
                    $stats['active_today']++;
                }
                if ($sent_date >= $week_ago) {
                    $stats['active_week']++;
                }
                if ($sent_date >= $month_ago) {
                    $stats['active_month']++;
                }
            }
        }
        
        return $stats;
    }
}
