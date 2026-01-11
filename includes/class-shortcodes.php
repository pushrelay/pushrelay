<?php
/**
 * Shortcodes Class
 * 
 * Handles all plugin shortcodes for easy integration
 * 
 * @package PushRelay
 * @since 1.6.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class PushRelay_Shortcodes {
    
    /**
     * Constructor
     */
    public function __construct() {
        // Register shortcodes
        add_shortcode('pushrelay_subscribe', array($this, 'subscribe_button'));
        add_shortcode('pushrelay_widget', array($this, 'subscription_widget'));
        add_shortcode('pushrelay_stats', array($this, 'statistics'));
        add_shortcode('pushrelay_count', array($this, 'subscriber_count'));
        add_shortcode('pushrelay_status', array($this, 'subscription_status'));
    }
    
    /**
     * Subscribe button shortcode
     * 
     * Usage: [pushrelay_subscribe text="Subscribe" class="my-class" style=""]
     */
    public function subscribe_button($atts) {
        $settings = get_option('pushrelay_settings', array());
        
        if (empty($settings['website_id'])) {
            return '';
        }
        
        $atts = shortcode_atts(array(
            'text' => __('Subscribe to Notifications', 'pushrelay'),
            'subscribed_text' => __('Subscribed ✓', 'pushrelay'),
            'class' => '',
            'style' => '',
            'show_icon' => 'yes',
            'icon_position' => 'left',
        ), $atts, 'pushrelay_subscribe');
        
        $frontend = new PushRelay_Frontend();
        
        return $frontend->get_subscribe_button(array(
            'text' => sanitize_text_field($atts['text']),
            'subscribed_text' => sanitize_text_field($atts['subscribed_text']),
            'class' => sanitize_html_class($atts['class']),
            'style' => esc_attr($atts['style']),
            'show_icon' => $atts['show_icon'] === 'yes',
            'icon_position' => in_array($atts['icon_position'], array('left', 'right')) ? $atts['icon_position'] : 'left',
        ));
    }
    
    /**
     * Subscription widget shortcode
     * 
     * Usage: [pushrelay_widget title="Get Notifications" description="..." show_count="yes"]
     */
    public function subscription_widget($atts) {
        $settings = get_option('pushrelay_settings', array());
        
        if (empty($settings['website_id'])) {
            return '';
        }
        
        $atts = shortcode_atts(array(
            'title' => __('Get Notifications', 'pushrelay'),
            'description' => __('Subscribe to receive push notifications about new content and updates.', 'pushrelay'),
            'button_text' => __('Subscribe Now', 'pushrelay'),
            'show_count' => 'yes',
            'show_privacy' => 'yes',
            'class' => '',
        ), $atts, 'pushrelay_widget');
        
        $frontend = new PushRelay_Frontend();
        
        ob_start();
        $frontend->display_widget(array(
            'title' => sanitize_text_field($atts['title']),
            'description' => sanitize_text_field($atts['description']),
            'button_text' => sanitize_text_field($atts['button_text']),
            'show_subscriber_count' => $atts['show_count'] === 'yes',
            'show_privacy_note' => $atts['show_privacy'] === 'yes',
            'container_class' => 'pushrelay-widget ' . sanitize_html_class($atts['class']),
        ));
        return ob_get_clean();
    }
    
    /**
     * Statistics shortcode
     * 
     * Usage: [pushrelay_stats type="overview" layout="grid"]
     */
    public function statistics($atts) {
        $settings = get_option('pushrelay_settings', array());
        
        if (empty($settings['website_id'])) {
            return '<p>' . esc_html__('PushRelay is not configured.', 'pushrelay') . '</p>';
        }
        
        $atts = shortcode_atts(array(
            'type' => 'overview', // overview, subscribers, campaigns
            'layout' => 'grid', // grid, list, inline
            'show_labels' => 'yes',
            'class' => '',
        ), $atts, 'pushrelay_stats');
        
        $api = pushrelay()->get_api_client();
        $analytics = new PushRelay_Analytics();
        
        if ($atts['type'] === 'overview') {
            $data = $analytics->get_overview();
            
            if (is_wp_error($data)) {
                return '<p>' . esc_html($data->get_error_message()) . '</p>';
            }
            
            return $this->render_overview_stats($data, $atts);
            
        } elseif ($atts['type'] === 'subscribers') {
            $result = $api->get_subscribers(1, 1);
            
            if (is_wp_error($result)) {
                return '<p>' . esc_html($result->get_error_message()) . '</p>';
            }
            
            $total = isset($result['meta']['total']) ? absint($result['meta']['total']) : 0;
            
            return $this->render_single_stat(
                __('Total Subscribers', 'pushrelay'),
                number_format_i18n($total),
                'subscribers',
                $atts
            );
            
        } elseif ($atts['type'] === 'campaigns') {
            $result = $api->get_campaigns(1, 1);
            
            if (is_wp_error($result)) {
                return '<p>' . esc_html($result->get_error_message()) . '</p>';
            }
            
            $total = isset($result['meta']['total']) ? absint($result['meta']['total']) : 0;
            
            return $this->render_single_stat(
                __('Total Campaigns', 'pushrelay'),
                number_format_i18n($total),
                'campaigns',
                $atts
            );
        }
        
        return '';
    }
    
    /**
     * Render overview statistics
     */
    private function render_overview_stats($data, $atts) {
        $layout_class = 'pushrelay-stats-' . sanitize_html_class($atts['layout']);
        $show_labels = $atts['show_labels'] === 'yes';
        
        ob_start();
        ?>
        <div class="pushrelay-stats-shortcode <?php echo esc_attr($layout_class . ' ' . sanitize_html_class($atts['class'])); ?>">
            
            <div class="pushrelay-stat-item">
                <?php if ($show_labels): ?>
                    <span class="pushrelay-stat-label"><?php esc_html_e('Subscribers', 'pushrelay'); ?></span>
                <?php endif; ?>
                <span class="pushrelay-stat-value"><?php echo esc_html(number_format_i18n($data['total_subscribers'])); ?></span>
            </div>
            
            <div class="pushrelay-stat-item">
                <?php if ($show_labels): ?>
                    <span class="pushrelay-stat-label"><?php esc_html_e('Campaigns', 'pushrelay'); ?></span>
                <?php endif; ?>
                <span class="pushrelay-stat-value"><?php echo esc_html(number_format_i18n($data['total_campaigns'])); ?></span>
            </div>
            
            <div class="pushrelay-stat-item">
                <?php if ($show_labels): ?>
                    <span class="pushrelay-stat-label"><?php esc_html_e('Click Rate', 'pushrelay'); ?></span>
                <?php endif; ?>
                <span class="pushrelay-stat-value"><?php echo esc_html($data['click_rate']); ?>%</span>
            </div>
            
            <div class="pushrelay-stat-item">
                <?php if ($show_labels): ?>
                    <span class="pushrelay-stat-label"><?php esc_html_e('Display Rate', 'pushrelay'); ?></span>
                <?php endif; ?>
                <span class="pushrelay-stat-value"><?php echo esc_html($data['display_rate']); ?>%</span>
            </div>
            
        </div>
        
        <style>
            .pushrelay-stats-shortcode { margin: 20px 0; }
            .pushrelay-stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 15px; }
            .pushrelay-stats-list .pushrelay-stat-item { margin-bottom: 15px; padding: 15px; background: #f9f9f9; border-radius: 5px; }
            .pushrelay-stats-inline { display: flex; gap: 20px; flex-wrap: wrap; }
            .pushrelay-stat-item { text-align: center; }
            .pushrelay-stats-grid .pushrelay-stat-item { padding: 20px; background: #f9f9f9; border-radius: 5px; }
            .pushrelay-stat-label { display: block; font-size: 14px; color: #666; margin-bottom: 5px; }
            .pushrelay-stat-value { display: block; font-size: 24px; font-weight: bold; color: #333; }
        </style>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Render single statistic
     */
    private function render_single_stat($label, $value, $type, $atts) {
        $show_labels = $atts['show_labels'] === 'yes';
        
        ob_start();
        ?>
        <div class="pushrelay-stat-single pushrelay-stat-<?php echo esc_attr($type); ?> <?php echo esc_attr(sanitize_html_class($atts['class'])); ?>">
            <?php if ($show_labels): ?>
                <span class="pushrelay-stat-label"><?php echo esc_html($label); ?></span>
            <?php endif; ?>
            <span class="pushrelay-stat-value"><?php echo esc_html($value); ?></span>
        </div>
        
        <style>
            .pushrelay-stat-single { 
                display: inline-block; 
                padding: 15px 25px; 
                background: #f9f9f9; 
                border-radius: 5px; 
                text-align: center;
                margin: 10px 0;
            }
            .pushrelay-stat-single .pushrelay-stat-label { 
                display: block; 
                font-size: 14px; 
                color: #666; 
                margin-bottom: 5px; 
            }
            .pushrelay-stat-single .pushrelay-stat-value { 
                display: block; 
                font-size: 32px; 
                font-weight: bold; 
                color: #0073aa; 
            }
        </style>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Subscriber count shortcode
     * 
     * Usage: [pushrelay_count format="number" prefix="Total: " suffix=" subscribers"]
     */
    public function subscriber_count($atts) {
        $settings = get_option('pushrelay_settings', array());
        
        if (empty($settings['website_id'])) {
            return '0';
        }
        
        $atts = shortcode_atts(array(
            'format' => 'number', // number, abbreviated (1.2K)
            'prefix' => '',
            'suffix' => '',
            'class' => '',
        ), $atts, 'pushrelay_count');
        
        // Get count with caching
        $cache_key = 'pushrelay_subscriber_count_shortcode';
        $count = get_transient($cache_key);
        
        if ($count === false) {
            $api = pushrelay()->get_api_client();
            $result = $api->get_subscribers(1, 1);
            
            if (is_wp_error($result)) {
                return '0';
            }
            
            $count = isset($result['meta']['total']) ? absint($result['meta']['total']) : 0;
            
            // Cache for 5 minutes
            set_transient($cache_key, $count, 5 * MINUTE_IN_SECONDS);
        }
        
        // Format count
        if ($atts['format'] === 'abbreviated') {
            if ($count >= 1000000) {
                $formatted = round($count / 1000000, 1) . 'M';
            } elseif ($count >= 1000) {
                $formatted = round($count / 1000, 1) . 'K';
            } else {
                $formatted = $count;
            }
        } else {
            $formatted = number_format_i18n($count);
        }
        
        $output = '<span class="pushrelay-subscriber-count ' . esc_attr(sanitize_html_class($atts['class'])) . '">';
        $output .= esc_html($atts['prefix']);
        $output .= '<strong>' . esc_html($formatted) . '</strong>';
        $output .= esc_html($atts['suffix']);
        $output .= '</span>';
        
        return $output;
    }
    
    /**
     * Subscription status shortcode
     * 
     * Usage: [pushrelay_status]
     */
    public function subscription_status($atts) {
        $settings = get_option('pushrelay_settings', array());
        
        if (empty($settings['website_id'])) {
            return '';
        }
        
        $atts = shortcode_atts(array(
            'class' => '',
        ), $atts, 'pushrelay_status');
        
        $frontend = new PushRelay_Frontend();
        
        return '<div class="' . esc_attr(sanitize_html_class($atts['class'])) . '">' . 
               $frontend->get_subscription_status_html() . 
               '</div>';
    }
    
    /**
     * Recent campaigns shortcode
     * 
     * Usage: [pushrelay_recent_campaigns limit="5" show_stats="yes"]
     */
    public function recent_campaigns($atts) {
        $settings = get_option('pushrelay_settings', array());
        
        if (empty($settings['website_id'])) {
            return '<p>' . esc_html__('PushRelay is not configured.', 'pushrelay') . '</p>';
        }
        
        $atts = shortcode_atts(array(
            'limit' => 5,
            'show_stats' => 'yes',
            'class' => '',
        ), $atts, 'pushrelay_recent_campaigns');
        
        $api = pushrelay()->get_api_client();
        $result = $api->get_campaigns(1, absint($atts['limit']));
        
        if (is_wp_error($result)) {
            return '<p>' . esc_html($result->get_error_message()) . '</p>';
        }
        
        if (empty($result['data'])) {
            return '<p>' . esc_html__('No campaigns found.', 'pushrelay') . '</p>';
        }
        
        $show_stats = $atts['show_stats'] === 'yes';
        
        ob_start();
        ?>
        <div class="pushrelay-recent-campaigns <?php echo esc_attr(sanitize_html_class($atts['class'])); ?>">
            <?php foreach ($result['data'] as $campaign): ?>
                <div class="pushrelay-campaign-item">
                    <h4 class="pushrelay-campaign-title">
                        <?php echo esc_html($campaign['name']); ?>
                    </h4>
                    
                    <?php if ($show_stats): ?>
                        <div class="pushrelay-campaign-stats">
                            <span class="stat">
                                <strong><?php esc_html_e('Sent:', 'pushrelay'); ?></strong> 
                                <?php echo esc_html(number_format_i18n($campaign['total_sent_push_notifications'])); ?>
                            </span>
                            <span class="stat">
                                <strong><?php esc_html_e('Clicked:', 'pushrelay'); ?></strong> 
                                <?php echo esc_html(number_format_i18n($campaign['total_clicked_push_notifications'])); ?>
                            </span>
                            <span class="stat">
                                <strong><?php esc_html_e('Status:', 'pushrelay'); ?></strong> 
                                <?php echo esc_html(ucfirst($campaign['status'])); ?>
                            </span>
                        </div>
                    <?php endif; ?>
                    
                    <div class="pushrelay-campaign-date">
                        <?php echo esc_html(date_i18n(get_option('date_format'), strtotime($campaign['datetime']))); ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        
        <style>
            .pushrelay-recent-campaigns { margin: 20px 0; }
            .pushrelay-campaign-item { 
                padding: 15px; 
                margin-bottom: 15px; 
                background: #f9f9f9; 
                border-left: 4px solid #0073aa; 
                border-radius: 3px; 
            }
            .pushrelay-campaign-title { 
                margin: 0 0 10px 0; 
                font-size: 16px; 
            }
            .pushrelay-campaign-stats { 
                margin: 10px 0; 
                font-size: 14px; 
            }
            .pushrelay-campaign-stats .stat { 
                display: inline-block; 
                margin-right: 15px; 
            }
            .pushrelay-campaign-date { 
                font-size: 12px; 
                color: #666; 
                margin-top: 5px; 
            }
        </style>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Health score shortcode
     * 
     * Usage: [pushrelay_health_score show_label="yes"]
     */
    public function health_score($atts) {
        $atts = shortcode_atts(array(
            'show_label' => 'yes',
            'class' => '',
        ), $atts, 'pushrelay_health_score');
        
        $health_check = new PushRelay_Health_Check();
        $score = $health_check->get_health_score();
        
        $show_label = $atts['show_label'] === 'yes';
        
        // Determine color based on score
        if ($score >= 80) {
            $color = '#28a745'; // Green
        } elseif ($score >= 60) {
            $color = '#ffc107'; // Yellow
        } else {
            $color = '#dc3545'; // Red
        }
        
        ob_start();
        ?>
        <div class="pushrelay-health-score <?php echo esc_attr(sanitize_html_class($atts['class'])); ?>">
            <?php if ($show_label): ?>
                <span class="pushrelay-health-label"><?php esc_html_e('Health Score', 'pushrelay'); ?></span>
            <?php endif; ?>
            <span class="pushrelay-health-value" style="color: <?php echo esc_attr($color); ?>;">
                <?php echo esc_html($score); ?>%
            </span>
        </div>
        
        <style>
            .pushrelay-health-score { 
                display: inline-block; 
                padding: 10px 20px; 
                background: #f9f9f9; 
                border-radius: 5px; 
                text-align: center;
            }
            .pushrelay-health-label { 
                display: block; 
                font-size: 14px; 
                color: #666; 
                margin-bottom: 5px; 
            }
            .pushrelay-health-value { 
                display: block; 
                font-size: 28px; 
                font-weight: bold; 
            }
        </style>
        <?php
        return ob_get_clean();
    }
}