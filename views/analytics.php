<?php
/**
 * Analytics View
 * 
 * Analytics and reporting interface - overview, charts, insights
 * 
 * @package PushRelay
 * @since 1.6.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

$api = pushrelay()->get_api_client();
$settings = get_option('pushrelay_settings', array());

// Get date range
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view filters
$start_date = isset($_GET['start_date']) ? sanitize_text_field(wp_unslash($_GET['start_date'])) : gmdate('Y-m-d', strtotime('-30 days'));
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view filters
$end_date = isset($_GET['end_date']) ? sanitize_text_field(wp_unslash($_GET['end_date'])) : gmdate('Y-m-d');
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view filters
$website_filter = isset($_GET['website_id']) ? absint($_GET['website_id']) : 0;

// Get analytics overview
$analytics_class = new PushRelay_Analytics();
$overview = $analytics_class->get_overview($start_date, $end_date);

// Check for errors
if (is_wp_error($overview)) {
    $overview = array(
        'total_subscribers' => 0,
        'total_campaigns' => 0,
        'total_sent' => 0,
        'total_clicks' => 0,
        'avg_ctr' => 0
    );
}

// Add missing keys with default values to prevent warnings
$overview = array_merge(array(
    'total_subscribers' => 0,
    'total_campaigns' => 0,
    'total_sent' => 0,
    'total_displayed' => 0,
    'total_clicked' => 0,
    'total_closed' => 0,
    'display_rate' => 0,
    'click_rate' => 0,
    'close_rate' => 0,
    'avg_ctr' => 0,
    'ctr' => 0
), is_array($overview) ? $overview : array());

// Get websites for filter
$websites_response = $api->get_websites();
$websites = !is_wp_error($websites_response) && isset($websites_response['data']) ? $websites_response['data'] : array();

// Get chart data
$chart_type = isset($_GET['chart_type']) ? sanitize_text_field(wp_unslash($_GET['chart_type'])) : 'subscribers';
?>

<div class="wrap pushrelay-analytics">
    <div class="pushrelay-header">
        <h1><?php esc_html_e('Analytics', 'pushrelay'); ?></h1>
        <button type="button" id="export-analytics-btn" class="pushrelay-btn pushrelay-btn-secondary">
            <?php esc_html_e('📊 Export Report', 'pushrelay'); ?>
        </button>
    </div>

    <!-- Notices Container -->
    <div class="pushrelay-notices"></div>

    <!-- Date Range Filter -->
    <div class="pushrelay-card" style="margin-bottom: 20px;">
        <div class="pushrelay-card-body">
            <form method="get" class="pushrelay-filters-form" style="display: flex; gap: 15px; flex-wrap: wrap; align-items: flex-end;">
                <input type="hidden" name="page" value="pushrelay-analytics">
                
                <div class="pushrelay-form-group" style="flex: 1; min-width: 200px;">
                    <label class="pushrelay-form-label" for="website-filter">
                        <?php esc_html_e('Website', 'pushrelay'); ?>
                    </label>
                    <select name="website_id" id="website-filter" class="pushrelay-form-control">
                        <option value="0"><?php esc_html_e('All Websites', 'pushrelay'); ?></option>
                        <?php foreach ($websites as $website): ?>
                            <option value="<?php echo esc_attr($website['id']); ?>" 
                                    <?php selected($website_filter, $website['id']); ?>>
                                <?php echo esc_html($website['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="pushrelay-form-group" style="min-width: 180px;">
                    <label class="pushrelay-form-label" for="start-date-filter">
                        <?php esc_html_e('Start Date', 'pushrelay'); ?>
                    </label>
                    <input type="date" 
                           name="start_date" 
                           id="start-date-filter" 
                           class="pushrelay-form-control"
                           value="<?php echo esc_attr($start_date); ?>">
                </div>
                
                <div class="pushrelay-form-group" style="min-width: 180px;">
                    <label class="pushrelay-form-label" for="end-date-filter">
                        <?php esc_html_e('End Date', 'pushrelay'); ?>
                    </label>
                    <input type="date" 
                           name="end_date" 
                           id="end-date-filter" 
                           class="pushrelay-form-control"
                           value="<?php echo esc_attr($end_date); ?>">
                </div>
                
                <div class="pushrelay-form-group">
                    <button type="submit" class="pushrelay-btn pushrelay-btn-primary">
                        <?php esc_html_e('Apply', 'pushrelay'); ?>
                    </button>
                    <button type="button" id="quick-range-30days" class="pushrelay-btn pushrelay-btn-secondary">
                        <?php esc_html_e('Last 30 Days', 'pushrelay'); ?>
                    </button>
                    <button type="button" id="quick-range-7days" class="pushrelay-btn pushrelay-btn-secondary">
                        <?php esc_html_e('Last 7 Days', 'pushrelay'); ?>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Overview Stats -->
    <div class="pushrelay-stats-grid" style="margin-bottom: 20px;">
        <div class="pushrelay-stat-card card-primary">
            <h3><?php esc_html_e('Total Subscribers', 'pushrelay'); ?></h3>
            <p class="stat-number"><?php echo esc_html(number_format_i18n($overview['total_subscribers'])); ?></p>
        </div>

        <div class="pushrelay-stat-card card-info">
            <h3><?php esc_html_e('Total Campaigns', 'pushrelay'); ?></h3>
            <p class="stat-number"><?php echo esc_html(number_format_i18n($overview['total_campaigns'])); ?></p>
        </div>

        <div class="pushrelay-stat-card card-success">
            <h3><?php esc_html_e('Total Sent', 'pushrelay'); ?></h3>
            <p class="stat-number"><?php echo esc_html(number_format_i18n($overview['total_sent'])); ?></p>
        </div>

        <div class="pushrelay-stat-card card-warning">
            <h3><?php esc_html_e('Click Rate (CTR)', 'pushrelay'); ?></h3>
            <p class="stat-number"><?php echo esc_html($overview['ctr']); ?>%</p>
        </div>
    </div>

    <!-- Performance Metrics -->
    <div class="pushrelay-card" style="margin-bottom: 20px;">
        <div class="pushrelay-card-header">
            <h2><?php esc_html_e('Performance Metrics', 'pushrelay'); ?></h2>
        </div>
        <div class="pushrelay-card-body">
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px;">
                
                <!-- Displayed Rate -->
                <div class="pushrelay-metric-box">
                    <div class="metric-label"><?php esc_html_e('Display Rate', 'pushrelay'); ?></div>
                    <div class="metric-value" style="color: #007cba;">
                        <?php echo esc_html($overview['display_rate']); ?>%
                    </div>
                    <div class="metric-bar">
                        <div class="metric-bar-fill" style="width: <?php echo esc_attr($overview['display_rate']); ?>%; background: #007cba;"></div>
                    </div>
                    <div class="metric-details">
                        <?php echo esc_html(number_format_i18n($overview['total_displayed'])); ?> / 
                        <?php echo esc_html(number_format_i18n($overview['total_sent'])); ?> 
                        <?php esc_html_e('notifications', 'pushrelay'); ?>
                    </div>
                </div>

                <!-- Click Rate -->
                <div class="pushrelay-metric-box">
                    <div class="metric-label"><?php esc_html_e('Click Rate (CTR)', 'pushrelay'); ?></div>
                    <div class="metric-value" style="color: #28a745;">
                        <?php echo esc_html($overview['click_rate']); ?>%
                    </div>
                    <div class="metric-bar">
                        <div class="metric-bar-fill" style="width: <?php echo esc_attr($overview['click_rate']); ?>%; background: #28a745;"></div>
                    </div>
                    <div class="metric-details">
                        <?php echo esc_html(number_format_i18n($overview['total_clicked'])); ?> / 
                        <?php echo esc_html(number_format_i18n($overview['total_displayed'])); ?> 
                        <?php esc_html_e('clicks', 'pushrelay'); ?>
                    </div>
                </div>

                <!-- Close Rate -->
                <div class="pushrelay-metric-box">
                    <div class="metric-label"><?php esc_html_e('Close Rate', 'pushrelay'); ?></div>
                    <div class="metric-value" style="color: #dc3545;">
                        <?php echo esc_html($overview['close_rate']); ?>%
                    </div>
                    <div class="metric-bar">
                        <div class="metric-bar-fill" style="width: <?php echo esc_attr($overview['close_rate']); ?>%; background: #dc3545;"></div>
                    </div>
                    <div class="metric-details">
                        <?php echo esc_html(number_format_i18n($overview['total_closed'])); ?> / 
                        <?php echo esc_html(number_format_i18n($overview['total_displayed'])); ?> 
                        <?php esc_html_e('closed', 'pushrelay'); ?>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <!-- Charts -->
    <div class="pushrelay-card" style="margin-bottom: 20px;">
        <div class="pushrelay-card-header">
            <h2><?php esc_html_e('Trends', 'pushrelay'); ?></h2>
            <div style="display: flex; gap: 10px;">
                <select id="chart-type-selector" class="pushrelay-form-control" style="width: auto;">
                    <option value="subscribers" <?php selected($chart_type, 'subscribers'); ?>><?php esc_html_e('Subscribers', 'pushrelay'); ?></option>
                    <option value="notifications" <?php selected($chart_type, 'notifications'); ?>><?php esc_html_e('Notifications', 'pushrelay'); ?></option>
                    <option value="engagement" <?php selected($chart_type, 'engagement'); ?>><?php esc_html_e('Engagement', 'pushrelay'); ?></option>
                </select>
            </div>
        </div>
        <div class="pushrelay-card-body">
            <canvas id="analytics-chart" width="400" height="150"></canvas>
            <div id="chart-loading" style="text-align: center; padding: 60px;">
                <div style="font-size: 32px; color: #999; margin-bottom: 10px;">📊</div>
                <p style="color: #666;"><?php esc_html_e('Loading chart data...', 'pushrelay'); ?></p>
            </div>
        </div>
    </div>

    <!-- Recent Campaigns Performance -->
    <?php if (!empty($overview['recent_campaigns'])): ?>
        <div class="pushrelay-card">
            <div class="pushrelay-card-header">
                <h2><?php esc_html_e('Recent Campaigns Performance', 'pushrelay'); ?></h2>
            </div>
            <div class="pushrelay-card-body">
                <table class="pushrelay-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Campaign', 'pushrelay'); ?></th>
                            <th><?php esc_html_e('Sent', 'pushrelay'); ?></th>
                            <th><?php esc_html_e('Displayed', 'pushrelay'); ?></th>
                            <th><?php esc_html_e('Clicked', 'pushrelay'); ?></th>
                            <th><?php esc_html_e('CTR', 'pushrelay'); ?></th>
                            <th><?php esc_html_e('Date', 'pushrelay'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($overview['recent_campaigns'] as $campaign): ?>
                            <?php
                            $sent = isset($campaign['total_sent_push_notifications']) ? absint($campaign['total_sent_push_notifications']) : 0;
                            $displayed = isset($campaign['total_displayed_push_notifications']) ? absint($campaign['total_displayed_push_notifications']) : 0;
                            $clicked = isset($campaign['total_clicked_push_notifications']) ? absint($campaign['total_clicked_push_notifications']) : 0;
                            $ctr = $displayed > 0 ? round(($clicked / $displayed) * 100, 2) : 0;
                            ?>
                            <tr>
                                <td><strong><?php echo esc_html($campaign['name']); ?></strong></td>
                                <td><?php echo esc_html(number_format_i18n($sent)); ?></td>
                                <td><?php echo esc_html(number_format_i18n($displayed)); ?></td>
                                <td><?php echo esc_html(number_format_i18n($clicked)); ?></td>
                                <td>
                                    <strong style="color: <?php echo esc_attr($ctr > 5 ? '#28a745' : ($ctr > 2 ? '#ffc107' : '#dc3545')); ?>">
                                        <?php echo esc_html($ctr); ?>%
                                    </strong>
                                </td>
                                <td><?php echo esc_html(date_i18n(get_option('date_format'), strtotime($campaign['datetime']))); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <!-- Insights -->
    <div class="pushrelay-card" style="margin-top: 20px;">
        <div class="pushrelay-card-header">
            <h2><?php esc_html_e('Insights & Recommendations', 'pushrelay'); ?></h2>
        </div>
        <div class="pushrelay-card-body">
            <div style="display: grid; gap: 15px;">
                
                <?php if ($overview['click_rate'] < 2): ?>
                    <div class="pushrelay-alert pushrelay-alert-warning">
                        <strong>💡 <?php esc_html_e('Low Click Rate', 'pushrelay'); ?></strong>
                        <p><?php esc_html_e('Your click rate is below average. Consider improving your notification copy and timing.', 'pushrelay'); ?></p>
                    </div>
                <?php endif; ?>

                <?php if ($overview['click_rate'] > 5): ?>
                    <div class="pushrelay-alert pushrelay-alert-success">
                        <strong>🎉 <?php esc_html_e('Great Performance!', 'pushrelay'); ?></strong>
                        <p><?php esc_html_e('Your click rate is above average. Keep up the good work!', 'pushrelay'); ?></p>
                    </div>
                <?php endif; ?>

                <?php if ($overview['total_subscribers'] < 100): ?>
                    <div class="pushrelay-alert pushrelay-alert-info">
                        <strong>📈 <?php esc_html_e('Grow Your Audience', 'pushrelay'); ?></strong>
                        <p><?php esc_html_e('You have less than 100 subscribers. Consider promoting your push notification subscription.', 'pushrelay'); ?></p>
                    </div>
                <?php endif; ?>

                <?php if ($overview['close_rate'] > 50): ?>
                    <div class="pushrelay-alert pushrelay-alert-danger">
                        <strong>⚠️ <?php esc_html_e('High Close Rate', 'pushrelay'); ?></strong>
                        <p><?php esc_html_e('Many users are closing your notifications. Review your notification frequency and relevance.', 'pushrelay'); ?></p>
                    </div>
                <?php endif; ?>

            </div>
        </div>
    </div>

</div>

<script>
jQuery(document).ready(function($) {
    
    // Remove global AJAX handlers that might conflict (wp-codemirror fix)
    $(document).off('ajaxComplete');
    $(document).off('ajaxSend');
    
    // Quick date range buttons
    $('#quick-range-30days').on('click', function() {
        var today = new Date();
        var thirtyDaysAgo = new Date(today.getTime() - (30 * 24 * 60 * 60 * 1000));
        
        $('#start-date-filter').val(thirtyDaysAgo.toISOString().split('T')[0]);
        $('#end-date-filter').val(today.toISOString().split('T')[0]);
        
        $(this).closest('form').submit();
    });
    
    $('#quick-range-7days').on('click', function() {
        var today = new Date();
        var sevenDaysAgo = new Date(today.getTime() - (7 * 24 * 60 * 60 * 1000));
        
        $('#start-date-filter').val(sevenDaysAgo.toISOString().split('T')[0]);
        $('#end-date-filter').val(today.toISOString().split('T')[0]);
        
        $(this).closest('form').submit();
    });
    
    // Chart type selector
    $('#chart-type-selector').on('change', function() {
        var currentUrl = new URL(window.location.href);
        currentUrl.searchParams.set('chart_type', $(this).val());
        window.location.href = currentUrl.toString();
    });
    
    // Load chart data using fetch API
    function loadChartData() {
        var chartType = $('#chart-type-selector').val() || 'subscribers';
        
        var formData = new FormData();
        formData.append('action', 'pushrelay_get_chart_data');
        formData.append('chart_type', chartType);
        formData.append('start_date', $('#start-date-filter').val());
        formData.append('end_date', $('#end-date-filter').val());
        formData.append('website_id', $('#website-filter').val());
        formData.append('nonce', '<?php echo esc_attr(wp_create_nonce('pushrelay_admin_nonce')); ?>');
        
        fetch(ajaxurl, {
            method: 'POST',
            body: formData
        })
        .then(function(response) { return response.json(); })
        .then(function(data) {
            if (data.success && data.data) {
                renderChart(data.data, chartType);
                $('#chart-loading').hide();
                $('#analytics-chart').show();
            } else {
                $('#chart-loading').html('<p style="color: #dc3545;">Error loading chart data</p>');
            }
        })
        .catch(function() {
            $('#chart-loading').html('<p style="color: #dc3545;">Connection error</p>');
        });
    }
    
    // Render chart using Chart.js
    function renderChart(data, type) {
        var ctx = document.getElementById('analytics-chart').getContext('2d');
        
        // Destroy existing chart if any
        if (window.analyticsChart) {
            window.analyticsChart.destroy();
        }
        
        var chartConfig = {
            type: 'line',
            data: data,
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: {
                        position: 'bottom',
                    },
                    title: {
                        display: true,
                        text: type.charAt(0).toUpperCase() + type.slice(1) + ' Over Time'
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true
                    }
                }
            }
        };
        
        window.analyticsChart = new Chart(ctx, chartConfig);
    }
    
    // Export analytics
    $('#export-analytics-btn').on('click', function() {
        var params = new URLSearchParams({
            action: 'pushrelay_export_analytics',
            start_date: $('#start-date-filter').val(),
            end_date: $('#end-date-filter').val(),
            website_id: $('#website-filter').val(),
            nonce: '<?php echo esc_attr(wp_create_nonce('pushrelay_admin_nonce')); ?>'
        });
        
        window.location.href = ajaxurl + '?' + params.toString();
    });
    
    // Load chart on page load
    $('#analytics-chart').hide();
    setTimeout(loadChartData, 500);
    
});
</script>

<style>
.pushrelay-analytics {
    max-width: 1400px;
}

.pushrelay-metric-box {
    padding: 20px;
    background: #f9f9f9;
    border-radius: 8px;
    border: 1px solid #e0e0e0;
}

.metric-label {
    font-size: 13px;
    color: #666;
    margin-bottom: 8px;
    font-weight: 500;
}

.metric-value {
    font-size: 32px;
    font-weight: 700;
    margin-bottom: 10px;
}

.metric-bar {
    height: 8px;
    background: #e0e0e0;
    border-radius: 4px;
    overflow: hidden;
    margin-bottom: 8px;
}

.metric-bar-fill {
    height: 100%;
    transition: width 0.3s ease;
}

.metric-details {
    font-size: 12px;
    color: #888;
}

#analytics-chart {
    max-height: 400px;
}

@media (max-width: 782px) {
    .pushrelay-filters-form {
        flex-direction: column !important;
    }
    
    .pushrelay-filters-form .pushrelay-form-group {
        width: 100% !important;
        min-width: auto !important;
    }
}
</style>
