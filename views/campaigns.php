<?php
/**
 * Campaigns View
 * 
 * Campaign management interface - list, create, edit, send
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
$campaign_id = isset($_GET['campaign_id']) ? absint($_GET['campaign_id']) : 0;

// Check if we just created a campaign
$created_campaign = isset($_GET['created']) ? sanitize_text_field(wp_unslash($_GET['created'])) : '';

// Pagination settings
$per_page = 25;
$current_page = isset($_GET['paged']) ? absint($_GET['paged']) : 1;

// Fetch ALL campaigns for proper sorting (max 1000)
// We need to fetch all to sort globally, then paginate client-side
$all_campaigns = array();
$total_campaigns = 0;
$fetch_page = 1;
$max_pages = 10; // Safety limit: 10 pages * 100 = 1000 campaigns max

// Use transient cache to avoid repeated API calls (cache for 1 minute)
$cache_key = 'pushrelay_all_campaigns_' . get_current_user_id();
$cached_data = get_transient($cache_key);

if ($cached_data !== false && !isset($_GET['refresh'])) {
    // Use cached data
    $all_campaigns = $cached_data['campaigns'];
    $total_campaigns = $cached_data['total'];
} else {
    // Fetch all campaigns from API
    do {
        $campaigns_response = $api->get_campaigns($fetch_page, 100);
        
        if (is_wp_error($campaigns_response)) {
            break;
        }
        
        $page_campaigns = array();
        if (isset($campaigns_response['data'])) {
            $page_campaigns = $campaigns_response['data'];
        } elseif (is_array($campaigns_response) && !isset($campaigns_response['data'])) {
            $page_campaigns = $campaigns_response;
        }
        
        if (empty($page_campaigns)) {
            break;
        }
        
        $all_campaigns = array_merge($all_campaigns, $page_campaigns);
        
        // Get total from first response
        if ($fetch_page === 1) {
            if (isset($campaigns_response['meta']['total'])) {
                $total_campaigns = absint($campaigns_response['meta']['total']);
            } elseif (isset($campaigns_response['pagination']['total'])) {
                $total_campaigns = absint($campaigns_response['pagination']['total']);
            } elseif (isset($campaigns_response['total'])) {
                $total_campaigns = absint($campaigns_response['total']);
            }
        }
        
        $fetch_page++;
        
        // Check if we have all campaigns or hit the limit
        if (count($all_campaigns) >= $total_campaigns || $fetch_page > $max_pages) {
            break;
        }
        
    } while (true);
    
    // Update total count
    $total_campaigns = count($all_campaigns);
    
    // Sort ALL campaigns by date (newest first)
    usort($all_campaigns, function($a, $b) {
        $date_a = isset($a['datetime']) ? strtotime($a['datetime']) : 0;
        $date_b = isset($b['datetime']) ? strtotime($b['datetime']) : 0;
        if ($date_a === 0 && isset($a['created_at'])) {
            $date_a = strtotime($a['created_at']);
        }
        if ($date_b === 0 && isset($b['created_at'])) {
            $date_b = strtotime($b['created_at']);
        }
        return $date_b - $date_a; // Descending (newest first)
    });
    
    // Cache the sorted results for 1 minute
    set_transient($cache_key, array(
        'campaigns' => $all_campaigns,
        'total' => $total_campaigns
    ), 60);
}

// Calculate pagination
$total_pages = ceil($total_campaigns / $per_page);
$offset = ($current_page - 1) * $per_page;

// Get campaigns for current page
$campaigns = array_slice($all_campaigns, $offset, $per_page);

// Calculate aggregate statistics from ALL campaigns (not just current page)
$total_sent = 0;
$total_displayed = 0;
$total_clicked = 0;
$status_counts = array(
    'sent' => 0,
    'processing' => 0,
    'pending' => 0,
    'draft' => 0,
    'failed' => 0,
);

foreach ($all_campaigns as $campaign) {
    $total_sent += isset($campaign['total_sent_push_notifications']) ? absint($campaign['total_sent_push_notifications']) : 0;
    $total_displayed += isset($campaign['total_displayed_push_notifications']) ? absint($campaign['total_displayed_push_notifications']) : 0;
    $total_clicked += isset($campaign['total_clicked_push_notifications']) ? absint($campaign['total_clicked_push_notifications']) : 0;
    
    // Count by status
    $status = isset($campaign['status']) ? strtolower($campaign['status']) : 'unknown';
    if (isset($status_counts[$status])) {
        $status_counts[$status]++;
    }
}

// Calculate overall CTR
$overall_ctr = $total_displayed > 0 ? round(($total_clicked / $total_displayed) * 100, 2) : 0;

// Check if there are any processing campaigns (for auto-refresh)
$has_processing = $status_counts['processing'] > 0 || $status_counts['pending'] > 0;
?>

<div class="wrap pushrelay-campaigns">
    <div class="pushrelay-header">
        <h1>
            <?php 
            if ($action === 'create') {
                esc_html_e('Create Campaign', 'pushrelay');
            } else {
                esc_html_e('Campaigns', 'pushrelay');
            }
            ?>
        </h1>
        <?php if ($action === 'list'): ?>
            <div style="display: flex; align-items: center; gap: 10px;">
                <a href="<?php echo esc_url(admin_url('admin.php?page=pushrelay-campaigns&refresh=1')); ?>" class="pushrelay-btn pushrelay-btn-secondary" style="margin-right: 10px;">
                    <?php esc_html_e('🔄 Refresh', 'pushrelay'); ?>
                </a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=pushrelay-campaigns&action=create')); ?>" class="pushrelay-btn pushrelay-btn-primary">
                    <?php esc_html_e('+ New Campaign', 'pushrelay'); ?>
                </a>
            </div>
        <?php else: ?>
            <a href="<?php echo esc_url(admin_url('admin.php?page=pushrelay-campaigns')); ?>" class="pushrelay-btn pushrelay-btn-secondary">
                <?php esc_html_e('← Back to Campaigns', 'pushrelay'); ?>
            </a>
        <?php endif; ?>
    </div>

    <!-- Notices Container -->
    <div class="pushrelay-notices">
        <?php if (!empty($created_campaign)): ?>
            <div class="pushrelay-notice pushrelay-notice-success pushrelay-notice-autodismiss" style="margin-bottom: 15px;">
                <p>
                    ✅ <?php 
                    /* translators: %s: Campaign name */
                    printf(esc_html__('Campaign "%s" created successfully!', 'pushrelay'), esc_html($created_campaign)); 
                    ?>
                </p>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($action === 'list'): ?>
        
        <!-- Campaign Stats -->
        <div class="pushrelay-stats-grid pushrelay-campaign-stats" style="margin-bottom: 20px;">
            <div class="pushrelay-stat-card card-primary" data-stat="total-campaigns">
                <h3><?php esc_html_e('Total Campaigns', 'pushrelay'); ?></h3>
                <p class="stat-number"><?php echo esc_html(number_format_i18n($total_campaigns)); ?></p>
            </div>

            <div class="pushrelay-stat-card card-warning" data-stat="processing" style="<?php echo ($status_counts['processing'] + $status_counts['pending']) === 0 ? 'display:none;' : ''; ?>">
                <h3><?php esc_html_e('Processing', 'pushrelay'); ?></h3>
                <p class="stat-number"><?php echo esc_html(number_format_i18n($status_counts['processing'] + $status_counts['pending'])); ?></p>
            </div>

            <div class="pushrelay-stat-card card-info" data-stat="total-sent">
                <h3><?php esc_html_e('Total Sent', 'pushrelay'); ?></h3>
                <p class="stat-number"><?php echo esc_html(number_format_i18n($total_sent)); ?></p>
            </div>

            <div class="pushrelay-stat-card card-success" data-stat="total-displayed">
                <h3><?php esc_html_e('Total Displayed', 'pushrelay'); ?></h3>
                <p class="stat-number"><?php echo esc_html(number_format_i18n($total_displayed)); ?></p>
            </div>

            <div class="pushrelay-stat-card card-secondary" data-stat="total-clicked">
                <h3><?php esc_html_e('Total Clicked', 'pushrelay'); ?></h3>
                <p class="stat-number"><?php echo esc_html(number_format_i18n($total_clicked)); ?></p>
            </div>

            <div class="pushrelay-stat-card <?php echo esc_attr($overall_ctr > 5 ? 'card-success' : ($overall_ctr > 2 ? 'card-warning' : 'card-danger')); ?>" data-stat="overall-ctr">
                <h3><?php esc_html_e('Overall CTR', 'pushrelay'); ?></h3>
                <p class="stat-number"><?php echo esc_html($overall_ctr); ?>%</p>
            </div>
        </div>

        <!-- Campaigns List -->
        <div class="pushrelay-card">
            <div class="pushrelay-card-header">
                <h2><?php esc_html_e('All Campaigns', 'pushrelay'); ?></h2>
            </div>
            <div class="pushrelay-card-body">
                <?php if (!empty($campaigns)): ?>
                    <table class="pushrelay-table pushrelay-campaigns-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Name', 'pushrelay'); ?></th>
                                <th><?php esc_html_e('Status', 'pushrelay'); ?></th>
                                <th><?php esc_html_e('Sent', 'pushrelay'); ?></th>
                                <th><?php esc_html_e('Displayed', 'pushrelay'); ?></th>
                                <th><?php esc_html_e('Clicked', 'pushrelay'); ?></th>
                                <th><?php esc_html_e('CTR', 'pushrelay'); ?></th>
                                <th><?php esc_html_e('Date', 'pushrelay'); ?></th>
                                <th><?php esc_html_e('Actions', 'pushrelay'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($campaigns as $campaign): ?>
                                <?php
                                $sent = isset($campaign['total_sent_push_notifications']) ? absint($campaign['total_sent_push_notifications']) : 0;
                                $displayed = isset($campaign['total_displayed_push_notifications']) ? absint($campaign['total_displayed_push_notifications']) : 0;
                                $clicked = isset($campaign['total_clicked_push_notifications']) ? absint($campaign['total_clicked_push_notifications']) : 0;
                                $ctr = $displayed > 0 ? round(($clicked / $displayed) * 100, 2) : 0;
                                
                                // Check if this is an auto-generated campaign
                                $campaign_name = isset($campaign['name']) ? $campaign['name'] : '';
                                $is_auto_campaign = strpos($campaign_name, 'auto:') === 0;
                                $display_name = $campaign_name;
                                $campaign_tooltip = '';
                                
                                if ($is_auto_campaign) {
                                    // Extract post title from "auto: Post Title" format
                                    $post_title = trim(substr($campaign_name, 5));
                                    $display_name = $campaign_name; // Keep original for display
                                    $campaign_tooltip = sprintf(
                                        /* translators: %s: Post title */
                                        esc_attr__('Auto-generated when "%s" was published with auto-push enabled', 'pushrelay'),
                                        $post_title
                                    );
                                }
                                ?>
                                <tr data-campaign-id="<?php echo esc_attr($campaign['id']); ?>" data-campaign-status="<?php echo esc_attr($campaign['status']); ?>">
                                    <td>
                                        <strong<?php echo $campaign_tooltip ? ' title="' . $campaign_tooltip . '" style="cursor: help;"' : ''; ?>>
                                            <?php echo esc_html($display_name); ?>
                                            <?php if ($is_auto_campaign): ?>
                                                <span class="pushrelay-badge pushrelay-badge-auto" title="<?php echo $campaign_tooltip; ?>" style="font-size: 10px; padding: 2px 5px; background: #6c757d; color: #fff; border-radius: 3px; margin-left: 5px; cursor: help;">
                                                    <?php esc_html_e('auto', 'pushrelay'); ?>
                                                </span>
                                            <?php endif; ?>
                                        </strong>
                                    </td>
                                    <td class="column-status">
                                        <span class="pushrelay-badge pushrelay-status-<?php echo esc_attr($campaign['status']); ?>">
                                            <?php echo esc_html(ucfirst($campaign['status'])); ?>
                                        </span>
                                    </td>
                                    <td class="cell-sent" data-value="<?php echo esc_attr($sent); ?>"><?php echo esc_html(number_format_i18n($sent)); ?></td>
                                    <td class="cell-displayed" data-value="<?php echo esc_attr($displayed); ?>"><?php echo esc_html(number_format_i18n($displayed)); ?></td>
                                    <td class="cell-clicked" data-value="<?php echo esc_attr($clicked); ?>"><?php echo esc_html(number_format_i18n($clicked)); ?></td>
                                    <td>
                                        <strong style="color: <?php echo esc_attr($ctr > 5 ? '#28a745' : ($ctr > 2 ? '#ffc107' : '#dc3545')); ?>">
                                            <?php echo esc_html($ctr); ?>%
                                        </strong>
                                    </td>
                                    <td><?php echo esc_html(date_i18n(get_option('date_format'), strtotime($campaign['datetime']))); ?></td>
                                    <td>
                                        <?php if ($campaign['status'] === 'draft'): ?>
                                            <button class="pushrelay-btn pushrelay-btn-small pushrelay-btn-success pushrelay-send-campaign" data-campaign-id="<?php echo esc_attr($campaign['id']); ?>">
                                                <?php esc_html_e('Send', 'pushrelay'); ?>
                                            </button>
                                        <?php endif; ?>
                                        
                                        <button class="pushrelay-btn pushrelay-btn-small pushrelay-btn-danger pushrelay-delete-campaign" data-campaign-id="<?php echo esc_attr($campaign['id']); ?>">
                                            <?php esc_html_e('Delete', 'pushrelay'); ?>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    
                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                        <div class="pushrelay-pagination" style="display: flex; justify-content: space-between; align-items: center; margin-top: 20px; padding: 15px; background: #f8f9fa; border-radius: 8px;">
                            <div class="pagination-info">
                                <?php
                                $start_item = $offset + 1;
                                $end_item = min($offset + $per_page, $total_campaigns);
                                printf(
                                    /* translators: %1$d: start item, %2$d: end item, %3$d: total items */
                                    esc_html__('Showing %1$d-%2$d of %3$d campaigns', 'pushrelay'),
                                    intval($start_item),
                                    intval($end_item),
                                    intval($total_campaigns)
                                );
                                ?>
                            </div>
                            <div class="pagination-links" style="display: flex; gap: 5px;">
                                <?php
                                $base_url = admin_url('admin.php?page=pushrelay-campaigns');
                                
                                // First page
                                if ($current_page > 1): ?>
                                    <a href="<?php echo esc_url($base_url . '&paged=1'); ?>" class="pushrelay-btn pushrelay-btn-sm pushrelay-btn-secondary" title="<?php esc_attr_e('First page', 'pushrelay'); ?>">
                                        &laquo;
                                    </a>
                                <?php endif; ?>
                                
                                <?php // Previous page
                                if ($current_page > 1): ?>
                                    <a href="<?php echo esc_url($base_url . '&paged=' . ($current_page - 1)); ?>" class="pushrelay-btn pushrelay-btn-sm pushrelay-btn-secondary">
                                        &lsaquo; <?php esc_html_e('Prev', 'pushrelay'); ?>
                                    </a>
                                <?php endif; ?>
                                
                                <?php
                                // Page numbers
                                $start_page = max(1, $current_page - 2);
                                $end_page = min($total_pages, $current_page + 2);
                                
                                for ($i = $start_page; $i <= $end_page; $i++):
                                    $is_current = ($i === $current_page);
                                ?>
                                    <a href="<?php echo esc_url($base_url . '&paged=' . $i); ?>" 
                                       class="pushrelay-btn pushrelay-btn-sm <?php echo esc_attr($is_current ? 'pushrelay-btn-primary' : 'pushrelay-btn-secondary'); ?>"
                                       <?php echo $is_current ? 'aria-current="page"' : ''; ?>>
                                        <?php echo esc_html($i); ?>
                                    </a>
                                <?php endfor; ?>
                                
                                <?php // Next page
                                if ($current_page < $total_pages): ?>
                                    <a href="<?php echo esc_url($base_url . '&paged=' . ($current_page + 1)); ?>" class="pushrelay-btn pushrelay-btn-sm pushrelay-btn-secondary">
                                        <?php esc_html_e('Next', 'pushrelay'); ?> &rsaquo;
                                    </a>
                                <?php endif; ?>
                                
                                <?php // Last page
                                if ($current_page < $total_pages): ?>
                                    <a href="<?php echo esc_url($base_url . '&paged=' . $total_pages); ?>" class="pushrelay-btn pushrelay-btn-sm pushrelay-btn-secondary" title="<?php esc_attr_e('Last page', 'pushrelay'); ?>">
                                        &raquo;
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                <?php else: ?>
                    <div style="text-align: center; padding: 60px 20px;">
                        <div style="font-size: 48px; margin-bottom: 20px;">📢</div>
                        <h3><?php esc_html_e('No campaigns yet', 'pushrelay'); ?></h3>
                        <p><?php esc_html_e('Create your first campaign to start engaging with your subscribers!', 'pushrelay'); ?></p>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=pushrelay-campaigns&action=create')); ?>" class="pushrelay-btn pushrelay-btn-primary">
                            <?php esc_html_e('Create Your First Campaign', 'pushrelay'); ?>
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>

    <?php elseif ($action === 'create'): ?>

        <!-- Campaign Builder -->
        <div style="display: grid; grid-template-columns: 1fr 400px; gap: 20px;">
            
            <!-- Left: Form -->
            <div>
                <form id="campaign-form" class="pushrelay-campaign-form">
                    
                    <div class="pushrelay-card">
                        <div class="pushrelay-card-header">
                            <h2><?php esc_html_e('Campaign Details', 'pushrelay'); ?></h2>
                        </div>
                        <div class="pushrelay-card-body">
                            
                            <!-- Campaign Name -->
                            <div class="pushrelay-form-group">
                                <label class="pushrelay-form-label" for="campaign_name">
                                    <?php esc_html_e('Campaign Name', 'pushrelay'); ?>
                                    <span style="color: red;">*</span>
                                </label>
                                <input type="text" 
                                       id="campaign_name" 
                                       name="campaign[name]" 
                                       class="pushrelay-form-control" 
                                       required 
                                       placeholder="<?php esc_attr_e('Internal name for this campaign', 'pushrelay'); ?>">
                                <span class="pushrelay-form-help">
                                    <?php esc_html_e('This is only visible to you', 'pushrelay'); ?>
                                </span>
                            </div>

                            <!-- Notification Title -->
                            <div class="pushrelay-form-group">
                                <label class="pushrelay-form-label" for="campaign_title">
                                    <?php esc_html_e('Notification Title', 'pushrelay'); ?>
                                    <span style="color: red;">*</span>
                                </label>
                                <input type="text" 
                                       id="campaign_title" 
                                       name="campaign[title]" 
                                       class="pushrelay-form-control" 
                                       required 
                                       maxlength="65"
                                       placeholder="<?php esc_attr_e('Get 50% off today!', 'pushrelay'); ?>">
                                <span class="pushrelay-form-help">
                                    <span id="title-counter">0</span>/65 <?php esc_html_e('characters', 'pushrelay'); ?>
                                </span>
                            </div>

                            <!-- Notification Description -->
                            <div class="pushrelay-form-group">
                                <label class="pushrelay-form-label" for="campaign_description">
                                    <?php esc_html_e('Notification Message', 'pushrelay'); ?>
                                    <span style="color: red;">*</span>
                                </label>
                                <textarea id="campaign_description" 
                                          name="campaign[description]" 
                                          class="pushrelay-form-control" 
                                          rows="3" 
                                          required 
                                          maxlength="150"
                                          placeholder="<?php esc_attr_e('Limited time offer - shop now and save!', 'pushrelay'); ?>"></textarea>
                                <span class="pushrelay-form-help">
                                    <span id="description-counter">0</span>/150 <?php esc_html_e('characters', 'pushrelay'); ?>
                                </span>
                            </div>

                            <!-- URL -->
                            <div class="pushrelay-form-group">
                                <label class="pushrelay-form-label" for="campaign_url">
                                    <?php esc_html_e('Target URL', 'pushrelay'); ?>
                                </label>
                                <input type="url" 
                                       id="campaign_url" 
                                       name="campaign[url]" 
                                       class="pushrelay-form-control" 
                                       placeholder="https://yoursite.com/special-offer">
                                <span class="pushrelay-form-help">
                                    <?php esc_html_e('Where users will go when they click the notification', 'pushrelay'); ?>
                                </span>
                            </div>

                            <!-- Image URL -->
                            <div class="pushrelay-form-group">
                                <label class="pushrelay-form-label" for="campaign_image_url">
                                    <?php esc_html_e('Image URL', 'pushrelay'); ?>
                                </label>
                                <div style="display: flex; gap: 10px;">
                                    <input type="url" 
                                           id="campaign_image_url" 
                                           name="campaign[image_url]" 
                                           class="pushrelay-form-control" 
                                           placeholder="https://yoursite.com/image.jpg">
                                    <button type="button" class="pushrelay-btn pushrelay-btn-secondary pushrelay-upload-image" data-target="#campaign_image_url">
                                        <?php esc_html_e('Upload', 'pushrelay'); ?>
                                    </button>
                                </div>
                                <div id="campaign_image_url-preview" style="margin-top: 10px;"></div>
                            </div>

                        </div>
                    </div>

                    <!-- Targeting -->
                    <div class="pushrelay-card" style="margin-top: 20px;">
                        <div class="pushrelay-card-header">
                            <h2><?php esc_html_e('Targeting', 'pushrelay'); ?></h2>
                        </div>
                        <div class="pushrelay-card-body">
                            
                            <div class="pushrelay-form-group">
                                <label class="pushrelay-form-label" for="campaign_segment">
                                    <?php esc_html_e('Send to:', 'pushrelay'); ?>
                                </label>
                                <select id="campaign_segment" name="campaign[segment]" class="pushrelay-form-control">
                                    <option value="all"><?php esc_html_e('All Subscribers', 'pushrelay'); ?></option>
                                    <option value="custom"><?php esc_html_e('Custom Selection (IDs)', 'pushrelay'); ?></option>
                                    <option value="filter"><?php esc_html_e('Advanced Filters', 'pushrelay'); ?></option>
                                </select>
                            </div>

                            <div id="custom-segment" style="display: none;">
                                <div class="pushrelay-form-group">
                                    <label class="pushrelay-form-label" for="campaign_subscribers_ids">
                                        <?php esc_html_e('Subscriber IDs (comma-separated)', 'pushrelay'); ?>
                                    </label>
                                    <textarea id="campaign_subscribers_ids" 
                                              name="campaign[subscribers_ids]" 
                                              class="pushrelay-form-control" 
                                              rows="3"
                                              placeholder="1,2,3,4,5"></textarea>
                                </div>
                            </div>

                            <div id="filter-segment" style="display: none;">
                                <div class="pushrelay-alert pushrelay-alert-info">
                                    <?php esc_html_e('Advanced filtering is available. Visit the Segmentation page to create and save custom segments.', 'pushrelay'); ?>
                                </div>
                            </div>

                        </div>
                    </div>

                    <!-- Actions -->
                    <div class="pushrelay-card" style="margin-top: 20px;">
                        <div class="pushrelay-card-body" style="text-align: right;">
                            <input type="hidden" name="campaign[website_id]" value="<?php echo esc_attr($settings['website_id'] ?? ''); ?>">
                            
                            <button type="submit" name="action_type" value="save" class="pushrelay-btn pushrelay-btn-secondary">
                                <?php esc_html_e('Save as Draft', 'pushrelay'); ?>
                            </button>
                            
                            <button type="submit" name="action_type" value="send" class="pushrelay-btn pushrelay-btn-primary pushrelay-btn-large pushrelay-create-campaign">
                                <?php esc_html_e('Send Campaign Now', 'pushrelay'); ?>
                            </button>
                        </div>
                    </div>

                </form>
            </div>

            <!-- Right: Preview -->
            <div>
                <div class="pushrelay-card" style="position: sticky; top: 32px;">
                    <div class="pushrelay-card-header">
                        <h2><?php esc_html_e('Preview', 'pushrelay'); ?></h2>
                    </div>
                    <div class="pushrelay-card-body">
                        <div id="campaign-preview">
                            <div class="pushrelay-notification-preview">
                                <div class="pushrelay-notification-image" id="preview-image" style="display: none;">
                                    <img src="" alt="">
                                </div>
                                <div class="pushrelay-notification-content">
                                    <div class="pushrelay-notification-title" id="preview-title">
                                        <?php esc_html_e('Your notification title', 'pushrelay'); ?>
                                    </div>
                                    <div class="pushrelay-notification-description" id="preview-description">
                                        <?php esc_html_e('Your notification message will appear here', 'pushrelay'); ?>
                                    </div>
                                </div>
                                <div class="pushrelay-notification-site">
                                    <?php echo esc_html(get_bloginfo('name')); ?>
                                </div>
                            </div>
                        </div>

                        <div class="pushrelay-alert pushrelay-alert-info" style="margin-top: 20px;">
                            <strong><?php esc_html_e('💡 Tip:', 'pushrelay'); ?></strong>
                            <?php esc_html_e('Keep your title short and your message clear. Use emojis to grab attention!', 'pushrelay'); ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    <?php endif; ?>
</div>

<script>
jQuery(document).ready(function($) {
    
    // Remove global AJAX handlers that might conflict (wp-codemirror fix)
    $(document).off('ajaxComplete');
    $(document).off('ajaxSend');
    
    // Character counters
    $('#campaign_title').on('input', function() {
        var length = $(this).val().length;
        $('#title-counter').text(length);
        $('#preview-title').text($(this).val() || '<?php esc_js(__('Your notification title', 'pushrelay')); ?>');
    });
    
    $('#campaign_description').on('input', function() {
        var length = $(this).val().length;
        $('#description-counter').text(length);
        $('#preview-description').text($(this).val() || '<?php esc_js(__('Your notification message will appear here', 'pushrelay')); ?>');
    });
    
    // Image preview
    $('#campaign_image_url').on('input', function() {
        var imageUrl = $(this).val();
        if (imageUrl) {
            $('#preview-image img').attr('src', imageUrl);
            $('#preview-image').show();
        } else {
            $('#preview-image').hide();
        }
    });
    
    // Segment selection
    $('#campaign_segment').on('change', function() {
        var segment = $(this).val();
        
        $('#custom-segment, #filter-segment').hide();
        
        if (segment === 'custom') {
            $('#custom-segment').show();
        } else if (segment === 'filter') {
            $('#filter-segment').show();
        }
    });
    
    // Campaign creation is handled by admin.js via .pushrelay-create-campaign click handler
});
</script>

<style>
.pushrelay-campaigns {
    max-width: 1400px;
}

.pushrelay-notification-preview {
    max-width: 100%;
    background: #fff;
    border-radius: 8px;
    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.2);
    overflow: hidden;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
}

