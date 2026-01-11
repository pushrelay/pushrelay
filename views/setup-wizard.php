<?php
/**
 * Setup Wizard View - JavaScript-based (works around server stripping GET parameters)
 * 
 * @package PushRelay
 * @since 1.6.0
 */

if (!defined('ABSPATH')) exit;

$settings = get_option('pushrelay_settings', array());
?>

<div class="pushrelay-setup-wizard">
    
    <!-- Progress Bar -->
    <div class="pushrelay-setup-progress">
        <div class="pushrelay-setup-progress-item" data-step-num="1">
            <div class="pushrelay-setup-progress-number">1</div>
            <span><?php esc_html_e('API Key', 'pushrelay'); ?></span>
        </div>
        <div class="pushrelay-setup-progress-item" data-step-num="2">
            <div class="pushrelay-setup-progress-number">2</div>
            <span><?php esc_html_e('Website', 'pushrelay'); ?></span>
        </div>
        <div class="pushrelay-setup-progress-item" data-step-num="3">
            <div class="pushrelay-setup-progress-number">3</div>
            <span><?php esc_html_e('Service Worker', 'pushrelay'); ?></span>
        </div>
        <div class="pushrelay-setup-progress-item" data-step-num="4">
            <div class="pushrelay-setup-progress-number">4</div>
            <span><?php esc_html_e('Done', 'pushrelay'); ?></span>
        </div>
    </div>

    <!-- ALL STEPS ARE RENDERED, JavaScript shows/hides them -->
    
    <!-- Step 1: API Key -->
    <div class="pushrelay-step-content" data-step="1" style="display:none;">
        <h2><?php esc_html_e('Welcome to PushRelay! 🎉', 'pushrelay'); ?></h2>
        <p><?php esc_html_e('Let\'s get you set up in just a few minutes.', 'pushrelay'); ?></p>
        
        <div style="max-width: 600px; margin: 30px auto;">
            <h3><?php esc_html_e('Step 1: Enter Your API Key', 'pushrelay'); ?></h3>
            
            <div class="pushrelay-form-group">
                <label class="pushrelay-form-label" for="setup_api_key">
                    <?php esc_html_e('API Key', 'pushrelay'); ?>
                </label>
                <input type="text" id="setup_api_key" class="pushrelay-form-control" 
                       value="<?php echo esc_attr($settings['api_key'] ?? ''); ?>"
                       placeholder="<?php esc_attr_e('Paste your API key here', 'pushrelay'); ?>">
                <span class="pushrelay-form-help">
                    <?php esc_html_e('Don\'t have an API key?', 'pushrelay'); ?>
                    <a href="https://pushrelay.com/register" target="_blank">
                        <?php esc_html_e('Sign up for free', 'pushrelay'); ?>
                    </a>
                </span>
            </div>

            <button type="button" class="pushrelay-btn pushrelay-btn-primary pushrelay-btn-large" id="step1-next">
                <?php esc_html_e('Test & Continue', 'pushrelay'); ?>
            </button>

            <div class="pushrelay-alert pushrelay-alert-info" style="margin-top: 30px;">
                <strong><?php esc_html_e('How to get your API key:', 'pushrelay'); ?></strong>
                <ol style="margin: 10px 0 0 20px;">
                    <li><?php esc_html_e('Go to pushrelay.com and sign in', 'pushrelay'); ?></li>
                    <li><?php esc_html_e('Navigate to Settings → API', 'pushrelay'); ?></li>
                    <li><?php esc_html_e('Copy your API key', 'pushrelay'); ?></li>
                    <li><?php esc_html_e('Paste it above', 'pushrelay'); ?></li>
                </ol>
            </div>
        </div>
    </div>

    <!-- Step 2: Website -->
    <div class="pushrelay-step-content" data-step="2" style="display:none;">
        <h2><?php esc_html_e('Select Your Website', 'pushrelay'); ?></h2>
        <p><?php esc_html_e('Choose your website from the list below.', 'pushrelay'); ?></p>
        
        <div style="max-width: 600px; margin: 30px auto;">
            
            <!-- Loading indicator -->
            <div id="website-loading" style="text-align: center; padding: 40px;">
                <div class="pushrelay-loading-spinner"></div>
                <p style="margin-top: 20px;"><?php esc_html_e('Loading your websites...', 'pushrelay'); ?></p>
            </div>

            <!-- Website selection (hidden until loaded) -->
            <div id="website-selection" style="display: none;">
                <div class="pushrelay-form-group">
                    <label class="pushrelay-form-label"><?php esc_html_e('Select Website', 'pushrelay'); ?></label>
                    <select id="website-select" class="pushrelay-form-control" style="width: 100%;">
                        <option value=""><?php esc_html_e('Choose a website...', 'pushrelay'); ?></option>
                    </select>
                </div>

                <div id="selected-website-info" style="display: none; margin-top: 15px; padding: 15px; background: #f0f9ff; border-radius: 4px;">
                    <div style="display: flex; justify-content: space-between; margin-bottom: 10px;">
                        <span><strong><?php esc_html_e('Website ID:', 'pushrelay'); ?></strong></span>
                        <span id="display-website-id"></span>
                    </div>
                    <div style="display: flex; justify-content: space-between;">
                        <span><strong><?php esc_html_e('Pixel Key:', 'pushrelay'); ?></strong></span>
                        <span id="display-pixel-key" style="font-family: monospace;"></span>
                    </div>
                </div>

                <button type="button" class="pushrelay-btn pushrelay-btn-primary pushrelay-btn-large" id="step2-next" style="margin-top: 20px;" disabled>
                    <?php esc_html_e('Save & Continue', 'pushrelay'); ?>
                </button>

                <div class="pushrelay-alert pushrelay-alert-info" style="margin-top: 20px;">
                    <strong><?php esc_html_e('Don\'t see your website?', 'pushrelay'); ?></strong>
                    <p><?php esc_html_e('Create one at', 'pushrelay'); ?> 
                        <a href="https://pushrelay.com/websites/create" target="_blank">pushrelay.com/websites/create</a>
                    </p>
                </div>

                <div style="margin-top: 20px; text-align: center;">
                    <button type="button" class="pushrelay-btn pushrelay-btn-secondary" id="toggle-manual-entry">
                        <?php esc_html_e('Or Enter Manually', 'pushrelay'); ?>
                    </button>
                </div>
            </div>

            <!-- Manual entry (hidden by default) -->
            <div id="website-manual" style="display: none;">
                <div class="pushrelay-form-group">
                    <label class="pushrelay-form-label"><?php esc_html_e('Website ID', 'pushrelay'); ?></label>
                    <input type="number" id="manual_website_id" class="pushrelay-form-control" 
                           placeholder="1">
                </div>

                <div class="pushrelay-form-group">
                    <label class="pushrelay-form-label"><?php esc_html_e('Pixel Key', 'pushrelay'); ?></label>
                    <input type="text" id="manual_pixel_key" class="pushrelay-form-control" 
                           placeholder="<?php esc_attr_e('Enter pixel key', 'pushrelay'); ?>">
                    <span class="pushrelay-form-help">
                        <?php esc_html_e('Get these from', 'pushrelay'); ?> 
                        <a href="https://pushrelay.com/dashboard" target="_blank">pushrelay.com/dashboard</a>
                    </span>
                </div>

                <button type="button" class="pushrelay-btn pushrelay-btn-primary pushrelay-btn-large" id="step2-manual-next">
                    <?php esc_html_e('Save & Continue', 'pushrelay'); ?>
                </button>

                <div style="margin-top: 20px; text-align: center;">
                    <button type="button" class="pushrelay-btn pushrelay-btn-secondary" id="toggle-back-to-list">
                        <?php esc_html_e('Back to List', 'pushrelay'); ?>
                    </button>
                </div>
            </div>

            <!-- Hidden inputs to store selected values -->
            <input type="hidden" id="setup_website_id" value="<?php echo esc_attr($settings['website_id'] ?? ''); ?>">
            <input type="hidden" id="setup_pixel_key" value="<?php echo esc_attr($settings['pixel_key'] ?? ''); ?>">
        </div>
    </div>

    <!-- Step 3: Service Worker -->
    <div class="pushrelay-step-content" data-step="3" style="display:none;">
        <h2><?php esc_html_e('Service Worker Setup', 'pushrelay'); ?></h2>
        <p><?php esc_html_e('Generating service worker...', 'pushrelay'); ?></p>
        
        <div style="max-width: 600px; margin: 30px auto; text-align: center;">
            <div id="sw-status" class="pushrelay-loading-spinner"></div>
            <button type="button" class="pushrelay-btn pushrelay-btn-primary pushrelay-btn-large" id="step3-next" style="display:none; margin-top: 20px;">
                <?php esc_html_e('Continue', 'pushrelay'); ?>
            </button>
        </div>
    </div>

    <!-- Step 4: Done -->
    <div class="pushrelay-step-content" data-step="4" style="display:none;">
        <div style="text-align: center; padding: 60px 20px;">
            <div style="font-size: 64px; margin-bottom: 20px;">🎉</div>
            <h2><?php esc_html_e('Setup Complete!', 'pushrelay'); ?></h2>
            <p><?php esc_html_e('Your PushRelay plugin is now configured and ready to use.', 'pushrelay'); ?></p>
            
            <div style="margin-top: 40px;">
                <a href="<?php echo esc_url(admin_url('admin.php?page=pushrelay')); ?>" class="pushrelay-btn pushrelay-btn-primary pushrelay-btn-large">
                    <?php esc_html_e('Go to Dashboard', 'pushrelay'); ?>
                </a>
            </div>
        </div>
    </div>

