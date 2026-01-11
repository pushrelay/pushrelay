<?php
/**
 * Analytics Class
 * 
 * Handles analytics, reporting, statistics, and data visualization
 * 
 * @package PushRelay
 * @since 1.6.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class PushRelay_Analytics {
    
    /**
     * Constructor
     */
    public function __construct() {
        // AJAX handlers
        add_action('wp_ajax_pushrelay_get_analytics_overview', array($this, 'ajax_get_overview'));
        add_action('wp_ajax_pushrelay_get_campaign_analytics', array($this, 'ajax_get_campaign_analytics'));
        add_action('wp_ajax_pushrelay_get_subscriber_analytics', array($this, 'ajax_get_subscriber_analytics'));
        add_action('wp_ajax_pushrelay_get_chart_data', array($this, 'ajax_get_chart_data'));
        add_action('wp_ajax_pushrelay_export_analytics', array($this, 'ajax_export_analytics'));
        add_action('wp_ajax_pushrelay_get_realtime_stats', array($this, 'ajax_get_realtime_stats'));
    }
    
    /**
     * Get analytics overview
     */
    public function get_overview($start_date = null, $end_date = null) {
        $settings = get_option('pushrelay_settings', array());
        
        if (empty($settings['website_id'])) {
            return new WP_Error('no_website', __('No website configured', 'pushrelay'));
        }
        
        $api = pushrelay()->get_api_client();
        
        // Get website data
        $website = $api->get_website($settings['website_id']);
        
        if (is_wp_error($website)) {
            return $website;
        }
        
        // Get campaigns (get more to ensure we have the latest ones after sorting)
        $campaigns = $api->get_campaigns(1, 50);
        
        // Sort campaigns by date (newest first)
        $recent_campaigns = array();
        if (!is_wp_error($campaigns) && isset($campaigns['data'])) {
            $recent_campaigns = $campaigns['data'];
            usort($recent_campaigns, function($a, $b) {
                $date_a = isset($a['datetime']) ? strtotime($a['datetime']) : 0;
                $date_b = isset($b['datetime']) ? strtotime($b['datetime']) : 0;
                return $date_b - $date_a; // Descending (newest first)
            });
            // Limit to 25 most recent
            $recent_campaigns = array_slice($recent_campaigns, 0, 25);
        }
        
        // Get subscribers
        $subscribers = $api->get_subscribers(1, 1);
        
        // Calculate totals
        $total_subscribers = 0;
        $total_campaigns = 0;
        $total_sent = 0;
        $total_displayed = 0;
        $total_clicked = 0;
        $total_closed = 0;
        
        if (!is_wp_error($website) && isset($website['data'])) {
            $total_subscribers = isset($website['data']['total_subscribers']) ? absint($website['data']['total_subscribers']) : 0;
            $total_sent = isset($website['data']['total_sent_push_notifications']) ? absint($website['data']['total_sent_push_notifications']) : 0;
            $total_displayed = isset($website['data']['total_displayed_push_notifications']) ? absint($website['data']['total_displayed_push_notifications']) : 0;
            $total_clicked = isset($website['data']['total_clicked_push_notifications']) ? absint($website['data']['total_clicked_push_notifications']) : 0;
            $total_closed = isset($website['data']['total_closed_push_notifications']) ? absint($website['data']['total_closed_push_notifications']) : 0;
            $total_campaigns = isset($website['data']['total_sent_campaigns']) ? absint($website['data']['total_sent_campaigns']) : 0;
        }
        
        // Calculate rates
        $display_rate = $total_sent > 0 ? ($total_displayed / $total_sent) * 100 : 0;
        $click_rate = $total_displayed > 0 ? ($total_clicked / $total_displayed) * 100 : 0;
        $close_rate = $total_displayed > 0 ? ($total_closed / $total_displayed) * 100 : 0;
        
        $overview = array(
            'total_subscribers' => $total_subscribers,
            'total_campaigns' => $total_campaigns,
            'total_sent' => $total_sent,
            'total_displayed' => $total_displayed,
            'total_clicked' => $total_clicked,
            'total_closed' => $total_closed,
            'display_rate' => round($display_rate, 2),
            'click_rate' => round($click_rate, 2),
            'close_rate' => round($close_rate, 2),
            'ctr' => round($click_rate, 2),
            'recent_campaigns' => $recent_campaigns,
        );
        
        return $overview;
    }
    
    /**
     * Get campaign analytics
     */
    public function get_campaign_analytics($campaign_id) {
        $api = pushrelay()->get_api_client();
        $campaign = $api->get_campaign($campaign_id);
        
        if (is_wp_error($campaign)) {
            return $campaign;
        }
        
        $data = isset($campaign['data']) ? $campaign['data'] : array();
        
        $sent = isset($data['total_sent_push_notifications']) ? absint($data['total_sent_push_notifications']) : 0;
        $displayed = isset($data['total_displayed_push_notifications']) ? absint($data['total_displayed_push_notifications']) : 0;
        $clicked = isset($data['total_clicked_push_notifications']) ? absint($data['total_clicked_push_notifications']) : 0;
        $closed = isset($data['total_closed_push_notifications']) ? absint($data['total_closed_push_notifications']) : 0;
        
        $analytics = array(
            'campaign_id' => $campaign_id,
            'name' => isset($data['name']) ? $data['name'] : '',
            'status' => isset($data['status']) ? $data['status'] : '',
            'sent' => $sent,
            'displayed' => $displayed,
            'clicked' => $clicked,
            'closed' => $closed,
            'display_rate' => $sent > 0 ? round(($displayed / $sent) * 100, 2) : 0,
            'click_rate' => $displayed > 0 ? round(($clicked / $displayed) * 100, 2) : 0,
            'close_rate' => $displayed > 0 ? round(($closed / $displayed) * 100, 2) : 0,
            'scheduled_datetime' => isset($data['scheduled_datetime']) ? $data['scheduled_datetime'] : null,
            'last_sent_datetime' => isset($data['last_sent_datetime']) ? $data['last_sent_datetime'] : null,
        );
        
        return $analytics;
    }
    
    /**
     * Get subscriber analytics
     */
    public function get_subscriber_analytics($start_date = null, $end_date = null) {
        $settings = get_option('pushrelay_settings', array());
        
        if (empty($settings['website_id'])) {
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
        
        // Get different types of stats
        $overview = $api->get_subscriber_statistics($settings['website_id'], $start_date, $end_date, 'overview');
        $by_country = $api->get_subscriber_statistics($settings['website_id'], $start_date, $end_date, 'country_code');
        $by_device = $api->get_subscriber_statistics($settings['website_id'], $start_date, $end_date, 'device_type');
        $by_browser = $api->get_subscriber_statistics($settings['website_id'], $start_date, $end_date, 'browser_name');
        $by_os = $api->get_subscriber_statistics($settings['website_id'], $start_date, $end_date, 'os_name');
        
        return array(
            'overview' => !is_wp_error($overview) && isset($overview['data']) ? $overview['data'] : array(),
            'by_country' => !is_wp_error($by_country) && isset($by_country['data']) ? $by_country['data'] : array(),
            'by_device' => !is_wp_error($by_device) && isset($by_device['data']) ? $by_device['data'] : array(),
            'by_browser' => !is_wp_error($by_browser) && isset($by_browser['data']) ? $by_browser['data'] : array(),
            'by_os' => !is_wp_error($by_os) && isset($by_os['data']) ? $by_os['data'] : array(),
        );
    }
    
    /**
     * Get chart data for visualization
     */
    public function get_chart_data($type = 'subscribers', $start_date = null, $end_date = null) {
        $settings = get_option('pushrelay_settings', array());
        
        if (empty($settings['website_id'])) {
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
        $stats = $api->get_subscriber_statistics($settings['website_id'], $start_date, $end_date, 'overview');
        
        if (is_wp_error($stats)) {
            return $stats;
        }
        
        $data = isset($stats['data']) ? $stats['data'] : array();
        
        // Format for Chart.js
        $labels = array();
        $values = array();
        
        foreach ($data as $item) {
            $labels[] = isset($item['formatted_date']) ? $item['formatted_date'] : '';
            $values[] = isset($item['subscribers']) ? absint($item['subscribers']) : 0;
        }
        
        return array(
            'labels' => $labels,
            'datasets' => array(
                array(
                    'label' => __('Subscribers', 'pushrelay'),
                    'data' => $values,
                    'borderColor' => 'rgb(75, 192, 192)',
                    'backgroundColor' => 'rgba(75, 192, 192, 0.2)',
                    'tension' => 0.1,
                )
            )
        );
    }
    
    /**
     * Get top performing campaigns
     */
    public function get_top_campaigns($limit = 5) {
        $api = pushrelay()->get_api_client();
        $campaigns = $api->get_campaigns(1, 100);
        
        if (is_wp_error($campaigns) || empty($campaigns['data'])) {
            return array();
        }
        
        $all_campaigns = $campaigns['data'];
        
        // Calculate click-through rate for each
        foreach ($all_campaigns as &$campaign) {
            $displayed = isset($campaign['total_displayed_push_notifications']) ? absint($campaign['total_displayed_push_notifications']) : 0;
            $clicked = isset($campaign['total_clicked_push_notifications']) ? absint($campaign['total_clicked_push_notifications']) : 0;
            
            $campaign['ctr'] = $displayed > 0 ? ($clicked / $displayed) * 100 : 0;
        }
        
        // Sort by CTR
        usort($all_campaigns, function($a, $b) {
            return $b['ctr'] <=> $a['ctr'];
        });
        
        // Return top campaigns
        return array_slice($all_campaigns, 0, $limit);
    }
    
    /**
     * Get subscriber growth data
     */
    public function get_subscriber_growth($days = 30) {
        $settings = get_option('pushrelay_settings', array());
        
        if (empty($settings['website_id'])) {
            return array();
        }
        
        $start_date = gmdate('Y-m-d', strtotime("-{$days} days"));
        $end_date = gmdate('Y-m-d');
        
        $api = pushrelay()->get_api_client();
        $stats = $api->get_subscriber_statistics($settings['website_id'], $start_date, $end_date, 'overview');
        
        if (is_wp_error($stats) || empty($stats['data'])) {
            return array();
        }
        
        return $stats['data'];
    }
    
    /**
     * Get real-time statistics
     */
    public function get_realtime_stats() {
        $settings = get_option('pushrelay_settings', array());
        
        if (empty($settings['website_id'])) {
            return array(
                'subscribers_online' => 0,
                'recent_clicks' => 0,
                'recent_displays' => 0,
            );
        }
        
        $api = pushrelay()->get_api_client();
        
        // Get subscriber logs from last 5 minutes
        $logs = $api->get_subscriber_logs(array(
            'website_id' => $settings['website_id'],
            'results_per_page' => 100
        ));
        
        $recent_clicks = 0;
        $recent_displays = 0;
        $five_minutes_ago = strtotime('-5 minutes');
        
        if (!is_wp_error($logs) && !empty($logs['data'])) {
            foreach ($logs['data'] as $log) {
                $log_time = isset($log['datetime']) ? strtotime($log['datetime']) : 0;
                
                if ($log_time >= $five_minutes_ago) {
                    if (isset($log['type']) && $log['type'] === 'clicked_notification') {
                        $recent_clicks++;
                    } elseif (isset($log['type']) && $log['type'] === 'displayed_notification') {
                        $recent_displays++;
                    }
                }
            }
        }
        
        return array(
            'recent_clicks' => $recent_clicks,
            'recent_displays' => $recent_displays,
            'timestamp' => current_time('mysql'),
        );
    }
    
    /**
     * Export analytics to CSV
     */
    public function export_analytics($type = 'overview', $start_date = null, $end_date = null) {
        if ($type === 'overview') {
            $data = $this->get_overview($start_date, $end_date);
        } elseif ($type === 'subscribers') {
            $data = $this->get_subscriber_analytics($start_date, $end_date);
        } else {
            return '';
        }
        
        if (is_wp_error($data)) {
            return '';
        }
        
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Using php://temp stream for CSV generation
        $output = fopen('php://temp', 'r+');
        
        // Add metadata
        fputcsv($output, array(__('PushRelay Analytics Export', 'pushrelay')));
        fputcsv($output, array(__('Generated:', 'pushrelay'), current_time('mysql')));
        fputcsv($output, array(__('Website:', 'pushrelay'), get_bloginfo('name')));
        fputcsv($output, array(''));
        
        if ($type === 'overview') {
            // Overview headers
            fputcsv($output, array(__('Metric', 'pushrelay'), __('Value', 'pushrelay')));
            
            fputcsv($output, array(__('Total Subscribers', 'pushrelay'), $data['total_subscribers']));
            fputcsv($output, array(__('Total Campaigns', 'pushrelay'), $data['total_campaigns']));
            fputcsv($output, array(__('Total Sent', 'pushrelay'), $data['total_sent']));
            fputcsv($output, array(__('Total Displayed', 'pushrelay'), $data['total_displayed']));
            fputcsv($output, array(__('Total Clicked', 'pushrelay'), $data['total_clicked']));
            fputcsv($output, array(__('Display Rate', 'pushrelay'), $data['display_rate'] . '%'));
            fputcsv($output, array(__('Click Rate (CTR)', 'pushrelay'), $data['click_rate'] . '%'));
            fputcsv($output, array(__('Close Rate', 'pushrelay'), $data['close_rate'] . '%'));
        }
        
        rewind($output);
        $csv = stream_get_contents($output);
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing php://temp stream
        fclose($output);
        
        return $csv;
    }
    
    /**
     * Calculate performance score
     */
    public function calculate_performance_score() {
        $overview = $this->get_overview();
        
        if (is_wp_error($overview)) {
            return 0;
        }
        
        $score = 0;
        
        // Display rate (0-40 points)
        $display_rate = isset($overview['display_rate']) ? floatval($overview['display_rate']) : 0;
        $score += min($display_rate * 0.4, 40);
        
        // Click rate (0-40 points)
        $click_rate = isset($overview['click_rate']) ? floatval($overview['click_rate']) : 0;
        $score += min($click_rate * 0.4, 40);
        
        // Subscriber count (0-20 points)
        $subscribers = isset($overview['total_subscribers']) ? absint($overview['total_subscribers']) : 0;
        if ($subscribers > 1000) {
            $score += 20;
        } elseif ($subscribers > 500) {
            $score += 15;
        } elseif ($subscribers > 100) {
            $score += 10;
        } elseif ($subscribers > 0) {
            $score += 5;
        }
        
        return round($score, 1);
    }
    
    /**
     * Get insights and recommendations
     */
    public function get_insights() {
        $overview = $this->get_overview();
        
        if (is_wp_error($overview)) {
            return array();
        }
        
        $insights = array();
        
        // Check display rate
        if ($overview['display_rate'] < 50) {
            $insights[] = array(
                'type' => 'warning',
                'title' => __('Low Display Rate', 'pushrelay'),
                'message' => __('Your notifications are being displayed less than 50% of the time. This could be due to users closing the browser or disabling notifications.', 'pushrelay'),
                'action' => __('Consider sending notifications at optimal times when users are most active.', 'pushrelay'),
            );
        }
        
        // Check click rate
        if ($overview['click_rate'] < 5) {
            $insights[] = array(
                'type' => 'warning',
                'title' => __('Low Click-Through Rate', 'pushrelay'),
                'message' => __('Less than 5% of users are clicking on your notifications. Your notification content may not be engaging enough.', 'pushrelay'),
                'action' => __('Try using more compelling titles, adding images, or creating urgency in your messages.', 'pushrelay'),
            );
        } elseif ($overview['click_rate'] > 15) {
            $insights[] = array(
                'type' => 'success',
                'title' => __('Excellent Click-Through Rate!', 'pushrelay'),
                'message' => __('Your notifications are performing very well with a CTR above 15%.', 'pushrelay'),
                'action' => __('Keep doing what you\'re doing!', 'pushrelay'),
            );
        }
        
        // Check subscriber count
        if ($overview['total_subscribers'] < 100) {
            $insights[] = array(
                'type' => 'info',
                'title' => __('Growing Your Subscriber Base', 'pushrelay'),
                'message' => __('You have fewer than 100 subscribers. Focus on growing your audience.', 'pushrelay'),
                'action' => __('Add subscription prompts to key pages and consider offering incentives for subscribing.', 'pushrelay'),
            );
        }
        
        // Check campaign frequency
        if ($overview['total_campaigns'] < 5 && $overview['total_subscribers'] > 100) {
            $insights[] = array(
                'type' => 'info',
                'title' => __('Increase Engagement', 'pushrelay'),
                'message' => __('You have subscribers but haven\'t sent many campaigns yet.', 'pushrelay'),
                'action' => __('Start sending regular notifications to keep your audience engaged.', 'pushrelay'),
            );
        }
        
        return $insights;
    }
    
    /**
     * AJAX: Get overview
     */
    public function ajax_get_overview() {
        check_ajax_referer('pushrelay_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'pushrelay')));
        }
        
        $start_date = isset($_POST['start_date']) ? sanitize_text_field(wp_unslash($_POST['start_date'])) : null;
        $end_date = isset($_POST['end_date']) ? sanitize_text_field(wp_unslash($_POST['end_date'])) : null;
        
        $overview = $this->get_overview($start_date, $end_date);
        
        if (is_wp_error($overview)) {
            wp_send_json_error(array('message' => $overview->get_error_message()));
        }
        
        // Add performance score and insights
        $overview['performance_score'] = $this->calculate_performance_score();
        $overview['insights'] = $this->get_insights();
        
        wp_send_json_success($overview);
    }
    
    /**
     * AJAX: Get campaign analytics
     */
    public function ajax_get_campaign_analytics() {
        check_ajax_referer('pushrelay_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'pushrelay')));
        }
        
        $campaign_id = isset($_POST['campaign_id']) ? absint($_POST['campaign_id']) : 0;
        
        if (!$campaign_id) {
            wp_send_json_error(array('message' => __('Invalid campaign ID', 'pushrelay')));
        }
        
        $analytics = $this->get_campaign_analytics($campaign_id);
        
        if (is_wp_error($analytics)) {
            wp_send_json_error(array('message' => $analytics->get_error_message()));
        }
        
        wp_send_json_success($analytics);
    }
    
    /**
     * AJAX: Get subscriber analytics
     */
    public function ajax_get_subscriber_analytics() {
        check_ajax_referer('pushrelay_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'pushrelay')));
        }
        
        $start_date = isset($_POST['start_date']) ? sanitize_text_field(wp_unslash($_POST['start_date'])) : null;
        $end_date = isset($_POST['end_date']) ? sanitize_text_field(wp_unslash($_POST['end_date'])) : null;
        
        $analytics = $this->get_subscriber_analytics($start_date, $end_date);
        
        if (is_wp_error($analytics)) {
            wp_send_json_error(array('message' => $analytics->get_error_message()));
        }
        
        wp_send_json_success($analytics);
    }
    
    /**
     * AJAX: Get chart data
     */
    public function ajax_get_chart_data() {
        check_ajax_referer('pushrelay_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'pushrelay')));
        }
        
        $type = isset($_POST['type']) ? sanitize_text_field(wp_unslash($_POST['type'])) : 'subscribers';
        $start_date = isset($_POST['start_date']) ? sanitize_text_field(wp_unslash($_POST['start_date'])) : null;
        $end_date = isset($_POST['end_date']) ? sanitize_text_field(wp_unslash($_POST['end_date'])) : null;
        
        $chart_data = $this->get_chart_data($type, $start_date, $end_date);
        
        if (is_wp_error($chart_data)) {
            wp_send_json_error(array('message' => $chart_data->get_error_message()));
        }
        
        wp_send_json_success($chart_data);
    }
    
    /**
     * AJAX: Export analytics
     */
    public function ajax_export_analytics() {
        check_ajax_referer('pushrelay_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'pushrelay')));
        }
        
        $type = isset($_POST['type']) ? sanitize_text_field(wp_unslash($_POST['type'])) : 'overview';
        $start_date = isset($_POST['start_date']) ? sanitize_text_field(wp_unslash($_POST['start_date'])) : null;
        $end_date = isset($_POST['end_date']) ? sanitize_text_field(wp_unslash($_POST['end_date'])) : null;
        
        $csv = $this->export_analytics($type, $start_date, $end_date);
        
        if (empty($csv)) {
            wp_send_json_error(array('message' => __('Failed to generate export', 'pushrelay')));
        }
        
        $filename = 'pushrelay-analytics-' . gmdate('Y-m-d-H-i-s') . '.csv';
        
        wp_send_json_success(array(
            'csv' => base64_encode($csv), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
            'filename' => $filename
        ));
    }
    
    /**
     * AJAX: Get realtime stats
     */
    public function ajax_get_realtime_stats() {
        check_ajax_referer('pushrelay_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'pushrelay')));
        }
        
        $stats = $this->get_realtime_stats();
        
        wp_send_json_success($stats);
    }
}