.pushrelay-notification-image {
    width: 100%;
    height: 150px;
    overflow: hidden;
}

.pushrelay-notification-image img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.pushrelay-notification-content {
    padding: 16px;
}

.pushrelay-notification-title {
    margin: 0 0 8px 0;
    font-size: 16px;
    font-weight: 600;
    color: #333;
}

.pushrelay-notification-description {
    margin: 0;
    font-size: 14px;
    color: #666;
    line-height: 1.5;
}

.pushrelay-notification-site {
    padding: 12px 16px;
    background: #f5f5f5;
    font-size: 12px;
    color: #999;
    border-top: 1px solid #ddd;
}

@media (max-width: 1200px) {
    .pushrelay-campaigns > div[style*="grid-template-columns"] {
        grid-template-columns: 1fr !important;
    }
    
    .pushrelay-card[style*="position: sticky"] {
        position: relative !important;
    }
}

/* Pagination Styles */
.pushrelay-pagination {
    font-size: 14px;
}

.pushrelay-btn-sm {
    padding: 6px 12px;
    font-size: 13px;
    min-width: 36px;
    text-align: center;
}

.pagination-info {
    color: #666;
}

@keyframes pulse {
    0% { background-color: #e7f5e7; }
    50% { background-color: #c8e6c9; }
    100% { background-color: #e7f5e7; }
}

.pushrelay-notice-success {
    background: #d4edda;
    border-left: 4px solid #28a745;
    padding: 12px 15px;
    border-radius: 4px;
}

.pushrelay-notice-success p {
    margin: 0;
    color: #155724;
}
</style>

<script>
jQuery(document).ready(function($) {
    var justCreated = <?php echo !empty($created_campaign) ? 'true' : 'false'; ?>;
    var createdCampaignName = '<?php echo esc_js($created_campaign); ?>';
    
    // Highlight newly created campaign in the table
    if (justCreated && createdCampaignName) {
        $('table.pushrelay-table tbody tr').each(function() {
            var name = $(this).find('td:first').text().trim();
            if (name === createdCampaignName || name.indexOf(createdCampaignName) !== -1) {
                $(this).css({
                    'background-color': '#e7f5e7',
                    'animation': 'pulse 2s infinite'
                });
            }
        });
    }
});
</script>