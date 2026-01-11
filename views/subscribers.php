<?php
/**
 * Subscribers View
 * 
 * Subscriber management interface - list, view, edit, delete
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

// Get action
$action = isset($_GET['action']) ? sanitize_text_field(wp_unslash($_GET['action'])) : 'list';
$subscriber_id = isset($_GET['subscriber_id']) ? absint($_GET['subscriber_id']) : 0;

// Get subscribers
$page = isset($_GET['paged']) ? absint($_GET['paged']) : 1;
$per_page = isset($_GET['per_page']) ? absint($_GET['per_page']) : 25;
$website_filter = isset($_GET['website_id']) ? absint($_GET['website_id']) : 0;
$search = isset($_GET['search']) ? sanitize_text_field(wp_unslash($_GET['search'])) : '';

// Build params
$params = array(
    'page' => $page,
    'results_per_page' => $per_page
);

if ($website_filter > 0) {
    $params['website_id'] = $website_filter;
}

if (!empty($search)) {
    $params['search'] = $search;
    $params['search_by'] = 'ip';
}

// Get subscribers from API
$subscribers_response = $api->get_subscribers($params);
$subscribers = !is_wp_error($subscribers_response) && isset($subscribers_response['data']) ? $subscribers_response['data'] : array();
$total_subscribers = !is_wp_error($subscribers_response) && isset($subscribers_response['meta']['total']) ? $subscribers_response['meta']['total'] : 0;

// Get websites for filter
$websites_response = $api->get_websites();
$websites = !is_wp_error($websites_response) && isset($websites_response['data']) ? $websites_response['data'] : array();

// Calculate subscriber stats manually
$stats = array(
    'active_today' => 0,
    'active_week' => 0,
    'active_month' => 0
);

$now = current_time('timestamp');
$today = gmdate('Y-m-d', $now);
$week_ago = gmdate('Y-m-d', strtotime('-7 days', $now));
$month_ago = gmdate('Y-m-d', strtotime('-30 days', $now));

foreach ($subscribers as $subscriber) {
    $last_sent = isset($subscriber['last_sent_datetime']) && !empty($subscriber['last_sent_datetime']) ? $subscriber['last_sent_datetime'] : '';
    
    if (!empty($last_sent)) {
        $sent_date = gmdate('Y-m-d', strtotime($last_sent));
        
        if ($sent_date === $today) {
            $stats['active_today']++;
        }
        if ($sent_date >= $week_ago) {
            $stats['active_week']++;
        }
        if ($sent_date >= $month_ago) {
            $stats['active_month']++;
        }
    }
}
?>

<div class="wrap pushrelay-subscribers">
    <div class="pushrelay-header">
        <h1>
            <?php 
            if ($action === 'view') {
                esc_html_e('Subscriber Details', 'pushrelay');
            } else {
                esc_html_e('Subscribers', 'pushrelay');
            }
            ?>
        </h1>
        <?php if ($action === 'view'): ?>
            <a href="<?php echo esc_url(admin_url('admin.php?page=pushrelay-subscribers')); ?>" class="pushrelay-btn pushrelay-btn-secondary">
                <?php esc_html_e('← Back to Subscribers', 'pushrelay'); ?>
            </a>
        <?php else: ?>
            <button type="button" id="export-subscribers-btn" class="pushrelay-btn pushrelay-btn-secondary">
                <?php esc_html_e('📥 Export CSV', 'pushrelay'); ?>
            </button>
        <?php endif; ?>
    </div>

    <!-- Notices Container -->
    <div class="pushrelay-notices"></div>

    <?php if ($action === 'list'): ?>
        
        <!-- Subscriber Stats -->
        <div class="pushrelay-stats-grid" style="margin-bottom: 20px;">
            <div class="pushrelay-stat-card card-primary">
                <h3><?php esc_html_e('Total Subscribers', 'pushrelay'); ?></h3>
                <p class="stat-number"><?php echo esc_html(number_format_i18n($total_subscribers)); ?></p>
            </div>

            <div class="pushrelay-stat-card card-info">
                <h3><?php esc_html_e('Active Today', 'pushrelay'); ?></h3>
                <p class="stat-number"><?php echo esc_html(number_format_i18n($stats['active_today'])); ?></p>
            </div>

            <div class="pushrelay-stat-card card-success">
                <h3><?php esc_html_e('Active This Week', 'pushrelay'); ?></h3>
                <p class="stat-number"><?php echo esc_html(number_format_i18n($stats['active_week'])); ?></p>
            </div>

            <div class="pushrelay-stat-card card-warning">
                <h3><?php esc_html_e('Active This Month', 'pushrelay'); ?></h3>
                <p class="stat-number"><?php echo esc_html(number_format_i18n($stats['active_month'])); ?></p>
            </div>
        </div>

        <!-- Filters -->
        <div class="pushrelay-card" style="margin-bottom: 20px;">
            <div class="pushrelay-card-header">
                <h2><?php esc_html_e('Filters', 'pushrelay'); ?></h2>
            </div>
            <div class="pushrelay-card-body">
                <form method="get" class="pushrelay-filters-form" style="display: flex; gap: 15px; flex-wrap: wrap; align-items: flex-end;">
                    <input type="hidden" name="page" value="pushrelay-subscribers">
                    
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
                    
                    <div class="pushrelay-form-group" style="flex: 1; min-width: 200px;">
                        <label class="pushrelay-form-label" for="search-filter">
                            <?php esc_html_e('Search IP', 'pushrelay'); ?>
                        </label>
                        <input type="text" 
                               name="search" 
                               id="search-filter" 
                               class="pushrelay-form-control"
                               value="<?php echo esc_attr($search); ?>" 
                               placeholder="<?php esc_attr_e('Search by IP...', 'pushrelay'); ?>">
                    </div>
                    
                    <div class="pushrelay-form-group" style="min-width: 150px;">
                        <label class="pushrelay-form-label" for="per-page-filter">
                            <?php esc_html_e('Per Page', 'pushrelay'); ?>
                        </label>
                        <select name="per_page" id="per-page-filter" class="pushrelay-form-control">
                            <option value="10" <?php selected($per_page, 10); ?>>10</option>
                            <option value="25" <?php selected($per_page, 25); ?>>25</option>
                            <option value="50" <?php selected($per_page, 50); ?>>50</option>
                            <option value="100" <?php selected($per_page, 100); ?>>100</option>
                            <option value="250" <?php selected($per_page, 250); ?>>250</option>
                        </select>
                    </div>
                    
                    <div class="pushrelay-form-group">
                        <button type="submit" class="pushrelay-btn pushrelay-btn-primary">
                            <?php esc_html_e('Apply Filters', 'pushrelay'); ?>
                        </button>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=pushrelay-subscribers')); ?>" class="pushrelay-btn pushrelay-btn-secondary">
                            <?php esc_html_e('Reset', 'pushrelay'); ?>
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Subscribers List -->
        <div class="pushrelay-card">
            <div class="pushrelay-card-header">
                <h2><?php esc_html_e('All Subscribers', 'pushrelay'); ?></h2>
            </div>
            <div class="pushrelay-card-body">
                <?php if (!empty($subscribers)): ?>
                    <div style="overflow-x: auto;">
                        <table class="pushrelay-table">
                            <thead>
                                <tr>
                                    <th style="width: 80px;"><?php esc_html_e('ID', 'pushrelay'); ?></th>
                                    <th><?php esc_html_e('Location', 'pushrelay'); ?></th>
                                    <th><?php esc_html_e('Device', 'pushrelay'); ?></th>
                                    <th><?php esc_html_e('Browser', 'pushrelay'); ?></th>
                                    <th><?php esc_html_e('Sent', 'pushrelay'); ?></th>
                                    <th><?php esc_html_e('Displayed', 'pushrelay'); ?></th>
                                    <th><?php esc_html_e('Clicked', 'pushrelay'); ?></th>
                                    <th><?php esc_html_e('CTR', 'pushrelay'); ?></th>
                                    <th><?php esc_html_e('Last Activity', 'pushrelay'); ?></th>
                                    <th><?php esc_html_e('Actions', 'pushrelay'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($subscribers as $subscriber): ?>
                                    <?php
                                    $sent = isset($subscriber['total_sent_push_notifications']) ? absint($subscriber['total_sent_push_notifications']) : 0;
                                    $displayed = isset($subscriber['total_displayed_push_notifications']) ? absint($subscriber['total_displayed_push_notifications']) : 0;
                                    $clicked = isset($subscriber['total_clicked_push_notifications']) ? absint($subscriber['total_clicked_push_notifications']) : 0;
                                    $ctr = $displayed > 0 ? round(($clicked / $displayed) * 100, 2) : 0;
                                    
                                    $city = isset($subscriber['city_name']) && !empty($subscriber['city_name']) ? $subscriber['city_name'] : 'Unknown';
                                    $country = isset($subscriber['country_code']) && !empty($subscriber['country_code']) ? $subscriber['country_code'] : 'XX';
                                    $continent = isset($subscriber['continent_code']) && !empty($subscriber['continent_code']) ? $subscriber['continent_code'] : 'XX';
                                    $device = isset($subscriber['device_type']) && !empty($subscriber['device_type']) ? $subscriber['device_type'] : 'unknown';
                                    $os = isset($subscriber['os_name']) && !empty($subscriber['os_name']) ? $subscriber['os_name'] : 'Unknown';
                                    $browser = isset($subscriber['browser_name']) && !empty($subscriber['browser_name']) ? $subscriber['browser_name'] : 'Unknown';
                                    $language = isset($subscriber['browser_language']) && !empty($subscriber['browser_language']) ? $subscriber['browser_language'] : 'en';
                                    $ip = isset($subscriber['ip']) && !empty($subscriber['ip']) ? $subscriber['ip'] : 'N/A';
                                    ?>
                                    <tr>
                                        <td>
                                            <strong>#<?php echo esc_html($subscriber['id']); ?></strong>
                                            <br><small style="color: #666;"><?php echo esc_html($ip); ?></small>
                                        </td>
                                        <td>
                                            <strong><?php echo esc_html($city); ?></strong>
                                            <br>
                                            <small style="color: #666;">
                                                <?php echo esc_html($country); ?> - 
                                                <?php echo esc_html($continent); ?>
                                            </small>
                                        </td>
                                        <td>
                                            <strong><?php echo esc_html(ucfirst($device)); ?></strong>
                                            <br>
                                            <small style="color: #666;"><?php echo esc_html($os); ?></small>
                                        </td>
                                        <td>
                                            <strong><?php echo esc_html($browser); ?></strong>
                                            <br>
                                            <small style="color: #666;"><?php echo esc_html($language); ?></small>
                                        </td>
                                        <td><?php echo esc_html(number_format_i18n($sent)); ?></td>
                                        <td><?php echo esc_html(number_format_i18n($displayed)); ?></td>
                                        <td><?php echo esc_html(number_format_i18n($clicked)); ?></td>
                                        <td>
                                            <strong style="color: <?php echo esc_attr($ctr > 5 ? '#28a745' : ($ctr > 2 ? '#ffc107' : '#dc3545')); ?>">
                                                <?php echo esc_html($ctr); ?>%
                                            </strong>
                                        </td>
                                        <td>
                                            <?php 
                                            $last_sent = isset($subscriber['last_sent_datetime']) && !empty($subscriber['last_sent_datetime']) ? $subscriber['last_sent_datetime'] : '';
                                            if (!empty($last_sent)): 
                                            ?>
                                                <strong><?php echo esc_html(human_time_diff(strtotime($last_sent), current_time('timestamp'))); ?></strong>
                                                <?php esc_html_e('ago', 'pushrelay'); ?>
                                            <?php else: ?>
                                                <span style="color: #999;"><?php esc_html_e('No activity', 'pushrelay'); ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <a href="<?php echo esc_url(admin_url('admin.php?page=pushrelay-subscribers&action=view&subscriber_id=' . $subscriber['id'])); ?>" 
                                               class="pushrelay-btn pushrelay-btn-small">
                                                <?php esc_html_e('View', 'pushrelay'); ?>
                                            </a>
                                            
                                            <button class="pushrelay-btn pushrelay-btn-small pushrelay-btn-danger pushrelay-delete-subscriber" 
                                                    data-subscriber-id="<?php echo esc_attr($subscriber['id']); ?>">
                                                <?php esc_html_e('Delete', 'pushrelay'); ?>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <?php
                    $total_pages = ceil($total_subscribers / $per_page);
                    if ($total_pages > 1):
                    ?>
                        <div class="pushrelay-pagination" style="margin-top: 20px; display: flex; justify-content: space-between; align-items: center;">
                            <div>
                                <?php
                                $start = (($page - 1) * $per_page) + 1;
                                $end = min($page * $per_page, $total_subscribers);
                                printf(
                                    /* translators: 1: Start number, 2: End number, 3: Total subscribers */
                                    esc_html__('Showing %1$d to %2$d of %3$d subscribers', 'pushrelay'),
                                    intval($start),
                                    intval($end),
                                    intval($total_subscribers)
                                );
                                ?>
                            </div>
                            <div style="display: flex; gap: 5px;">
                                <?php
                                $base_url = add_query_arg(array(
                                    'page' => 'pushrelay-subscribers',
                                    'website_id' => $website_filter,
                                    'search' => $search,
                                    'per_page' => $per_page
                                ), admin_url('admin.php'));
                                
                                // First/Previous
                                if ($page > 1) {
                                    echo '<a href="' . esc_url(add_query_arg('paged', 1, $base_url)) . '" class="pushrelay-btn pushrelay-btn-small">&laquo;</a>';
                                    echo '<a href="' . esc_url(add_query_arg('paged', $page - 1, $base_url)) . '" class="pushrelay-btn pushrelay-btn-small">&lsaquo;</a>';
                                }
                                
                                // Page numbers
                                $range = 2;
                                for ($i = max(1, $page - $range); $i <= min($total_pages, $page + $range); $i++) {
                                    $class = ($i === $page) ? 'pushrelay-btn pushrelay-btn-small pushrelay-btn-primary' : 'pushrelay-btn pushrelay-btn-small';
                                    echo '<a href="' . esc_url(add_query_arg('paged', $i, $base_url)) . '" class="' . esc_attr($class) . '">' . intval($i) . '</a>';
                                }
                                
                                // Next/Last
                                if ($page < $total_pages) {
                                    echo '<a href="' . esc_url(add_query_arg('paged', $page + 1, $base_url)) . '" class="pushrelay-btn pushrelay-btn-small">&rsaquo;</a>';
                                    echo '<a href="' . esc_url(add_query_arg('paged', $total_pages, $base_url)) . '" class="pushrelay-btn pushrelay-btn-small">&raquo;</a>';
                                }
                                ?>
                            </div>
                        </div>
                    <?php endif; ?>

                <?php else: ?>
                    <div style="text-align: center; padding: 60px 20px;">
                        <div style="font-size: 48px; margin-bottom: 20px;">👥</div>
                        <h3><?php esc_html_e('No subscribers yet', 'pushrelay'); ?></h3>
                        <p><?php esc_html_e('When users subscribe to your push notifications, they will appear here.', 'pushrelay'); ?></p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

    <?php elseif ($action === 'view' && $subscriber_id > 0): ?>

        <!-- Subscriber Details -->
        <?php
        $subscriber_response = $api->get_subscriber($subscriber_id);
        $subscriber = !is_wp_error($subscriber_response) && isset($subscriber_response['data']) ? $subscriber_response['data'] : null;
        ?>

        <?php if ($subscriber): ?>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                
                <!-- Basic Info -->
                <div class="pushrelay-card">
                    <div class="pushrelay-card-header">
                        <h2><?php esc_html_e('Basic Information', 'pushrelay'); ?></h2>
                    </div>
                    <div class="pushrelay-card-body">
                        <table class="pushrelay-detail-table">
                            <tr>
                                <th><?php esc_html_e('ID:', 'pushrelay'); ?></th>
                                <td><?php echo esc_html($subscriber['id']); ?></td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('IP Address:', 'pushrelay'); ?></th>
                                <td><?php echo esc_html(isset($subscriber['ip']) ? $subscriber['ip'] : 'N/A'); ?></td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('Endpoint ID:', 'pushrelay'); ?></th>
                                <td><code style="font-size: 11px;"><?php echo esc_html(isset($subscriber['unique_endpoint_id']) ? $subscriber['unique_endpoint_id'] : 'N/A'); ?></code></td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('Subscribed:', 'pushrelay'); ?></th>
                                <td><?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($subscriber['datetime']))); ?></td>
                            </tr>
                            <?php if (isset($subscriber['subscribed_on_url']) && !empty($subscriber['subscribed_on_url'])): ?>
                            <tr>
                                <th><?php esc_html_e('Subscribed On:', 'pushrelay'); ?></th>
                                <td><a href="<?php echo esc_url($subscriber['subscribed_on_url']); ?>" target="_blank"><?php echo esc_html($subscriber['subscribed_on_url']); ?></a></td>
                            </tr>
                            <?php endif; ?>
                        </table>
                    </div>
                </div>

                <!-- Location -->
                <div class="pushrelay-card">
                    <div class="pushrelay-card-header">
                        <h2><?php esc_html_e('Location', 'pushrelay'); ?></h2>
                    </div>
                    <div class="pushrelay-card-body">
                        <table class="pushrelay-detail-table">
                            <tr>
                                <th><?php esc_html_e('City:', 'pushrelay'); ?></th>
                                <td><?php echo esc_html(isset($subscriber['city_name']) ? $subscriber['city_name'] : 'Unknown'); ?></td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('Country:', 'pushrelay'); ?></th>
                                <td><?php echo esc_html(isset($subscriber['country_code']) ? $subscriber['country_code'] : 'XX'); ?></td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('Continent:', 'pushrelay'); ?></th>
                                <td><?php echo esc_html(isset($subscriber['continent_code']) ? $subscriber['continent_code'] : 'XX'); ?></td>
                            </tr>
                        </table>
                    </div>
                </div>

                <!-- Device Info -->
                <div class="pushrelay-card">
                    <div class="pushrelay-card-header">
                        <h2><?php esc_html_e('Device Information', 'pushrelay'); ?></h2>
                    </div>
                    <div class="pushrelay-card-body">
                        <table class="pushrelay-detail-table">
                            <tr>
                                <th><?php esc_html_e('Device Type:', 'pushrelay'); ?></th>
                                <td><?php echo esc_html(isset($subscriber['device_type']) ? ucfirst($subscriber['device_type']) : 'Unknown'); ?></td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('Operating System:', 'pushrelay'); ?></th>
                                <td><?php echo esc_html(isset($subscriber['os_name']) ? $subscriber['os_name'] : 'Unknown'); ?></td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('Browser:', 'pushrelay'); ?></th>
                                <td><?php echo esc_html(isset($subscriber['browser_name']) ? $subscriber['browser_name'] : 'Unknown'); ?></td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('Language:', 'pushrelay'); ?></th>
                                <td><?php echo esc_html(isset($subscriber['browser_language']) ? $subscriber['browser_language'] : 'en'); ?></td>
                            </tr>
                        </table>
                    </div>
                </div>

                <!-- Statistics -->
                <div class="pushrelay-card">
                    <div class="pushrelay-card-header">
                        <h2><?php esc_html_e('Statistics', 'pushrelay'); ?></h2>
                    </div>
                    <div class="pushrelay-card-body">
                        <table class="pushrelay-detail-table">
                            <tr>
                                <th><?php esc_html_e('Sent:', 'pushrelay'); ?></th>
                                <td><?php echo esc_html(number_format_i18n(isset($subscriber['total_sent_push_notifications']) ? $subscriber['total_sent_push_notifications'] : 0)); ?></td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('Displayed:', 'pushrelay'); ?></th>
                                <td><?php echo esc_html(number_format_i18n(isset($subscriber['total_displayed_push_notifications']) ? $subscriber['total_displayed_push_notifications'] : 0)); ?></td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('Clicked:', 'pushrelay'); ?></th>
                                <td><?php echo esc_html(number_format_i18n(isset($subscriber['total_clicked_push_notifications']) ? $subscriber['total_clicked_push_notifications'] : 0)); ?></td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('Closed:', 'pushrelay'); ?></th>
                                <td><?php echo esc_html(number_format_i18n(isset($subscriber['total_closed_push_notifications']) ? $subscriber['total_closed_push_notifications'] : 0)); ?></td>
                            </tr>
                            <?php if (isset($subscriber['last_sent_datetime']) && !empty($subscriber['last_sent_datetime'])): ?>
                            <tr>
                                <th><?php esc_html_e('Last Activity:', 'pushrelay'); ?></th>
                                <td><?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($subscriber['last_sent_datetime']))); ?></td>
                            </tr>
                            <?php endif; ?>
                        </table>
                    </div>
                </div>

            </div>

            <!-- Custom Parameters -->
            <?php if (isset($subscriber['custom_parameters']) && !empty($subscriber['custom_parameters']) && is_array($subscriber['custom_parameters'])): ?>
                <div class="pushrelay-card" style="margin-top: 20px;">
                    <div class="pushrelay-card-header">
                        <h2><?php esc_html_e('Custom Parameters', 'pushrelay'); ?></h2>
                    </div>
                    <div class="pushrelay-card-body">
                        <table class="pushrelay-detail-table">
                            <?php foreach ($subscriber['custom_parameters'] as $key => $value): ?>
                                <tr>
                                    <th><?php echo esc_html($key); ?>:</th>
                                    <td><?php echo esc_html($value); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </table>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Actions -->
            <div class="pushrelay-card" style="margin-top: 20px;">
                <div class="pushrelay-card-body" style="text-align: right;">
                    <button class="pushrelay-btn pushrelay-btn-danger pushrelay-delete-subscriber" 
                            data-subscriber-id="<?php echo esc_attr($subscriber['id']); ?>">
                        <?php esc_html_e('🗑️ Delete Subscriber', 'pushrelay'); ?>
                    </button>
                </div>
            </div>

        <?php else: ?>
            <div class="pushrelay-alert pushrelay-alert-danger">
                <?php esc_html_e('Subscriber not found.', 'pushrelay'); ?>
            </div>
        <?php endif; ?>

    <?php endif; ?>
