<?php
/**
 * Health Check View
 * 
 * System diagnostics and health monitoring
 * 
 * @package PushRelay
 * @since 1.6.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Check if API is configured first
$settings = get_option('pushrelay_settings', array());
$api_configured = !empty($settings['api_key']) && !empty($settings['website_id']);

$health_check = new PushRelay_Health_Check();
$results = $health_check->run_all_checks();

// Extract checks from results structure
$checks = array();
if (is_array($results) && isset($results['checks']) && is_array($results['checks'])) {
    $checks = $results['checks'];
} elseif (is_array($results) && !isset($results['checks'])) {
    // Fallback: results might be the checks directly
    $checks = $results;
}

// Define check labels for display
$check_labels = array(
    'api_connection' => __('API Connection', 'pushrelay'),
    'api_key_valid' => __('API Key Validation', 'pushrelay'),
    'website_configured' => __('Website Configuration', 'pushrelay'),
    'service_worker' => __('Service Worker', 'pushrelay'),
    'database_tables' => __('Database Tables', 'pushrelay'),
    'cron_jobs' => __('Scheduled Tasks (Cron)', 'pushrelay'),
    'permissions' => __('File Permissions', 'pushrelay'),
    'ssl' => __('SSL Certificate', 'pushrelay'),
    'php_version' => __('PHP Version', 'pushrelay'),
    'wordpress_version' => __('WordPress Version', 'pushrelay'),
);
?>

<div class="wrap pushrelay-health-check">
    <div class="pushrelay-header">
        <h1><?php esc_html_e('Health Check', 'pushrelay'); ?></h1>
        <div class="pushrelay-header-actions">
            <button type="button" id="fix-issues" class="pushrelay-btn pushrelay-btn-secondary" style="margin-right: 10px;">
                <?php esc_html_e('🔧 Fix Issues', 'pushrelay'); ?>
            </button>
            <button type="button" id="run-health-check" class="pushrelay-btn pushrelay-btn-primary">
                <?php esc_html_e('🔄 Run Checks', 'pushrelay'); ?>
            </button>
        </div>
    </div>

    <!-- Notices Container -->
    <div class="pushrelay-notices"></div>

    <?php if (!$api_configured): ?>
    <!-- API Not Configured Notice -->
    <div class="pushrelay-card" style="margin-bottom: 20px;">
        <div class="pushrelay-card-body">
            <div class="pushrelay-alert pushrelay-alert-warning">
                <strong><?php esc_html_e('API Not Configured', 'pushrelay'); ?></strong>
                <p><?php esc_html_e('Please configure your API key and website in Settings before running health checks.', 'pushrelay'); ?></p>
                <a href="<?php echo esc_url(admin_url('admin.php?page=pushrelay-settings')); ?>" class="pushrelay-btn pushrelay-btn-primary">
                    <?php esc_html_e('Go to Settings', 'pushrelay'); ?>
                </a>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Overall Status -->
    <div class="pushrelay-card" style="margin-bottom: 20px;">
        <div class="pushrelay-card-header">
            <h2><?php esc_html_e('System Status', 'pushrelay'); ?></h2>
        </div>
        <div class="pushrelay-card-body">
            <?php
            $total_checks = is_array($checks) ? count($checks) : 0;
            $passed_checks = 0;
            if (is_array($checks)) {
                foreach ($checks as $check) {
                    if (is_array($check) && isset($check['status']) && ($check['status'] === 'pass' || $check['status'] === 'success')) {
                        $passed_checks++;
                    }
                }
            }
            $health_percentage = ($total_checks > 0) ? round(($passed_checks / $total_checks) * 100) : 0;
            ?>
            
            <div class="health-status-summary">
                <div class="health-circle <?php echo esc_attr($health_percentage >= 80 ? 'health-good' : ($health_percentage >= 50 ? 'health-warning' : 'health-error')); ?>">
                    <span class="health-percentage"><?php echo esc_html($health_percentage); ?>%</span>
                    <span class="health-label"><?php esc_html_e('System Health', 'pushrelay'); ?></span>
                </div>
                
                <div class="health-summary-stats">
                    <div class="stat-item">
                        <span class="stat-icon pass">✓</span>
                        <span class="stat-value"><?php echo esc_html($passed_checks); ?></span>
                        <span class="stat-label"><?php esc_html_e('Passed', 'pushrelay'); ?></span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-icon fail">✗</span>
                        <span class="stat-value"><?php echo esc_html($total_checks - $passed_checks); ?></span>
                        <span class="stat-label"><?php esc_html_e('Failed', 'pushrelay'); ?></span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-icon total">●</span>
                        <span class="stat-value"><?php echo esc_html($total_checks); ?></span>
                        <span class="stat-label"><?php esc_html_e('Total Checks', 'pushrelay'); ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Detailed Checks -->
    <div class="pushrelay-card">
        <div class="pushrelay-card-header">
            <h2><?php esc_html_e('Diagnostic Results', 'pushrelay'); ?></h2>
        </div>
        <div class="pushrelay-card-body">
            <div class="health-checks-list">
                <?php if (empty($checks)): ?>
                    <p><?php esc_html_e('No diagnostic data available. Click "Run Checks" to perform a health check.', 'pushrelay'); ?></p>
                <?php else: ?>
                    <?php foreach ($checks as $check_id => $check): ?>
                        <?php 
                        // Safety check: ensure $check is a valid array
                        if (!is_array($check)) {
                            continue;
                        }
                        
                        // Get status - normalize 'success' to 'pass' for display
                        $status = isset($check['status']) ? $check['status'] : 'unknown';
                        if ($status === 'success') {
                            $status = 'pass';
                        }
                        
                        // Get label from our labels array or use check_id
                        $label = isset($check_labels[$check_id]) ? $check_labels[$check_id] : ucwords(str_replace('_', ' ', $check_id));
                        
                        // Get message/description
                        $description = isset($check['message']) ? $check['message'] : '';
                        
                        // Get details
                        $details = isset($check['details']) ? $check['details'] : array();
                        ?>
                        <div class="health-check-item <?php echo esc_attr($status); ?>">
                            <div class="check-header">
                                <span class="check-icon">
                                    <?php if ($status === 'pass'): ?>
                                        <span class="dashicons dashicons-yes-alt" style="color: #28a745;"></span>
                                    <?php elseif ($status === 'warning'): ?>
                                        <span class="dashicons dashicons-warning" style="color: #ffc107;"></span>
                                    <?php else: ?>
                                        <span class="dashicons dashicons-dismiss" style="color: #dc3545;"></span>
                                    <?php endif; ?>
                                </span>
                                <h3 class="check-title"><?php echo esc_html($label); ?></h3>
                                <span class="check-status-badge <?php echo esc_attr($status); ?>">
                                    <?php echo esc_html(ucfirst($status)); ?>
                                </span>
                            </div>
                            
                            <?php if (!empty($description)): ?>
                            <div class="check-description">
                                <?php echo esc_html($description); ?>
                            </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($details) && is_array($details)): ?>
                                <div class="check-data">
                                    <details>
                                        <summary><?php esc_html_e('View Details', 'pushrelay'); ?></summary>
                                        <table class="pushrelay-detail-table" style="margin-top: 10px;">
                                            <?php foreach ($details as $key => $value): ?>
                                            <tr>
                                                <td><strong><?php echo esc_html(ucwords(str_replace('_', ' ', $key))); ?>:</strong></td>
                                                <td><?php echo esc_html(is_array($value) ? wp_json_encode($value) : $value); ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </table>
                                    </details>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- System Information -->
    <div class="pushrelay-card" style="margin-top: 20px;">
        <div class="pushrelay-card-header">
            <h2><?php esc_html_e('System Information', 'pushrelay'); ?></h2>
        </div>
        <div class="pushrelay-card-body">
            <table class="pushrelay-detail-table">
                <tr>
                    <th><?php esc_html_e('WordPress Version:', 'pushrelay'); ?></th>
                    <td><?php echo esc_html(get_bloginfo('version')); ?></td>
                </tr>
                <tr>
                    <th><?php esc_html_e('PHP Version:', 'pushrelay'); ?></th>
                    <td><?php echo esc_html(phpversion()); ?></td>
                </tr>
                <tr>
                    <th><?php esc_html_e('MySQL Version:', 'pushrelay'); ?></th>
                    <td><?php global $wpdb; echo esc_html($wpdb->db_version()); ?></td>
                </tr>
                <tr>
                    <th><?php esc_html_e('Server Software:', 'pushrelay'); ?></th>
                    <td><?php echo esc_html(isset($_SERVER['SERVER_SOFTWARE']) ? sanitize_text_field(wp_unslash($_SERVER['SERVER_SOFTWARE'])) : 'Unknown'); ?></td>
                </tr>
                <tr>
                    <th><?php esc_html_e('PushRelay Version:', 'pushrelay'); ?></th>
                    <td><?php echo esc_html(defined('PUSHRELAY_VERSION') ? PUSHRELAY_VERSION : '1.6.0'); ?></td>
                </tr>
                <tr>
                    <th><?php esc_html_e('Active Theme:', 'pushrelay'); ?></th>
                    <td><?php $theme = wp_get_theme(); echo esc_html($theme->get('Name') . ' ' . $theme->get('Version')); ?></td>
                </tr>
                <tr>
                    <th><?php esc_html_e('Memory Limit:', 'pushrelay'); ?></th>
                    <td><?php echo esc_html(WP_MEMORY_LIMIT); ?></td>
                </tr>
                <tr>
                    <th><?php esc_html_e('Max Execution Time:', 'pushrelay'); ?></th>
                    <td><?php echo esc_html(ini_get('max_execution_time')); ?>s</td>
                </tr>
            </table>
        </div>
    </div>

</div>

<script>
jQuery(document).ready(function($) {
    
    // Remove global AJAX handlers that might conflict (wp-codemirror fix)
    $(document).off('ajaxComplete');
    $(document).off('ajaxSend');
    
    // Run health check using fetch API
    $('#run-health-check').on('click', function() {
        var $button = $(this);
        $button.prop('disabled', true).html('<?php esc_js(__('⏳ Running...', 'pushrelay')); ?>');
        
        var formData = new FormData();
        formData.append('action', 'pushrelay_run_health_check');
        formData.append('nonce', '<?php echo esc_attr(wp_create_nonce('pushrelay_admin_nonce')); ?>');
        
        fetch(ajaxurl, {
            method: 'POST',
            body: formData
        })
        .then(function(response) { return response.json(); })
        .then(function(data) {
            if (data.success) {
                location.reload();
            } else {
                alert('<?php esc_js(__('Error running health check', 'pushrelay')); ?>');
                $button.prop('disabled', false).html('<?php esc_js(__('🔄 Run Checks', 'pushrelay')); ?>');
            }
        })
        .catch(function() {
            alert('<?php esc_js(__('Connection error', 'pushrelay')); ?>');
            $button.prop('disabled', false).html('<?php esc_js(__('🔄 Run Checks', 'pushrelay')); ?>');
        });
    });
    
});
</script>

<style>
.pushrelay-health-check {
    max-width: 1200px;
}

.health-status-summary {
    display: flex;
    align-items: center;
    gap: 40px;
    padding: 20px;
}

.health-circle {
    width: 150px;
    height: 150px;
    border-radius: 50%;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    border: 8px solid;
    position: relative;
}

.health-circle.health-good {
    border-color: #28a745;
    background: rgba(40, 167, 69, 0.1);
}

.health-circle.health-warning {
    border-color: #ffc107;
    background: rgba(255, 193, 7, 0.1);
}

.health-circle.health-error {
    border-color: #dc3545;
    background: rgba(220, 53, 69, 0.1);
}

.health-percentage {
    font-size: 36px;
    font-weight: 700;
    line-height: 1;
}

.health-label {
    font-size: 12px;
    margin-top: 5px;
    color: #666;
}

.health-summary-stats {
    display: flex;
    gap: 30px;
}

.stat-item {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 5px;
}

.stat-icon {
    font-size: 24px;
}

.stat-icon.pass {
    color: #28a745;
}

.stat-icon.fail {
    color: #dc3545;
}

.stat-icon.total {
    color: #007cba;
}

.stat-value {
    font-size: 28px;
    font-weight: 700;
}

.stat-label {
    font-size: 12px;
    color: #666;
}

.health-checks-list {
    display: flex;
    flex-direction: column;
    gap: 15px;
}

.health-check-item {
    border: 1px solid #ddd;
    border-radius: 8px;
    padding: 20px;
    background: #fff;
}

.health-check-item.pass {
    border-left: 4px solid #28a745;
}

.health-check-item.warning {
    border-left: 4px solid #ffc107;
}

.health-check-item.fail {
    border-left: 4px solid #dc3545;
}

.check-header {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 10px;
}

.check-icon {
    font-size: 24px;
}

.check-title {
    flex: 1;
    margin: 0;
    font-size: 16px;
}

.check-status-badge {
    padding: 4px 12px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 600;
}

.check-status-badge.pass {
    background: #d4edda;
    color: #155724;
}

.check-status-badge.warning {
    background: #fff3cd;
    color: #856404;
}

.check-status-badge.fail {
    background: #f8d7da;
    color: #721c24;
}

.check-description {
    color: #666;
    margin-bottom: 10px;
}

.check-actions {
    background: #f8f9fa;
    padding: 15px;
    border-radius: 4px;
    margin-top: 10px;
}

.check-actions ul {
    margin: 10px 0 0 20px;
}

.check-data details {
    margin-top: 10px;
}

.check-data summary {
    cursor: pointer;
    color: #007cba;
    font-weight: 600;
}

.check-data pre {
    background: #f5f5f5;
    padding: 10px;
    border-radius: 4px;
    overflow-x: auto;
    font-size: 12px;
}

.pushrelay-detail-table {
    width: 100%;
    border-collapse: collapse;
}

.pushrelay-detail-table tr {
    border-bottom: 1px solid #eee;
}

.pushrelay-detail-table th,
.pushrelay-detail-table td {
    padding: 12px;
    text-align: left;
}

.pushrelay-detail-table th {
    font-weight: 600;
    color: #666;
    width: 30%;
}

@media (max-width: 782px) {
    .health-status-summary {
        flex-direction: column;
    }
    
    .health-summary-stats {
        flex-direction: column;
        width: 100%;
    }
}

.pushrelay-header-actions {
    display: flex;
    align-items: center;
    gap: 10px;
}
</style>

<script>
jQuery(document).ready(function($) {
    // Run Health Check button
    $('#run-health-check').on('click', function(e) {
        e.preventDefault();
        
        var $btn = $(this);
        var originalText = $btn.html();
        
        $btn.html('<?php echo esc_js(__('Running...', 'pushrelay')); ?>').prop('disabled', true);
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'pushrelay_run_health_check',
                nonce: '<?php echo esc_js(wp_create_nonce('pushrelay_admin_nonce')); ?>'
            },
            success: function(response) {
                if (response.success) {
                    location.reload();
                } else {
                    alert(response.data.message || '<?php echo esc_js(__('Error running health check', 'pushrelay')); ?>');
                }
            },
            error: function() {
                alert('<?php echo esc_js(__('Error running health check', 'pushrelay')); ?>');
            },
            complete: function() {
                $btn.html(originalText).prop('disabled', false);
            }
        });
    });
    
    // Fix Issues button
    $('#fix-issues').on('click', function(e) {
        e.preventDefault();
        
        var $btn = $(this);
        var originalText = $btn.html();
        
        $btn.html('<?php echo esc_js(__('Fixing...', 'pushrelay')); ?>').prop('disabled', true);
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'pushrelay_fix_issues',
                nonce: '<?php echo esc_js(wp_create_nonce('pushrelay_admin_nonce')); ?>'
            },
            success: function(response) {
                if (response.success) {
                    var message = '<?php echo esc_js(__('Issues fixed:', 'pushrelay')); ?>\n';
                    if (response.data.fixed && response.data.fixed.length > 0) {
                        message += response.data.fixed.join('\n');
                    }
                    if (response.data.failed && response.data.failed.length > 0) {
                        message += '\n\n<?php echo esc_js(__('Failed:', 'pushrelay')); ?>\n' + response.data.failed.join('\n');
                    }
                    alert(message);
                    location.reload();
                } else {
                    alert(response.data.message || '<?php echo esc_js(__('Error fixing issues', 'pushrelay')); ?>');
                }
            },
            error: function() {
                alert('<?php echo esc_js(__('Error fixing issues', 'pushrelay')); ?>');
            },
            complete: function() {
                $btn.html(originalText).prop('disabled', false);
            }
        });
    });
});
</script>
