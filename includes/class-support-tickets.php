<?php
/**
 * Support Tickets Class
 * 
 * Handles support ticket creation and management
 * Sends tickets to support@pushrelay.com with system information
 * 
 * @package PushRelay
 * @since 1.6.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class PushRelay_Support_Tickets {
    
    /**
     * Support email
     */
    const SUPPORT_EMAIL = 'support@pushrelay.com';
    
    /**
     * Constructor
     */
    public function __construct() {
        // AJAX handlers
        add_action('wp_ajax_pushrelay_create_ticket', array($this, 'ajax_create_ticket'));
        add_action('wp_ajax_pushrelay_get_tickets', array($this, 'ajax_get_tickets'));
        add_action('wp_ajax_pushrelay_get_ticket', array($this, 'ajax_get_ticket'));
        add_action('wp_ajax_pushrelay_delete_ticket', array($this, 'ajax_delete_ticket'));
    }
    
    /**
     * Create support ticket
     */
    public function create_ticket($subject, $message, $priority = 'medium', $attach_logs = false) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'pushrelay_tickets';
        $user_id = get_current_user_id();
        
        // Validate inputs
        if (empty($subject) || empty($message)) {
            return new WP_Error('missing_fields', __('Subject and message are required', 'pushrelay'));
        }
        
        // Validate priority
        $valid_priorities = array('low', 'medium', 'high', 'urgent');
        if (!in_array($priority, $valid_priorities, true)) {
            $priority = 'medium';
        }
        
        // Generate unique ticket ID
        $ticket_id = 'PR-' . strtoupper(wp_generate_password(8, false));
        
        // Prepare attachments
        $attachments = array();
        if ($attach_logs) {
            $attachments['logs'] = $this->prepare_logs_attachment();
            $attachments['system_info'] = $this->get_system_information();
        }
        
        // Insert ticket into database
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $result = $wpdb->insert(
            $table_name,
            array(
                'user_id' => $user_id,
                'subject' => sanitize_text_field($subject),
                'message' => wp_kses_post($message),
                'priority' => $priority,
                'status' => 'open',
                'ticket_id' => $ticket_id,
                'email_sent' => 0,
                'attachments' => !empty($attachments) ? wp_json_encode($attachments) : null,
                'created_at' => current_time('mysql'),
            ),
            array('%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s')
        );
        
        if (!$result) {
            return new WP_Error('db_error', __('Failed to create ticket', 'pushrelay'));
        }
        
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $db_ticket_id = $wpdb->insert_id;
        
        // Send email to support
        $email_sent = $this->send_ticket_email($ticket_id, $subject, $message, $priority, $attachments);
        
        if ($email_sent) {
            // Update email_sent flag
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->update(
                $table_name,
                array('email_sent' => 1),
                array('id' => $db_ticket_id),
                array('%d'),
                array('%d')
            );
        }
        
        PushRelay_Debug_Logger::log(
            sprintf('Support ticket created: %s - %s', $ticket_id, $subject),
            'info',
            array('email_sent' => $email_sent)
        );
        
        return array(
            'id' => $db_ticket_id,
            'ticket_id' => $ticket_id,
            'email_sent' => $email_sent,
        );
    }
    
    /**
     * Send ticket email to support
     */
    private function send_ticket_email($ticket_id, $subject, $message, $priority, $attachments = array()) {
        $user = wp_get_current_user();
        $site_url = get_site_url();
        $site_name = get_bloginfo('name');
        
        // Prepare email subject
        $email_subject = sprintf('[%s] %s - %s', $priority, $ticket_id, $subject);
        
        // Prepare email body
        $email_body = $this->get_email_template($ticket_id, $subject, $message, $priority, $user, $attachments);
        
        // Prepare headers
        $parsed_url = wp_parse_url($site_url);
        $host = isset($parsed_url['host']) ? $parsed_url['host'] : 'localhost';
        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $site_name . ' <noreply@' . $host . '>',
            'Reply-To: ' . $user->user_email,
        );
        
        // Send email
        $sent = wp_mail(self::SUPPORT_EMAIL, $email_subject, $email_body, $headers);
        
        if (!$sent) {
            PushRelay_Debug_Logger::log(
                sprintf('Failed to send ticket email: %s', $ticket_id),
                'error'
            );
        }
        
        return $sent;
    }
    
    /**
     * Get email template
     */
    private function get_email_template($ticket_id, $subject, $message, $priority, $user, $attachments = array()) {
        $settings = get_option('pushrelay_settings', array());
        
        ob_start();
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: #0073aa; color: white; padding: 20px; border-radius: 5px 5px 0 0; }
                .content { background: #f9f9f9; padding: 20px; border: 1px solid #ddd; }
                .footer { background: #f1f1f1; padding: 15px; border-radius: 0 0 5px 5px; font-size: 12px; color: #666; }
                .priority { display: inline-block; padding: 5px 10px; border-radius: 3px; font-weight: bold; }
                .priority-low { background: #d1ecf1; color: #0c5460; }
                .priority-medium { background: #fff3cd; color: #856404; }
                .priority-high { background: #f8d7da; color: #721c24; }
                .priority-urgent { background: #dc3545; color: white; }
                .info-box { background: white; padding: 15px; margin: 10px 0; border-left: 4px solid #0073aa; }
                .system-info { background: #fff; padding: 10px; margin: 10px 0; font-family: monospace; font-size: 12px; }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="header">
                    <h2>🎫 PushRelay Support Ticket</h2>
                    <p>Ticket ID: <strong><?php echo esc_html($ticket_id); ?></strong></p>
                </div>
                
                <div class="content">
                    <div class="info-box">
                        <h3><?php echo esc_html($subject); ?></h3>
                        <p><strong>Priority:</strong> <span class="priority priority-<?php echo esc_attr($priority); ?>"><?php echo esc_html(strtoupper($priority)); ?></span></p>
                    </div>
                    
                    <div class="info-box">
                        <h4>Customer Information</h4>
                        <p>
                            <strong>Name:</strong> <?php echo esc_html($user->display_name); ?><br>
                            <strong>Email:</strong> <a href="mailto:<?php echo esc_attr($user->user_email); ?>"><?php echo esc_html($user->user_email); ?></a><br>
                            <strong>Website:</strong> <a href="<?php echo esc_url(get_site_url()); ?>"><?php echo esc_html(get_bloginfo('name')); ?></a><br>
                            <strong>URL:</strong> <?php echo esc_url(get_site_url()); ?>
                        </p>
                    </div>
                    
                    <div class="info-box">
                        <h4>Message</h4>
                        <div><?php echo wp_kses_post(wpautop($message)); ?></div>
                    </div>
                    
                    <?php if (!empty($attachments['system_info'])): ?>
                        <div class="info-box">
                            <h4>System Information</h4>
                            <div class="system-info">
                                <?php foreach ($attachments['system_info'] as $key => $value): ?>
                                    <strong><?php echo esc_html(ucwords(str_replace('_', ' ', $key))); ?>:</strong> 
                                    <?php 
                                    if (is_array($value)) {
                                        echo esc_html(implode(', ', array_slice($value, 0, 3)));
                                        if (count($value) > 3) {
                                            echo ' (' . (count($value) - 3) . ' more...)';
                                        }
                                    } else {
                                        echo esc_html($value);
                                    }
                                    ?><br>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($attachments['logs'])): ?>
                        <div class="info-box">
                            <h4>Recent Debug Logs (Last 10)</h4>
                            <div class="system-info">
                                <?php foreach ($attachments['logs'] as $log): ?>
                                    <div style="margin-bottom: 10px; padding: 5px; background: #f5f5f5;">
                                        <strong>[<?php echo esc_html($log['level']); ?>]</strong> 
                                        <?php echo esc_html($log['timestamp']); ?><br>
                                        <?php echo esc_html($log['message']); ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <div class="info-box">
                        <h4>Plugin Configuration</h4>
                        <div class="system-info">
                            <strong>API Key:</strong> <?php echo !empty($settings['api_key']) ? '✓ Configured' : '✗ Not configured'; ?><br>
                            <strong>Website ID:</strong> <?php echo isset($settings['website_id']) ? esc_html($settings['website_id']) : 'Not set'; ?><br>
                            <strong>Debug Mode:</strong> <?php echo !empty($settings['debug_mode']) ? 'Enabled' : 'Disabled'; ?><br>
                            <strong>Auto Notifications:</strong> <?php echo !empty($settings['auto_notifications']) ? 'Enabled' : 'Disabled'; ?><br>
                            <strong>WooCommerce:</strong> <?php echo class_exists('WooCommerce') ? 'Active' : 'Not active'; ?>
                        </div>
                    </div>
                </div>
                
                <div class="footer">
                    <p>This ticket was automatically generated by PushRelay WordPress Plugin v<?php echo esc_html(PUSHRELAY_VERSION); ?></p>
                    <p>Submitted: <?php echo esc_html(current_time('mysql')); ?></p>
                </div>
            </div>
        </body>
        </html>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Prepare logs attachment
     */
    private function prepare_logs_attachment() {
        $logs = PushRelay_Debug_Logger::get_logs(10);
        return $logs;
    }
    
    /**
     * Get system information
     */
    private function get_system_information() {
        return PushRelay_Debug_Logger::get_system_info();
    }
    
    /**
     * Get all tickets
     */
    public function get_tickets($limit = 50) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'pushrelay_tickets';
        
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safe
        $tickets = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table_name} ORDER BY created_at DESC LIMIT %d",
                absint($limit)
            ),
            ARRAY_A
        );
        
        return $tickets ? $tickets : array();
    }
    
    /**
     * Get single ticket
     */
    public function get_ticket($ticket_id) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'pushrelay_tickets';
        
        // Try to find by database ID first
        if (is_numeric($ticket_id)) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safe
            $ticket = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$table_name} WHERE id = %d",
                    absint($ticket_id)
                ),
                ARRAY_A
            );
        } else {
            // Find by ticket_id string
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safe
            $ticket = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$table_name} WHERE ticket_id = %s",
                    sanitize_text_field($ticket_id)
                ),
                ARRAY_A
            );
        }
        
        if ($ticket && !empty($ticket['attachments'])) {
            $ticket['attachments'] = json_decode($ticket['attachments'], true);
        }
        
        return $ticket;
    }
    
    /**
     * Update ticket status
     */
    public function update_ticket_status($ticket_id, $status) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'pushrelay_tickets';
        
        $valid_statuses = array('open', 'in_progress', 'resolved', 'closed');
        if (!in_array($status, $valid_statuses, true)) {
            return new WP_Error('invalid_status', __('Invalid status', 'pushrelay'));
        }
        
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $result = $wpdb->update(
            $table_name,
            array(
                'status' => $status,
                'updated_at' => current_time('mysql'),
            ),
            array('id' => absint($ticket_id)),
            array('%s', '%s'),
            array('%d')
        );
        
        if ($result === false) {
            return new WP_Error('db_error', __('Failed to update ticket', 'pushrelay'));
        }
        
        PushRelay_Debug_Logger::log(
            sprintf('Ticket status updated: %d - %s', $ticket_id, $status),
            'info'
        );
        
        return true;
    }
    
    /**
     * Delete ticket
     */
    public function delete_ticket($ticket_id) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'pushrelay_tickets';
        
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $result = $wpdb->delete(
            $table_name,
            array('id' => absint($ticket_id)),
            array('%d')
        );
        
        if (!$result) {
            return new WP_Error('db_error', __('Failed to delete ticket', 'pushrelay'));
        }
        
        PushRelay_Debug_Logger::log(
            sprintf('Ticket deleted: %d', $ticket_id),
            'info'
        );
        
        return true;
    }
    
    /**
     * Get ticket statistics
     */
    public function get_statistics() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'pushrelay_tickets';
        
        $stats = array(
            'total' => 0,
            'open' => 0,
            'in_progress' => 0,
            'resolved' => 0,
            'closed' => 0,
            'email_sent' => 0,
            'email_failed' => 0,
        );
        
        // Total tickets
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $total = $wpdb->get_var("SELECT COUNT(*) FROM {$table_name}");
        $stats['total'] = $total ? absint($total) : 0;
        
        // By status
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $by_status = $wpdb->get_results(
            "SELECT status, COUNT(*) as count FROM {$table_name} GROUP BY status",
            ARRAY_A
        );
        
        if ($by_status) {
            foreach ($by_status as $row) {
                $status = $row['status'];
                $count = absint($row['count']);
                
                if (isset($stats[$status])) {
                    $stats[$status] = $count;
                }
            }
        }
        
        // Email stats
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $email_sent = $wpdb->get_var("SELECT COUNT(*) FROM {$table_name} WHERE email_sent = 1");
        $stats['email_sent'] = $email_sent ? absint($email_sent) : 0;
        
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $email_failed = $wpdb->get_var("SELECT COUNT(*) FROM {$table_name} WHERE email_sent = 0");
        $stats['email_failed'] = $email_failed ? absint($email_failed) : 0;
        
        return $stats;
    }
    
    /**
     * Get priority badge HTML
     */
    public function get_priority_badge($priority) {
        $badges = array(
            'low' => '<span class="pushrelay-badge pushrelay-badge-low">' . __('Low', 'pushrelay') . '</span>',
            'medium' => '<span class="pushrelay-badge pushrelay-badge-medium">' . __('Medium', 'pushrelay') . '</span>',
            'high' => '<span class="pushrelay-badge pushrelay-badge-high">' . __('High', 'pushrelay') . '</span>',
            'urgent' => '<span class="pushrelay-badge pushrelay-badge-urgent">' . __('Urgent', 'pushrelay') . '</span>',
        );
        
        return isset($badges[$priority]) ? $badges[$priority] : '';
    }
    
    /**
     * Get status badge HTML
     */
    public function get_status_badge($status) {
        $badges = array(
            'open' => '<span class="pushrelay-badge pushrelay-badge-open">' . __('Open', 'pushrelay') . '</span>',
            'in_progress' => '<span class="pushrelay-badge pushrelay-badge-progress">' . __('In Progress', 'pushrelay') . '</span>',
            'resolved' => '<span class="pushrelay-badge pushrelay-badge-resolved">' . __('Resolved', 'pushrelay') . '</span>',
            'closed' => '<span class="pushrelay-badge pushrelay-badge-closed">' . __('Closed', 'pushrelay') . '</span>',
        );
        
        return isset($badges[$status]) ? $badges[$status] : '';
    }
    
    /**
     * AJAX: Create ticket
     */
    public function ajax_create_ticket() {
        check_ajax_referer('pushrelay_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'pushrelay')));
        }
        
        $subject = isset($_POST['subject']) ? sanitize_text_field(wp_unslash($_POST['subject'])) : '';
        $message = isset($_POST['message']) ? wp_kses_post(wp_unslash($_POST['message'])) : '';
        $priority = isset($_POST['priority']) ? sanitize_text_field(wp_unslash($_POST['priority'])) : 'medium';
        $attach_logs = isset($_POST['attach_logs']) && $_POST['attach_logs'] === 'true';
        
        if (empty($subject) || empty($message)) {
            wp_send_json_error(array('message' => __('Subject and message are required', 'pushrelay')));
        }
        
        $result = $this->create_ticket($subject, $message, $priority, $attach_logs);
        
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }
        
        wp_send_json_success(array(
            'message' => sprintf(
                /* translators: %s: Ticket ID */
                __('Support ticket created successfully! Ticket ID: %s', 'pushrelay'),
                $result['ticket_id']
            ),
            'ticket' => $result,
            'email_sent' => $result['email_sent'],
        ));
    }
    
    /**
     * AJAX: Get tickets
     */
    public function ajax_get_tickets() {
        check_ajax_referer('pushrelay_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'pushrelay')));
        }
        
        $limit = isset($_POST['limit']) ? absint($_POST['limit']) : 50;
        
        $tickets = $this->get_tickets($limit);
        $stats = $this->get_statistics();
        
        wp_send_json_success(array(
            'tickets' => $tickets,
            'stats' => $stats,
        ));
    }
    
    /**
     * AJAX: Get ticket
     */
    public function ajax_get_ticket() {
        check_ajax_referer('pushrelay_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'pushrelay')));
        }
        
        $ticket_id = isset($_POST['ticket_id']) ? $_POST['ticket_id'] : 0; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        
        if (empty($ticket_id)) {
            wp_send_json_error(array('message' => __('Invalid ticket ID', 'pushrelay')));
        }
        
        $ticket = $this->get_ticket($ticket_id);
        
        if (!$ticket) {
            wp_send_json_error(array('message' => __('Ticket not found', 'pushrelay')));
        }
        
        wp_send_json_success($ticket);
    }
    
    /**
     * AJAX: Delete ticket
     */
    public function ajax_delete_ticket() {
        check_ajax_referer('pushrelay_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'pushrelay')));
        }
        
        $ticket_id = isset($_POST['ticket_id']) ? absint($_POST['ticket_id']) : 0;
        
        if (!$ticket_id) {
            wp_send_json_error(array('message' => __('Invalid ticket ID', 'pushrelay')));
        }
        
        $result = $this->delete_ticket($ticket_id);
        
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }
        
        wp_send_json_success(array(
            'message' => __('Ticket deleted successfully', 'pushrelay')
        ));
    }
}
