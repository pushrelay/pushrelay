<?php
/**
 * Segmentation Class
 * 
 * Handles advanced subscriber segmentation, filtering,
 * and audience targeting
 * 
 * @package PushRelay
 * @since 1.6.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class PushRelay_Segmentation {
    
    /**
     * Constructor
     */
    public function __construct() {
        // AJAX handlers
        add_action('wp_ajax_pushrelay_create_segment', array($this, 'ajax_create_segment'));
        add_action('wp_ajax_pushrelay_get_segments', array($this, 'ajax_get_segments'));
        add_action('wp_ajax_pushrelay_get_segment', array($this, 'ajax_get_segment'));
        add_action('wp_ajax_pushrelay_update_segment', array($this, 'ajax_update_segment'));
        add_action('wp_ajax_pushrelay_delete_segment', array($this, 'ajax_delete_segment'));
        add_action('wp_ajax_pushrelay_calculate_segment', array($this, 'ajax_calculate_segment'));
        add_action('wp_ajax_pushrelay_get_segment_preview', array($this, 'ajax_get_segment_preview'));
    }
    
    /**
     * Create a new segment
     */
    public function create_segment($name, $description, $filters) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'pushrelay_segments';
        
        // Validate filters
        if (!is_array($filters)) {
            return new WP_Error('invalid_filters', __('Invalid filters provided', 'pushrelay'));
        }
        
        // Insert segment
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $result = $wpdb->insert(
            $table_name,
            array(
                'name' => sanitize_text_field($name),
                'description' => sanitize_textarea_field($description),
                'filters' => wp_json_encode($filters),
                'subscriber_count' => 0,
                'created_at' => current_time('mysql'),
            ),
            array('%s', '%s', '%s', '%d', '%s')
        );
        
        if (!$result) {
            return new WP_Error('db_error', __('Failed to create segment', 'pushrelay'));
        }
        
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $segment_id = $wpdb->insert_id;
        
        // Calculate subscriber count
        $this->calculate_segment_count($segment_id);
        
        PushRelay_Debug_Logger::log(
            sprintf('Segment created: %s (ID: %d)', $name, $segment_id),
            'success'
        );
        
        return $segment_id;
    }
    
    /**
     * Get all segments
     */
    public function get_segments() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'pushrelay_segments';
        
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $segments = $wpdb->get_results(
            "SELECT * FROM {$table_name} ORDER BY created_at DESC",
            ARRAY_A
        );
        
        if ($segments) {
            foreach ($segments as &$segment) {
                $segment['filters'] = json_decode($segment['filters'], true);
            }
        }
        
        return $segments ? $segments : array();
    }
    
    /**
     * Get single segment
     */
    public function get_segment($segment_id) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'pushrelay_segments';
        
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $segment = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table_name} WHERE id = %d",
                absint($segment_id)
            ),
            ARRAY_A
        );
        
        if ($segment) {
            $segment['filters'] = json_decode($segment['filters'], true);
        }
        
        return $segment;
    }
    
    /**
     * Update segment
     */
    public function update_segment($segment_id, $name = null, $description = null, $filters = null) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'pushrelay_segments';
        
        $update_data = array(
            'updated_at' => current_time('mysql'),
        );
        $update_format = array('%s');
        
        if (!is_null($name)) {
            $update_data['name'] = sanitize_text_field($name);
            $update_format[] = '%s';
        }
        
        if (!is_null($description)) {
            $update_data['description'] = sanitize_textarea_field($description);
            $update_format[] = '%s';
        }
        
        if (!is_null($filters)) {
            $update_data['filters'] = wp_json_encode($filters);
            $update_format[] = '%s';
        }
        
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $result = $wpdb->update(
            $table_name,
            $update_data,
            array('id' => absint($segment_id)),
            $update_format,
            array('%d')
        );
        
        if ($result === false) {
            return new WP_Error('db_error', __('Failed to update segment', 'pushrelay'));
        }
        
        // Recalculate subscriber count if filters changed
        if (!is_null($filters)) {
            $this->calculate_segment_count($segment_id);
        }
        
        PushRelay_Debug_Logger::log(
            sprintf('Segment updated: ID %d', $segment_id),
            'info'
        );
        
        return true;
    }
    
    /**
     * Delete segment
     */
    public function delete_segment($segment_id) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'pushrelay_segments';
        
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $result = $wpdb->delete(
            $table_name,
            array('id' => absint($segment_id)),
            array('%d')
        );
        
        if (!$result) {
            return new WP_Error('db_error', __('Failed to delete segment', 'pushrelay'));
        }
        
        PushRelay_Debug_Logger::log(
            sprintf('Segment deleted: ID %d', $segment_id),
            'info'
        );
        
        return true;
    }
    
    /**
     * Calculate subscriber count for segment
     */
    public function calculate_segment_count($segment_id) {
        global $wpdb;
        
        $segment = $this->get_segment($segment_id);
        
        if (!$segment) {
            return 0;
        }
        
        $filters = isset($segment['filters']) ? $segment['filters'] : array();
        $matching_subscribers = $this->apply_filters($filters);
        
        $count = count($matching_subscribers);
        
        // Update count in database
        $table_name = $wpdb->prefix . 'pushrelay_segments';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->update(
            $table_name,
            array(
                'subscriber_count' => $count,
                'last_calculated' => current_time('mysql'),
            ),
            array('id' => absint($segment_id)),
            array('%d', '%s'),
            array('%d')
        );
        
        return $count;
    }
    
    /**
     * Apply filters to get matching subscribers
     */
    public function apply_filters($filters) {
        $subscribers_class = new PushRelay_Subscribers();
        
        // Get all subscribers (cached)
        $cache_key = 'pushrelay_all_subscribers';
        $all_subscribers = get_transient($cache_key);
        
        if ($all_subscribers === false) {
            $api = pushrelay()->get_api_client();
            $all_subscribers = array();
            $page = 1;
            $per_page = 100;
            
            do {
                $result = $api->get_subscribers($page, $per_page);
                
                if (is_wp_error($result) || empty($result['data'])) {
                    break;
                }
                
                $all_subscribers = array_merge($all_subscribers, $result['data']);
                
                $total_pages = isset($result['meta']['total_pages']) ? $result['meta']['total_pages'] : 1;
                $page++;
                
            } while ($page <= $total_pages);
            
            set_transient($cache_key, $all_subscribers, 5 * MINUTE_IN_SECONDS);
        }
        
        if (empty($all_subscribers) || empty($filters)) {
            return $all_subscribers;
        }
        
        // Apply filters
        $filtered = array_filter($all_subscribers, function($subscriber) use ($filters) {
            return $this->subscriber_matches_filters($subscriber, $filters);
        });
        
        return array_values($filtered);
    }
    
    /**
     * Check if subscriber matches filters
     */
    private function subscriber_matches_filters($subscriber, $filters) {
        // Country filter
        if (!empty($filters['countries']) && is_array($filters['countries'])) {
            $subscriber_country = isset($subscriber['country_code']) ? $subscriber['country_code'] : '';
            if (!in_array($subscriber_country, $filters['countries'], true)) {
                return false;
            }
        }
        
        // City filter
        if (!empty($filters['cities']) && is_array($filters['cities'])) {
            $subscriber_city = isset($subscriber['city_name']) ? $subscriber['city_name'] : '';
            if (!in_array($subscriber_city, $filters['cities'], true)) {
                return false;
            }
        }
        
        // Continent filter
        if (!empty($filters['continents']) && is_array($filters['continents'])) {
            $subscriber_continent = isset($subscriber['continent_code']) ? $subscriber['continent_code'] : '';
            if (!in_array($subscriber_continent, $filters['continents'], true)) {
                return false;
            }
        }
        
        // Device type filter
        if (!empty($filters['device_types']) && is_array($filters['device_types'])) {
            $subscriber_device = isset($subscriber['device_type']) ? $subscriber['device_type'] : '';
            if (!in_array($subscriber_device, $filters['device_types'], true)) {
                return false;
            }
        }
        
        // Browser filter
        if (!empty($filters['browsers']) && is_array($filters['browsers'])) {
            $subscriber_browser = isset($subscriber['browser_name']) ? $subscriber['browser_name'] : '';
            if (!in_array($subscriber_browser, $filters['browsers'], true)) {
                return false;
            }
        }
        
        // Operating system filter
        if (!empty($filters['operating_systems']) && is_array($filters['operating_systems'])) {
            $subscriber_os = isset($subscriber['os_name']) ? $subscriber['os_name'] : '';
            if (!in_array($subscriber_os, $filters['operating_systems'], true)) {
                return false;
            }
        }
        
        // Language filter
        if (!empty($filters['languages']) && is_array($filters['languages'])) {
            $subscriber_lang = isset($subscriber['browser_language']) ? $subscriber['browser_language'] : '';
            if (!in_array($subscriber_lang, $filters['languages'], true)) {
                return false;
            }
        }
        
        // Subscribed on URL filter
        if (!empty($filters['subscribed_on_url'])) {
            $subscriber_url = isset($subscriber['subscribed_on_url']) ? $subscriber['subscribed_on_url'] : '';
            $filter_url = $filters['subscribed_on_url'];
            
            if (strpos($subscriber_url, $filter_url) === false) {
                return false;
            }
        }
        
        // Custom parameter filters
        if (!empty($filters['custom_parameters']) && is_array($filters['custom_parameters'])) {
            $subscriber_params = isset($subscriber['custom_parameters']) ? $subscriber['custom_parameters'] : array();
            
            foreach ($filters['custom_parameters'] as $param_filter) {
                if (!isset($param_filter['key'], $param_filter['condition'], $param_filter['value'])) {
                    continue;
                }
                
                $key = $param_filter['key'];
                $condition = $param_filter['condition'];
                $filter_value = $param_filter['value'];
                
                // Find parameter value
                $param_value = '';
                foreach ($subscriber_params as $param) {
                    if (isset($param['key']) && $param['key'] === $key) {
                        $param_value = isset($param['value']) ? $param['value'] : '';
                        break;
                    }
                }
                
                // Apply condition
                if (!$this->check_condition($param_value, $condition, $filter_value)) {
                    return false;
                }
            }
        }
        
        // Engagement filters
        if (!empty($filters['min_clicks'])) {
            $clicks = isset($subscriber['total_clicked_push_notifications']) ? absint($subscriber['total_clicked_push_notifications']) : 0;
            if ($clicks < absint($filters['min_clicks'])) {
                return false;
            }
        }
        
        if (!empty($filters['max_clicks'])) {
            $clicks = isset($subscriber['total_clicked_push_notifications']) ? absint($subscriber['total_clicked_push_notifications']) : 0;
            if ($clicks > absint($filters['max_clicks'])) {
                return false;
            }
        }
        
        // Date filters
        if (!empty($filters['subscribed_after'])) {
            $subscribed_date = isset($subscriber['datetime']) ? strtotime($subscriber['datetime']) : 0;
            $filter_date = strtotime($filters['subscribed_after']);
            if ($subscribed_date < $filter_date) {
                return false;
            }
        }
        
        if (!empty($filters['subscribed_before'])) {
            $subscribed_date = isset($subscriber['datetime']) ? strtotime($subscriber['datetime']) : 0;
            $filter_date = strtotime($filters['subscribed_before']);
            if ($subscribed_date > $filter_date) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Check condition for custom parameters
     */
    private function check_condition($value, $condition, $filter_value) {
        // Guard against null values for PHP 8.1+ compatibility
        $value = is_string( $value ) ? $value : '';
        $filter_value = is_string( $filter_value ) ? $filter_value : '';
        
        switch ($condition) {
            case 'exact':
                return $value === $filter_value;
                
            case 'not_exact':
                return $value !== $filter_value;
                
            case 'contains':
                return strpos(strtolower($value), strtolower($filter_value)) !== false;
                
            case 'not_contains':
                return strpos(strtolower($value), strtolower($filter_value)) === false;
                
            case 'starts_with':
                return strpos(strtolower($value), strtolower($filter_value)) === 0;
                
            case 'not_starts_with':
                return strpos(strtolower($value), strtolower($filter_value)) !== 0;
                
            case 'ends_with':
                $filter_len = strlen($filter_value);
                return $filter_len > 0 && substr(strtolower($value), -$filter_len) === strtolower($filter_value);
                
            case 'not_ends_with':
                $filter_len = strlen($filter_value);
                return $filter_len === 0 || substr(strtolower($value), -$filter_len) !== strtolower($filter_value);
                
            case 'bigger_than':
                return is_numeric($value) && is_numeric($filter_value) && floatval($value) > floatval($filter_value);
                
            case 'lower_than':
                return is_numeric($value) && is_numeric($filter_value) && floatval($value) < floatval($filter_value);
                
            default:
                return false;
        }
    }
    
    /**
     * Get predefined segments
     */
    public function get_predefined_segments() {
        return array(
            array(
                'id' => 'all',
                'name' => __('All Subscribers', 'pushrelay'),
                'description' => __('Every subscriber in your database', 'pushrelay'),
                'icon' => '👥',
            ),
            array(
                'id' => 'mobile',
                'name' => __('Mobile Users', 'pushrelay'),
                'description' => __('Subscribers using mobile devices', 'pushrelay'),
                'icon' => '📱',
                'filters' => array('device_types' => array('mobile')),
            ),
            array(
                'id' => 'desktop',
                'name' => __('Desktop Users', 'pushrelay'),
                'description' => __('Subscribers using desktop computers', 'pushrelay'),
                'icon' => '💻',
                'filters' => array('device_types' => array('desktop')),
            ),
            array(
                'id' => 'highly_engaged',
                'name' => __('Highly Engaged', 'pushrelay'),
                'description' => __('Subscribers with high click rates', 'pushrelay'),
                'icon' => '⭐',
                'filters' => array('min_clicks' => 5),
            ),
            array(
                'id' => 'new_subscribers',
                'name' => __('New Subscribers', 'pushrelay'),
                'description' => __('Subscribed in the last 7 days', 'pushrelay'),
                'icon' => '🆕',
                'filters' => array('subscribed_after' => gmdate('Y-m-d', strtotime('-7 days'))),
            ),
        );
    }
    
    /**
     * Get segment by predefined ID
     */
    public function get_predefined_segment($segment_id) {
        $predefined = $this->get_predefined_segments();
        
        foreach ($predefined as $segment) {
            if ($segment['id'] === $segment_id) {
                return $segment;
            }
        }
        
        return null;
    }
    
    /**
     * AJAX: Create segment
     */
    public function ajax_create_segment() {
        check_ajax_referer('pushrelay_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'pushrelay')));
        }
        
        $name = isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '';
        $description = isset($_POST['description']) ? sanitize_textarea_field(wp_unslash($_POST['description'])) : '';
        $filters = isset($_POST['filters']) ? $_POST['filters'] : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        
        if (empty($name)) {
            wp_send_json_error(array('message' => __('Segment name is required', 'pushrelay')));
        }
        
        $segment_id = $this->create_segment($name, $description, $filters);
        
        if (is_wp_error($segment_id)) {
            wp_send_json_error(array('message' => $segment_id->get_error_message()));
        }
        
        wp_send_json_success(array(
            'message' => __('Segment created successfully', 'pushrelay'),
            'segment_id' => $segment_id
        ));
    }
    
    /**
     * AJAX: Get segments
     */
    public function ajax_get_segments() {
        check_ajax_referer('pushrelay_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'pushrelay')));
        }
        
        $segments = $this->get_segments();
        $predefined = $this->get_predefined_segments();
        
        wp_send_json_success(array(
            'segments' => $segments,
            'predefined' => $predefined
        ));
    }
    
    /**
     * AJAX: Get segment
     */
    public function ajax_get_segment() {
        check_ajax_referer('pushrelay_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'pushrelay')));
        }
        
        $segment_id = isset($_POST['segment_id']) ? absint($_POST['segment_id']) : 0;
        
        if (!$segment_id) {
            wp_send_json_error(array('message' => __('Invalid segment ID', 'pushrelay')));
        }
        
        $segment = $this->get_segment($segment_id);
        
        if (!$segment) {
            wp_send_json_error(array('message' => __('Segment not found', 'pushrelay')));
        }
        
        wp_send_json_success($segment);
    }
    
    /**
     * AJAX: Update segment
     */
    public function ajax_update_segment() {
        check_ajax_referer('pushrelay_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'pushrelay')));
        }
        
        $segment_id = isset($_POST['segment_id']) ? absint($_POST['segment_id']) : 0;
        $name = isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : null;
        $description = isset($_POST['description']) ? sanitize_textarea_field(wp_unslash($_POST['description'])) : null;
        $filters = isset($_POST['filters']) ? $_POST['filters'] : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        
        if (!$segment_id) {
            wp_send_json_error(array('message' => __('Invalid segment ID', 'pushrelay')));
        }
        
        $result = $this->update_segment($segment_id, $name, $description, $filters);
        
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }
        
        wp_send_json_success(array('message' => __('Segment updated successfully', 'pushrelay')));
    }
    
    /**
     * AJAX: Delete segment
     */
    public function ajax_delete_segment() {
        check_ajax_referer('pushrelay_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'pushrelay')));
        }
        
        $segment_id = isset($_POST['segment_id']) ? absint($_POST['segment_id']) : 0;
        
        if (!$segment_id) {
            wp_send_json_error(array('message' => __('Invalid segment ID', 'pushrelay')));
        }
        
        $result = $this->delete_segment($segment_id);
        
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }
        
        wp_send_json_success(array('message' => __('Segment deleted successfully', 'pushrelay')));
    }
    
    /**
     * AJAX: Calculate segment
     */
    public function ajax_calculate_segment() {
        check_ajax_referer('pushrelay_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'pushrelay')));
        }
        
        $segment_id = isset($_POST['segment_id']) ? absint($_POST['segment_id']) : 0;
        
        if (!$segment_id) {
            wp_send_json_error(array('message' => __('Invalid segment ID', 'pushrelay')));
        }
        
        $count = $this->calculate_segment_count($segment_id);
        
        wp_send_json_success(array(
            'count' => $count,
            /* translators: %d: Number of subscribers */
            'message' => sprintf(__('Segment contains %d subscribers', 'pushrelay'), $count)
        ));
    }
    
    /**
     * AJAX: Get segment preview
     */
    public function ajax_get_segment_preview() {
        check_ajax_referer('pushrelay_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'pushrelay')));
        }
        
        $filters = isset($_POST['filters']) ? $_POST['filters'] : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        
        $matching = $this->apply_filters($filters);
        
        // Limit preview to 10 subscribers
        $preview = array_slice($matching, 0, 10);
        
        wp_send_json_success(array(
            'total_count' => count($matching),
            'preview' => $preview
        ));
    }
}
