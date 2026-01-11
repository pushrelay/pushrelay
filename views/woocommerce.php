<?php
/**
 * WooCommerce Integration View
 * 
 * WooCommerce-specific settings and automation management
 * 
 * @package PushRelay
 * @since 1.6.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Check if WooCommerce is active
if (!class_exists('WooCommerce')) {
    ?>
    <div class="wrap pushrelay-woocommerce">
        <div class="pushrelay-header">
            <h1><?php esc_html_e('WooCommerce Integration', 'pushrelay'); ?></h1>
        </div>
        
        <div class="pushrelay-card">
            <div class="pushrelay-card-body">
                <div class="pushrelay-alert pushrelay-alert-warning">
                    <strong><?php esc_html_e('WooCommerce Not Detected', 'pushrelay'); ?></strong>
                    <p><?php esc_html_e('WooCommerce must be installed and activated to use this feature.', 'pushrelay'); ?></p>
                    <a href="<?php echo esc_url(admin_url('plugin-install.php?s=woocommerce&tab=search&type=term')); ?>" class="pushrelay-btn pushrelay-btn-primary">
                        <?php esc_html_e('Install WooCommerce', 'pushrelay'); ?>
                    </a>
                </div>
            </div>
        </div>
    </div>
    <?php
    return;
}

// Get settings
$woo_settings = get_option('pushrelay_woo_settings', array());
$defaults = array(
    'cart_abandonment_enabled' => true,
    'cart_abandonment_delay' => 60,
    'back_in_stock_enabled' => true,
    'price_drop_enabled' => true,
    'new_product_enabled' => true,
    'order_status_enabled' => true,
    'order_statuses' => array('processing', 'completed', 'shipped'),
);
$woo_settings = wp_parse_args($woo_settings, $defaults);

// Get stats
$woo = new PushRelay_WooCommerce();
$stats = $woo->get_woo_stats();

// Get order statuses
$order_statuses = wc_get_order_statuses();
?>

<div class="wrap pushrelay-woocommerce">
    <div class="pushrelay-header">
        <h1><?php esc_html_e('WooCommerce Integration', 'pushrelay'); ?></h1>
        <button type="button" id="save-woo-settings" class="pushrelay-btn pushrelay-btn-primary">
            <?php esc_html_e('Save Settings', 'pushrelay'); ?>
        </button>
    </div>

    <!-- Notices Container -->
    <div class="pushrelay-notices"></div>

    <!-- Stats Grid -->
    <div class="pushrelay-stats-grid" style="margin-bottom: 20px;">
        <div class="pushrelay-stat-card card-warning">
            <h3><?php esc_html_e('Abandoned Carts', 'pushrelay'); ?></h3>
            <p class="stat-number"><?php echo esc_html(number_format_i18n($stats['abandoned_carts'])); ?></p>
        </div>

        <div class="pushrelay-stat-card card-success">
            <h3><?php esc_html_e('Notifications Sent', 'pushrelay'); ?></h3>
            <p class="stat-number"><?php echo esc_html(number_format_i18n($stats['notifications_sent'])); ?></p>
        </div>

        <div class="pushrelay-stat-card card-primary">
            <h3><?php esc_html_e('Revenue Recovered', 'pushrelay'); ?></h3>
            <p class="stat-number"><?php echo wp_kses_post(wc_price($stats['revenue_recovered'])); ?></p>
        </div>
    </div>

    <!-- Settings Form -->
    <form id="pushrelay-woo-settings-form">
        
        <!-- Cart Abandonment -->
        <div class="pushrelay-card" style="margin-bottom: 20px;">
            <div class="pushrelay-card-header">
                <h2><?php esc_html_e('Cart Abandonment', 'pushrelay'); ?></h2>
            </div>
            <div class="pushrelay-card-body">
                <p class="description" style="margin-bottom: 15px;">
                    <?php esc_html_e('Send push notifications to customers who leave items in their cart without completing checkout.', 'pushrelay'); ?>
                </p>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="cart_abandonment_enabled"><?php esc_html_e('Enable Cart Abandonment', 'pushrelay'); ?></label>
                        </th>
                        <td>
                            <label class="pushrelay-switch">
                                <input type="checkbox" 
                                       id="cart_abandonment_enabled" 
                                       name="cart_abandonment_enabled" 
                                       value="1" 
                                       <?php checked($woo_settings['cart_abandonment_enabled']); ?> />
                                <span class="pushrelay-slider"></span>
                            </label>
                            <p class="description"><?php esc_html_e('Send reminders to customers who abandon their carts.', 'pushrelay'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="cart_abandonment_delay"><?php esc_html_e('Delay (Minutes)', 'pushrelay'); ?></label>
                        </th>
                        <td>
                            <input type="number" 
                                   id="cart_abandonment_delay" 
                                   name="cart_abandonment_delay" 
                                   value="<?php echo esc_attr($woo_settings['cart_abandonment_delay']); ?>" 
                                   min="15" 
                                   max="1440"
                                   class="small-text" />
                            <p class="description"><?php esc_html_e('Time to wait before sending an abandonment notification (15-1440 minutes).', 'pushrelay'); ?></p>
                        </td>
                    </tr>
                </table>
            </div>
        </div>

        <!-- Product Notifications -->
        <div class="pushrelay-card" style="margin-bottom: 20px;">
            <div class="pushrelay-card-header">
                <h2><?php esc_html_e('Product Notifications', 'pushrelay'); ?></h2>
            </div>
            <div class="pushrelay-card-body">
                <p class="description" style="margin-bottom: 15px;">
                    <?php esc_html_e('Automatically notify customers about product updates and new arrivals.', 'pushrelay'); ?>
                </p>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="back_in_stock_enabled"><?php esc_html_e('Back in Stock', 'pushrelay'); ?></label>
                        </th>
                        <td>
                            <label class="pushrelay-switch">
                                <input type="checkbox" 
                                       id="back_in_stock_enabled" 
                                       name="back_in_stock_enabled" 
                                       value="1" 
                                       <?php checked($woo_settings['back_in_stock_enabled']); ?> />
                                <span class="pushrelay-slider"></span>
                            </label>
                            <p class="description"><?php esc_html_e('Notify customers when out-of-stock products become available again.', 'pushrelay'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="price_drop_enabled"><?php esc_html_e('Price Drops', 'pushrelay'); ?></label>
                        </th>
                        <td>
                            <label class="pushrelay-switch">
                                <input type="checkbox" 
                                       id="price_drop_enabled" 
                                       name="price_drop_enabled" 
                                       value="1" 
                                       <?php checked($woo_settings['price_drop_enabled']); ?> />
                                <span class="pushrelay-slider"></span>
                            </label>
                            <p class="description"><?php esc_html_e('Notify customers when products they viewed go on sale.', 'pushrelay'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="new_product_enabled"><?php esc_html_e('New Products', 'pushrelay'); ?></label>
                        </th>
                        <td>
                            <label class="pushrelay-switch">
                                <input type="checkbox" 
                                       id="new_product_enabled" 
                                       name="new_product_enabled" 
                                       value="1" 
                                       <?php checked($woo_settings['new_product_enabled']); ?> />
                                <span class="pushrelay-slider"></span>
                            </label>
                            <p class="description"><?php esc_html_e('Send notifications when new products are published.', 'pushrelay'); ?></p>
                        </td>
                    </tr>
                </table>
            </div>
        </div>

        <!-- Order Status Notifications -->
        <div class="pushrelay-card" style="margin-bottom: 20px;">
            <div class="pushrelay-card-header">
                <h2><?php esc_html_e('Order Status Notifications', 'pushrelay'); ?></h2>
            </div>
            <div class="pushrelay-card-body">
                <p class="description" style="margin-bottom: 15px;">
                    <?php esc_html_e('Keep customers informed about their order status via push notifications.', 'pushrelay'); ?>
                </p>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="order_status_enabled"><?php esc_html_e('Enable Order Updates', 'pushrelay'); ?></label>
                        </th>
                        <td>
                            <label class="pushrelay-switch">
                                <input type="checkbox" 
                                       id="order_status_enabled" 
                                       name="order_status_enabled" 
                                       value="1" 
                                       <?php checked($woo_settings['order_status_enabled']); ?> />
                                <span class="pushrelay-slider"></span>
                            </label>
                            <p class="description"><?php esc_html_e('Send push notifications when order status changes.', 'pushrelay'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label><?php esc_html_e('Notify on Statuses', 'pushrelay'); ?></label>
                        </th>
                        <td>
                            <fieldset>
                                <?php foreach ($order_statuses as $status_key => $status_label): 
                                    $status_slug = str_replace('wc-', '', $status_key);
                                ?>
                                    <label style="display: block; margin-bottom: 8px;">
                                        <input type="checkbox" 
                                               name="order_statuses[]" 
                                               value="<?php echo esc_attr($status_slug); ?>" 
                                               <?php checked(in_array($status_slug, $woo_settings['order_statuses'], true)); ?> />
                                        <?php echo esc_html($status_label); ?>
                                    </label>
                                <?php endforeach; ?>
                            </fieldset>
                            <p class="description"><?php esc_html_e('Select which order status changes should trigger notifications.', 'pushrelay'); ?></p>
                        </td>
                    </tr>
                </table>
            </div>
        </div>

    </form>
</div>

<style>
.pushrelay-woocommerce .pushrelay-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
}

.pushrelay-switch {
    position: relative;
    display: inline-block;
    width: 50px;
    height: 26px;
}

.pushrelay-switch input {
    opacity: 0;
    width: 0;
    height: 0;
}

.pushrelay-slider {
    position: absolute;
    cursor: pointer;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background-color: #ccc;
    transition: .3s;
    border-radius: 26px;
}

.pushrelay-slider:before {
    position: absolute;
    content: "";
    height: 20px;
    width: 20px;
    left: 3px;
    bottom: 3px;
    background-color: white;
    transition: .3s;
    border-radius: 50%;
}

.pushrelay-switch input:checked + .pushrelay-slider {
    background-color: #2196F3;
}

.pushrelay-switch input:checked + .pushrelay-slider:before {
    transform: translateX(24px);
}

.pushrelay-stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
}

.pushrelay-stat-card {
    background: #fff;
    border-radius: 8px;
    padding: 20px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.pushrelay-stat-card h3 {
    margin: 0 0 10px 0;
    font-size: 14px;
    color: #fff;
    text-transform: uppercase;
}

.pushrelay-stat-card .stat-number {
    font-size: 32px;
    font-weight: bold;
    margin: 0;
    color: #fff;
}

.pushrelay-stat-card.card-warning {
    background: linear-gradient(135deg, #f5af19 0%, #f12711 100%);
}

.pushrelay-stat-card.card-success {
    background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
}

.pushrelay-stat-card.card-primary {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
}

.pushrelay-card {
    background: #fff;
    border: 1px solid #ddd;
    border-radius: 8px;
    overflow: hidden;
}

.pushrelay-card-header {
    background: #f8f9fa;
    padding: 15px 20px;
    border-bottom: 1px solid #ddd;
}

.pushrelay-card-header h2 {
    margin: 0;
    font-size: 16px;
}

.pushrelay-card-body {
    padding: 20px;
}

.pushrelay-alert {
    padding: 15px 20px;
    border-radius: 4px;
    margin-bottom: 15px;
}

.pushrelay-alert-warning {
    background: #fff3cd;
    border: 1px solid #ffc107;
    color: #856404;
}

.pushrelay-alert-success {
    background: #d4edda;
    border: 1px solid #28a745;
    color: #155724;
}

.pushrelay-btn {
    display: inline-block;
    padding: 8px 16px;
    border: none;
    border-radius: 4px;
    cursor: pointer;
    font-size: 14px;
    text-decoration: none;
    transition: all 0.2s;
}

.pushrelay-btn-primary {
    background: #007cba;
    color: #fff;
}

.pushrelay-btn-primary:hover {
    background: #005a87;
    color: #fff;
}

.form-table th {
    width: 200px;
    padding: 15px 10px 15px 0;
}

.form-table td {
    padding: 15px 10px;
}

@media (max-width: 782px) {
    .pushrelay-woocommerce .pushrelay-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 10px;
    }
    
    .form-table th,
    .form-table td {
        display: block;
        width: 100%;
        padding: 10px 0;
    }
}
</style>

<script>
jQuery(document).ready(function($) {
    // Save WooCommerce settings
    $('#save-woo-settings').on('click', function(e) {
        e.preventDefault();
        
        var $btn = $(this);
        var originalText = $btn.text();
        
        $btn.text('<?php echo esc_js(__('Saving...', 'pushrelay')); ?>').prop('disabled', true);
        
        // Gather settings
        var settings = {
            cart_abandonment_enabled: $('#cart_abandonment_enabled').is(':checked') ? 1 : 0,
            cart_abandonment_delay: $('#cart_abandonment_delay').val(),
            back_in_stock_enabled: $('#back_in_stock_enabled').is(':checked') ? 1 : 0,
            price_drop_enabled: $('#price_drop_enabled').is(':checked') ? 1 : 0,
            new_product_enabled: $('#new_product_enabled').is(':checked') ? 1 : 0,
            order_status_enabled: $('#order_status_enabled').is(':checked') ? 1 : 0,
            order_statuses: []
        };
        
        // Get selected order statuses
        $('input[name="order_statuses[]"]:checked').each(function() {
            settings.order_statuses.push($(this).val());
        });
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'pushrelay_woo_save_settings',
                nonce: '<?php echo esc_js(wp_create_nonce('pushrelay_admin_nonce')); ?>',
                settings: settings
            },
            success: function(response) {
                if (response.success) {
                    showNotice('success', response.data.message || '<?php echo esc_js(__('Settings saved successfully', 'pushrelay')); ?>');
                } else {
                    showNotice('error', response.data.message || '<?php echo esc_js(__('Error saving settings', 'pushrelay')); ?>');
                }
            },
            error: function() {
                showNotice('error', '<?php echo esc_js(__('Error saving settings', 'pushrelay')); ?>');
            },
            complete: function() {
                $btn.text(originalText).prop('disabled', false);
            }
        });
    });
    
    // Show notice
    function showNotice(type, message) {
        var alertClass = type === 'success' ? 'pushrelay-alert-success' : 'pushrelay-alert-danger';
        var $notice = $('<div class="pushrelay-alert ' + alertClass + '">' + message + '</div>');
        
        $('.pushrelay-notices').html($notice);
        
        setTimeout(function() {
            $notice.fadeOut(function() {
                $(this).remove();
            });
        }, 5000);
    }
});
</script>