</div>

<script>
jQuery(document).ready(function($) {
    
    // Remove global AJAX handlers that might conflict (wp-codemirror fix)
    $(document).off('ajaxComplete');
    $(document).off('ajaxSend');
    
    // Delete subscriber using fetch API
    $('.pushrelay-delete-subscriber').on('click', function() {
        if (!confirm('<?php echo esc_js(__('Are you sure you want to delete this subscriber?', 'pushrelay')); ?>')) {
            return;
        }
        
        var subscriberId = $(this).data('subscriber-id');
        var $button = $(this);
        var $row = $(this).closest('tr');
        
        $button.prop('disabled', true).text('<?php echo esc_js(__('Deleting...', 'pushrelay')); ?>');
        
        var formData = new FormData();
        formData.append('action', 'pushrelay_delete_subscriber');
        formData.append('subscriber_id', subscriberId);
        formData.append('nonce', '<?php echo esc_attr(wp_create_nonce('pushrelay_admin_nonce')); ?>');
        
        fetch(ajaxurl, {
            method: 'POST',
            body: formData
        })
        .then(function(response) { return response.json(); })
        .then(function(data) {
            if (data.success) {
                // If in detail view, redirect to list
                if ($row.length === 0) {
                    window.location.href = '<?php echo esc_js(admin_url('admin.php?page=pushrelay-subscribers')); ?>';
                    return;
                }
                
                // If in list view, remove row
                $row.fadeOut(function() {
                    $(this).remove();
                });
                
                // Show success notice
                $('.pushrelay-notices').html('<div class="pushrelay-alert pushrelay-alert-success">' + 
                    '<?php echo esc_js(__('Subscriber deleted successfully!', 'pushrelay')); ?>' + 
                    '</div>');
                    
                setTimeout(function() {
                    $('.pushrelay-notices .pushrelay-alert').fadeOut();
                }, 3000);
            } else {
                alert('<?php echo esc_js(__('Error:', 'pushrelay')); ?> ' + (data.data ? data.data.message : '<?php echo esc_js(__('Unknown error', 'pushrelay')); ?>'));
                $button.prop('disabled', false).text('<?php echo esc_js(__('Delete', 'pushrelay')); ?>');
            }
        })
        .catch(function() {
            alert('<?php echo esc_js(__('Connection error. Please try again.', 'pushrelay')); ?>');
            $button.prop('disabled', false).text('<?php echo esc_js(__('Delete', 'pushrelay')); ?>');
        });
    });
    
    // Export subscribers
    $('#export-subscribers-btn').on('click', function() {
        window.location.href = ajaxurl + '?action=pushrelay_export_subscribers&nonce=<?php echo esc_attr(wp_create_nonce('pushrelay_admin_nonce')); ?>';
    });
    
});
</script>

