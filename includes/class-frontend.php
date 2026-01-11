<?php
/**
 * Frontend Class
 * 
 * Handles all frontend functionality including pixel code injection,
 * widget display, and subscription management
 * 
 * @package PushRelay
 * @since 1.6.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class PushRelay_Frontend {
    
    /**
     * Constructor
     */
    public function __construct() {
        add_action('wp_head', array($this, 'add_pixel_code'), 1);
        add_action('wp_footer', array($this, 'add_widget_script'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        
        // Meta box for post editor - send push notification checkbox
        add_action('add_meta_boxes', array($this, 'add_post_meta_box'));
        add_action('save_post', array($this, 'save_post_meta_box'), 10, 1);
        
        // AJAX handlers for frontend
        add_action('wp_ajax_pushrelay_subscribe', array($this, 'ajax_subscribe'));
        add_action('wp_ajax_nopriv_pushrelay_subscribe', array($this, 'ajax_subscribe'));
    }
    
    /**
     * Enqueue frontend scripts
     */
    public function enqueue_scripts() {
        $settings = get_option('pushrelay_settings', array());
        
        // Only load if configured
        if (empty($settings['website_id'])) {
            return;
        }
        
        // Frontend CSS
        wp_enqueue_style(
            'pushrelay-frontend',
            PUSHRELAY_PLUGIN_URL . 'assets/css/frontend.css',
            array(),
            PUSHRELAY_VERSION
        );
        
        // Frontend JS
        wp_enqueue_script(
            'pushrelay-frontend',
            PUSHRELAY_PLUGIN_URL . 'assets/js/frontend.js',
            array('jquery'),
            PUSHRELAY_VERSION,
            true
        );
        
        // Get service worker URL
        $sw = new PushRelay_Service_Worker();
        $sw_url = $sw->get_service_worker_url();
        
        // Localize script with configuration
        wp_localize_script('pushrelay-frontend', 'pushrelayFrontend', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('pushrelay_frontend_nonce'),
            'websiteId' => absint($settings['website_id']),
            'serviceWorkerUrl' => esc_url($sw_url),
            'strings' => array(
                'subscribe_success' => __('Successfully subscribed to notifications!', 'pushrelay'),
                'subscribe_error' => __('Failed to subscribe. Please try again.', 'pushrelay'),
                'already_subscribed' => __('You are already subscribed.', 'pushrelay'),
                'permission_denied' => __('Notification permission was denied.', 'pushrelay'),
            ),
        ));
    }
    
    /**
     * Add PushRelay pixel code to head
     */
    public function add_pixel_code() {
        $settings = get_option('pushrelay_settings', array());
        
        // Check if website is configured - need both website_id and pixel_key
        if (empty($settings['website_id']) || empty($settings['pixel_key'])) {
            return;
        }
        
        $pixel_key = sanitize_text_field($settings['pixel_key']);
        
        // Output the PushRelay pixel code using pixel_key (not website_id)
        // This is an intentional external script for push notification functionality
        echo "\n<!-- PushRelay Pixel Code - https://pushrelay.com/ -->\n";
        // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- External tracking pixel required for push notifications
        echo '<script defer src="https://pushrelay.com/pixel/' . esc_attr($pixel_key) . '"></script>';
        echo "\n<!-- END PushRelay Pixel Code -->\n";
        
        // Log pixel injection
        if (!empty($settings['debug_mode'])) {
            PushRelay_Debug_Logger::log(
                'Pixel code injected on page: ' . get_permalink(),
                'debug',
                array('pixel_key' => $pixel_key)
            );
        }
    }
    
    /**
     * Add widget script to footer
     */
    public function add_widget_script() {
        $settings = get_option('pushrelay_settings', array());
        
        if (empty($settings['website_id'])) {
            return;
        }
        
        // Add any custom widget initialization here
        do_action('pushrelay_before_widget_script');
        
        // You can add custom JavaScript here if needed
        ?>
        <script>
        // PushRelay Widget Initialization
        (function() {
            if (typeof PushRelay !== 'undefined') {
                // Widget is loaded, you can customize it here
                <?php do_action('pushrelay_widget_init_script'); ?>
            }
        })();
        </script>
        <?php
        
        do_action('pushrelay_after_widget_script');
    }
    
    /**
     * Get subscription button HTML
     * 
     * @param array $args Button arguments
     * @return string
     */
    public function get_subscribe_button($args = array()) {
        $defaults = array(
            'text' => __('Subscribe to Notifications', 'pushrelay'),
            'subscribed_text' => __('Subscribed ✓', 'pushrelay'),
            'class' => 'pushrelay-subscribe-btn',
            'show_icon' => true,
            'icon_position' => 'left',
            'style' => '',
        );
        
        $args = wp_parse_args($args, $defaults);
        
        $classes = array('pushrelay-subscribe-button');
        if (!empty($args['class'])) {
            $classes[] = sanitize_html_class($args['class']);
        }
        
        $icon_html = '';
        if ($args['show_icon']) {
            $icon_html = '<span class="pushrelay-bell-icon">🔔</span>';
        }
        
        $style_attr = !empty($args['style']) ? ' style="' . esc_attr($args['style']) . '"' : '';
        
        $button_html = '<button class="' . esc_attr(implode(' ', $classes)) . '" data-subscribed-text="' . esc_attr($args['subscribed_text']) . '"' . $style_attr . '>';
        
        if ($args['icon_position'] === 'left') {
            $button_html .= $icon_html . ' ';
        }
        
        $button_html .= '<span class="pushrelay-button-text">' . esc_html($args['text']) . '</span>';
        
        if ($args['icon_position'] === 'right') {
            $button_html .= ' ' . $icon_html;
        }
        
        $button_html .= '</button>';
        
        return $button_html;
    }
    
    /**
     * Display subscription widget
     * 
     * @param array $args Widget arguments
     */
    public function display_widget($args = array()) {
        $defaults = array(
            'title' => __('Get Notifications', 'pushrelay'),
            'description' => __('Subscribe to receive push notifications about new content and updates.', 'pushrelay'),
            'button_text' => __('Subscribe Now', 'pushrelay'),
            'show_subscriber_count' => true,
            'show_privacy_note' => true,
            'container_class' => 'pushrelay-widget',
        );
        
        $args = wp_parse_args($args, $defaults);
        
        $settings = get_option('pushrelay_settings', array());
        if (empty($settings['website_id'])) {
            return;
        }
        
        ?>
        <div class="<?php echo esc_attr($args['container_class']); ?>" id="pushrelay-subscription-widget">
            <div class="pushrelay-widget-inner">
                <?php if (!empty($args['title'])): ?>
                    <h3 class="pushrelay-widget-title"><?php echo esc_html($args['title']); ?></h3>
                <?php endif; ?>
                
                <?php if (!empty($args['description'])): ?>
                    <p class="pushrelay-widget-description"><?php echo esc_html($args['description']); ?></p>
                <?php endif; ?>
                
                <div class="pushrelay-widget-actions">
                    <?php echo $this->get_subscribe_button(array('text' => $args['button_text'])); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                </div>
                
                <?php if ($args['show_subscriber_count']): ?>
                    <div class="pushrelay-subscriber-count">
                        <span class="pushrelay-count-icon">👥</span>
                        <span class="pushrelay-count-text">
                            <?php
                            $count = $this->get_subscriber_count();
                            printf(
                                /* translators: %s: number of subscribers */
                                esc_html(_n('%s subscriber', '%s subscribers', $count, 'pushrelay')),
                                '<strong>' . esc_html(number_format_i18n($count)) . '</strong>'
                            );
                            ?>
                        </span>
                    </div>
                <?php endif; ?>
                
                <?php if ($args['show_privacy_note']): ?>
                    <p class="pushrelay-privacy-note">
                        <?php esc_html_e('You can unsubscribe at any time. We respect your privacy.', 'pushrelay'); ?>
                    </p>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }
    
    /**
     * Get subscriber count
     * 
     * @return int
     */
    private function get_subscriber_count() {
        $settings = get_option('pushrelay_settings', array());
        
        if (empty($settings['website_id'])) {
            return 0;
        }
        
        // Check cache first
        $cache_key = 'pushrelay_subscriber_count_' . $settings['website_id'];
        $cached_count = get_transient($cache_key);
        
        if ($cached_count !== false) {
            return absint($cached_count);
        }
        
        // Fetch from API
        $api = pushrelay()->get_api_client();
        $website = $api->get_website($settings['website_id']);
        
        $count = 0;
        if (!is_wp_error($website) && isset($website['data']['total_subscribers'])) {
            $count = absint($website['data']['total_subscribers']);
        }
        
        // Cache for 5 minutes
        set_transient($cache_key, $count, 5 * MINUTE_IN_SECONDS);
        
        return $count;
    }
    
    /**
     * Add custom user parameters for tracking
     * 
     * @param array $params Custom parameters
     */
    public function add_user_tracking_params($params = array()) {
        if (empty($params)) {
            return;
        }
        
        $settings = get_option('pushrelay_settings', array());
        if (empty($settings['website_id'])) {
            return;
        }
        
        // Sanitize parameters
        $clean_params = array();
        foreach ($params as $key => $value) {
            $clean_params[sanitize_key($key)] = sanitize_text_field($value);
        }
        
        // Add to page as data attribute or localStorage
        ?>
        <script>
        (function() {
            if (typeof PushRelay !== 'undefined' && PushRelay.setCustomParameters) {
                PushRelay.setCustomParameters(<?php echo wp_json_encode($clean_params); ?>);
            }
        })();
        </script>
        <?php
    }
    
    /**
     * Track WooCommerce customer data
     */
    public function track_woocommerce_customer() {
        if (!class_exists('WooCommerce') || !is_user_logged_in()) {
            return;
        }
        
        $customer = new WC_Customer(get_current_user_id());
        
        $params = array(
            'customer_id' => $customer->get_id(),
            'customer_email' => $customer->get_email(),
            'customer_name' => $customer->get_first_name() . ' ' . $customer->get_last_name(),
            'total_orders' => $customer->get_order_count(),
            'total_spent' => $customer->get_total_spent(),
        );
        
        $this->add_user_tracking_params($params);
    }
    
    /**
     * Track page views for analytics
     */
    public function track_page_view() {
        $settings = get_option('pushrelay_settings', array());
        
        if (empty($settings['debug_mode'])) {
            return;
        }
        
        $page_data = array(
            'url' => get_permalink(),
            'title' => get_the_title(),
            'post_type' => get_post_type(),
            'timestamp' => current_time('mysql'),
        );
        
        PushRelay_Debug_Logger::log(
            'Page view tracked',
            'debug',
            $page_data
        );
    }
    
    /**
     * AJAX: Subscribe handler
     */
    public function ajax_subscribe() {
        check_ajax_referer('pushrelay_frontend_nonce', 'nonce');
        
        $subscription_data = isset($_POST['subscription']) ? $_POST['subscription'] : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        
        // Validate subscription data
        if (empty($subscription_data)) {
            wp_send_json_error(array(
                'message' => __('Invalid subscription data', 'pushrelay')
            ));
        }
        
        // Here you would normally send this to your API
        // For now, just log it
        PushRelay_Debug_Logger::log(
            'Frontend subscription attempted',
            'info',
            array(
                'user_id' => get_current_user_id(),
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
            )
        );
        
        wp_send_json_success(array(
            'message' => __('Successfully subscribed!', 'pushrelay')
        ));
    }
    
    /**
     * Check if user is subscribed
     * 
     * @return bool
     */
    public function is_user_subscribed() {
        // This would check against your API or local database
        // For now, return false
        return false;
    }
    
    /**
     * Get subscription status HTML
     */
    public function get_subscription_status_html() {
        $is_subscribed = $this->is_user_subscribed();
        
        if ($is_subscribed) {
            return '<div class="pushrelay-status pushrelay-subscribed">' .
                   '<span class="pushrelay-status-icon">✓</span> ' .
                   esc_html__('You are subscribed', 'pushrelay') .
                   '</div>';
        }
        
        return '<div class="pushrelay-status pushrelay-not-subscribed">' .
               '<span class="pushrelay-status-icon">🔔</span> ' .
               esc_html__('Not subscribed', 'pushrelay') .
               '</div>';
    }
    
    /**
     * Add subscription meta box to post editor
     */
    public function add_post_meta_box() {
        $settings = get_option('pushrelay_settings', array());
        
        if (empty($settings['auto_notifications'])) {
            return;
        }
        
        $post_types = isset($settings['notification_types']) ? $settings['notification_types'] : array('post');
        
        add_meta_box(
            'pushrelay_notification',
            __('Push Notification', 'pushrelay'),
            array($this, 'render_post_meta_box'),
            $post_types,
            'side',
            'high'
        );
    }
    
    /**
     * Render post meta box
     */
    public function render_post_meta_box($post) {
        wp_nonce_field('pushrelay_post_notification', 'pushrelay_post_notification_nonce');
        
        $send_notification = get_post_meta($post->ID, '_pushrelay_send_notification', true);
        $notification_sent = get_post_meta($post->ID, '_pushrelay_notification_sent', true);
        
        // Default to checked for new posts (empty meta means not yet set)
        $is_checked = ($send_notification === '' || $send_notification === '1');
        
        ?>
        <div class="pushrelay-post-notification-settings">
            <?php if ($notification_sent): ?>
                <div class="pushrelay-notification-sent-notice">
                    <span class="dashicons dashicons-yes-alt"></span>
                    <?php esc_html_e('Notification already sent', 'pushrelay'); ?>
                </div>
            <?php else: ?>
                <label>
                    <input type="checkbox" 
                           name="pushrelay_send_notification" 
                           value="1" 
                           <?php checked($is_checked); ?> />
                    <?php esc_html_e('Send push notification on publish', 'pushrelay'); ?>
                </label>
                
                <p class="description">
                    <?php esc_html_e('Check this to send a push notification when this post is published.', 'pushrelay'); ?>
                </p>
            <?php endif; ?>
        </div>
        <?php
    }
    
    /**
     * Save post meta box data
     */
    public function save_post_meta_box($post_id) {
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
    
    /**
     * Get widget settings from API
     */
    private function get_widget_settings() {
        $settings = get_option('pushrelay_settings', array());
        
        if (empty($settings['website_id'])) {
            return array();
        }
        
        // Cache widget settings
        $cache_key = 'pushrelay_widget_settings_' . $settings['website_id'];
        $cached_settings = get_transient($cache_key);
        
        if ($cached_settings !== false) {
            return $cached_settings;
        }
        
        // Fetch from API
        $api = pushrelay()->get_api_client();
        $website = $api->get_website($settings['website_id']);
        
        $widget_settings = array();
        if (!is_wp_error($website) && isset($website['data']['widget'])) {
            $widget_settings = $website['data']['widget'];
        }
        
        // Cache for 1 hour
        set_transient($cache_key, $widget_settings, HOUR_IN_SECONDS);
        
        return $widget_settings;
    }
}