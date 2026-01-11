<?php
/**
 * WooCommerce Integration Class
 * 
 * Handles WooCommerce-specific notifications and automation:
 * - Cart abandonment
 * - Back in stock
 * - Price drops
 * - Order status updates
 * - Product recommendations
 * 
 * @package PushRelay
 * @since 1.6.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class PushRelay_WooCommerce {
    
    /**
     * Constructor
     */
    public function __construct() {
        // Only run if WooCommerce is active
        if (!class_exists('WooCommerce')) {
            return;
        }
        
        // Cart abandonment tracking
        add_action('woocommerce_add_to_cart', array($this, 'track_cart_add'), 10, 6);
        add_action('woocommerce_cart_updated', array($this, 'track_cart_update'));
        
        // Order hooks
        add_action('woocommerce_new_order', array($this, 'handle_new_order'), 10, 1);
        add_action('woocommerce_order_status_changed', array($this, 'handle_order_status_change'), 10, 4);
        
        // Product hooks
        add_action('woocommerce_product_set_stock', array($this, 'handle_stock_change'), 10, 1);
        add_action('woocommerce_product_set_sale_price', array($this, 'handle_price_change'), 10, 1);
        add_action('woocommerce_new_product', array($this, 'handle_new_product'), 10, 1);
        
        // Customer tracking
        add_action('wp_footer', array($this, 'track_customer_data'));
        
        // Scheduled events
        add_action('pushrelay_woo_check_abandoned_carts', array($this, 'check_abandoned_carts'));
        add_action('pushrelay_woo_check_price_drops', array($this, 'check_price_drops'));
        
        // Schedule events if not already scheduled
        if (!wp_next_scheduled('pushrelay_woo_check_abandoned_carts')) {
            wp_schedule_event(time(), 'hourly', 'pushrelay_woo_check_abandoned_carts');
        }
        
        if (!wp_next_scheduled('pushrelay_woo_check_price_drops')) {
            wp_schedule_event(time(), 'daily', 'pushrelay_woo_check_price_drops');
        }
        
        // AJAX handlers
        add_action('wp_ajax_pushrelay_woo_get_settings', array($this, 'ajax_get_settings'));
        add_action('wp_ajax_pushrelay_woo_save_settings', array($this, 'ajax_save_settings'));
        add_action('wp_ajax_pushrelay_woo_get_stats', array($this, 'ajax_get_stats'));
        add_action('wp_ajax_nopriv_pushrelay_track_product_view', array($this, 'ajax_track_product_view'));
        add_action('wp_ajax_pushrelay_track_product_view', array($this, 'ajax_track_product_view'));
    }
    
    /**
     * Get WooCommerce settings
     */
    private function get_woo_settings() {
        $defaults = array(
            'cart_abandonment_enabled' => true,
            'cart_abandonment_delay' => 60, // minutes
            'back_in_stock_enabled' => true,
            'price_drop_enabled' => true,
            'new_product_enabled' => true,
            'order_status_enabled' => true,
            'order_statuses' => array('processing', 'completed', 'shipped'),
        );
        
        $settings = get_option('pushrelay_woo_settings', array());
        
        return wp_parse_args($settings, $defaults);
    }
    
    /**
     * Track cart addition
     */
    public function track_cart_add($cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data) {
        if (!is_user_logged_in()) {
            return;
        }
        
        $user_id = get_current_user_id();
        $cart_data = array(
            'user_id' => $user_id,
            'product_id' => $product_id,
            'quantity' => $quantity,
            'timestamp' => current_time('mysql'),
        );
        
        // Store cart data
        update_user_meta($user_id, '_pushrelay_cart_data', $cart_data);
        update_user_meta($user_id, '_pushrelay_cart_last_update', time());
        
        PushRelay_Debug_Logger::log(
            sprintf('Cart tracked for user %d: Product %d', $user_id, $product_id),
            'debug'
        );
    }
    
    /**
     * Track cart update
     */
    public function track_cart_update() {
        if (!is_user_logged_in()) {
            return;
        }
        
        $user_id = get_current_user_id();
        update_user_meta($user_id, '_pushrelay_cart_last_update', time());
    }
    
    /**
     * Check for abandoned carts
     */
    public function check_abandoned_carts() {
        $woo_settings = $this->get_woo_settings();
        
        if (empty($woo_settings['cart_abandonment_enabled'])) {
            return;
        }
        
        $delay_minutes = absint($woo_settings['cart_abandonment_delay']);
        $threshold_time = time() - ($delay_minutes * 60);
        
        global $wpdb;
        
        // Find users with abandoned carts
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $abandoned_carts = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DISTINCT user_id 
                FROM {$wpdb->usermeta} 
                WHERE meta_key = '_pushrelay_cart_last_update' 
                AND meta_value < %d 
                AND user_id NOT IN (
                    SELECT DISTINCT user_id 
                    FROM {$wpdb->usermeta} 
                    WHERE meta_key = '_pushrelay_cart_notification_sent'
                )
                LIMIT 50",
                $threshold_time
            )
        );
        
        if (empty($abandoned_carts)) {
            return;
        }
        
        foreach ($abandoned_carts as $cart) {
            $this->send_cart_abandonment_notification($cart->user_id);
        }
        
        PushRelay_Debug_Logger::log(
            sprintf('Processed %d abandoned cart notifications', count($abandoned_carts)),
            'info'
        );
    }
    
    /**
     * Send cart abandonment notification
     */
    private function send_cart_abandonment_notification($user_id) {
        $user = get_user_by('id', $user_id);
        
        if (!$user) {
            return;
        }
        
        $cart_data = get_user_meta($user_id, '_pushrelay_cart_data', true);
        
        if (empty($cart_data)) {
            return;
        }
        
        $product_id = isset($cart_data['product_id']) ? absint($cart_data['product_id']) : 0;
        $product = wc_get_product($product_id);
        
        if (!$product) {
            return;
        }
        
        $settings = get_option('pushrelay_settings', array());
        
        if (empty($settings['website_id'])) {
            return;
        }
        
        // Create campaign
        $campaign_data = array(
            'website_id' => $settings['website_id'],
            /* translators: %s: Customer display name */
            'name' => sprintf(__('Cart Abandonment: %s', 'pushrelay'), $user->display_name),
            /* translators: %s: Product name */
            'title' => sprintf(__('You left %s in your cart!', 'pushrelay'), $product->get_name()),
            /* translators: %s: Product name */
            'description' => sprintf(__('Complete your purchase now and get %s', 'pushrelay'), $product->get_name()),
            'url' => wc_get_cart_url(),
            'segment' => 'custom',
            'send' => true,
        );
        
        // Add product image
        $image_id = $product->get_image_id();
        if ($image_id) {
            $image_url = wp_get_attachment_url($image_id);
            if ($image_url) {
                $campaign_data['image_url'] = $image_url;
            }
        }
        
        // Queue notification
        $campaigns_class = new PushRelay_Campaigns();
        $campaigns_class->create_campaign($campaign_data);
        
        // Mark as sent
        update_user_meta($user_id, '_pushrelay_cart_notification_sent', current_time('mysql'));
        
        PushRelay_Debug_Logger::log(
            sprintf('Cart abandonment notification sent to user %d', $user_id),
            'success'
        );
    }
    
    /**
     * Handle new order
     */
    public function handle_new_order($order_id) {
        $woo_settings = $this->get_woo_settings();
        
        if (empty($woo_settings['order_status_enabled'])) {
            return;
        }
        
        $order = wc_get_order($order_id);
        
        if (!$order) {
            return;
        }
        
        // Clear cart abandonment flags for this user
        $user_id = $order->get_user_id();
        if ($user_id) {
            delete_user_meta($user_id, '_pushrelay_cart_notification_sent');
            delete_user_meta($user_id, '_pushrelay_cart_data');
        }
        
        PushRelay_Debug_Logger::log(
            sprintf('New order created: #%d', $order_id),
            'info'
        );
    }
    
    /**
     * Handle order status change
     */
    public function handle_order_status_change($order_id, $old_status, $new_status, $order) {
        $woo_settings = $this->get_woo_settings();
        
        if (empty($woo_settings['order_status_enabled'])) {
            return;
        }
        
        $enabled_statuses = isset($woo_settings['order_statuses']) ? $woo_settings['order_statuses'] : array();
        
        if (!in_array($new_status, $enabled_statuses, true)) {
            return;
        }
        
        $user_id = $order->get_user_id();
        
        if (!$user_id) {
            return;
        }
        
        $settings = get_option('pushrelay_settings', array());
        
        if (empty($settings['website_id'])) {
            return;
        }
        
        // Get status labels
        $statuses = wc_get_order_statuses();
        $status_label = isset($statuses['wc-' . $new_status]) ? $statuses['wc-' . $new_status] : ucfirst($new_status);
        
        // Create notification
        $campaign_data = array(
            'website_id' => $settings['website_id'],
            /* translators: 1: Order ID, 2: Order status */
            'name' => sprintf(__('Order Status: #%1$d - %2$s', 'pushrelay'), $order_id, $status_label),
            /* translators: 1: Order ID, 2: Order status */
            'title' => sprintf(__('Your order #%1$d is %2$s', 'pushrelay'), $order_id, strtolower($status_label)),
            'description' => $this->get_order_status_message($new_status, $order),
            'url' => $order->get_view_order_url(),
            'segment' => 'custom',
            'send' => true,
        );
        
        // Queue notification
        $campaigns_class = new PushRelay_Campaigns();
        $campaigns_class->create_campaign($campaign_data);
        
        PushRelay_Debug_Logger::log(
            sprintf('Order status notification sent: #%d - %s', $order_id, $new_status),
            'success'
        );
    }
    
    /**
     * Get order status message
     */
    private function get_order_status_message($status, $order) {
        switch ($status) {
            case 'processing':
                return __('We\'re preparing your order for shipment!', 'pushrelay');
                
            case 'completed':
                return __('Your order has been completed. Thank you!', 'pushrelay');
                
            case 'shipped':
            case 'on-hold':
                return __('Your order is on the way!', 'pushrelay');
                
            case 'cancelled':
                return __('Your order has been cancelled.', 'pushrelay');
                
            case 'refunded':
                return __('Your order has been refunded.', 'pushrelay');
                
            default:
                /* translators: %s: Order status */
                return sprintf(__('Order status updated to: %s', 'pushrelay'), $status);
        }
    }
    
    /**
     * Handle stock change
     */
    public function handle_stock_change($product) {
        $woo_settings = $this->get_woo_settings();
        
        if (empty($woo_settings['back_in_stock_enabled'])) {
            return;
        }
        
        // Check if product is now in stock
        if (!$product->is_in_stock()) {
            return;
        }
        
        // Check if it was previously out of stock
        $was_out_of_stock = get_post_meta($product->get_id(), '_pushrelay_was_out_of_stock', true);
        
        if (!$was_out_of_stock) {
            return;
        }
        
        // Send back in stock notification
        $this->send_back_in_stock_notification($product);
        
        // Clear the flag
        delete_post_meta($product->get_id(), '_pushrelay_was_out_of_stock');
    }
    
    /**
     * Send back in stock notification
     */
    private function send_back_in_stock_notification($product) {
        $settings = get_option('pushrelay_settings', array());
        
        if (empty($settings['website_id'])) {
            return;
        }
        
        $campaign_data = array(
            'website_id' => $settings['website_id'],
            /* translators: %s: Product name */
            'name' => sprintf(__('Back in Stock: %s', 'pushrelay'), $product->get_name()),
            /* translators: %s: Product name */
            'title' => sprintf(__('%s is back in stock!', 'pushrelay'), $product->get_name()),
            'description' => __('Get it now before it\'s gone again!', 'pushrelay'),
            'url' => get_permalink($product->get_id()),
            'segment' => 'all',
            'send' => true,
        );
        
        // Add product image
        $image_id = $product->get_image_id();
        if ($image_id) {
            $image_url = wp_get_attachment_url($image_id);
            if ($image_url) {
                $campaign_data['image_url'] = $image_url;
            }
        }
        
        // Queue notification
        $campaigns_class = new PushRelay_Campaigns();
        $campaigns_class->create_campaign($campaign_data);
        
        PushRelay_Debug_Logger::log(
            sprintf('Back in stock notification sent: %s', $product->get_name()),
            'success'
        );
    }
    
    /**
     * Handle price change
     */
    public function handle_price_change($product_id) {
        $woo_settings = $this->get_woo_settings();
        
        if (empty($woo_settings['price_drop_enabled'])) {
            return;
        }
        
        $product = wc_get_product($product_id);
        
        if (!$product) {
            return;
        }
        
        // Only send notification if price actually dropped
        $old_price = get_post_meta($product_id, '_pushrelay_last_price', true);
        $new_price = $product->get_sale_price();
        
        if (empty($old_price) || empty($new_price)) {
            update_post_meta($product_id, '_pushrelay_last_price', $product->get_regular_price());
            return;
        }
        
        if (floatval($new_price) >= floatval($old_price)) {
            update_post_meta($product_id, '_pushrelay_last_price', $new_price);
            return;
        }
        
        // Calculate discount percentage (with division by zero protection)
        $old_price_float = floatval($old_price);
        $discount_percent = $old_price_float > 0 ? round((($old_price_float - floatval($new_price)) / $old_price_float) * 100) : 0;
        
        $this->send_price_drop_notification($product, $discount_percent);
        
        update_post_meta($product_id, '_pushrelay_last_price', $new_price);
    }
    
    /**
     * Send price drop notification
     */
    private function send_price_drop_notification($product, $discount_percent) {
        $settings = get_option('pushrelay_settings', array());
        
        if (empty($settings['website_id'])) {
            return;
        }
        
        $campaign_data = array(
            'website_id' => $settings['website_id'],
            /* translators: %s: Product name */
            'name' => sprintf(__('Price Drop: %s', 'pushrelay'), $product->get_name()),
            /* translators: 1: Product name, 2: Discount percentage */
            'title' => sprintf(__('%1$s is now %2$d%% off!', 'pushrelay'), $product->get_name(), $discount_percent),
            /* translators: %d: Discount percentage */
            'description' => sprintf(__('Limited time offer - Save %d%% now!', 'pushrelay'), $discount_percent),
            'url' => get_permalink($product->get_id()),
            'segment' => 'all',
            'send' => true,
        );
        
        // Add product image
        $image_id = $product->get_image_id();
        if ($image_id) {
            $image_url = wp_get_attachment_url($image_id);
            if ($image_url) {
                $campaign_data['image_url'] = $image_url;
            }
        }
        
        // Queue notification
        $campaigns_class = new PushRelay_Campaigns();
        $campaigns_class->create_campaign($campaign_data);
        
        PushRelay_Debug_Logger::log(
            sprintf('Price drop notification sent: %s (%d%% off)', $product->get_name(), $discount_percent),
            'success'
        );
    }
    
    /**
     * Handle new product
     */
    public function handle_new_product($product_id) {
        $woo_settings = $this->get_woo_settings();
        
        if (empty($woo_settings['new_product_enabled'])) {
            return;
        }
        
        $product = wc_get_product($product_id);
        
        if (!$product) {
            return;
        }
        
        $settings = get_option('pushrelay_settings', array());
        
        if (empty($settings['website_id'])) {
            return;
        }
        
        $campaign_data = array(
            'website_id' => $settings['website_id'],
            /* translators: %s: Product name */
            'name' => sprintf(__('New Product: %s', 'pushrelay'), $product->get_name()),
            /* translators: %s: Product name */
            'title' => sprintf(__('New: %s', 'pushrelay'), $product->get_name()),
            'description' => wp_trim_words($product->get_short_description(), 15),
            'url' => get_permalink($product_id),
            'segment' => 'all',
            'send' => true,
        );
        
        // Add product image
        $image_id = $product->get_image_id();
        if ($image_id) {
            $image_url = wp_get_attachment_url($image_id);
            if ($image_url) {
                $campaign_data['image_url'] = $image_url;
            }
        }
        
        // Queue notification
        $campaigns_class = new PushRelay_Campaigns();
        $campaigns_class->create_campaign($campaign_data);
        
        PushRelay_Debug_Logger::log(
            sprintf('New product notification sent: %s', $product->get_name()),
            'success'
        );
    }
    
    /**
     * Track customer data on frontend
     */
    public function track_customer_data() {
        if (!is_user_logged_in()) {
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
        
        // Add to page
        ?>
        <script>
        if (typeof PushRelay !== 'undefined' && PushRelay.setCustomParameters) {
            PushRelay.setCustomParameters(<?php echo wp_json_encode($params); ?>);
        }
        </script>
        <?php
    }
    
    /**
     * Get WooCommerce statistics
     */
    public function get_woo_stats() {
        global $wpdb;
        
        $stats = array(
            'abandoned_carts' => 0,
            'notifications_sent' => 0,
            'revenue_recovered' => 0,
        );
        
        // Get abandoned carts count
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $abandoned = $wpdb->get_var(
            "SELECT COUNT(DISTINCT user_id) 
            FROM {$wpdb->usermeta} 
            WHERE meta_key = '_pushrelay_cart_last_update'"
        );
        
        $stats['abandoned_carts'] = $abandoned ? absint($abandoned) : 0;
        
        // Get notifications sent
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $sent = $wpdb->get_var(
            "SELECT COUNT(*) 
            FROM {$wpdb->usermeta} 
            WHERE meta_key = '_pushrelay_cart_notification_sent'"
        );
        
        $stats['notifications_sent'] = $sent ? absint($sent) : 0;
        
        return $stats;
    }
    
    /**
     * Check price drops daily
     */
    public function check_price_drops() {
        // This is a placeholder for future functionality
        // You could track products users have viewed and notify them of price drops
        PushRelay_Debug_Logger::log('Daily price drop check completed', 'debug');
    }
    
    /**
     * AJAX: Get WooCommerce settings
     */
    public function ajax_get_settings() {
        check_ajax_referer('pushrelay_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'pushrelay')));
        }
        
        $settings = $this->get_woo_settings();
        
        wp_send_json_success($settings);
    }
    
    /**
     * AJAX: Save WooCommerce settings
     */
    public function ajax_save_settings() {
        check_ajax_referer('pushrelay_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'pushrelay')));
        }
        
        $settings = isset($_POST['settings']) ? $_POST['settings'] : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        
        // Sanitize settings
        $clean_settings = array(
            'cart_abandonment_enabled' => !empty($settings['cart_abandonment_enabled']),
            'cart_abandonment_delay' => isset($settings['cart_abandonment_delay']) ? absint($settings['cart_abandonment_delay']) : 60,
            'back_in_stock_enabled' => !empty($settings['back_in_stock_enabled']),
            'price_drop_enabled' => !empty($settings['price_drop_enabled']),
            'new_product_enabled' => !empty($settings['new_product_enabled']),
            'order_status_enabled' => !empty($settings['order_status_enabled']),
            'order_statuses' => isset($settings['order_statuses']) && is_array($settings['order_statuses']) ? array_map('sanitize_text_field', $settings['order_statuses']) : array(),
        );
        
        update_option('pushrelay_woo_settings', $clean_settings);
        
        wp_send_json_success(array(
            'message' => __('Settings saved successfully', 'pushrelay')
        ));
    }
    
    /**
     * AJAX: Get WooCommerce stats
     */
    public function ajax_get_stats() {
        check_ajax_referer('pushrelay_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'pushrelay')));
        }
        
        $stats = $this->get_woo_stats();
        
        wp_send_json_success($stats);
    }
    
    /**
     * AJAX: Track product view
     */
    public function ajax_track_product_view() {
        check_ajax_referer('pushrelay_frontend_nonce', 'nonce');
        
        $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
        
        if (!$product_id) {
            wp_send_json_error(array('message' => __('Invalid product ID', 'pushrelay')));
        }
        
        // Track product view for price drop notifications
        if (is_user_logged_in()) {
            $user_id = get_current_user_id();
            $viewed_products = get_user_meta($user_id, '_pushrelay_viewed_products', true);
            
            if (!is_array($viewed_products)) {
                $viewed_products = array();
            }
            
            $viewed_products[$product_id] = current_time('mysql');
            
            update_user_meta($user_id, '_pushrelay_viewed_products', $viewed_products);
        }
        
        wp_send_json_success();
    }
}
