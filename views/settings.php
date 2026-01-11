<?php
/**
 * Settings View
 * 
 * Plugin settings and configuration
 * 
 * @package PushRelay
 * @since 1.6.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

$settings = get_option('pushrelay_settings', array());
$api = pushrelay()->get_api_client();

// Get API user info if configured
$user_info = null;
if (!empty($settings['api_key'])) {
    $user_info = $api->get_user();
}

// Get service worker status
$sw = new PushRelay_Service_Worker();
$sw_status = $sw->get_status();

// Get available websites
$websites = array();
if (!empty($settings['api_key'])) {
    $websites_response = $api->get_websites(1, 100);
    if (!is_wp_error($websites_response) && !empty($websites_response['data'])) {
        $websites = $websites_response['data'];
    }
}

// Post types for auto notifications
$post_types = get_post_types(array('public' => true), 'objects');
$selected_post_types = isset($settings['notification_types']) ? $settings['notification_types'] : array('post');
?>

<div class="wrap pushrelay-settings">
    <div class="pushrelay-header">
        <h1><?php esc_html_e('PushRelay Settings', 'pushrelay'); ?></h1>
    </div>

    <!-- Notices Container -->
    <div class="pushrelay-notices"></div>

    <!-- Tabs -->
    <div class="pushrelay-tabs">
        <button class="pushrelay-tab active" data-tab="#tab-general">
            <?php esc_html_e('General', 'pushrelay'); ?>
        </button>
        <button class="pushrelay-tab" data-tab="#tab-notifications">
            <?php esc_html_e('Auto Notifications', 'pushrelay'); ?>
        </button>
        <button class="pushrelay-tab" data-tab="#tab-service-worker">
            <?php esc_html_e('Service Worker', 'pushrelay'); ?>
        </button>
        <button class="pushrelay-tab" data-tab="#tab-advanced">
            <?php esc_html_e('Advanced', 'pushrelay'); ?>
        </button>
    </div>

    <form method="post" id="pushrelay-settings-form">
        <?php wp_nonce_field('pushrelay_save_settings', 'pushrelay_settings_nonce'); ?>

        <!-- General Tab -->
        <div id="tab-general" class="pushrelay-tab-content active">
            <div class="pushrelay-card">
                <div class="pushrelay-card-header">
                    <h2><?php esc_html_e('API Configuration', 'pushrelay'); ?></h2>
                </div>
                <div class="pushrelay-card-body">
                    
                    <!-- API Key -->
                    <div class="pushrelay-form-group">
                        <label class="pushrelay-form-label" for="api_key">
                            <?php esc_html_e('API Key', 'pushrelay'); ?>
                            <span style="color: red;">*</span>
                        </label>
                        <input type="text" 
                               id="api_key" 
                               name="pushrelay_settings[api_key]" 
                               value="<?php echo esc_attr($settings['api_key'] ?? ''); ?>" 
                               class="pushrelay-form-control" 
                               required 
                               placeholder="<?php esc_attr_e('Enter your PushRelay API key', 'pushrelay'); ?>">
                        <span class="pushrelay-form-help">
                            <?php esc_html_e('Get your API key from', 'pushrelay'); ?>
                            <a href="https://pushrelay.com/settings/api" target="_blank">
                                <?php esc_html_e('your PushRelay dashboard', 'pushrelay'); ?>
                            </a>
                        </span>
                    </div>

                    <!-- Test Connection Button -->
                    <div class="pushrelay-form-group">
                        <button type="button" class="pushrelay-btn pushrelay-btn-secondary pushrelay-test-connection">
                            <?php esc_html_e('Test Connection', 'pushrelay'); ?>
                        </button>
                        
                        <?php if (!empty($user_info) && !is_wp_error($user_info)): ?>
                            <div class="pushrelay-alert pushrelay-alert-success" style="margin-top: 10px;">
                                <strong><?php esc_html_e('✓ Connected', 'pushrelay'); ?></strong><br>
                                <?php esc_html_e('Email:', 'pushrelay'); ?> <?php echo esc_html($user_info['data']['email'] ?? 'N/A'); ?><br>
                                <?php esc_html_e('Plan:', 'pushrelay'); ?> <?php echo esc_html(ucfirst($user_info['data']['billing']['plan_id'] ?? 'N/A')); ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <hr style="margin: 30px 0;">

                    <!-- Website Selection -->
                    <div class="pushrelay-form-group">
                        <label class="pushrelay-form-label" for="website_id">
                            <?php esc_html_e('Website', 'pushrelay'); ?>
                            <span style="color: red;">*</span>
                        </label>
                        
                        <?php if (!empty($websites)): ?>
                            <select id="website_id" 
                                    name="pushrelay_settings[website_id]" 
                                    class="pushrelay-form-control" 
                                    required>
                                <option value=""><?php esc_html_e('Select a website', 'pushrelay'); ?></option>
                                <?php foreach ($websites as $website): ?>
                                    <option value="<?php echo esc_attr($website['id']); ?>" 
                                            data-pixel-key="<?php echo esc_attr($website['pixel_key']); ?>"
                                            <?php selected($settings['website_id'] ?? '', $website['id']); ?>>
                                        <?php echo esc_html($website['name'] . ' - ' . $website['host']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        <?php else: ?>
                            <input type="number" 
                                   id="website_id" 
                                   name="pushrelay_settings[website_id]" 
                                   value="<?php echo esc_attr($settings['website_id'] ?? ''); ?>" 
                                   class="pushrelay-form-control" 
                                   required 
                                   placeholder="<?php esc_attr_e('Website ID', 'pushrelay'); ?>">
                        <?php endif; ?>
                        
                        <span class="pushrelay-form-help">
                            <?php esc_html_e('Select your website from the list or enter the website ID manually', 'pushrelay'); ?>
                        </span>
                        
                        <button type="button" class="pushrelay-btn pushrelay-btn-secondary pushrelay-detect-website" style="margin-top: 10px;">
                            <?php esc_html_e('Auto-Detect Website', 'pushrelay'); ?>
                        </button>
                    </div>

                    <!-- Pixel Key -->
                    <div class="pushrelay-form-group">
                        <label class="pushrelay-form-label" for="pixel_key">
                            <?php esc_html_e('Pixel Key', 'pushrelay'); ?>
                            <span style="color: red;">*</span>
                        </label>
                        <input type="text" 
                               id="pixel_key" 
                               name="pushrelay_settings[pixel_key]" 
                               value="<?php echo esc_attr($settings['pixel_key'] ?? ''); ?>" 
                               class="pushrelay-form-control" 
                               required 
                               placeholder="<?php esc_attr_e('Pixel key (auto-filled when selecting website)', 'pushrelay'); ?>">
                        <span class="pushrelay-form-help">
                            <?php esc_html_e('This is automatically filled when you select a website', 'pushrelay'); ?>
                        </span>
                    </div>

                </div>
            </div>
        </div>

        <!-- Auto Notifications Tab -->
        <div id="tab-notifications" class="pushrelay-tab-content">
            <div class="pushrelay-card">
                <div class="pushrelay-card-header">
                    <h2><?php esc_html_e('Automatic Notifications', 'pushrelay'); ?></h2>
                </div>
                <div class="pushrelay-card-body">
                    
                    <!-- Enable Auto Notifications -->
                    <div class="pushrelay-form-group">
                        <label>
                            <input type="checkbox" 
                                   name="pushrelay_settings[auto_notifications]" 
                                   value="1" 
                                   <?php checked(!empty($settings['auto_notifications'])); ?>>
                            <?php esc_html_e('Enable automatic notifications when new content is published', 'pushrelay'); ?>
                        </label>
                    </div>

                    <!-- Post Types -->
                    <div class="pushrelay-form-group">
                        <label class="pushrelay-form-label">
                            <?php esc_html_e('Send notifications for these post types:', 'pushrelay'); ?>
                        </label>
                        <?php foreach ($post_types as $post_type): ?>
                            <label style="display: block; margin-bottom: 8px;">
                                <input type="checkbox" 
                                       name="pushrelay_settings[notification_types][]" 
                                       value="<?php echo esc_attr($post_type->name); ?>"
                                       <?php checked(in_array($post_type->name, $selected_post_types, true)); ?>>
                                <?php echo esc_html($post_type->labels->name); ?>
                            </label>
                        <?php endforeach; ?>
                    </div>

                </div>
            </div>
        </div>

        <!-- Service Worker Tab -->
        <div id="tab-service-worker" class="pushrelay-tab-content">
            <div class="pushrelay-card">
                <div class="pushrelay-card-header">
                    <h2><?php esc_html_e('Service Worker Status', 'pushrelay'); ?></h2>
                </div>
                <div class="pushrelay-card-body">
                    
                    <table class="pushrelay-table">
                        <tbody>
                            <tr>
                                <td><strong><?php esc_html_e('Configuration:', 'pushrelay'); ?></strong></td>
                                <td>
                                    <?php if ($sw_status['configured']): ?>
                                        <span class="pushrelay-badge pushrelay-badge-success">✓ <?php esc_html_e('Configured', 'pushrelay'); ?></span>
                                    <?php else: ?>
                                        <span class="pushrelay-badge pushrelay-badge-danger">✗ <?php esc_html_e('Not Configured', 'pushrelay'); ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <td><strong><?php esc_html_e('File Exists:', 'pushrelay'); ?></strong></td>
                                <td>
                                    <?php if ($sw_status['exists']): ?>
                                        <span class="pushrelay-badge pushrelay-badge-success">✓ <?php esc_html_e('Yes', 'pushrelay'); ?></span>
                                    <?php else: ?>
                                        <span class="pushrelay-badge pushrelay-badge-warning">✗ <?php esc_html_e('No', 'pushrelay'); ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <td><strong><?php esc_html_e('Location:', 'pushrelay'); ?></strong></td>
                                <td><?php echo esc_html(ucfirst($sw_status['location'] ?: 'N/A')); ?></td>
                            </tr>
                            <tr>
                                <td><strong><?php esc_html_e('Version:', 'pushrelay'); ?></strong></td>
                                <td><?php echo esc_html($sw_status['version'] ?: 'N/A'); ?></td>
                            </tr>
                            <tr>
                                <td><strong><?php esc_html_e('URL:', 'pushrelay'); ?></strong></td>
                                <td>
                                    <code style="font-size: 12px;"><?php echo esc_html($sw_status['url']); ?></code>
                                </td>
                            </tr>
                            <tr>
                                <td><strong><?php esc_html_e('Up to Date:', 'pushrelay'); ?></strong></td>
                                <td>
                                    <?php if ($sw_status['up_to_date']): ?>
                                        <span class="pushrelay-badge pushrelay-badge-success">✓ <?php esc_html_e('Yes', 'pushrelay'); ?></span>
                                    <?php else: ?>
                                        <span class="pushrelay-badge pushrelay-badge-warning">⚠ <?php esc_html_e('Outdated', 'pushrelay'); ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <div style="margin-top: 20px;">
                        <button type="button" class="pushrelay-btn pushrelay-btn-primary pushrelay-regenerate-sw">
                            <?php esc_html_e('Regenerate Service Worker', 'pushrelay'); ?>
                        </button>
                        
                        <button type="button" class="pushrelay-btn pushrelay-btn-secondary pushrelay-test-sw">
                            <?php esc_html_e('Test Service Worker', 'pushrelay'); ?>
                        </button>
                    </div>

                    <div class="pushrelay-alert pushrelay-alert-info" style="margin-top: 20px;">
                        <strong><?php esc_html_e('Note:', 'pushrelay'); ?></strong>
                        <?php esc_html_e('The service worker is automatically generated and managed by the plugin. You don\'t need FTP access!', 'pushrelay'); ?>
                    </div>

                </div>
            </div>
        </div>

        <!-- Advanced Tab -->
        <div id="tab-advanced" class="pushrelay-tab-content">
            <div class="pushrelay-card">
                <div class="pushrelay-card-header">
                    <h2><?php esc_html_e('Advanced Settings', 'pushrelay'); ?></h2>
                </div>
                <div class="pushrelay-card-body">
                    
                    <!-- Debug Mode -->
                    <div class="pushrelay-form-group">
                        <label>
                            <input type="checkbox" 
                                   name="pushrelay_settings[debug_mode]" 
                                   value="1" 
                                   <?php checked(!empty($settings['debug_mode'])); ?>>
                            <?php esc_html_e('Enable debug mode', 'pushrelay'); ?>
                        </label>
                        <span class="pushrelay-form-help" style="display: block; margin-top: 5px;">
                            <?php esc_html_e('Logs detailed information for troubleshooting. Disable in production.', 'pushrelay'); ?>
                        </span>
                    </div>

                    <!-- Health Check -->
                    <div class="pushrelay-form-group">
                        <label>
                            <input type="checkbox" 
                                   name="pushrelay_settings[health_check_enabled]" 
                                   value="1" 
                                   <?php checked(!empty($settings['health_check_enabled'])); ?>>
                            <?php esc_html_e('Enable automatic health checks', 'pushrelay'); ?>
                        </label>
                        <span class="pushrelay-form-help" style="display: block; margin-top: 5px;">
                            <?php esc_html_e('Runs health checks hourly to monitor system status.', 'pushrelay'); ?>
                        </span>
                    </div>

                    <!-- WooCommerce Integration -->
                    <?php if (class_exists('WooCommerce')): ?>
                        <div class="pushrelay-form-group">
                            <label>
                                <input type="checkbox" 
                                       name="pushrelay_settings[woocommerce_enabled]" 
                                       value="1" 
                                       <?php checked(!empty($settings['woocommerce_enabled'])); ?>>
                                <?php esc_html_e('Enable WooCommerce integration', 'pushrelay'); ?>
                            </label>
                            <span class="pushrelay-form-help" style="display: block; margin-top: 5px;">
                                <?php esc_html_e('Enables cart abandonment, back in stock, and order notifications.', 'pushrelay'); ?>
                            </span>
                        </div>
                    <?php endif; ?>

                    <hr style="margin: 30px 0;">

                    <!-- System Information -->
                    <h3><?php esc_html_e('System Information', 'pushrelay'); ?></h3>
                    
                    <?php
                    $system_info = PushRelay_Debug_Logger::get_system_info();
                    ?>
                    
                    <table class="pushrelay-table">
                        <tbody>
                            <tr>
                                <td><strong><?php esc_html_e('Plugin Version:', 'pushrelay'); ?></strong></td>
                                <td><?php echo esc_html($system_info['plugin_version']); ?></td>
                            </tr>
                            <tr>
                                <td><strong><?php esc_html_e('WordPress Version:', 'pushrelay'); ?></strong></td>
                                <td><?php echo esc_html($system_info['wordpress_version']); ?></td>
                            </tr>
                            <tr>
                                <td><strong><?php esc_html_e('PHP Version:', 'pushrelay'); ?></strong></td>
                                <td><?php echo esc_html($system_info['php_version']); ?></td>
                            </tr>
                            <tr>
                                <td><strong><?php esc_html_e('MySQL Version:', 'pushrelay'); ?></strong></td>
                                <td><?php echo esc_html($system_info['mysql_version']); ?></td>
                            </tr>
                            <tr>
                                <td><strong><?php esc_html_e('Server:', 'pushrelay'); ?></strong></td>
                                <td><?php echo esc_html($system_info['server_software']); ?></td>
                            </tr>
                            <tr>
                                <td><strong><?php esc_html_e('SSL:', 'pushrelay'); ?></strong></td>
                                <td>
                                    <?php if ($system_info['is_ssl']): ?>
                                        <span class="pushrelay-badge pushrelay-badge-success">✓ <?php esc_html_e('Enabled', 'pushrelay'); ?></span>
                                    <?php else: ?>
                                        <span class="pushrelay-badge pushrelay-badge-danger">✗ <?php esc_html_e('Disabled', 'pushrelay'); ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <td><strong><?php esc_html_e('Memory Limit:', 'pushrelay'); ?></strong></td>
                                <td><?php echo esc_html($system_info['memory_limit']); ?></td>
                            </tr>
                        </tbody>
                    </table>

                    <hr style="margin: 30px 0;" id="database-status">

                    <!-- Database Status -->
                    <h3><?php esc_html_e('Database Tables Status', 'pushrelay'); ?></h3>
                    <p><?php esc_html_e('PushRelay requires 5 database tables to function properly. Check the status below:', 'pushrelay'); ?></p>
                    
                    <?php
                    global $wpdb;
                    $required_tables = array(
                        'pushrelay_api_logs' => __('API Request Logs', 'pushrelay'),
                        'pushrelay_queue' => __('Campaign Queue', 'pushrelay'),
                        'pushrelay_campaigns_local' => __('Local Campaign Data', 'pushrelay'),
                        'pushrelay_tickets' => __('Support Tickets', 'pushrelay'),
                        'pushrelay_segments' => __('Subscriber Segments', 'pushrelay')
                    );
                    
                    $all_exist = true;
                    $missing_tables = array();
                    ?>
                    
                    <table class="pushrelay-table" style="margin-top: 15px;">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Table Name', 'pushrelay'); ?></th>
                                <th><?php esc_html_e('Description', 'pushrelay'); ?></th>
                                <th><?php esc_html_e('Status', 'pushrelay'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($required_tables as $table => $description): 
                                $full_table_name = $wpdb->prefix . $table;
                                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Checking table existence
                                $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $full_table_name ) );
                                
                                if (!$exists) {
                                    $all_exist = false;
                                    $missing_tables[] = $table;
                                }
                            ?>
                            <tr>
                                <td><code><?php echo esc_html($wpdb->prefix . $table); ?></code></td>
                                <td><?php echo esc_html($description); ?></td>
                                <td>
                                    <?php if ($exists): ?>
                                        <span class="pushrelay-badge pushrelay-badge-success">✓ <?php esc_html_e('Exists', 'pushrelay'); ?></span>
                                    <?php else: ?>
                                        <span class="pushrelay-badge pushrelay-badge-danger">✗ <?php esc_html_e('Missing', 'pushrelay'); ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <?php if (!$all_exist): ?>
                    <div class="pushrelay-alert pushrelay-alert-danger" style="margin-top: 20px;">
                        <strong><?php esc_html_e('⚠️ Action Required:', 'pushrelay'); ?></strong>
                        <?php esc_html_e('Some database tables are missing. Click the button below to create them automatically.', 'pushrelay'); ?>
                        <div style="margin-top: 15px;">
                            <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=pushrelay&action=create_tables'), 'pushrelay_create_tables')); ?>" 
                               class="pushrelay-btn pushrelay-btn-primary">
                                🔧 <?php esc_html_e('Create Missing Tables Now', 'pushrelay'); ?>
                            </a>
                        </div>
                    </div>
                    
                    <details style="margin-top: 20px; padding: 15px; background: #f5f5f5; border-radius: 4px;">
                        <summary style="cursor: pointer; font-weight: bold;">
                            <?php esc_html_e('🔍 Show SQL for Manual Creation', 'pushrelay'); ?>
                        </summary>
                        <div style="margin-top: 15px;">
                            <p><?php esc_html_e('If the automatic creation fails, you can manually create the missing tables by running this SQL in phpMyAdmin:', 'pushrelay'); ?></p>
                            <textarea readonly style="width: 100%; height: 300px; font-family: monospace; font-size: 12px; padding: 10px;" onclick="this.select();"><?php
// Generate SQL for missing tables only
foreach ($missing_tables as $table) {
    $full_table_name = $wpdb->prefix . $table;
    
    switch ($table) {
        case 'pushrelay_api_logs':
            echo esc_html("CREATE TABLE IF NOT EXISTS `" . $full_table_name . "`") . " (\n";
            echo "    id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,\n";
            echo "    endpoint varchar(255) NOT NULL DEFAULT '',\n";
            echo "    method varchar(10) NOT NULL DEFAULT 'GET',\n";
            echo "    request_data longtext,\n";
            echo "    response_data longtext,\n";
            echo "    response_code int(11) DEFAULT NULL,\n";
            echo "    execution_time float DEFAULT NULL,\n";
            echo "    ip_address varchar(45) DEFAULT NULL,\n";
            echo "    user_id bigint(20) UNSIGNED DEFAULT NULL,\n";
            echo "    created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,\n";
            echo "    PRIMARY KEY (id),\n";
            echo "    KEY endpoint (endpoint),\n";
            echo "    KEY created_at (created_at),\n";
            echo "    KEY user_id (user_id)\n";
            echo ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;\n\n";
            break;
            
        case 'pushrelay_queue':
            echo esc_html("CREATE TABLE IF NOT EXISTS `" . $full_table_name . "`") . " (\n";
            echo "    id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,\n";
            echo "    campaign_id bigint(20) UNSIGNED NOT NULL DEFAULT 0,\n";
            echo "    subscriber_id bigint(20) UNSIGNED NOT NULL DEFAULT 0,\n";
            echo "    website_id bigint(20) UNSIGNED NOT NULL DEFAULT 0,\n";
            echo "    status varchar(20) NOT NULL DEFAULT 'pending',\n";
            echo "    attempts int(11) NOT NULL DEFAULT 0,\n";
            echo "    max_attempts int(11) NOT NULL DEFAULT 3,\n";
            echo "    error_message text,\n";
            echo "    scheduled_at datetime DEFAULT NULL,\n";
            echo "    sent_at datetime DEFAULT NULL,\n";
            echo "    created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,\n";
            echo "    updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,\n";
            echo "    PRIMARY KEY (id),\n";
            echo "    KEY campaign_id (campaign_id),\n";
            echo "    KEY subscriber_id (subscriber_id),\n";
            echo "    KEY status (status),\n";
            echo "    KEY scheduled_at (scheduled_at)\n";
            echo ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;\n\n";
            break;
            
        case 'pushrelay_campaigns_local':
            echo esc_html("CREATE TABLE IF NOT EXISTS `" . $full_table_name . "`") . " (\n";
            echo "    id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,\n";
            echo "    campaign_id bigint(20) UNSIGNED NOT NULL DEFAULT 0,\n";
            echo "    website_id bigint(20) UNSIGNED NOT NULL DEFAULT 0,\n";
            echo "    user_id bigint(20) UNSIGNED NOT NULL DEFAULT 0,\n";
            echo "    name varchar(255) NOT NULL DEFAULT '',\n";
            echo "    status varchar(20) NOT NULL DEFAULT 'draft',\n";
            echo "    total_subscribers int(11) NOT NULL DEFAULT 0,\n";
            echo "    total_sent int(11) NOT NULL DEFAULT 0,\n";
            echo "    total_displayed int(11) NOT NULL DEFAULT 0,\n";
            echo "    total_clicked int(11) NOT NULL DEFAULT 0,\n";
            echo "    total_closed int(11) NOT NULL DEFAULT 0,\n";
            echo "    created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,\n";
            echo "    updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,\n";
            echo "    PRIMARY KEY (id),\n";
            echo "    KEY campaign_id (campaign_id),\n";
            echo "    KEY website_id (website_id),\n";
            echo "    KEY user_id (user_id)\n";
            echo ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;\n\n";
            break;
            
        case 'pushrelay_tickets':
            echo esc_html("CREATE TABLE IF NOT EXISTS `" . $full_table_name . "`") . " (\n";
            echo "    id bigint(20) NOT NULL AUTO_INCREMENT,\n";
            echo "    user_id bigint(20) NOT NULL DEFAULT 0,\n";
            echo "    subject varchar(255) NOT NULL DEFAULT '',\n";
            echo "    message longtext NOT NULL,\n";
            echo "    priority varchar(20) NOT NULL DEFAULT 'medium',\n";
            echo "    status varchar(20) NOT NULL DEFAULT 'open',\n";
            echo "    ticket_id varchar(50) DEFAULT NULL,\n";
            echo "    email_sent tinyint(1) DEFAULT 0,\n";
            echo "    attachments longtext DEFAULT NULL,\n";
            echo "    created_at datetime DEFAULT CURRENT_TIMESTAMP,\n";
            echo "    updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,\n";
            echo "    PRIMARY KEY (id),\n";
            echo "    KEY user_id (user_id),\n";
            echo "    KEY status (status),\n";
            echo "    KEY created_at (created_at)\n";
            echo ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;\n\n";
            break;
            
        case 'pushrelay_segments':
            echo esc_html("CREATE TABLE IF NOT EXISTS `" . $full_table_name . "`") . " (\n";
            echo "    id bigint(20) NOT NULL AUTO_INCREMENT,\n";
            echo "    name varchar(255) NOT NULL DEFAULT '',\n";
            echo "    description text DEFAULT NULL,\n";
            echo "    rules longtext DEFAULT NULL,\n";
            echo "    subscriber_count int(11) DEFAULT 0,\n";
            echo "    last_calculated datetime DEFAULT NULL,\n";
            echo "    created_at datetime DEFAULT CURRENT_TIMESTAMP,\n";
            echo "    updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,\n";
            echo "    PRIMARY KEY (id),\n";
            echo "    KEY name (name)\n";
            echo ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;\n\n";
            break;
    }
}
?></textarea>
                            <p style="margin-top: 10px;">
                                <small><?php esc_html_e('Click the SQL above to select all, then copy and paste into phpMyAdmin SQL tab.', 'pushrelay'); ?></small>
                            </p>
                        </div>
                    </details>
                    <?php else: ?>
                    <div class="pushrelay-alert pushrelay-alert-success" style="margin-top: 20px;">
                        <strong>✅ <?php esc_html_e('All Tables Present', 'pushrelay'); ?></strong><br>
                        <?php esc_html_e('All 5 required database tables exist and are ready to use!', 'pushrelay'); ?>
                    </div>
                    <?php endif; ?>

                </div>
            </div>

            <!-- Danger Zone -->
            <div class="pushrelay-card" style="border-left: 4px solid #dc3545;">
                <div class="pushrelay-card-header" style="background: #f8d7da; color: #721c24;">
                    <h2><?php esc_html_e('Danger Zone', 'pushrelay'); ?></h2>
                </div>
                <div class="pushrelay-card-body">
                    <p><?php esc_html_e('These actions cannot be undone. Use with caution.', 'pushrelay'); ?></p>
                    
                    <button type="button" class="pushrelay-btn pushrelay-btn-danger pushrelay-clear-logs">
                        <?php esc_html_e('Clear All Logs', 'pushrelay'); ?>
                    </button>
                    
                    <p style="margin-top: 20px; font-size: 12px; color: #666;">
                        <?php esc_html_e('To completely remove all plugin data, deactivate and delete the plugin from the Plugins page.', 'pushrelay'); ?>
                    </p>
                </div>
            </div>
        </div>

        <!-- Save Button (outside tabs, always visible) -->
        <div class="pushrelay-card" style="margin-top: 20px;">
            <div class="pushrelay-card-body" style="text-align: right;">
                <button type="submit" class="pushrelay-btn pushrelay-btn-primary pushrelay-btn-large pushrelay-save-settings">
                    <?php esc_html_e('Save Settings', 'pushrelay'); ?>
                </button>
            </div>
        </div>
    </form>
</div>

<script>
jQuery(document).ready(function($) {
    // Remove global AJAX handlers that might conflict (wp-codemirror fix)
    $(document).off('ajaxComplete');
    $(document).off('ajaxSend');
    
    // Auto-fill pixel key when website is selected
    $('#website_id').on('change', function() {
        var pixelKey = $(this).find('option:selected').data('pixel-key');
        if (pixelKey) {
            $('#pixel_key').val(pixelKey);
        }
    });
});
</script>

<style>
.pushrelay-settings {
    max-width: 1000px;
}

.pushrelay-form-control {
    max-width: 600px;
}

.pushrelay-table td:first-child {
    width: 200px;
}
</style>