<style>
.pushrelay-subscribers {
    max-width: 1400px;
}

.pushrelay-detail-table {
    width: 100%;
    border-collapse: collapse;
}

.pushrelay-detail-table tr {
    border-bottom: 1px solid #eee;
}

.pushrelay-detail-table tr:last-child {
    border-bottom: none;
}

.pushrelay-detail-table th,
.pushrelay-detail-table td {
    padding: 12px;
    text-align: left;
}

.pushrelay-detail-table th {
    font-weight: 600;
    color: #666;
    width: 35%;
}

.pushrelay-detail-table td {
    color: #333;
}

.pushrelay-detail-table code {
    background: #f5f5f5;
    padding: 4px 8px;
    border-radius: 4px;
    color: #e83e8c;
    word-break: break-all;
}

@media (max-width: 1200px) {
    .pushrelay-subscribers > div[style*="grid-template-columns"] {
        grid-template-columns: 1fr !important;
    }
}

@media (max-width: 782px) {
    .pushrelay-filters-form {
        flex-direction: column !important;
    }
    
    .pushrelay-filters-form .pushrelay-form-group {
        width: 100% !important;
        min-width: auto !important;
    }
    
    .pushrelay-table {
        font-size: 12px;
    }
    
    .pushrelay-table th,
    .pushrelay-table td {
        padding: 8px 4px;
    }
}
</style>
