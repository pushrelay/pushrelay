<?php
/**
 * Dashboard View
 * 
 * Main dashboard with overview statistics and recent activity
 * 
 * @package PushRelay
 * @since 1.6.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

$api = pushrelay()->get_api_client();
$analytics = new PushRelay_Analytics();
$health_check = new PushRelay_Health_Check();

// Get overview data
$overview = $analytics->get_overview();
$health_status = $health_check->get_quick_status();
$insights = $analytics->get_insights();

// Handle errors
$has_error = is_wp_error($overview);
?>

<div class="wrap pushrelay-dashboard">
    <div class="pushrelay-header">
        <h1>
            <?php esc_html_e('PushRelay Dashboard', 'pushrelay'); ?>
        </h1>
        <button class="pushrelay-btn pushrelay-btn-primary pushrelay-refresh-stats">
            <?php esc_html_e('Refresh Stats', 'pushrelay'); ?>
        </button>
    </div>

    <!-- Notices Container -->
    <div class="pushrelay-notices"></div>

    <?php if ($has_error): ?>
        <div class="pushrelay-alert pushrelay-alert-danger">
            <strong><?php esc_html_e('Error:', 'pushrelay'); ?></strong>
            <?php echo esc_html($overview->get_error_message()); ?>
        </div>
    <?php else: ?>

        <!-- Health Status Banner -->
        <?php if ($health_status['critical_issues'] > 0): ?>
            <div class="pushrelay-alert pushrelay-alert-danger">
                <strong><?php esc_html_e('Action Required:', 'pushrelay'); ?></strong>
                <?php
                printf(
                    /* translators: %d: number of critical issues */
                    esc_html(_n('%d critical issue detected.', '%d critical issues detected.', intval($health_status['critical_issues']), 'pushrelay')),
                    intval($health_status['critical_issues'])
                );
                ?>
                <a href="<?php echo esc_url(admin_url('admin.php?page=pushrelay-health')); ?>">
                    <?php esc_html_e('View Details', 'pushrelay'); ?>
                </a>
            </div>
        <?php elseif ($health_status['warnings'] > 0): ?>
            <div class="pushrelay-alert pushrelay-alert-warning">
                <strong><?php esc_html_e('Warning:', 'pushrelay'); ?></strong>
                <?php
                printf(
                    /* translators: %d: number of warnings */
                    esc_html(_n('%d warning found.', '%d warnings found.', intval($health_status['warnings']), 'pushrelay')),
                    intval($health_status['warnings'])
                );
                ?>
                <a href="<?php echo esc_url(admin_url('admin.php?page=pushrelay-health')); ?>">
                    <?php esc_html_e('View Details', 'pushrelay'); ?>
                </a>
            </div>
        <?php endif; ?>

        <!-- Stats Grid -->
        <div class="pushrelay-stats-grid">
            <div class="pushrelay-stat-card card-primary">
                <h3><?php esc_html_e('Total Subscribers', 'pushrelay'); ?></h3>
                <p class="stat-number stat-subscribers">
                    <?php echo esc_html(number_format_i18n($overview['total_subscribers'])); ?>
                </p>
            </div>

            <div class="pushrelay-stat-card card-success">
                <h3><?php esc_html_e('Total Campaigns', 'pushrelay'); ?></h3>
                <p class="stat-number stat-campaigns">
                    <?php echo esc_html(number_format_i18n($overview['total_campaigns'])); ?>
                </p>
            </div>

            <div class="pushrelay-stat-card card-warning">
                <h3><?php esc_html_e('Click Rate (CTR)', 'pushrelay'); ?></h3>
                <p class="stat-number stat-ctr">
                    <?php echo esc_html($overview['click_rate']); ?>%
                </p>
            </div>

            <div class="pushrelay-stat-card card-info">
                <h3><?php esc_html_e('Display Rate', 'pushrelay'); ?></h3>
                <p class="stat-number stat-display-rate">
                    <?php echo esc_html($overview['display_rate']); ?>%
                </p>
            </div>
        </div>

        <!-- Two Column Layout -->
        <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 20px; margin-top: 20px;">
            
            <!-- Left Column: Recent Campaigns -->
            <div class="pushrelay-card">
                <div class="pushrelay-card-header">
                    <h2><?php esc_html_e('Recent Campaigns', 'pushrelay'); ?></h2>
                </div>
                <div class="pushrelay-card-body">
                    <?php if (!empty($overview['recent_campaigns'])): ?>
                        <table class="pushrelay-table">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e('Name', 'pushrelay'); ?></th>
                                    <th><?php esc_html_e('Status', 'pushrelay'); ?></th>
                                    <th><?php esc_html_e('Sent', 'pushrelay'); ?></th>
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
                                        <td>
                                            <span class="pushrelay-badge pushrelay-status-<?php echo esc_attr($campaign['status']); ?>">
                                                <?php echo esc_html(ucfirst($campaign['status'])); ?>
                                            </span>
                                        </td>
                                        <td><?php echo esc_html(number_format_i18n($sent)); ?></td>
                                        <td><?php echo esc_html(number_format_i18n($clicked)); ?></td>
                                        <td><?php echo esc_html($ctr); ?>%</td>
                                        <td><?php echo esc_html(date_i18n(get_option('date_format'), strtotime($campaign['datetime']))); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>

                        <p style="text-align: center; margin-top: 15px;">
                            <a href="<?php echo esc_url(admin_url('admin.php?page=pushrelay-campaigns')); ?>" class="pushrelay-btn pushrelay-btn-secondary">
                                <?php esc_html_e('View All Campaigns', 'pushrelay'); ?>
                            </a>
                        </p>
                    <?php else: ?>
                        <p style="text-align: center; padding: 40px 20px; color: #666;">
                            <?php esc_html_e('No campaigns yet. Create your first campaign to get started!', 'pushrelay'); ?>
                        </p>
                        <p style="text-align: center;">
                            <a href="<?php echo esc_url(admin_url('admin.php?page=pushrelay-campaigns')); ?>" class="pushrelay-btn pushrelay-btn-primary">
                                <?php esc_html_e('Create Campaign', 'pushrelay'); ?>
                            </a>
                        </p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Right Column: Health & Insights -->
            <div>
                <!-- Health Score -->
                <div class="pushrelay-card" style="margin-bottom: 20px;">
                    <div class="pushrelay-card-header">
                        <h2><?php esc_html_e('System Health', 'pushrelay'); ?></h2>
                    </div>
                    <div class="pushrelay-card-body">
                        <div class="pushrelay-health-score">
                            <?php
                            $score = $health_status['score'];
                            $score_class = 'score-bad';
                            if ($score >= 80) {
                                $score_class = 'score-good';
                            } elseif ($score >= 60) {
                                $score_class = 'score-warning';
                            }
                            ?>
                            <div class="pushrelay-health-score-value <?php echo esc_attr($score_class); ?>">
                                <?php echo esc_html($score); ?>%
                            </div>
                            <p style="text-align: center; margin: 10px 0 0 0; color: #666;">
                                <?php
                                if ($score >= 80) {
                                    esc_html_e('Excellent', 'pushrelay');
                                } elseif ($score >= 60) {
                                    esc_html_e('Good', 'pushrelay');
                                } else {
                                    esc_html_e('Needs Attention', 'pushrelay');
                                }
                                ?>
                            </p>
                        </div>
                        
                        <div style="margin-top: 20px; text-align: center;">
                            <a href="<?php echo esc_url(admin_url('admin.php?page=pushrelay-health')); ?>" class="pushrelay-btn pushrelay-btn-secondary pushrelay-btn-small">
                                <?php esc_html_e('View Details', 'pushrelay'); ?>
                            </a>
                            <button class="pushrelay-btn pushrelay-btn-primary pushrelay-btn-small pushrelay-run-health-check">
                                <?php esc_html_e('Run Check', 'pushrelay'); ?>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Insights -->
                <?php if (!empty($insights)): ?>
                    <div class="pushrelay-card">
                        <div class="pushrelay-card-header">
                            <h2><?php esc_html_e('Insights & Recommendations', 'pushrelay'); ?></h2>
                        </div>
                        <div class="pushrelay-card-body">
                            <?php foreach ($insights as $insight): ?>
                                <div class="pushrelay-alert pushrelay-alert-<?php echo esc_attr($insight['type']); ?>" style="margin-bottom: 15px;">
                                    <strong><?php echo esc_html($insight['title']); ?></strong>
                                    <p style="margin: 5px 0;"><?php echo esc_html($insight['message']); ?></p>
                                    <?php if (!empty($insight['action'])): ?>
                                        <p style="margin: 5px 0; font-style: italic;">
                                            💡 <?php echo esc_html($insight['action']); ?>
                                        </p>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Subscriber Growth Chart -->
        <div class="pushrelay-card" style="margin-top: 20px;">
            <div class="pushrelay-card-header">
                <h2><?php esc_html_e('Subscriber Growth (Last 30 Days)', 'pushrelay'); ?></h2>
            </div>
            <div class="pushrelay-card-body">
                <canvas id="pushrelay-subscriber-chart" style="max-height: 300px;"></canvas>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="pushrelay-card" style="margin-top: 20px;">
            <div class="pushrelay-card-header">
                <h2><?php esc_html_e('Quick Actions', 'pushrelay'); ?></h2>
            </div>
            <div class="pushrelay-card-body">
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
                    
                    <a href="<?php echo esc_url(admin_url('admin.php?page=pushrelay-campaigns')); ?>" class="pushrelay-btn pushrelay-btn-primary pushrelay-btn-block">
                        📢 <?php esc_html_e('Create Campaign', 'pushrelay'); ?>
                    </a>
                    
                    <a href="<?php echo esc_url(admin_url('admin.php?page=pushrelay-subscribers')); ?>" class="pushrelay-btn pushrelay-btn-secondary pushrelay-btn-block">
                        👥 <?php esc_html_e('View Subscribers', 'pushrelay'); ?>
                    </a>
                    
                    <a href="<?php echo esc_url(admin_url('admin.php?page=pushrelay-analytics')); ?>" class="pushrelay-btn pushrelay-btn-secondary pushrelay-btn-block">
                        📊 <?php esc_html_e('Analytics', 'pushrelay'); ?>
                    </a>
                    
                    <a href="<?php echo esc_url(admin_url('admin.php?page=pushrelay-settings')); ?>" class="pushrelay-btn pushrelay-btn-secondary pushrelay-btn-block">
                        ⚙️ <?php esc_html_e('Settings', 'pushrelay'); ?>
                    </a>
                    
                    <?php if (class_exists('WooCommerce')): ?>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=pushrelay-woocommerce')); ?>" class="pushrelay-btn pushrelay-btn-secondary pushrelay-btn-block">
                            🛒 <?php esc_html_e('WooCommerce', 'pushrelay'); ?>
                        </a>
                    <?php endif; ?>
                    
                    <a href="<?php echo esc_url(admin_url('admin.php?page=pushrelay-support')); ?>" class="pushrelay-btn pushrelay-btn-secondary pushrelay-btn-block">
                        🎫 <?php esc_html_e('Get Support', 'pushrelay'); ?>
                    </a>
                </div>
            </div>
        </div>

        <!-- Performance Overview -->
        <div class="pushrelay-card" style="margin-top: 20px;">
            <div class="pushrelay-card-header">
                <h2><?php esc_html_e('Performance Metrics', 'pushrelay'); ?></h2>
            </div>
            <div class="pushrelay-card-body">
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 20px; text-align: center;">
                    
                    <div>
                        <div style="font-size: 12px; color: #666; margin-bottom: 5px;">
                            <?php esc_html_e('Total Sent', 'pushrelay'); ?>
                        </div>
                        <div style="font-size: 24px; font-weight: bold; color: #333;">
                            <?php echo esc_html(number_format_i18n($overview['total_sent'])); ?>
                        </div>
                    </div>
                    
                    <div>
                        <div style="font-size: 12px; color: #666; margin-bottom: 5px;">
                            <?php esc_html_e('Total Displayed', 'pushrelay'); ?>
                        </div>
                        <div style="font-size: 24px; font-weight: bold; color: #333;">
                            <?php echo esc_html(number_format_i18n($overview['total_displayed'])); ?>
                        </div>
                    </div>
                    
                    <div>
                        <div style="font-size: 12px; color: #666; margin-bottom: 5px;">
                            <?php esc_html_e('Total Clicked', 'pushrelay'); ?>
                        </div>
                        <div style="font-size: 24px; font-weight: bold; color: #333;">
                            <?php echo esc_html(number_format_i18n($overview['total_clicked'])); ?>
                        </div>
                    </div>
                    
                    <div>
                        <div style="font-size: 12px; color: #666; margin-bottom: 5px;">
                            <?php esc_html_e('Total Closed', 'pushrelay'); ?>
                        </div>
                        <div style="font-size: 24px; font-weight: bold; color: #333;">
                            <?php echo esc_html(number_format_i18n($overview['total_closed'])); ?>
                        </div>
                    </div>
                    
                    <div>
                        <div style="font-size: 12px; color: #666; margin-bottom: 5px;">
                            <?php esc_html_e('Close Rate', 'pushrelay'); ?>
                        </div>
                        <div style="font-size: 24px; font-weight: bold; color: #333;">
                            <?php echo esc_html($overview['close_rate']); ?>%
                        </div>
                    </div>
                </div>
            </div>
        </div>

    <?php endif; ?>
</div>

<style>
.pushrelay-dashboard {
    max-width: 1400px;
}

.pushrelay-health-score {
    padding: 20px;
    text-align: center;
}

.pushrelay-health-score-value {
    font-size: 64px;
    font-weight: bold;
    line-height: 1;
}

.pushrelay-health-score-value.score-good {
    color: #28a745;
}

.pushrelay-health-score-value.score-warning {
    color: #ffc107;
}

.pushrelay-health-score-value.score-bad {
    color: #dc3545;
}

@media (max-width: 782px) {
    .pushrelay-dashboard > div[style*="grid-template-columns: 2fr 1fr"] {
        grid-template-columns: 1fr !important;
    }
}
</style>