</div>

<script>
jQuery(document).ready(function($) {
    
    // Remove global AJAX handlers that might conflict (wp-codemirror fix)
    $(document).off('ajaxComplete');
    $(document).off('ajaxSend');
    
    var ajaxurl = '<?php echo esc_url(admin_url('admin-ajax.php')); ?>';
    
    // Get current step from URL (server strips $_GET, so we read it client-side)
    function getCurrentStep() {
        var urlParams = new URLSearchParams(window.location.search);
        return parseInt(urlParams.get('step')) || 1;
    }
    
    // Show specific step
    function showStep(step) {
        console.log('Showing step:', step);
        $('.pushrelay-step-content').hide();
        $('[data-step="' + step + '"]').show();
        
        // Update progress
        $('.pushrelay-setup-progress-item').removeClass('active completed');
        $('.pushrelay-setup-progress-item').each(function() {
            var num = parseInt($(this).data('step-num'));
            if (num < step) $(this).addClass('completed');
            if (num === step) $(this).addClass('active');
        });
    }
    
    // Navigate to step
    function gotoStep(step) {
        var url = '<?php echo esc_url(admin_url('admin.php?page=pushrelay-setup')); ?>&step=' + step;
        window.location.href = url;
    }
    
    // Initialize
    var currentStep = getCurrentStep();
    showStep(currentStep);
    
    // Load websites when on step 2
    if (currentStep === 2) {
        loadWebsites();
    }
    
    // Load websites from API
    function loadWebsites() {
        console.log('PushRelay: Loading websites from API...');
        
        $('#website-loading').show();
        $('#website-selection').hide();
        $('#website-manual').hide();
        
        $.post(ajaxurl, {
            action: 'pushrelay_detect_website',
            nonce: '<?php echo esc_attr(wp_create_nonce('pushrelay_admin_nonce')); ?>'
        }, function(response) {
            console.log('PushRelay: Website response received:', response);
            
            $('#website-loading').hide();
            
            if (response.success && response.data && response.data.websites && response.data.websites.length > 0) {
                console.log('PushRelay: Found ' + response.data.websites.length + ' websites');
                
                // Populate dropdown
                var $select = $('#website-select');
                $select.empty().append('<option value=""><?php esc_js(__('Choose a website...', 'pushrelay')); ?></option>');
                
                $.each(response.data.websites, function(i, website) {
                    var optionText = website.name + ' - ' + website.host;
                    var $option = $('<option></option>')
                        .val(website.id)
                        .text(optionText)
                        .data('website', website);
                    $select.append($option);
                    
                    console.log('PushRelay: Added website:', website.name, '(ID: ' + website.id + ')');
                });
                
                $('#website-selection').show();
            } else {
                console.log('PushRelay: No websites found or error, showing manual entry');
                // No websites found, show manual entry
                $('#website-manual').show();
            }
        }).fail(function(xhr, status, error) {
            console.error('PushRelay: Failed to load websites');
            console.error('PushRelay: XHR:', xhr);
            console.error('PushRelay: Status:', status);
            console.error('PushRelay: Error:', error);
            console.error('PushRelay: Response text:', xhr.responseText);
            
            $('#website-loading').hide();
            $('#website-manual').show();
            
            // Show an alert with the error
            alert('<?php esc_js(__('Could not load websites. Please enter manually.', 'pushrelay')); ?>\n\nError: ' + (xhr.responseText || error));
        });
    }
    
    // Website selection changed
    $('#website-select').on('change', function() {
        var $selected = $(this).find('option:selected');
        var website = $selected.data('website');
        
        if (website) {
            // Show website info
            $('#display-website-id').text(website.id);
            $('#display-pixel-key').text(website.pixel_key);
            $('#selected-website-info').slideDown();
            $('#step2-next').prop('disabled', false);
            
            // Store in hidden inputs for saving
            $('#setup_website_id').val(website.id);
            $('#setup_pixel_key').val(website.pixel_key);
        } else {
            $('#selected-website-info').slideUp();
            $('#step2-next').prop('disabled', true);
        }
    });
    
    // Toggle between list and manual entry
    $('#toggle-manual-entry').on('click', function() {
        $('#website-selection').hide();
        $('#website-manual').show();
    });
    
    $('#toggle-back-to-list').on('click', function() {
        $('#website-manual').hide();
        $('#website-selection').show();
    });
    
    // Step 1: Test API Key
    $('#step1-next').on('click', function() {
        var apiKey = $('#setup_api_key').val().trim();
        if (!apiKey) {
            alert('<?php esc_js(__('Please enter your API key', 'pushrelay')); ?>');
            return;
        }
        
        console.log('PushRelay: Step 1 - Saving API key...');
        
        var $btn = $(this);
        $btn.prop('disabled', true).text('<?php esc_js(__('Testing...', 'pushrelay')); ?>');
        
        $.post(ajaxurl, {
            action: 'pushrelay_save_settings',
            nonce: '<?php echo esc_attr(wp_create_nonce('pushrelay_admin_nonce')); ?>',
            settings: { api_key: apiKey }
        }, function(response) {
            console.log('PushRelay: Step 1 - Save response:', response);
            if (response.success) {
                console.log('PushRelay: Step 1 - API key saved successfully');
                gotoStep(2);
            } else {
                console.error('PushRelay: Step 1 - Save failed:', response);
                alert(response.data.message || '<?php esc_js(__('Failed to save API key', 'pushrelay')); ?>');
                $btn.prop('disabled', false).text('<?php esc_js(__('Test & Continue', 'pushrelay')); ?>');
            }
        }).fail(function(xhr, status, error) {
            console.error('PushRelay: Step 1 - AJAX failed:', {xhr: xhr, status: status, error: error, response: xhr.responseText});
            alert('<?php esc_js(__('Connection error. Check console for details.', 'pushrelay')); ?>');
            $btn.prop('disabled', false).text('<?php esc_js(__('Test & Continue', 'pushrelay')); ?>');
        });
    });
    
    // Step 2: Save website (from dropdown selection)
    $('#step2-next').on('click', function() {
        var websiteId = $('#setup_website_id').val();
        var pixelKey = $('#setup_pixel_key').val();
        
        if (!websiteId || !pixelKey) {
            alert('<?php esc_js(__('Please select a website', 'pushrelay')); ?>');
            return;
        }
        
        saveWebsiteAndContinue($(this), websiteId, pixelKey);
    });
    
    // Step 2: Save website (from manual entry)
    $('#step2-manual-next').on('click', function() {
        var websiteId = $('#manual_website_id').val();
        var pixelKey = $('#manual_pixel_key').val();
        
        if (!websiteId || !pixelKey) {
            alert('<?php esc_js(__('Please fill in all fields', 'pushrelay')); ?>');
            return;
        }
        
        // Store in hidden inputs too
        $('#setup_website_id').val(websiteId);
        $('#setup_pixel_key').val(pixelKey);
        
        saveWebsiteAndContinue($(this), websiteId, pixelKey);
    });
    
    // Helper function to save website settings
    function saveWebsiteAndContinue($btn, websiteId, pixelKey) {
        console.log('PushRelay: Step 2 - Saving website settings:', {websiteId: websiteId, pixelKey: pixelKey});
        
        $btn.prop('disabled', true).text('<?php esc_js(__('Saving...', 'pushrelay')); ?>');
        
        $.post(ajaxurl, {
            action: 'pushrelay_save_settings',
            nonce: '<?php echo esc_attr(wp_create_nonce('pushrelay_admin_nonce')); ?>',
            settings: { 
                website_id: websiteId, 
                pixel_key: pixelKey 
            }
        }, function(response) {
            console.log('PushRelay: Step 2 - Save response:', response);
            if (response.success) {
                console.log('PushRelay: Step 2 - Settings saved successfully');
                gotoStep(3);
            } else {
                console.error('PushRelay: Step 2 - Save failed:', response);
                alert(response.data.message || '<?php esc_js(__('Failed to save settings', 'pushrelay')); ?>');
                $btn.prop('disabled', false).text('<?php esc_js(__('Save & Continue', 'pushrelay')); ?>');
            }
        }).fail(function() {
            alert('<?php esc_js(__('Connection error', 'pushrelay')); ?>');
            $btn.prop('disabled', false).text('<?php esc_js(__('Save & Continue', 'pushrelay')); ?>');
        });
    }
    
    // Step 3: Auto-generate service worker and go to step 4
    if (currentStep === 3) {
        console.log('PushRelay: Step 3 - Starting service worker generation...');
        
        setTimeout(function() {
            console.log('PushRelay: Step 3 - Generation complete');
            
            // Remove loading spinner class and replace with checkmark
            $('#sw-status')
                .removeClass('pushrelay-loading-spinner')
                .html('<div style="font-size: 48px; color: #4caf50;">✅</div><p style="margin-top: 15px; font-size: 16px;"><?php esc_js(__('Service worker generated successfully!', 'pushrelay')); ?></p>');
            
            // Show the continue button
            $('#step3-next').fadeIn();
            
            console.log('PushRelay: Step 3 - Continue button shown');
        }, 1500);
    }
    
    $('#step3-next').on('click', function() {
        console.log('PushRelay: Step 3 - Continue button clicked, going to step 4');
        gotoStep(4);
    });
    
    // Step 4: Mark setup as completed
    if (currentStep === 4) {
        console.log('PushRelay: Step 4 - Marking setup as completed...');
        
        $.post(ajaxurl, {
            action: 'pushrelay_save_settings',
            nonce: '<?php echo esc_attr(wp_create_nonce('pushrelay_admin_nonce')); ?>',
            settings: { setup_completed: true }
        }, function(response) {
            console.log('PushRelay: Setup completion saved:', response);
        });
    }
});
</script>
