/**
 * PushRelay Frontend JavaScript
 * 
 * Handles subscription prompts, service worker registration,
 * and frontend interactions for push notifications.
 * 
 * @package PushRelay
 * @since 1.6.0
 */

(function($) {
    'use strict';

    // Global PushRelay frontend object
    window.PushRelay = {
        
        /**
         * Configuration object
         */
        config: {
            serviceWorkerUrl: null,
            websiteId: null,
            subscribed: false,
            supported: false,
            registration: null
        },

        /**
         * Initialize frontend functionality
         */
        init: function() {
            // Get configuration from localized script
            if (typeof pushrelayFrontend !== 'undefined') {
                this.config.websiteId = pushrelayFrontend.websiteId;
            }

            // Check browser support
            this.checkSupport();

            // Bind event handlers
            this.bindEvents();

            // Check subscription status
            this.checkSubscriptionStatus();

            // Initialize service worker if supported
            if (this.config.supported) {
                this.initServiceWorker();
            }
        },

        /**
         * Check browser support for push notifications
         */
        checkSupport: function() {
            // Check if service workers are supported
            if (!('serviceWorker' in navigator)) {
                console.log('PushRelay: Service workers not supported');
                this.config.supported = false;
                return;
            }

            // Check if push notifications are supported
            if (!('PushManager' in window)) {
                console.log('PushRelay: Push notifications not supported');
                this.config.supported = false;
                return;
            }

            // Check if notifications are supported
            if (!('Notification' in window)) {
                console.log('PushRelay: Notifications not supported');
                this.config.supported = false;
                return;
            }

            // Check if site is served over HTTPS (required for service workers)
            if (window.location.protocol !== 'https:' && window.location.hostname !== 'localhost') {
                console.log('PushRelay: HTTPS required for service workers');
                this.config.supported = false;
                return;
            }

            this.config.supported = true;
            console.log('PushRelay: Browser supports push notifications');
        },

        /**
         * Initialize service worker registration
         */
        initServiceWorker: function() {
            var self = this;

            // Get service worker URL
            var swUrl = this.getServiceWorkerUrl();

            if (!swUrl) {
                console.error('PushRelay: Service worker URL not found');
                return;
            }

            console.log('PushRelay: Registering service worker at', swUrl);

            // Register service worker with scope
            navigator.serviceWorker.register(swUrl, { scope: '/' })
                .then(function(registration) {
                    console.log('PushRelay: Service worker registered successfully');
                    self.config.registration = registration;

                    // Check for updates
                    registration.addEventListener('updatefound', function() {
                        console.log('PushRelay: Service worker update found');
                    });
                })
                .catch(function(error) {
                    console.error('PushRelay: Service worker registration failed', error);
                    
                    // Try fallback URL if main registration fails
                    self.tryFallbackRegistration();
                });
        },

        /**
         * Try fallback service worker registration via REST API
         */
        tryFallbackRegistration: function() {
            var self = this;
            var baseUrl = window.location.origin;
            var fallbackUrl = baseUrl + '/wp-json/pushrelay/v1/service-worker';

            console.log('PushRelay: Trying fallback service worker URL:', fallbackUrl);

            navigator.serviceWorker.register(fallbackUrl, { scope: '/' })
                .then(function(registration) {
                    console.log('PushRelay: Service worker registered via fallback URL');
                    self.config.registration = registration;
                })
                .catch(function(error) {
                    console.error('PushRelay: Fallback service worker registration also failed', error);
                });
        },

        /**
         * Get service worker URL from meta tag or default
         */
        getServiceWorkerUrl: function() {
            // Try to get from meta tag (preferred method)
            var swUrl = $('meta[name="pushrelay-sw-url"]').attr('content');
            
            if (swUrl) {
                return swUrl;
            }

            // Fallback: construct default URL
            var baseUrl = window.location.origin;
            return baseUrl + '/pushrelay-sw.js';
        },

        /**
         * Check current subscription status
         */
        checkSubscriptionStatus: function() {
            var self = this;

            // Check localStorage first
            if (localStorage.getItem('pushrelay_subscribed') === '1') {
                this.config.subscribed = true;
                this.updateSubscribeButtons(true);
            }

            // Verify with service worker if available
            if (this.config.registration) {
                this.config.registration.pushManager.getSubscription()
                    .then(function(subscription) {
                        if (subscription) {
                            self.config.subscribed = true;
                            localStorage.setItem('pushrelay_subscribed', '1');
                            self.updateSubscribeButtons(true);
                        }
                    });
            }
        },

        /**
         * Bind event handlers
         */
        bindEvents: function() {
            var self = this;

            // Subscribe button clicks
            $(document).on('click', '.pushrelay-subscribe-button', function(e) {
                e.preventDefault();
                self.handleSubscribeClick($(this));
            });

            // Close floating prompts
            $(document).on('click', '.pushrelay-float-close', function(e) {
                e.preventDefault();
                $(this).closest('.pushrelay-float-prompt').fadeOut(300);
                
                // Set cookie to not show again for 7 days
                self.setCookie('pushrelay_prompt_closed', '1', 7);
            });
        },

        /**
         * Handle subscribe button click
         */
        handleSubscribeClick: function($btn) {
            var self = this;

            // Check if already subscribed
            if (this.config.subscribed) {
                this.showMessage('info', this.getString('already_subscribed'));
                return;
            }

            // Check browser support
            if (!this.config.supported) {
                this.showMessage('error', 'Your browser does not support push notifications.');
                return;
            }

            // Request permission
            this.requestPermission($btn);
        },

        /**
         * Get localized string with fallback
         */
        getString: function(key) {
            if (typeof pushrelayFrontend !== 'undefined' && pushrelayFrontend.strings && pushrelayFrontend.strings[key]) {
                return pushrelayFrontend.strings[key];
            }

            // Default strings
            var defaults = {
                'subscribe_success': 'Successfully subscribed to notifications!',
                'subscribe_error': 'Failed to subscribe. Please try again.',
                'already_subscribed': 'You are already subscribed.',
                'permission_denied': 'Notification permission was denied.'
            };

            return defaults[key] || '';
        },

        /**
         * Request notification permission from user
         */
        requestPermission: function($btn) {
            var self = this;
            var originalText = $btn.text();

            $btn.text('Requesting permission...').prop('disabled', true);

            Notification.requestPermission().then(function(permission) {
                if (permission === 'granted') {
                    console.log('PushRelay: Permission granted');
                    self.subscribe($btn);
                } else if (permission === 'denied') {
                    console.log('PushRelay: Permission denied');
                    self.showMessage('error', self.getString('permission_denied'));
                    $btn.text(originalText).prop('disabled', false);
                } else {
                    console.log('PushRelay: Permission dismissed');
                    $btn.text(originalText).prop('disabled', false);
                }
            });
        },

        /**
         * Subscribe to push notifications
         */
        subscribe: function($btn) {
            var self = this;

            if (!this.config.registration) {
                console.error('PushRelay: Service worker not registered');
                $btn.text($btn.data('original-text') || 'Subscribe').prop('disabled', false);
                return;
            }

            this.config.registration.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: this.urlBase64ToUint8Array(this.getVapidPublicKey())
            })
            .then(function(subscription) {
                console.log('PushRelay: User subscribed', subscription);
                
                // Send subscription to server
                self.sendSubscriptionToServer(subscription, $btn);
            })
            .catch(function(error) {
                console.error('PushRelay: Subscription failed', error);
                self.showMessage('error', self.getString('subscribe_error'));
                $btn.text($btn.data('original-text') || 'Subscribe').prop('disabled', false);
            });
        },

        /**
         * Send subscription data to server
         */
        sendSubscriptionToServer: function(subscription, $btn) {
            var self = this;
            var ajaxUrl = (typeof pushrelayFrontend !== 'undefined') ? pushrelayFrontend.ajaxurl : '/wp-admin/admin-ajax.php';
            var nonce = (typeof pushrelayFrontend !== 'undefined') ? pushrelayFrontend.nonce : '';

            $.ajax({
                url: ajaxUrl,
                type: 'POST',
                data: {
                    action: 'pushrelay_subscribe',
                    subscription: JSON.stringify(subscription),
                    nonce: nonce
                },
                success: function(response) {
                    if (response.success) {
                        self.config.subscribed = true;
                        self.updateSubscribeButtons(true);
                        self.showMessage('success', self.getString('subscribe_success'));
                        
                        // Store subscription status
                        localStorage.setItem('pushrelay_subscribed', '1');
                    } else {
                        self.showMessage('error', response.data.message || self.getString('subscribe_error'));
                        $btn.text($btn.data('original-text') || 'Subscribe').prop('disabled', false);
                    }
                },
                error: function() {
                    self.showMessage('error', self.getString('subscribe_error'));
                    $btn.text($btn.data('original-text') || 'Subscribe').prop('disabled', false);
                }
            });
        },

        /**
         * Update all subscribe buttons state
         */
        updateSubscribeButtons: function(isSubscribed) {
            var self = this;
            
            if (isSubscribed) {
                $('.pushrelay-subscribe-button').each(function() {
                    var $btn = $(this);
                    var subscribedText = $btn.data('subscribed-text') || 'Subscribed ✓';
                    
                    $btn.text(subscribedText)
                        .addClass('subscribed')
                        .prop('disabled', true);
                });
            }
        },

        /**
         * Get VAPID public key
         */
        getVapidPublicKey: function() {
            // This key is set by the PushRelay service
            return 'BEl62iUYgUivxIkv69yViEuiBIa-Ib9-SkvMeAtA3LFgDzkrxZJjSgSnfckjBJuBkr3qBUYIHBQFLXYp5Nksh8U';
        },

        /**
         * Convert VAPID key to Uint8Array
         */
        urlBase64ToUint8Array: function(base64String) {
            var padding = '='.repeat((4 - base64String.length % 4) % 4);
            var base64 = (base64String + padding)
                .replace(/\-/g, '+')
                .replace(/_/g, '/');

            var rawData = window.atob(base64);
            var outputArray = new Uint8Array(rawData.length);

            for (var i = 0; i < rawData.length; ++i) {
                outputArray[i] = rawData.charCodeAt(i);
            }
            return outputArray;
        },

        /**
         * Set custom parameters for tracking
         */
        setCustomParameters: function(params) {
            if (typeof params !== 'object') {
                return;
            }

            // Store in localStorage for later use
            var existingParams = JSON.parse(localStorage.getItem('pushrelay_custom_params') || '{}');
            var mergedParams = $.extend(existingParams, params);
            
            localStorage.setItem('pushrelay_custom_params', JSON.stringify(mergedParams));
        },

        /**
         * Show message notification to user
         */
        showMessage: function(type, message) {
            var $message = $('<div class="pushrelay-message pushrelay-message-' + type + '">' + message + '</div>');
            
            // Add to body
            $('body').append($message);
            
            // Position at top center
            $message.css({
                position: 'fixed',
                top: '20px',
                left: '50%',
                transform: 'translateX(-50%)',
                zIndex: 999999,
                padding: '15px 25px',
                borderRadius: '8px',
                boxShadow: '0 4px 12px rgba(0,0,0,0.15)',
                fontWeight: 'bold',
                maxWidth: '90%',
                textAlign: 'center'
            });

            // Style based on type
            switch(type) {
                case 'success':
                    $message.css({
                        background: '#28a745',
                        color: 'white'
                    });
                    break;
                case 'error':
                    $message.css({
                        background: '#dc3545',
                        color: 'white'
                    });
                    break;
                case 'info':
                    $message.css({
                        background: '#17a2b8',
                        color: 'white'
                    });
                    break;
            }

            // Fade in
            $message.hide().fadeIn(300);

            // Auto remove after 5 seconds
            setTimeout(function() {
                $message.fadeOut(300, function() {
                    $(this).remove();
                });
            }, 5000);
        },

        /**
         * Track product view for WooCommerce
         */
        trackProductView: function(productId) {
            if (!productId) {
                return;
            }

            var ajaxUrl = (typeof pushrelayFrontend !== 'undefined') ? pushrelayFrontend.ajaxurl : '/wp-admin/admin-ajax.php';
            var nonce = (typeof pushrelayFrontend !== 'undefined') ? pushrelayFrontend.nonce : '';

            $.ajax({
                url: ajaxUrl,
                type: 'POST',
                data: {
                    action: 'pushrelay_track_product_view',
                    product_id: productId,
                    nonce: nonce
                }
            });
        },

        /**
         * Set cookie with expiration
         */
        setCookie: function(name, value, days) {
            var expires = "";
            if (days) {
                var date = new Date();
                date.setTime(date.getTime() + (days * 24 * 60 * 60 * 1000));
                expires = "; expires=" + date.toUTCString();
            }
            document.cookie = name + "=" + (value || "") + expires + "; path=/";
        },

        /**
         * Get cookie value by name
         */
        getCookie: function(name) {
            var nameEQ = name + "=";
            var ca = document.cookie.split(';');
            for(var i = 0; i < ca.length; i++) {
                var c = ca[i];
                while (c.charAt(0) === ' ') c = c.substring(1, c.length);
                if (c.indexOf(nameEQ) === 0) return c.substring(nameEQ.length, c.length);
            }
            return null;
        },

        /**
         * Show floating prompt for subscription
         */
        showFloatingPrompt: function(options) {
            // Check if already closed
            if (this.getCookie('pushrelay_prompt_closed') === '1') {
                return;
            }

            // Check if already subscribed
            if (this.config.subscribed) {
                return;
            }

            var defaults = {
                title: 'Get Notifications',
                description: 'Subscribe to receive push notifications',
                delay: 5000
            };

            options = $.extend(defaults, options);

            setTimeout(function() {
                var html = '<div class="pushrelay-float-prompt">';
                html += '<button class="pushrelay-float-close">&times;</button>';
                html += '<div class="pushrelay-float-content">';
                html += '<h3 class="pushrelay-float-title">' + options.title + '</h3>';
                html += '<p class="pushrelay-float-description">' + options.description + '</p>';
                html += '<button class="pushrelay-subscribe-button">Subscribe Now</button>';
                html += '</div>';
                html += '</div>';

                $('body').append(html);
            }, options.delay);
        },

        /**
         * Unsubscribe from notifications
         */
        unsubscribe: function() {
            var self = this;

            if (!this.config.registration) {
                return;
            }

            this.config.registration.pushManager.getSubscription().then(function(subscription) {
                if (subscription) {
                    subscription.unsubscribe().then(function(successful) {
                        if (successful) {
                            console.log('PushRelay: Unsubscribed successfully');
                            self.config.subscribed = false;
                            localStorage.removeItem('pushrelay_subscribed');
                            self.updateSubscribeButtons(false);
                            self.showMessage('info', 'You have unsubscribed from notifications');
                        }
                    });
                }
            });
        },

        /**
         * Get current subscription info
         */
        getSubscription: function(callback) {
            if (!this.config.registration) {
                callback(null);
                return;
            }

            this.config.registration.pushManager.getSubscription().then(function(subscription) {
                callback(subscription);
            });
        }
    };

    // Auto-initialize on document ready
    $(document).ready(function() {
        PushRelay.init();
    });

    // Expose to window for external access
    window.PushRelay = PushRelay;

})(jQuery);
