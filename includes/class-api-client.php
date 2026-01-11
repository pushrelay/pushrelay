<?php
/**
 * API Client Class
 * 
 * Handles all communication with PushRelay API
 * Includes error handling, logging, and automatic website detection
 * 
 * @package PushRelay
 * @since 1.6.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class PushRelay_API_Client {
    
    /**
     * API base URL
     */
    private $api_base = 'https://pushrelay.com/api';
    
    /**
     * API key
     */
    private $api_key = '';
    
    /**
     * Transient errors that may be retried (5xx, timeout)
     * 
     * @var array
     */
    private static $transient_errors = array( 500, 502, 503, 504, 0 );
    
    /**
     * Methods that are safe to retry (idempotent)
     * 
     * @var array
     */
    private static $idempotent_methods = array( 'GET', 'HEAD', 'OPTIONS' );
    
    /**
     * Rate limit backoff duration in seconds
     * 
     * @var int
     */
    const RATE_LIMIT_BACKOFF_SECONDS = 60;
    
    /**
     * Transient name for rate limit tracking
     * 
     * @var string
     */
    const RATE_LIMIT_TRANSIENT = 'pushrelay_api_rate_limited';
    
    /**
     * Constructor
     */
    public function __construct() {
        $settings = get_option('pushrelay_settings', array());
        $this->api_key = isset($settings['api_key']) ? $settings['api_key'] : '';
    }
    
    /**
     * Make API request
     * 
     * @param string $endpoint API endpoint
     * @param string $method HTTP method
     * @param array $data Request data
     * @param array $files Files to upload
     * @return array|WP_Error
     */
    private function request($endpoint, $method = 'GET', $data = array(), $files = array()) {
        if (empty($this->api_key)) {
            return new WP_Error('no_api_key', __('API key not configured', 'pushrelay'));
        }
        
        // Check if we're in rate limit backoff period
        if ( $this->is_rate_limited() ) {
            PushRelay_Debug_Logger::log(
                'API request skipped: rate limit backoff active',
                PushRelay_Debug_Logger::LEVEL_NOTICE,
                array( 'endpoint' => $endpoint, 'method' => $method )
            );
            return new WP_Error( 
                'rate_limited', 
                __( 'API rate limit active. Please wait before retrying.', 'pushrelay' ),
                array( 'retry_after' => self::RATE_LIMIT_BACKOFF_SECONDS )
            );
        }
        
        $url = $this->api_base . $endpoint;
        
        // Prepare request arguments
        $args = array(
            'method' => $method,
            'timeout' => 30,
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->api_key,
            ),
        );
        
        // For POST/PUT/PATCH requests, ALWAYS use multipart/form-data (as per API docs)
        if (!empty($data) && in_array($method, array('POST', 'PUT', 'PATCH'), true)) {
            $boundary = wp_generate_password(24, false);
            $args['headers']['Content-Type'] = 'multipart/form-data; boundary=' . $boundary;
            
            $body = '';
            
            // Add regular data fields
            foreach ($data as $key => $value) {
                if (is_array($value)) {
                    foreach ($value as $index => $item) {
                        $body .= '--' . $boundary . "\r\n";
                        $body .= 'Content-Disposition: form-data; name="' . $key . '[' . $index . ']"' . "\r\n\r\n";
                        $body .= $item . "\r\n";
                    }
                } else {
                    $body .= '--' . $boundary . "\r\n";
                    $body .= 'Content-Disposition: form-data; name="' . $key . '"' . "\r\n\r\n";
                    $body .= $value . "\r\n";
                }
            }
            
            // Add files if any
            foreach ($files as $field_name => $file_path) {
                if (file_exists($file_path)) {
                    $file_content = file_get_contents($file_path); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
                    $file_name = basename($file_path);
                    $mime_type = mime_content_type($file_path);
                    
                    $body .= '--' . $boundary . "\r\n";
                    $body .= 'Content-Disposition: form-data; name="' . $field_name . '"; filename="' . $file_name . '"' . "\r\n";
                    $body .= 'Content-Type: ' . $mime_type . "\r\n\r\n";
                    $body .= $file_content . "\r\n";
                }
            }
            
            $body .= '--' . $boundary . '--';
            $args['body'] = $body;
            
        } elseif ($method === 'GET' && !empty($data)) {
            // For GET requests, append data as query params
            $url = add_query_arg($data, $url);
        }
        
        // Execute request with optional retry for transient failures
        return $this->execute_request_with_retry( $url, $args, $endpoint, $method, $data );
    }
    
    /**
     * Execute HTTP request with single retry for transient failures
     * 
     * @param string $url Full request URL
     * @param array $args wp_remote_request arguments
     * @param string $endpoint API endpoint (for logging)
     * @param string $method HTTP method
     * @param array $data Request data (for logging)
     * @return array|WP_Error
     */
    private function execute_request_with_retry( $url, $args, $endpoint, $method, $data ) {
        $start_time = microtime(true);
        $attempt = 1;
        $max_attempts = in_array( $method, self::$idempotent_methods, true ) ? 2 : 1;
        
        while ( $attempt <= $max_attempts ) {
            $response = wp_remote_request( $url, $args );
            $execution_time = microtime(true) - $start_time;
            
            // Handle WP_Error (network failures, timeouts)
            if ( is_wp_error( $response ) ) {
                $error_message = $response->get_error_message();
                
                // Log and possibly retry
                if ( $attempt < $max_attempts && $this->is_transient_error( 0, $error_message ) ) {
                    $attempt++;
                    usleep( 500000 ); // 500ms delay before retry
                    continue;
                }
                
                // Final failure - log and return
                PushRelay_Debug_Logger::log_api_request(
                    $endpoint,
                    $method,
                    0,
                    $data,
                    null,
                    $error_message,
                    $execution_time
                );
                
                return $response;
            }
            
            $body = wp_remote_retrieve_body( $response );
            $code = wp_remote_retrieve_response_code( $response );
            
            // Handle invalid JSON gracefully
            $decoded = $this->safe_json_decode( $body );
            
            // Log API request
            PushRelay_Debug_Logger::log_api_request(
                $endpoint,
                $method,
                $code,
                $data,
                $decoded,
                ( $code >= 400 ) ? $body : null,
                $execution_time
            );
            
            // Check if we should retry (5xx errors, idempotent methods only)
            if ( $attempt < $max_attempts && $this->is_transient_error( $code, null ) ) {
                $attempt++;
                usleep( 500000 ); // 500ms delay before retry
                continue;
            }
            
            // Process response
            return $this->process_response( $code, $decoded, $body, $url, $method, $data );
        }
        
        // Should not reach here, but safety fallback
        return new WP_Error( 'api_error', __( 'API request failed', 'pushrelay' ) );
    }
    
    /**
     * Check if error is transient and may be retried
     * 
     * @param int $status_code HTTP status code
     * @param string|null $error_message Error message
     * @return bool
     */
    private function is_transient_error( $status_code, $error_message = null ) {
        // Network errors (status 0)
        if ( $status_code === 0 ) {
            return true;
        }
        
        // 5xx server errors
        if ( in_array( $status_code, self::$transient_errors, true ) ) {
            return true;
        }
        
        // Check for timeout in error message
        if ( $error_message && stripos( $error_message, 'timeout' ) !== false ) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Safely decode JSON, returning empty array on failure
     * 
     * @param string $body Response body
     * @return array|null Decoded JSON or null
     */
    private function safe_json_decode( $body ) {
        if ( empty( $body ) ) {
            return null;
        }
        
        $decoded = json_decode( $body, true );
        
        // Check for JSON decode errors
        if ( json_last_error() !== JSON_ERROR_NONE ) {
            return null;
        }
        
        return $decoded;
    }
    
    /**
     * Process API response and return result or WP_Error
     * 
     * @param int $code HTTP status code
     * @param array|null $decoded Decoded JSON response
     * @param string $body Raw response body
     * @param string $url Request URL
     * @param string $method HTTP method
     * @param array $data Request data
     * @return array|WP_Error
     */
    private function process_response( $code, $decoded, $body, $url, $method, $data ) {
        // Success response
        if ( $code >= 200 && $code < 300 ) {
            return $decoded !== null ? $decoded : array();
        }
        
        // Handle rate limiting (429) - set backoff and return specific error
        if ( $code === 429 ) {
            $this->set_rate_limit_backoff();
            return new WP_Error( 
                'rate_limited', 
                __( 'API rate limit exceeded. Requests paused temporarily.', 'pushrelay' ),
                array( 'status_code' => 429, 'retry_after' => self::RATE_LIMIT_BACKOFF_SECONDS )
            );
        }
        
        // Build error message
        /* translators: %d: HTTP status code */
        $error_message = sprintf( __( 'API returned error code %d', 'pushrelay' ), $code );
        
        if ( $decoded !== null && isset( $decoded['message'] ) ) {
            $error_message .= ': ' . $decoded['message'];
        } elseif ( $decoded !== null && isset( $decoded['error'] ) ) {
            $error_message .= ': ' . $decoded['error'];
        } elseif ( ! empty( $body ) ) {
            // Include raw body for debugging (truncated)
            $error_message .= ' - Response: ' . substr( $body, 0, 200 );
        }
        
        // Debug logging (only when WP_DEBUG and WP_DEBUG_LOG are enabled)
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
            // phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_error_log
            // phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_print_r
            error_log( 'PushRelay API Error: ' . $error_message );
            error_log( 'PushRelay API URL: ' . $url );
            error_log( 'PushRelay API Method: ' . $method );
            error_log( 'PushRelay API Data: ' . print_r( $data, true ) );
            error_log( 'PushRelay API Response Body: ' . $body );
            // phpcs:enable
        }
        
        return new WP_Error( 'api_error', $error_message, array( 'status_code' => $code ) );
    }
    
    /**
     * Check if API is currently rate limited
     * 
     * @return bool True if rate limited
     */
    private function is_rate_limited() {
        return (bool) get_transient( self::RATE_LIMIT_TRANSIENT );
    }
    
    /**
     * Set rate limit backoff
     */
    private function set_rate_limit_backoff() {
        set_transient( self::RATE_LIMIT_TRANSIENT, time(), self::RATE_LIMIT_BACKOFF_SECONDS );
        
        PushRelay_Debug_Logger::log(
            sprintf( 'API rate limit detected. Backing off for %d seconds.', self::RATE_LIMIT_BACKOFF_SECONDS ),
            PushRelay_Debug_Logger::LEVEL_WARNING,
            array( 'backoff_seconds' => self::RATE_LIMIT_BACKOFF_SECONDS )
        );
    }
    
    /**
     * Test API connection
     */
    public function test_connection() {
        $result = $this->get_user();
        
        if (is_wp_error($result)) {
            return array(
                'success' => false,
                'message' => $result->get_error_message()
            );
        }
        
        return array(
            'success' => true,
            'message' => __('API connection successful', 'pushrelay'),
            'data' => $result
        );
    }
    
    /**
     * Get user info
     */
    public function get_user() {
        return $this->request('/user');
    }
    
    /**
     * Get websites with auto-detection
     */
    public function get_websites($page = 1, $per_page = 25) {
        return $this->request('/websites/', 'GET', array(
            'page' => absint($page),
            'results_per_page' => absint($per_page)
        ));
    }
    
    /**
     * Get single website
     * 
     * @param int $website_id Website ID (required, must be > 0)
     * @return array|WP_Error
     */
    public function get_website($website_id) {
        $website_id = absint( $website_id );
        if ( $website_id === 0 ) {
            return new WP_Error( 'invalid_parameter', __( 'Website ID is required', 'pushrelay' ) );
        }
        return $this->request('/websites/' . $website_id);
    }
    
    /**
     * Update website settings (including widget configuration)
     * 
     * @param int $website_id Website ID (required, must be > 0)
     * @param array $data Website data to update
     * @return array|WP_Error
     */
    public function update_website($website_id, $data) {
        $website_id = absint( $website_id );
        if ( $website_id === 0 ) {
            return new WP_Error( 'invalid_parameter', __( 'Website ID is required', 'pushrelay' ) );
        }
        return $this->request('/websites/' . $website_id, 'POST', $data);
    }
    
    /**
     * Delete website
     * 
     * @param int $website_id Website ID (required, must be > 0)
     * @return array|WP_Error
     */
    public function delete_website($website_id) {
        $website_id = absint( $website_id );
        if ( $website_id === 0 ) {
            return new WP_Error( 'invalid_parameter', __( 'Website ID is required', 'pushrelay' ) );
        }
        return $this->request('/websites/' . $website_id, 'DELETE');
    }
    
    /**
     * Auto-detect and suggest website from API
     */
    public function auto_detect_website() {
        $parsed_url = wp_parse_url(get_site_url());
        $current_domain = isset($parsed_url['host']) ? $parsed_url['host'] : '';
        
        // Get all websites
        $websites = $this->get_websites(1, 100);
        
        if (is_wp_error($websites)) {
            return $websites;
        }
        
        if (empty($websites['data'])) {
            return new WP_Error('no_websites', __('No websites found in your PushRelay account', 'pushrelay'));
        }
        
        // Try to find matching website
        $matches = array();
        $exact_match = null;
        
        foreach ($websites['data'] as $website) {
            $api_host = isset($website['host']) ? $website['host'] : '';
            
            // Remove www. for comparison
            $api_host_clean = str_replace('www.', '', $api_host);
            $current_domain_clean = str_replace('www.', '', $current_domain);
            
            // Exact match
            if ($api_host_clean === $current_domain_clean) {
                $exact_match = $website;
                break;
            }
            
            // Partial match
            if (strpos($api_host_clean, $current_domain_clean) !== false || 
                strpos($current_domain_clean, $api_host_clean) !== false) {
                $matches[] = $website;
            }
        }
        
        if ($exact_match) {
            return array(
                'match_type' => 'exact',
                'website' => $exact_match,
                'all_websites' => $websites['data']
            );
        }
        
        if (!empty($matches)) {
            return array(
                'match_type' => 'partial',
                'website' => $matches[0],
                'matches' => $matches,
                'all_websites' => $websites['data']
            );
        }
        
        return array(
            'match_type' => 'none',
            'all_websites' => $websites['data']
        );
    }
    
    /**
     * Get subscribers
     */
    public function get_subscribers($page = 1, $per_page = 25) {
        return $this->request('/subscribers/', 'GET', array(
            'page' => absint($page),
            'results_per_page' => absint($per_page)
        ));
    }
    
    /**
     * Get subscriber
     * 
     * @param int $subscriber_id Subscriber ID (required, must be > 0)
     * @return array|WP_Error
     */
    public function get_subscriber($subscriber_id) {
        $subscriber_id = absint( $subscriber_id );
        if ( $subscriber_id === 0 ) {
            return new WP_Error( 'invalid_parameter', __( 'Subscriber ID is required', 'pushrelay' ) );
        }
        return $this->request('/subscribers/' . $subscriber_id);
    }
    
    /**
     * Update subscriber
     * 
     * @param int $subscriber_id Subscriber ID (required, must be > 0)
     * @param array $custom_parameters Custom parameters to update
     * @return array|WP_Error
     */
    public function update_subscriber($subscriber_id, $custom_parameters = array()) {
        $subscriber_id = absint( $subscriber_id );
        if ( $subscriber_id === 0 ) {
            return new WP_Error( 'invalid_parameter', __( 'Subscriber ID is required', 'pushrelay' ) );
        }
        
        $data = array();
        
        foreach ($custom_parameters as $index => $param) {
            if (isset($param['key']) && isset($param['value'])) {
                $data['custom_parameter_key[' . $index . ']'] = sanitize_text_field($param['key']);
                $data['custom_parameter_value[' . $index . ']'] = sanitize_text_field($param['value']);
            }
        }
        
        return $this->request('/subscribers/' . $subscriber_id, 'POST', $data);
    }
    
    /**
     * Delete subscriber
     * 
     * @param int $subscriber_id Subscriber ID (required, must be > 0)
     * @return array|WP_Error
     */
    public function delete_subscriber($subscriber_id) {
        $subscriber_id = absint( $subscriber_id );
        if ( $subscriber_id === 0 ) {
            return new WP_Error( 'invalid_parameter', __( 'Subscriber ID is required', 'pushrelay' ) );
        }
        return $this->request('/subscribers/' . $subscriber_id, 'DELETE');
    }
    
    /**
     * Get campaigns
     */
    public function get_campaigns($page = 1, $per_page = 25) {
        // Note: API doesn't support sorting parameters
        // Client-side sorting is done in views/campaigns.php
        return $this->request('/campaigns/', 'GET', array(
            'page' => absint($page),
            'results_per_page' => absint($per_page)
        ));
    }
    
    /**
     * Get campaign
     * 
     * @param int $campaign_id Campaign ID (required, must be > 0)
     * @return array|WP_Error
     */
    public function get_campaign($campaign_id) {
        $campaign_id = absint( $campaign_id );
        if ( $campaign_id === 0 ) {
            return new WP_Error( 'invalid_parameter', __( 'Campaign ID is required', 'pushrelay' ) );
        }
        return $this->request('/campaigns/' . $campaign_id);
    }
    
    /**
     * Create campaign
     * 
     * @param array $data Campaign data
     * @return array|WP_Error
     */
    public function create_campaign($data) {
        // Validate required website_id
        $website_id = isset( $data['website_id'] ) ? absint( $data['website_id'] ) : 0;
        if ( $website_id === 0 ) {
            return new WP_Error( 'invalid_parameter', __( 'Website ID is required to create campaign', 'pushrelay' ) );
        }
        
        // All values must be strings for multipart/form-data
        $sanitized_data = array(
            'website_id' => strval($website_id),
            'name' => sanitize_text_field($data['name']),
            'title' => sanitize_text_field($data['title']),
            'description' => sanitize_textarea_field($data['description']),
        );
        
        // Optional fields
        if (!empty($data['url'])) {
            $sanitized_data['url'] = esc_url_raw($data['url']);
        }
        
        if (!empty($data['segment'])) {
            $sanitized_data['segment'] = sanitize_text_field($data['segment']);
        }
        
        if (isset($data['subscribers_ids']) && is_array($data['subscribers_ids'])) {
            $sanitized_data['subscribers_ids'] = implode(',', array_map('absint', $data['subscribers_ids']));
        }
        
        // Buttons
        if (!empty($data['button_title_1'])) {
            $sanitized_data['button_title_1'] = sanitize_text_field($data['button_title_1']);
        }
        if (!empty($data['button_url_1'])) {
            $sanitized_data['button_url_1'] = esc_url_raw($data['button_url_1']);
        }
        if (!empty($data['button_title_2'])) {
            $sanitized_data['button_title_2'] = sanitize_text_field($data['button_title_2']);
        }
        if (!empty($data['button_url_2'])) {
            $sanitized_data['button_url_2'] = esc_url_raw($data['button_url_2']);
        }
        
        // Settings - API expects '1' or '0' for boolean values
        if (isset($data['is_scheduled'])) {
            $sanitized_data['is_scheduled'] = $data['is_scheduled'] ? '1' : '0';
        }
        if (!empty($data['scheduled_datetime'])) {
            $sanitized_data['scheduled_datetime'] = sanitize_text_field($data['scheduled_datetime']);
        }
        if (isset($data['is_silent'])) {
            $sanitized_data['is_silent'] = $data['is_silent'] ? '1' : '0';
        }
        if (isset($data['is_auto_hide'])) {
            $sanitized_data['is_auto_hide'] = $data['is_auto_hide'] ? '1' : '0';
        }
        if (!empty($data['ttl'])) {
            $sanitized_data['ttl'] = strval(absint($data['ttl']));
        }
        
        // UTM parameters
        if (!empty($data['utm_source'])) {
            $sanitized_data['utm_source'] = sanitize_text_field($data['utm_source']);
        }
        if (!empty($data['utm_medium'])) {
            $sanitized_data['utm_medium'] = sanitize_text_field($data['utm_medium']);
        }
        if (!empty($data['utm_campaign'])) {
            $sanitized_data['utm_campaign'] = sanitize_text_field($data['utm_campaign']);
        }
        
        // Filters for segmentation
        if (!empty($data['filters_subscribed_on_url'])) {
            $sanitized_data['filters_subscribed_on_url'] = esc_url_raw($data['filters_subscribed_on_url']);
        }
        if (!empty($data['filters_cities'])) {
            $sanitized_data['filters_cities'] = sanitize_text_field($data['filters_cities']);
        }
        if (!empty($data['filters_countries']) && is_array($data['filters_countries'])) {
            $sanitized_data['filters_countries'] = array_map('sanitize_text_field', $data['filters_countries']);
        }
        if (!empty($data['filters_continents']) && is_array($data['filters_continents'])) {
            $sanitized_data['filters_continents'] = array_map('sanitize_text_field', $data['filters_continents']);
        }
        if (!empty($data['filters_device_type']) && is_array($data['filters_device_type'])) {
            $sanitized_data['filters_device_type'] = array_map('sanitize_text_field', $data['filters_device_type']);
        }
        if (!empty($data['filters_languages']) && is_array($data['filters_languages'])) {
            $sanitized_data['filters_languages'] = array_map('sanitize_text_field', $data['filters_languages']);
        }
        if (!empty($data['filters_operating_systems']) && is_array($data['filters_operating_systems'])) {
            $sanitized_data['filters_operating_systems'] = array_map('sanitize_text_field', $data['filters_operating_systems']);
        }
        if (!empty($data['filters_browsers']) && is_array($data['filters_browsers'])) {
            $sanitized_data['filters_browsers'] = array_map('sanitize_text_field', $data['filters_browsers']);
        }
        
        // NOTE: API only accepts 'image' as file upload, not URL
        // For auto-notifications, images are not supported via URL
        // To use images, the image must be uploaded as a file via 'image_path'
        
        // Custom parameter filters
        if (!empty($data['filters_custom_parameter_key']) && is_array($data['filters_custom_parameter_key'])) {
            foreach ($data['filters_custom_parameter_key'] as $index => $key) {
                $sanitized_data['filters_custom_parameter_key[' . $index . ']'] = sanitize_text_field($key);
                
                if (isset($data['filters_custom_parameter_condition'][$index])) {
                    $sanitized_data['filters_custom_parameter_condition[' . $index . ']'] = sanitize_text_field($data['filters_custom_parameter_condition'][$index]);
                }
                
                if (isset($data['filters_custom_parameter_value'][$index])) {
                    $sanitized_data['filters_custom_parameter_value[' . $index . ']'] = sanitize_text_field($data['filters_custom_parameter_value'][$index]);
                }
            }
        }
        
        // Action flags - API expects '1' for true values
        if (!empty($data['send'])) {
            $sanitized_data['send'] = '1';
        }
        if (!empty($data['save'])) {
            $sanitized_data['save'] = '1';
        }
        
        // Handle image upload
        $files = array();
        if (!empty($data['image_path']) && file_exists($data['image_path'])) {
            $files['image'] = $data['image_path'];
        }
        
        return $this->request('/campaigns', 'POST', $sanitized_data, $files);
    }
    
    /**
     * Send campaign (trigger sending of an existing campaign)
     * 
     * According to API docs, campaigns are sent by updating with send=1
     * 
     * @param int $campaign_id Campaign ID (required, must be > 0)
     * @return array|WP_Error
     */
    public function send_campaign($campaign_id) {
        $campaign_id = absint( $campaign_id );
        if ( $campaign_id === 0 ) {
            return new WP_Error( 'invalid_parameter', __( 'Campaign ID is required', 'pushrelay' ) );
        }
        return $this->request('/campaigns/' . $campaign_id, 'POST', array('send' => '1'));
    }
    
    /**
     * Update campaign
     * 
     * @param int $campaign_id Campaign ID (required, must be > 0)
     * @param array $data Campaign data to update
     * @return array|WP_Error
     */
    public function update_campaign($campaign_id, $data) {
        $campaign_id = absint( $campaign_id );
        if ( $campaign_id === 0 ) {
            return new WP_Error( 'invalid_parameter', __( 'Campaign ID is required', 'pushrelay' ) );
        }
        
        $sanitized_data = array();
        
        // Only include fields that are being updated
        if (isset($data['name'])) {
            $sanitized_data['name'] = sanitize_text_field($data['name']);
        }
        if (isset($data['title'])) {
            $sanitized_data['title'] = sanitize_text_field($data['title']);
        }
        if (isset($data['description'])) {
            $sanitized_data['description'] = sanitize_textarea_field($data['description']);
        }
        if (isset($data['url'])) {
            $sanitized_data['url'] = esc_url_raw($data['url']);
        }
        if (isset($data['segment'])) {
            $sanitized_data['segment'] = sanitize_text_field($data['segment']);
        }
        
        // Handle image upload
        $files = array();
        if (!empty($data['image_path']) && file_exists($data['image_path'])) {
            $files['image'] = $data['image_path'];
        }
        
        return $this->request('/campaigns/' . $campaign_id, 'POST', $sanitized_data, $files);
    }
    
    /**
     * Delete campaign
     * 
     * @param int $campaign_id Campaign ID (required, must be > 0)
     * @return array|WP_Error
     */
    public function delete_campaign($campaign_id) {
        $campaign_id = absint( $campaign_id );
        if ( $campaign_id === 0 ) {
            return new WP_Error( 'invalid_parameter', __( 'Campaign ID is required', 'pushrelay' ) );
        }
        return $this->request('/campaigns/' . $campaign_id, 'DELETE');
    }
    
    /**
     * Get subscriber statistics
     * 
     * @param int $website_id Website ID (required, must be > 0)
     * @param string $start_date Start date (Y-m-d format)
     * @param string $end_date End date (Y-m-d format)
     * @param string $type Statistics type
     * @return array|WP_Error
     */
    public function get_subscriber_statistics($website_id, $start_date, $end_date, $type = 'overview') {
        // Validate website_id - prevent calls like /subscribers-statistics/0
        $website_id = absint( $website_id );
        if ( $website_id === 0 ) {
            PushRelay_Debug_Logger::log(
                'Subscriber statistics skipped: invalid website_id (0)',
                PushRelay_Debug_Logger::LEVEL_NOTICE,
                array( 'start_date' => $start_date, 'end_date' => $end_date, 'type' => $type )
            );
            return new WP_Error( 
                'invalid_parameter', 
                __( 'Website ID is required for subscriber statistics', 'pushrelay' ),
                array( 'parameter' => 'website_id' )
            );
        }
        
        // Validate dates
        if ( empty( $start_date ) || empty( $end_date ) ) {
            PushRelay_Debug_Logger::log(
                'Subscriber statistics skipped: missing date parameters',
                PushRelay_Debug_Logger::LEVEL_NOTICE,
                array( 'website_id' => $website_id, 'start_date' => $start_date, 'end_date' => $end_date )
            );
            return new WP_Error(
                'invalid_parameter',
                __( 'Start date and end date are required', 'pushrelay' ),
                array( 'parameter' => 'dates' )
            );
        }
        
        $endpoint = sprintf(
            '/subscribers-statistics/%d',
            $website_id
        );
        
        $params = array(
            'start_date' => sanitize_text_field($start_date),
            'end_date' => sanitize_text_field($end_date),
            'type' => sanitize_text_field($type)
        );
        
        return $this->request($endpoint, 'GET', $params);
    }
    
    /**
     * Get subscriber logs
     */
    public function get_subscriber_logs($params = array()) {
        $sanitized_params = array();
        
        if (!empty($params['page'])) {
            $sanitized_params['page'] = absint($params['page']);
        }
        if (!empty($params['results_per_page'])) {
            $sanitized_params['results_per_page'] = absint($params['results_per_page']);
        }
        if (!empty($params['website_id'])) {
            $sanitized_params['website_id'] = absint($params['website_id']);
        }
        if (!empty($params['campaign_id'])) {
            $sanitized_params['campaign_id'] = absint($params['campaign_id']);
        }
        if (!empty($params['subscriber_id'])) {
            $sanitized_params['subscriber_id'] = absint($params['subscriber_id']);
        }
        if (!empty($params['search'])) {
            $sanitized_params['search'] = sanitize_text_field($params['search']);
        }
        if (!empty($params['search_by'])) {
            $sanitized_params['search_by'] = sanitize_text_field($params['search_by']);
        }
        
        return $this->request('/subscribers-logs/', 'GET', $sanitized_params);
    }
    
    /**
     * Get personal notifications
     */
    public function get_personal_notifications($page = 1, $per_page = 25) {
        return $this->request('/personal-notifications/', 'GET', array(
            'page' => absint($page),
            'results_per_page' => absint($per_page)
        ));
    }
    
    /**
     * Get personal notification
     * 
     * @param int $notification_id Notification ID (required, must be > 0)
     * @return array|WP_Error
     */
    public function get_personal_notification($notification_id) {
        $notification_id = absint( $notification_id );
        if ( $notification_id === 0 ) {
            return new WP_Error( 'invalid_parameter', __( 'Notification ID is required', 'pushrelay' ) );
        }
        return $this->request('/personal-notifications/' . $notification_id);
    }
}