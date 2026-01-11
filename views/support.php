<?php
/**
 * Support View
 * 
 * Support ticket system and help center
 * 
 * @package PushRelay
 * @since 1.6.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

$support = new PushRelay_Support_Tickets();
$tickets = $support->get_tickets();
?>

<div class="wrap pushrelay-support">
    <div class="pushrelay-header">
        <h1><?php esc_html_e('Support', 'pushrelay'); ?></h1>
        <button type="button" id="new-ticket-btn" class="pushrelay-btn pushrelay-btn-primary">
            <?php esc_html_e('+ New Ticket', 'pushrelay'); ?>
        </button>
    </div>

    <!-- Notices Container -->
    <div class="pushrelay-notices"></div>

    <!-- Quick Help -->
    <div class="pushrelay-card" style="margin-bottom: 20px;">
        <div class="pushrelay-card-header">
            <h2><?php esc_html_e('Quick Help', 'pushrelay'); ?></h2>
        </div>
        <div class="pushrelay-card-body">
            <div class="quick-help-grid">
                
                <div class="help-item">
                    <span class="help-icon">📚</span>
                    <h3><?php esc_html_e('Documentation', 'pushrelay'); ?></h3>
                    <p><?php esc_html_e('Browse our comprehensive guides and tutorials', 'pushrelay'); ?></p>
                    <a href="https://pushrelay.com/docs" target="_blank" class="pushrelay-btn pushrelay-btn-secondary">
                        <?php esc_html_e('View Docs', 'pushrelay'); ?>
                    </a>
                </div>

                <div class="help-item">
                    <span class="help-icon">💬</span>
                    <h3><?php esc_html_e('Community Forum', 'pushrelay'); ?></h3>
                    <p><?php esc_html_e('Get help from our active community', 'pushrelay'); ?></p>
                    <a href="https://pushrelay.com/community" target="_blank" class="pushrelay-btn pushrelay-btn-secondary">
                        <?php esc_html_e('Visit Forum', 'pushrelay'); ?>
                    </a>
                </div>

                <div class="help-item">
                    <span class="help-icon">🎥</span>
                    <h3><?php esc_html_e('Video Tutorials', 'pushrelay'); ?></h3>
                    <p><?php esc_html_e('Watch step-by-step video guides', 'pushrelay'); ?></p>
                    <a href="https://pushrelay.com/tutorials" target="_blank" class="pushrelay-btn pushrelay-btn-secondary">
                        <?php esc_html_e('Watch Videos', 'pushrelay'); ?>
                    </a>
                </div>

                <div class="help-item">
                    <span class="help-icon">⚡</span>
                    <h3><?php esc_html_e('API Reference', 'pushrelay'); ?></h3>
                    <p><?php esc_html_e('Complete API documentation for developers', 'pushrelay'); ?></p>
                    <a href="https://pushrelay.com/api-docs" target="_blank" class="pushrelay-btn pushrelay-btn-secondary">
                        <?php esc_html_e('API Docs', 'pushrelay'); ?>
                    </a>
                </div>

            </div>
        </div>
    </div>

    <!-- Support Tickets -->
    <div class="pushrelay-card">
        <div class="pushrelay-card-header">
            <h2><?php esc_html_e('Your Support Tickets', 'pushrelay'); ?></h2>
        </div>
        <div class="pushrelay-card-body">
            <?php if (!empty($tickets)): ?>
                <table class="pushrelay-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Ticket ID', 'pushrelay'); ?></th>
                            <th><?php esc_html_e('Subject', 'pushrelay'); ?></th>
                            <th><?php esc_html_e('Status', 'pushrelay'); ?></th>
                            <th><?php esc_html_e('Priority', 'pushrelay'); ?></th>
                            <th><?php esc_html_e('Created', 'pushrelay'); ?></th>
                            <th><?php esc_html_e('Last Update', 'pushrelay'); ?></th>
                            <th><?php esc_html_e('Actions', 'pushrelay'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($tickets as $ticket): ?>
                            <tr>
                                <td><strong>#<?php echo esc_html($ticket['id']); ?></strong></td>
                                <td><?php echo esc_html($ticket['subject']); ?></td>
                                <td>
                                    <span class="pushrelay-badge pushrelay-status-<?php echo esc_attr($ticket['status']); ?>">
                                        <?php echo esc_html(ucfirst($ticket['status'])); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="priority-badge priority-<?php echo esc_attr($ticket['priority']); ?>">
                                        <?php echo esc_html(ucfirst($ticket['priority'])); ?>
                                    </span>
                                </td>
                                <td><?php echo esc_html(date_i18n(get_option('date_format'), strtotime($ticket['created_at']))); ?></td>
                                <td><?php echo esc_html(date_i18n(get_option('date_format'), strtotime($ticket['updated_at']))); ?></td>
                                <td>
                                    <button class="pushrelay-btn pushrelay-btn-small view-ticket" data-ticket-id="<?php echo esc_attr($ticket['id']); ?>">
                                        <?php esc_html_e('View', 'pushrelay'); ?>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div style="text-align: center; padding: 60px 20px;">
                    <div style="font-size: 48px; margin-bottom: 20px;">🎫</div>
                    <h3><?php esc_html_e('No support tickets yet', 'pushrelay'); ?></h3>
                    <p><?php esc_html_e('Create your first support ticket to get help from our team', 'pushrelay'); ?></p>
                    <button type="button" id="new-ticket-btn-2" class="pushrelay-btn pushrelay-btn-primary">
                        <?php esc_html_e('Create Ticket', 'pushrelay'); ?>
                    </button>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- FAQ -->
    <div class="pushrelay-card" style="margin-top: 20px;">
        <div class="pushrelay-card-header">
            <h2><?php esc_html_e('Frequently Asked Questions', 'pushrelay'); ?></h2>
        </div>
        <div class="pushrelay-card-body">
            <div class="faq-list">
                
                <details class="faq-item">
                    <summary><?php esc_html_e('How do I set up push notifications?', 'pushrelay'); ?></summary>
                    <p><?php esc_html_e('To set up push notifications, go to Settings > General and configure your API key. Then enable the widget or button to allow users to subscribe.', 'pushrelay'); ?></p>
                </details>

                <details class="faq-item">
                    <summary><?php esc_html_e('How do I create a campaign?', 'pushrelay'); ?></summary>
                    <p><?php esc_html_e('Navigate to Campaigns > New Campaign. Fill in the title, message, and URL, then choose your target audience. You can save as draft or send immediately.', 'pushrelay'); ?></p>
                </details>

                <details class="faq-item">
                    <summary><?php esc_html_e('Can I schedule notifications?', 'pushrelay'); ?></summary>
                    <p><?php esc_html_e('Yes! When creating a campaign, enable the scheduling option and select your desired date and time.', 'pushrelay'); ?></p>
                </details>

                <details class="faq-item">
                    <summary><?php esc_html_e('How do I segment my subscribers?', 'pushrelay'); ?></summary>
                    <p><?php esc_html_e('You can segment subscribers by location, device, browser, language, or custom parameters. Use the filter option when creating campaigns.', 'pushrelay'); ?></p>
                </details>

                <details class="faq-item">
                    <summary><?php esc_html_e('What browsers are supported?', 'pushrelay'); ?></summary>
                    <p><?php esc_html_e('Push notifications are supported on Chrome, Firefox, Safari, Edge, and Opera on both desktop and mobile devices.', 'pushrelay'); ?></p>
                </details>

                <details class="faq-item">
                    <summary><?php esc_html_e('How do I track campaign performance?', 'pushrelay'); ?></summary>
                    <p><?php esc_html_e('Go to Analytics to see detailed metrics including sent, displayed, clicked notifications, and click-through rates (CTR).', 'pushrelay'); ?></p>
                </details>

            </div>
        </div>
    </div>

</div>

<!-- New Ticket Modal -->
<div id="new-ticket-modal" class="pushrelay-modal" style="display: none;">
    <div class="pushrelay-modal-content">
        <span class="pushrelay-modal-close">&times;</span>
        <h2><?php esc_html_e('Create Support Ticket', 'pushrelay'); ?></h2>
        
        <form id="new-ticket-form">
            <div class="pushrelay-form-group">
                <label class="pushrelay-form-label" for="ticket-subject">
                    <?php esc_html_e('Subject', 'pushrelay'); ?>
                    <span style="color: red;">*</span>
                </label>
                <input type="text" 
                       id="ticket-subject" 
                       name="subject" 
                       class="pushrelay-form-control" 
                       required
                       placeholder="<?php esc_attr_e('Brief description of your issue', 'pushrelay'); ?>">
            </div>

            <div class="pushrelay-form-group">
                <label class="pushrelay-form-label" for="ticket-priority">
                    <?php esc_html_e('Priority', 'pushrelay'); ?>
                </label>
                <select id="ticket-priority" name="priority" class="pushrelay-form-control">
                    <option value="low"><?php esc_html_e('Low', 'pushrelay'); ?></option>
                    <option value="medium" selected><?php esc_html_e('Medium', 'pushrelay'); ?></option>
                    <option value="high"><?php esc_html_e('High', 'pushrelay'); ?></option>
                    <option value="urgent"><?php esc_html_e('Urgent', 'pushrelay'); ?></option>
                </select>
            </div>

            <div class="pushrelay-form-group">
                <label class="pushrelay-form-label" for="ticket-message">
                    <?php esc_html_e('Message', 'pushrelay'); ?>
                    <span style="color: red;">*</span>
                </label>
                <textarea id="ticket-message" 
                          name="message" 
                          class="pushrelay-form-control" 
                          rows="8" 
                          required
                          placeholder="<?php esc_attr_e('Describe your issue in detail...', 'pushrelay'); ?>"></textarea>
            </div>

            <div class="pushrelay-form-group">
                <button type="submit" class="pushrelay-btn pushrelay-btn-primary">
                    <?php esc_html_e('Submit Ticket', 'pushrelay'); ?>
                </button>
                <button type="button" class="pushrelay-btn pushrelay-btn-secondary pushrelay-modal-close">
                    <?php esc_html_e('Cancel', 'pushrelay'); ?>
                </button>
            </div>
        </form>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    
    // Remove global AJAX handlers that might conflict (wp-codemirror fix)
    $(document).off('ajaxComplete');
    $(document).off('ajaxSend');
    
    // Open new ticket modal
    $('#new-ticket-btn, #new-ticket-btn-2').on('click', function() {
        $('#new-ticket-modal').show();
    });
    
    // Close modal
    $('.pushrelay-modal-close').on('click', function() {
        $(this).closest('.pushrelay-modal').hide();
    });
    
    $(window).on('click', function(e) {
        if ($(e.target).hasClass('pushrelay-modal')) {
            $(e.target).hide();
        }
    });
    
    // Submit ticket using fetch API to avoid wp-codemirror conflict
    $('#new-ticket-form').on('submit', function(e) {
        e.preventDefault();
        
        var $btn = $(this).find('button[type="submit"]');
        var originalText = $btn.text();
        $btn.text('<?php esc_js(__('Sending...', 'pushrelay')); ?>').prop('disabled', true);
        
        var formData = new FormData();
        formData.append('action', 'pushrelay_create_ticket');
        formData.append('subject', $('#ticket-subject').val());
        formData.append('priority', $('#ticket-priority').val());
        formData.append('message', $('#ticket-message').val());
        formData.append('nonce', '<?php echo esc_attr(wp_create_nonce('pushrelay_admin_nonce')); ?>');
        
        fetch(ajaxurl, {
            method: 'POST',
            body: formData
        })
        .then(function(response) { return response.json(); })
        .then(function(data) {
            if (data.success) {
                $('#new-ticket-modal').hide();
                $('.pushrelay-notices').html('<div class="pushrelay-alert pushrelay-alert-success">✓ ' + 
                    (data.data.message || '<?php esc_js(__('Ticket created successfully!', 'pushrelay')); ?>') + 
                    '</div>');
                setTimeout(function() {
                    location.reload();
                }, 1500);
            } else {
                alert('<?php esc_js(__('Error:', 'pushrelay')); ?> ' + (data.data ? data.data.message : '<?php esc_js(__('Unknown error', 'pushrelay')); ?>'));
            }
        })
        .catch(function(error) {
            alert('<?php esc_js(__('Connection error. Please try again.', 'pushrelay')); ?>');
        })
        .finally(function() {
            $btn.text(originalText).prop('disabled', false);
        });
    });
    
});
</script>

<style>
.pushrelay-support {
    max-width: 1200px;
}

.quick-help-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
}

.help-item {
    text-align: center;
    padding: 30px 20px;
    border: 1px solid #ddd;
    border-radius: 8px;
    background: #fff;
    transition: transform 0.2s, box-shadow 0.2s;
}

.help-item:hover {
    transform: translateY(-5px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
}

.help-icon {
    font-size: 48px;
    display: block;
    margin-bottom: 15px;
}

.help-item h3 {
    margin: 0 0 10px 0;
    font-size: 18px;
}

.help-item p {
    color: #666;
    margin-bottom: 15px;
}

.priority-badge {
    padding: 4px 12px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 600;
}

.priority-badge.priority-low {
    background: #d1ecf1;
    color: #0c5460;
}

.priority-badge.priority-medium {
    background: #fff3cd;
    color: #856404;
}

.priority-badge.priority-high {
    background: #f8d7da;
    color: #721c24;
}

.priority-badge.priority-urgent {
    background: #dc3545;
    color: #fff;
}

.faq-list {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.faq-item {
    border: 1px solid #ddd;
    border-radius: 6px;
    padding: 15px;
    background: #fff;
}

.faq-item summary {
    cursor: pointer;
    font-weight: 600;
    color: #1d2327;
    user-select: none;
}

.faq-item summary:hover {
    color: #007cba;
}

.faq-item p {
    margin: 15px 0 0 0;
    color: #666;
    line-height: 1.6;
}

.pushrelay-modal {
    position: fixed;
    z-index: 100000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0,0,0,0.5);
}

.pushrelay-modal-content {
    background-color: #fff;
    margin: 5% auto;
    padding: 30px;
    border: 1px solid #ddd;
    border-radius: 8px;
    width: 90%;
    max-width: 600px;
    max-height: 80vh;
    overflow-y: auto;
    position: relative;
}

.pushrelay-modal-close {
    position: absolute;
    right: 15px;
    top: 15px;
    font-size: 28px;
    font-weight: bold;
    cursor: pointer;
    color: #666;
}

.pushrelay-modal-close:hover {
    color: #000;
}

@media (max-width: 782px) {
    .quick-help-grid {
        grid-template-columns: 1fr;
    }
}
</style>
