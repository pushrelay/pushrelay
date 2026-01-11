/**
 * PushRelay Admin JavaScript
 * 
 * Handles all admin interface interactions, AJAX calls, and UI updates
 * 
 * @package PushRelay
 * @since 1.6.0
 */

(function($) {
    'use strict';

    // Global PushRelay admin object
    window.PushRelayAdmin = {
        
        /**
         * Initialize admin functionality
         */
        init: function() {
            // IMPORTANT: Remove global AJAX handlers that conflict with wp-codemirror
            // This must happen before any AJAX calls are made
            $(document).off('ajaxComplete');
            $(document).off('ajaxSend');
            
            this.bindEvents();
            this.initCharts();
            this.initTabs();
            this.initTooltips();
            this.checkHealth();
            this.initAutoDismissNotices();
        },

        /**
         * Auto-dismiss PHP-rendered notices after 5 seconds
         */
        initAutoDismissNotices: function() {
            $('.pushrelay-notice-autodismiss').each(function() {
                var $notice = $(this);
                setTimeout(function() {
                    $notice.fadeOut(300, function() {
                        $(this).remove();
                    });
                }, 5000);
            });
        },

        /**
         * Bind event handlers
         */
        bindEvents: function() {
            console.log('PushRelay: Binding events...');
            
            // Test API connection
            $(document).on('click', '.pushrelay-test-connection', this.testConnection);
            
            // Detect website
            $(document).on('click', '.pushrelay-detect-website', this.detectWebsite);
            
            // Save settings
            $(document).on('click', '.pushrelay-save-settings', this.saveSettings);
            
            // Create campaign
            $(document).on('click', '.pushrelay-create-campaign', this.createCampaign);
            console.log('PushRelay: Bound .pushrelay-create-campaign click handler');
            
            // Send campaign
            $(document).on('click', '.pushrelay-send-campaign', this.sendCampaign);
            
            // Delete campaign
            $(document).on('click', '.pushrelay-delete-campaign', this.deleteCampaign);
            
            // Preview campaign
            $(document).on('input', '.pushrelay-campaign-form input, .pushrelay-campaign-form textarea', this.updateCampaignPreview);
            
            // Regenerate service worker
            $(document).on('click', '.pushrelay-regenerate-sw', this.regenerateServiceWorker);
            
            // Test service worker
            $(document).on('click', '.pushrelay-test-sw', this.testServiceWorker);
            
            // Run health check
            $(document).on('click', '.pushrelay-run-health-check', this.runHealthCheck);
            
            // Create support ticket
            $(document).on('click', '.pushrelay-create-ticket', this.createTicket);
            
            // Export data
            $(document).on('click', '.pushrelay-export-data', this.exportData);
            
            // Load more items (pagination)
            $(document).on('click', '.pushrelay-load-more', this.loadMore);
            
            // Delete item with confirmation
            $(document).on('click', '.pushrelay-delete-item', this.confirmDelete);
            
            // Toggle sections
            $(document).on('click', '.pushrelay-toggle-section', this.toggleSection);
            
            // Image upload
            $(document).on('click', '.pushrelay-upload-image', this.uploadImage);
            
            // Clear logs
            $(document).on('click', '.pushrelay-clear-logs', this.clearLogs);
            
            // Refresh stats
            $(document).on('click', '.pushrelay-refresh-stats', this.refreshStats);
        },

        /**
         * Test API connection
         */
        testConnection: function(e) {
            e.preventDefault();
            
            var $btn = $(this);
            var originalText = $btn.text();
            
            $btn.text(pushrelayAdmin.strings.testing).prop('disabled', true);
            
            var formData = new FormData();
            formData.append('action', 'pushrelay_test_connection');
            formData.append('nonce', pushrelayAdmin.nonce);
            
            fetch(pushrelayAdmin.ajaxurl, {
                method: 'POST',
                body: formData
            })
            .then(function(response) { return response.json(); })
            .then(function(data) {
                if (data.success) {
                    PushRelayAdmin.showNotice('success', data.data.message);
                } else {
                    PushRelayAdmin.showNotice('error', data.data ? data.data.message : 'Connection failed');
                }
            })
            .catch(function() {
                PushRelayAdmin.showNotice('error', pushrelayAdmin.strings.error);
            })
            .finally(function() {
                $btn.text(originalText).prop('disabled', false);
            });
        },

        /**
         * Auto-detect website
         */
        detectWebsite: function(e) {
            e.preventDefault();
            
            var $btn = $(this);
            var originalText = $btn.text();
            
            $btn.text(pushrelayAdmin.strings.detecting).prop('disabled', true);
            
            $.ajax({
                url: pushrelayAdmin.ajaxurl,
                type: 'POST',
                data: {
                    action: 'pushrelay_detect_website',
                    nonce: pushrelayAdmin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        PushRelayAdmin.showNotice('success', response.data.message);
                        
                        // Update website ID and pixel key if detected
                        if (response.data.website_id) {
                            $('#pushrelay_website_id').val(response.data.website_id);
                        }
                        if (response.data.pixel_key) {
                            $('#pushrelay_pixel_key').val(response.data.pixel_key);
                        }
                        
                        // Show website options if multiple found
                        if (response.data.websites && response.data.websites.length > 1) {
                            PushRelayAdmin.showWebsiteSelector(response.data.websites);
                        }
                    } else {
                        PushRelayAdmin.showNotice('error', response.data.message);
                    }
                },
                error: function() {
                    PushRelayAdmin.showNotice('error', pushrelayAdmin.strings.error);
                },
                complete: function() {
                    $btn.text(originalText).prop('disabled', false);
                }
            });
        },

        /**
         * Show website selector modal
         */
        showWebsiteSelector: function(websites) {
            var html = '<div class="pushrelay-modal">';
            html += '<div class="pushrelay-modal-content">';
            html += '<h2>Select Your Website</h2>';
            html += '<div class="pushrelay-website-list">';
            
            websites.forEach(function(website) {
                html += '<div class="pushrelay-website-option" data-id="' + website.id + '" data-key="' + website.pixel_key + '">';
                html += '<h3>' + website.name + '</h3>';
                html += '<p>' + website.host + '</p>';
                html += '<button class="pushrelay-btn pushrelay-btn-primary">Select</button>';
                html += '</div>';
            });
            
            html += '</div>';
            html += '</div>';
            html += '</div>';
            
            $('body').append(html);
            
            // Handle website selection
            $(document).on('click', '.pushrelay-website-option button', function() {
                var $option = $(this).closest('.pushrelay-website-option');
                $('#pushrelay_website_id').val($option.data('id'));
                $('#pushrelay_pixel_key').val($option.data('key'));
                $('.pushrelay-modal').remove();
                PushRelayAdmin.showNotice('success', 'Website selected successfully!');
            });
        },

        /**
         * Save settings
         */
        saveSettings: function(e) {
            e.preventDefault();
            
            var $form = $(this).closest('form');
            var $btn = $(this);
            var originalText = $btn.text();
            
            $btn.text(pushrelayAdmin.strings.saving).prop('disabled', true);
            
            var formData = new FormData($form[0]);
            formData.append('action', 'pushrelay_save_settings');
            formData.append('nonce', pushrelayAdmin.nonce);
            
            fetch(pushrelayAdmin.ajaxurl, {
                method: 'POST',
                body: formData
            })
            .then(function(response) { return response.json(); })
            .then(function(data) {
                if (data.success) {
                    PushRelayAdmin.showNotice('success', data.data.message);
                } else {
                    PushRelayAdmin.showNotice('error', data.data ? data.data.message : 'Save failed');
                }
            })
            .catch(function() {
                PushRelayAdmin.showNotice('error', pushrelayAdmin.strings.error);
            })
            .finally(function() {
                $btn.text(originalText).prop('disabled', false);
            });
        },

        /**
         * Create campaign
         */
        createCampaign: function(e) {
            e.preventDefault();
            
            var $form = $(this).closest('form');
            var $btn = $(this);
            var originalText = $btn.text();
            var campaignName = $form.find('[name="name"]').val() || 'Campaign';
            
            console.log('PushRelay: Starting campaign creation...');
            console.log('PushRelay: Form found:', $form.length > 0);
            
            $btn.text(pushrelayAdmin.strings.sending).prop('disabled', true);
            
            // Use native fetch API to avoid jQuery AJAX global handler conflicts
            var formData = new FormData($form[0]);
            formData.append('action', 'pushrelay_create_campaign');
            formData.append('nonce', pushrelayAdmin.nonce);
            
            // Debug: log form data
            console.log('PushRelay: Form data entries:');
            for (var pair of formData.entries()) {
                console.log('  ' + pair[0] + ': ' + pair[1]);
            }
            
            console.log('PushRelay: Sending to:', pushrelayAdmin.ajaxurl);
            
            fetch(pushrelayAdmin.ajaxurl, {
                method: 'POST',
                body: formData
            })
            .then(function(response) {
                console.log('PushRelay: Response status:', response.status);
                console.log('PushRelay: Response ok:', response.ok);
                return response.text(); // Get text first to debug
            })
            .then(function(text) {
                console.log('PushRelay: Raw response:', text);
                try {
                    var data = JSON.parse(text);
                    console.log('PushRelay: Parsed response:', data);
                    
                    if (data.success) {
                        // Show success message with campaign name
                        var successMsg = '✅ Campaign "' + campaignName + '" created successfully!';
                        if (formData.get('send') === '1') {
                            successMsg += ' Status: Processing...';
                        }
                        PushRelayAdmin.showNotice('success', successMsg);
                        $form[0].reset();
                        
                        // Redirect to campaigns page with refresh parameter to bypass cache
                        setTimeout(function() {
                            var redirectUrl = 'admin.php?page=pushrelay-campaigns&created=' + encodeURIComponent(campaignName) + '&refresh=' + Date.now();
                            window.location.href = redirectUrl;
                        }, 1500);
                    } else {
                        PushRelayAdmin.showNotice('error', data.data ? data.data.message : 'Error creating campaign');
                        $btn.text(originalText).prop('disabled', false);
                    }
                } catch (parseError) {
                    console.error('PushRelay: JSON parse error:', parseError);
                    console.error('PushRelay: Response was:', text);
                    PushRelayAdmin.showNotice('error', 'Invalid server response');
                    $btn.text(originalText).prop('disabled', false);
                }
            })
            .catch(function(error) {
                console.error('PushRelay: Fetch error:', error);
                PushRelayAdmin.showNotice('error', pushrelayAdmin.strings.error);
                $btn.text(originalText).prop('disabled', false);
            });
        },

        /**
         * Send campaign
         */
        sendCampaign: function(e) {
            e.preventDefault();
            
            var campaignId = $(this).data('campaign-id');
            var $btn = $(this);
            var $row = $btn.closest('tr');
            var originalText = $btn.text();
            
            if (!confirm('Are you sure you want to send this campaign?')) {
                return;
            }
            
            $btn.text(pushrelayAdmin.strings.sending).prop('disabled', true);
            
            var formData = new FormData();
            formData.append('action', 'pushrelay_send_campaign');
            formData.append('campaign_id', campaignId);
            formData.append('nonce', pushrelayAdmin.nonce);
            
            fetch(pushrelayAdmin.ajaxurl, {
                method: 'POST',
                body: formData
            })
            .then(function(response) { return response.json(); })
            .then(function(data) {
                if (data.success) {
                    PushRelayAdmin.showNotice('success', data.data.message);
                    
                    // Update status with actual value from API response
                    var actualStatus = data.data.status || 'sent';
                    var $statusCell = $row.find('.column-status .status, .status');
                    
                    // Remove old status classes and add new one
                    $statusCell
                        .removeClass('draft pending sending processing')
                        .addClass(actualStatus.toLowerCase())
                        .text(actualStatus.charAt(0).toUpperCase() + actualStatus.slice(1));
                    
                    // Disable send button since campaign is now sent
                    $btn.prop('disabled', true).addClass('disabled');
                } else {
                    PushRelayAdmin.showNotice('error', data.data ? data.data.message : 'Send failed');
                    $btn.text(originalText).prop('disabled', false);
                }
            })
            .catch(function() {
                PushRelayAdmin.showNotice('error', pushrelayAdmin.strings.error);
                $btn.text(originalText).prop('disabled', false);
            });
        },

        /**
         * Delete campaign
         */
        deleteCampaign: function(e) {
            e.preventDefault();
            
            var campaignId = $(this).data('campaign-id');
            var $row = $(this).closest('tr');
            
            if (!confirm(pushrelayAdmin.strings.confirm_delete)) {
                return;
            }
            
            var formData = new FormData();
            formData.append('action', 'pushrelay_delete_campaign');
            formData.append('campaign_id', campaignId);
            formData.append('nonce', pushrelayAdmin.nonce);
            
            fetch(pushrelayAdmin.ajaxurl, {
                method: 'POST',
                body: formData
            })
            .then(function(response) { return response.json(); })
            .then(function(data) {
                if (data.success) {
                    PushRelayAdmin.showNotice('success', data.data.message);
                    $row.fadeOut(300, function() {
                        $(this).remove();
                    });
                } else {
                    PushRelayAdmin.showNotice('error', data.data ? data.data.message : 'Delete failed');
                }
            })
            .catch(function() {
                PushRelayAdmin.showNotice('error', pushrelayAdmin.strings.error);
            });
        },

        /**
         * Update campaign preview
         */
        updateCampaignPreview: function() {
            var title = $('#campaign_title').val();
            var description = $('#campaign_description').val();
            var imageUrl = $('#campaign_image_url').val();
            
            $.ajax({
                url: pushrelayAdmin.ajaxurl,
                type: 'POST',
                data: {
                    action: 'pushrelay_preview_campaign',
                    title: title,
                    description: description,
                    image_url: imageUrl,
                    nonce: pushrelayAdmin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        $('#campaign-preview').html(response.data.preview);
                    }
                }
            });
        },

        /**
         * Regenerate service worker
         */
        regenerateServiceWorker: function(e) {
            e.preventDefault();
            
            var $btn = $(this);
            var originalText = $btn.text();
            
            $btn.text(pushrelayAdmin.strings.regenerating).prop('disabled', true);
            
            $.ajax({
                url: pushrelayAdmin.ajaxurl,
                type: 'POST',
                data: {
                    action: 'pushrelay_regenerate_sw',
                    nonce: pushrelayAdmin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        PushRelayAdmin.showNotice('success', response.data.message);
                    } else {
                        PushRelayAdmin.showNotice('error', response.data.message);
                    }
                },
                error: function() {
                    PushRelayAdmin.showNotice('error', pushrelayAdmin.strings.error);
                },
                complete: function() {
                    $btn.text(originalText).prop('disabled', false);
                }
            });
        },

        /**
         * Test service worker
         */
        testServiceWorker: function(e) {
            e.preventDefault();
            
            var $btn = $(this);
            var originalText = $btn.text();
            
            $btn.text(pushrelayAdmin.strings.testing).prop('disabled', true);
            
            $.ajax({
                url: pushrelayAdmin.ajaxurl,
                type: 'POST',
                data: {
                    action: 'pushrelay_test_sw',
                    nonce: pushrelayAdmin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        PushRelayAdmin.showNotice('success', response.data.message + '<br>URL: ' + response.data.url);
                    } else {
                        PushRelayAdmin.showNotice('error', response.data.message);
                    }
                },
                error: function() {
                    PushRelayAdmin.showNotice('error', pushrelayAdmin.strings.error);
                },
                complete: function() {
                    $btn.text(originalText).prop('disabled', false);
                }
            });
        },

        /**
         * Run health check
         */
        runHealthCheck: function(e) {
            e.preventDefault();
            
            var $btn = $(this);
            var originalText = $btn.text();
            
            $btn.text(pushrelayAdmin.strings.loading).prop('disabled', true);
            
            $.ajax({
                url: pushrelayAdmin.ajaxurl,
                type: 'POST',
                data: {
                    action: 'pushrelay_run_health_check',
                    nonce: pushrelayAdmin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        PushRelayAdmin.updateHealthDisplay(response.data);
                        PushRelayAdmin.showNotice('success', response.data.message);
                    } else {
                        PushRelayAdmin.showNotice('error', response.data.message);
                    }
                },
                error: function() {
                    PushRelayAdmin.showNotice('error', pushrelayAdmin.strings.error);
                },
                complete: function() {
                    $btn.text(originalText).prop('disabled', false);
                }
            });
        },

        /**
         * Update health display
         */
        updateHealthDisplay: function(data) {
            if (data.score !== undefined) {
                $('.pushrelay-health-score-value').text(data.score + '%');
                
                // Update color based on score
                var scoreClass = 'score-bad';
                if (data.score >= 80) {
                    scoreClass = 'score-good';
                } else if (data.score >= 60) {
                    scoreClass = 'score-warning';
                }
                
                $('.pushrelay-health-score-value')
                    .removeClass('score-good score-warning score-bad')
                    .addClass(scoreClass);
            }
            
            // Update individual checks if provided
            if (data.results && data.results.checks) {
                // Update checks display here
            }
        },

        /**
         * Create support ticket
         */
        createTicket: function(e) {
            e.preventDefault();
            
            var $form = $(this).closest('form');
            var $btn = $(this);
            var originalText = $btn.text();
            
            $btn.text(pushrelayAdmin.strings.sending).prop('disabled', true);
            
            var formData = $form.serialize();
            formData += '&action=pushrelay_create_ticket&nonce=' + pushrelayAdmin.nonce;
            
            $.ajax({
                url: pushrelayAdmin.ajaxurl,
                type: 'POST',
                data: formData,
                success: function(response) {
                    if (response.success) {
                        PushRelayAdmin.showNotice('success', response.data.message);
                        $form[0].reset();
                    } else {
                        PushRelayAdmin.showNotice('error', response.data.message);
                    }
                },
                error: function() {
                    PushRelayAdmin.showNotice('error', pushrelayAdmin.strings.error);
                },
                complete: function() {
                    $btn.text(originalText).prop('disabled', false);
                }
            });
        },

        /**
         * Export data
         */
        exportData: function(e) {
            e.preventDefault();
            
            var type = $(this).data('type') || 'subscribers';
            var $btn = $(this);
            var originalText = $btn.text();
            
            $btn.text(pushrelayAdmin.strings.loading).prop('disabled', true);
            
            $.ajax({
                url: pushrelayAdmin.ajaxurl,
                type: 'POST',
                data: {
                    action: 'pushrelay_export_' + type,
                    nonce: pushrelayAdmin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        // Create download link
                        var blob = PushRelayAdmin.base64toBlob(response.data.csv, 'text/csv');
                        var url = window.URL.createObjectURL(blob);
                        var a = document.createElement('a');
                        a.href = url;
                        a.download = response.data.filename;
                        a.click();
                        window.URL.revokeObjectURL(url);
                        
                        PushRelayAdmin.showNotice('success', 'Export completed successfully!');
                    } else {
                        PushRelayAdmin.showNotice('error', response.data.message);
                    }
                },
                error: function() {
                    PushRelayAdmin.showNotice('error', pushrelayAdmin.strings.error);
                },
                complete: function() {
                    $btn.text(originalText).prop('disabled', false);
                }
            });
        },

        /**
         * Convert base64 to blob
         */
        base64toBlob: function(base64, type) {
            var binary = atob(base64);
            var array = [];
            for (var i = 0; i < binary.length; i++) {
                array.push(binary.charCodeAt(i));
            }
            return new Blob([new Uint8Array(array)], {type: type});
        },

        /**
         * Load more items (pagination)
         */
        loadMore: function(e) {
            e.preventDefault();
            
            var $btn = $(this);
            var page = $btn.data('page') || 1;
            var type = $btn.data('type');
            
            page++;
            
            $.ajax({
                url: pushrelayAdmin.ajaxurl,
                type: 'POST',
                data: {
                    action: 'pushrelay_get_' + type,
                    page: page,
                    nonce: pushrelayAdmin.nonce
                },
                success: function(response) {
                    if (response.success && response.data.data) {
                        // Append items
                        var $container = $('.pushrelay-' + type + '-list');
                        // Render items here
                        
                        $btn.data('page', page);
                        
                        // Hide button if no more items
                        if (!response.data.links || !response.data.links.next) {
                            $btn.hide();
                        }
                    }
                }
            });
        },

        /**
         * Confirm delete
         */
        confirmDelete: function(e) {
            e.preventDefault();
            
            if (!confirm(pushrelayAdmin.strings.confirm_delete)) {
                return false;
            }
            
            return true;
        },

        /**
         * Toggle section
         */
        toggleSection: function(e) {
            e.preventDefault();
            
            var target = $(this).data('target');
            $(target).slideToggle(300);
            $(this).toggleClass('active');
        },

        /**
         * Upload image
         */
        uploadImage: function(e) {
            e.preventDefault();
            
            var $btn = $(this);
            var targetInput = $btn.data('target');
            
            var frame = wp.media({
                title: 'Select Image',
                button: {
                    text: 'Use this image'
                },
                multiple: false
            });
            
            frame.on('select', function() {
                var attachment = frame.state().get('selection').first().toJSON();
                $(targetInput).val(attachment.url);
                
                // Update preview if exists
                var $preview = $(targetInput + '-preview');
                if ($preview.length) {
                    $preview.html('<img src="' + attachment.url + '" style="max-width: 200px;">');
                }
            });
            
            frame.open();
        },

        /**
         * Clear logs
         */
        clearLogs: function(e) {
            e.preventDefault();
            
            if (!confirm('Are you sure you want to clear all logs?')) {
                return;
            }
            
            $.ajax({
                url: pushrelayAdmin.ajaxurl,
                type: 'POST',
                data: {
                    action: 'pushrelay_clear_logs',
                    nonce: pushrelayAdmin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        PushRelayAdmin.showNotice('success', response.data.message);
                        $('.pushrelay-logs-list').empty();
                    } else {
                        PushRelayAdmin.showNotice('error', response.data.message);
                    }
                }
            });
        },

        /**
         * Refresh stats
         */
        refreshStats: function(e) {
            e.preventDefault();
            
            var $btn = $(this);
            var originalText = $btn.text();
            
            $btn.text(pushrelayAdmin.strings.loading).prop('disabled', true);
            
            $.ajax({
                url: pushrelayAdmin.ajaxurl,
                type: 'POST',
                data: {
                    action: 'pushrelay_get_analytics_overview',
                    nonce: pushrelayAdmin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        PushRelayAdmin.updateStatsDisplay(response.data);
                        PushRelayAdmin.showNotice('success', 'Stats refreshed!');
                    }
                },
                complete: function() {
                    $btn.text(originalText).prop('disabled', false);
                }
            });
        },

        /**
         * Update stats display
         */
        updateStatsDisplay: function(data) {
            $('.stat-subscribers').text(data.total_subscribers || 0);
            $('.stat-campaigns').text(data.total_campaigns || 0);
            $('.stat-ctr').text((data.click_rate || 0) + '%');
            $('.stat-display-rate').text((data.display_rate || 0) + '%');
        },

        /**
         * Check health on page load
         */
        checkHealth: function() {
            // Auto-check health on dashboard
            if ($('.pushrelay-dashboard').length) {
                setTimeout(function() {
                    $.ajax({
                        url: pushrelayAdmin.ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'pushrelay_get_health_status',
                            nonce: pushrelayAdmin.nonce
                        },
                        success: function(response) {
                            if (response.success) {
                                PushRelayAdmin.updateHealthDisplay(response.data);
                            }
                        }
                    });
                }, 1000);
            }
        },

        /**
         * Initialize tabs
         */
        initTabs: function() {
            $('.pushrelay-tabs .pushrelay-tab').on('click', function(e) {
                e.preventDefault();
                
                var $tab = $(this);
                var target = $tab.data('tab');
                
                // Remove active class from all tabs
                $tab.siblings().removeClass('active');
                $tab.addClass('active');
                
                // Hide all tab content
                $('.pushrelay-tab-content').removeClass('active');
                
                // Show target tab content
                $(target).addClass('active');
            });
        },

        /**
         * Initialize tooltips
         */
        initTooltips: function() {
            $('[data-tooltip]').each(function() {
                var $el = $(this);
                var tooltip = $el.data('tooltip');
                
                $el.hover(
                    function() {
                        var $tooltip = $('<div class="pushrelay-tooltip">' + tooltip + '</div>');
                        $('body').append($tooltip);
                        
                        var pos = $el.offset();
                        $tooltip.css({
                            top: pos.top - $tooltip.outerHeight() - 10,
                            left: pos.left + ($el.outerWidth() / 2) - ($tooltip.outerWidth() / 2)
                        });
                        
                        $tooltip.fadeIn(200);
                    },
                    function() {
                        $('.pushrelay-tooltip').fadeOut(200, function() {
                            $(this).remove();
                        });
                    }
                );
            });
        },

        /**
         * Initialize charts
         */
        initCharts: function() {
            if (typeof Chart === 'undefined') {
                return;
            }
            
            // Subscriber growth chart
            var $subscriberChart = $('#pushrelay-subscriber-chart');
            if ($subscriberChart.length) {
                this.loadSubscriberChart();
            }
            
            // Campaign performance chart
            var $campaignChart = $('#pushrelay-campaign-chart');
            if ($campaignChart.length) {
                this.loadCampaignChart();
            }
        },

        /**
         * Load subscriber chart
         */
        loadSubscriberChart: function() {
            $.ajax({
                url: pushrelayAdmin.ajaxurl,
                type: 'POST',
                data: {
                    action: 'pushrelay_get_chart_data',
                    type: 'subscribers',
                    nonce: pushrelayAdmin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        var ctx = document.getElementById('pushrelay-subscriber-chart').getContext('2d');
                        new Chart(ctx, {
                            type: 'line',
                            data: response.data,
                            options: {
                                responsive: true,
                                maintainAspectRatio: false,
                                plugins: {
                                    legend: {
                                        display: true,
                                        position: 'bottom'
                                    }
                                },
                                scales: {
                                    y: {
                                        beginAtZero: true
                                    }
                                }
                            }
                        });
                    }
                }
            });
        },

        /**
         * Load campaign chart
         */
        loadCampaignChart: function() {
            // Similar to subscriber chart
        },

        /**
         * Show notice message
         */
        showNotice: function(type, message) {
            var $notice = $('<div class="pushrelay-notice pushrelay-notice-' + type + '">' + message + '</div>');
            
            $('.pushrelay-notices').append($notice);
            
            $notice.fadeIn(300).delay(5000).fadeOut(300, function() {
                $(this).remove();
            });
        },

        /**
         * Campaign Status Polling
         * Polls campaigns with transient statuses (processing, queued) every 20 seconds
         * Stops when status becomes terminal (sent, completed, failed)
         */
        statusPollInterval: null,
        statusPollActive: false,

        /**
         * Initialize campaign status polling
         */
        initStatusPolling: function() {
            var self = this;
            
            // Only run on campaigns page
            if (!$('.pushrelay-campaigns-table').length) {
                return;
            }
            
            // Check if there are any campaigns with transient statuses
            var transientStatuses = ['processing', 'queued', 'sending'];
            var $transientRows = $('tr[data-campaign-status]').filter(function() {
                var status = $(this).data('campaign-status');
                return transientStatuses.indexOf(status) !== -1;
            });
            
            if ($transientRows.length === 0) {
                return; // No campaigns need polling
            }
            
            console.log('PushRelay: Starting status polling for ' + $transientRows.length + ' campaigns');
            
            // Start polling every 20 seconds
            self.statusPollActive = true;
            self.statusPollInterval = setInterval(function() {
                self.pollCampaignStatuses();
            }, 20000);
            
            // Also poll immediately once
            self.pollCampaignStatuses();
        },

        /**
         * Poll campaign statuses
         */
        pollCampaignStatuses: function() {
            var self = this;
            
            if (!self.statusPollActive) {
                return;
            }
            
            var transientStatuses = ['processing', 'queued', 'sending'];
            var $transientRows = $('tr[data-campaign-status]').filter(function() {
                var status = $(this).data('campaign-status');
                return transientStatuses.indexOf(status) !== -1;
            });
            
            if ($transientRows.length === 0) {
                // No more campaigns to poll, stop polling
                self.stopStatusPolling();
                return;
            }
            
            // Poll each campaign individually (to avoid new endpoints)
            $transientRows.each(function() {
                var $row = $(this);
                var campaignId = $row.data('campaign-id');
                
                if (!campaignId) {
                    return;
                }
                
                self.fetchCampaignStatus(campaignId, $row);
            });
        },

        /**
         * Fetch single campaign status
         */
        fetchCampaignStatus: function(campaignId, $row) {
            var self = this;
            
            var formData = new FormData();
            formData.append('action', 'pushrelay_get_campaign');
            formData.append('campaign_id', campaignId);
            formData.append('nonce', pushrelayAdmin.nonce);
            
            fetch(pushrelayAdmin.ajaxurl, {
                method: 'POST',
                body: formData
            })
            .then(function(response) { return response.json(); })
            .then(function(data) {
                if (data.success && data.data && data.data.campaign) {
                    var campaign = data.data.campaign;
                    var newStatus = campaign.status || campaign.data && campaign.data.status;
                    
                    if (newStatus) {
                        self.updateCampaignRowStatus($row, newStatus, campaign);
                    }
                }
            })
            .catch(function(error) {
                console.log('PushRelay: Status poll error for campaign ' + campaignId, error);
            });
        },

        /**
         * Update campaign row with new status
         */
        updateCampaignRowStatus: function($row, newStatus, campaign) {
            var self = this;
            var currentStatus = $row.data('campaign-status');
            
            if (currentStatus === newStatus) {
                return; // No change
            }
            
            console.log('PushRelay: Campaign ' + $row.data('campaign-id') + ' status changed: ' + currentStatus + ' → ' + newStatus);
            
            // Update data attribute
            $row.attr('data-campaign-status', newStatus);
            $row.data('campaign-status', newStatus);
            
            // Update status badge
            var $statusCell = $row.find('.column-status');
            var $badge = $statusCell.find('.pushrelay-badge');
            
            // Remove old status class and add new one
            $badge.removeClass(function(index, className) {
                return (className.match(/pushrelay-status-\S+/g) || []).join(' ');
            });
            $badge.addClass('pushrelay-status-' + newStatus);
            $badge.text(newStatus.charAt(0).toUpperCase() + newStatus.slice(1));
            
            // Update stats if available
            if (campaign.total_sent_push_notifications !== undefined) {
                var sent = parseInt(campaign.total_sent_push_notifications) || 0;
                var $sentCell = $row.find('.cell-sent');
                if ($sentCell.length) {
                    $sentCell.attr('data-value', sent).text(sent.toLocaleString());
                } else {
                    $row.find('td:nth-child(3)').text(sent.toLocaleString());
                }
            }
            if (campaign.total_displayed_push_notifications !== undefined) {
                var displayed = parseInt(campaign.total_displayed_push_notifications) || 0;
                var $displayedCell = $row.find('.cell-displayed');
                if ($displayedCell.length) {
                    $displayedCell.attr('data-value', displayed).text(displayed.toLocaleString());
                } else {
                    $row.find('td:nth-child(4)').text(displayed.toLocaleString());
                }
            }
            if (campaign.total_clicked_push_notifications !== undefined) {
                var clicked = parseInt(campaign.total_clicked_push_notifications) || 0;
                var $clickedCell = $row.find('.cell-clicked');
                if ($clickedCell.length) {
                    $clickedCell.attr('data-value', clicked).text(clicked.toLocaleString());
                } else {
                    $row.find('td:nth-child(5)').text(clicked.toLocaleString());
                }
            }
            
            // Hide send button if no longer draft
            if (newStatus !== 'draft') {
                $row.find('.pushrelay-send-campaign').hide();
            }
            
            // Recalculate and update dashboard widgets
            self.refreshCampaignWidgets();
        },

        /**
         * Refresh campaign dashboard widgets from current DOM state
         * Recalculates totals from visible table rows
         */
        refreshCampaignWidgets: function() {
            var $statsGrid = $('.pushrelay-campaign-stats');
            if (!$statsGrid.length) {
                return;
            }
            
            var $rows = $('.pushrelay-campaigns-table tbody tr[data-campaign-status]');
            if (!$rows.length) {
                return;
            }
            
            // Count statuses - use attr() for reliable DOM reading
            var statusCounts = {
                processing: 0,
                pending: 0,
                queued: 0,
                sending: 0,
                sent: 0,
                completed: 0,
                draft: 0,
                failed: 0
            };
            
            var totalSent = 0;
            var totalDisplayed = 0;
            var totalClicked = 0;
            
            $rows.each(function() {
                var $row = $(this);
                // Use attr() instead of data() to read current DOM state
                var status = $row.attr('data-campaign-status') || '';
                
                // Count status
                if (statusCounts.hasOwnProperty(status)) {
                    statusCounts[status]++;
                }
                
                // Sum stats from data attributes
                var sent = parseInt($row.find('.cell-sent').attr('data-value')) || 0;
                var displayed = parseInt($row.find('.cell-displayed').attr('data-value')) || 0;
                var clicked = parseInt($row.find('.cell-clicked').attr('data-value')) || 0;
                
                totalSent += sent;
                totalDisplayed += displayed;
                totalClicked += clicked;
            });
            
            // Calculate processing count (all transient statuses)
            var processingCount = statusCounts.processing + statusCounts.pending + statusCounts.queued + statusCounts.sending;
            
            // Calculate CTR
            var overallCtr = totalDisplayed > 0 ? Math.round((totalClicked / totalDisplayed) * 10000) / 100 : 0;
            
            console.log('PushRelay: Widget refresh - Processing: ' + processingCount + ', Sent: ' + totalSent + ', statuses:', statusCounts);
            
            // Update widgets - Total Campaigns
            var $totalCampaigns = $statsGrid.find('[data-stat="total-campaigns"] .stat-number');
            if ($totalCampaigns.length) {
                $totalCampaigns.text($rows.length.toLocaleString());
            }
            
            // Update Processing widget - show/hide based on count
            var $processingCard = $statsGrid.find('[data-stat="processing"]');
            if ($processingCard.length) {
                var $processingNumber = $processingCard.find('.stat-number');
                if (processingCount > 0) {
                    $processingNumber.text(processingCount.toLocaleString());
                    $processingCard.show();
                } else {
                    $processingCard.hide();
                }
            }
            
            // Update Total Sent
            var $totalSentWidget = $statsGrid.find('[data-stat="total-sent"] .stat-number');
            if ($totalSentWidget.length) {
                $totalSentWidget.text(totalSent.toLocaleString());
            }
            
            // Update Total Displayed
            var $totalDisplayedWidget = $statsGrid.find('[data-stat="total-displayed"] .stat-number');
            if ($totalDisplayedWidget.length) {
                $totalDisplayedWidget.text(totalDisplayed.toLocaleString());
            }
            
            // Update Total Clicked
            var $totalClickedWidget = $statsGrid.find('[data-stat="total-clicked"] .stat-number');
            if ($totalClickedWidget.length) {
                $totalClickedWidget.text(totalClicked.toLocaleString());
            }
            
            // Update CTR widget with color
            var $ctrWidget = $statsGrid.find('[data-stat="overall-ctr"]');
            if ($ctrWidget.length) {
                $ctrWidget.find('.stat-number').text(overallCtr + '%');
                
                // Update CTR card color
                $ctrWidget.removeClass('card-success card-warning card-danger');
                if (overallCtr > 5) {
                    $ctrWidget.addClass('card-success');
                } else if (overallCtr > 2) {
                    $ctrWidget.addClass('card-warning');
                } else {
                    $ctrWidget.addClass('card-danger');
                }
            }
        },

        /**
         * Stop status polling
         */
        stopStatusPolling: function() {
            if (this.statusPollInterval) {
                clearInterval(this.statusPollInterval);
                this.statusPollInterval = null;
            }
            this.statusPollActive = false;
            console.log('PushRelay: Status polling stopped');
            
            // Final widget refresh when polling stops
            this.refreshCampaignWidgets();
        }
    };

    // Initialize on document ready
    $(document).ready(function() {
        console.log('PushRelay: Admin JS initializing...');
        PushRelayAdmin.init();
        
        // Initialize status polling for campaigns page
        PushRelayAdmin.initStatusPolling();
        
        // Initial widget refresh on campaigns page to sync with DOM state
        // This ensures widgets reflect current table data after page load
        if ($('.pushrelay-campaigns-table').length && $('.pushrelay-campaign-stats').length) {
            console.log('PushRelay: Running initial widget refresh');
            PushRelayAdmin.refreshCampaignWidgets();
        }
        
        console.log('PushRelay: Admin JS initialized');
    });

})(jQuery);