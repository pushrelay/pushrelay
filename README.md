# PushRelay - WordPress Push Notifications Plugin

![Version](https://img.shields.io/badge/version-2.0.1-blue.svg)
![WordPress](https://img.shields.io/badge/wordpress-5.8+-green.svg)
![PHP](https://img.shields.io/badge/php-7.4%2B-purple.svg)
![License](https://img.shields.io/badge/license-GPL--2.0-red.svg)

## 🚀 The Most Powerful Push Notifications Plugin for WordPress

PushRelay is a professional-grade push notifications plugin that rivals OneSignal with advanced features, beautiful UI, and seamless WordPress integration.

## ✨ Features

### 🎯 Core Features
- **Push Notifications API Integration** - Full integration with PushRelay.com API
- **Subscriber Management** - Complete subscriber tracking and analytics
- **Campaign Builder** - Visual campaign creator with live preview
- **Advanced Analytics** - Detailed reports and performance metrics
- **Segmentation** - Target specific user groups
- **WooCommerce Integration** - E-commerce specific notifications

### 📊 Analytics & Reporting
- Real-time subscriber statistics
- Campaign performance tracking
- Click-through rate (CTR) analysis
- Engagement metrics
- Export reports to CSV
- Custom date range filtering

### 🎨 User Interface
- Modern, clean admin interface
- Responsive design for all devices
- Live notification preview
- Drag-and-drop campaign builder
- Interactive charts and graphs

### 🔧 Developer Features
- REST API endpoints
- Webhook support
- Custom actions and filters
- Debug logging
- Health check system
- Service Worker integration

## 📦 Installation

### Automatic Installation
1. Upload the plugin files to `/wp-content/plugins/pushrelay/`
2. Activate the plugin through the 'Plugins' screen in WordPress
3. Use the Setup Wizard to configure your API key

### Manual Installation
1. Download the latest release
2. Upload via WordPress admin → Plugins → Add New → Upload Plugin
3. Activate and configure via Settings → PushRelay

## ⚙️ Configuration

### API Setup
1. Create an account at [PushRelay.com](https://pushrelay.com)
2. Get your API key from the dashboard
3. Enter the API key in WordPress → PushRelay → Settings
4. Select your website from the dropdown
5. Save settings and you're ready!

### First Campaign
1. Navigate to PushRelay → Campaigns
2. Click "New Campaign"
3. Enter title and message
4. Choose your audience
5. Send or schedule!

## 📋 Requirements

- **WordPress**: 5.8 or higher
- **PHP**: 7.4 or higher (PHP 8.0+ fully supported)
- **MySQL**: 5.7 or higher
- **WooCommerce**: 5.0+ (optional, for e-commerce features)

## 🔄 Version 2.0.1 Updates

### Fixed
✅ PHP 8.0+ compatibility issues resolved
✅ Deprecated warnings eliminated
✅ Header output errors fixed
✅ Null value handling improved
✅ Character encoding cleaned (UTF-8 without BOM)

### Added
✅ `get_subscriber_stats()` method in Subscribers class
✅ Enhanced null safety checks throughout
✅ Better error handling in all views

### Improved
✅ Code quality and consistency
✅ Performance optimizations
✅ Documentation updates

## 📂 Plugin Structure

```
pushrelay/
├── assets/
│   ├── css/
│   │   ├── admin.css
│   │   └── frontend.css
│   └── js/
│       ├── admin.js
│       └── frontend.js
├── includes/
│   ├── class-admin.php
│   ├── class-analytics.php
│   ├── class-api-client.php
│   ├── class-campaigns.php
│   ├── class-debug-logger.php
│   ├── class-frontend.php
│   ├── class-health-check.php
│   ├── class-segmentation.php
│   ├── class-service-worker.php
│   ├── class-shortcodes.php
│   ├── class-subscribers.php
│   ├── class-support-tickets.php
│   └── class-woocommerce.php
├── views/
│   ├── analytics.php
│   ├── campaigns.php
│   ├── dashboard.php
│   ├── settings.php
│   ├── setup-wizard.php
│   └── subscribers.php
├── pushrelay.php
├── readme.txt
└── uninstall.php
```

## 🎯 Usage Examples

### Send a Simple Notification
```php
$campaign = new PushRelay_Campaigns();
$campaign->create_campaign(array(
    'name' => 'Welcome Message',
    'title' => 'Welcome to our site!',
    'description' => 'Thanks for subscribing.',
    'url' => home_url(),
    'segment' => 'all'
));
```

### Track Subscriber Stats
```php
$subscribers = new PushRelay_Subscribers();
$stats = $subscribers->get_subscriber_stats();
echo "Active Today: " . $stats['active_today'];
```

### Create Custom Segment
```php
$segmentation = new PushRelay_Segmentation();
$segment = $segmentation->create_segment(array(
    'name' => 'Premium Users',
    'filters' => array(
        'custom_parameter_key' => 'membership',
        'custom_parameter_value' => 'premium'
    )
));
```

## 🛠️ Troubleshooting

### PHP Deprecated Warnings
If you see deprecated warnings on PHP 8+, the plugin automatically suppresses them in production mode. For development:

```php
// In wp-config.php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);
```

### Headers Already Sent Error
Ensure no whitespace before `<?php` or after `?>` in PHP files. The plugin uses UTF-8 encoding without BOM.

### API Connection Issues
1. Verify your API key is correct
2. Check server can connect to pushrelay.com
3. Enable debug logging in settings
4. Check `/wp-content/debug.log`

## 📚 Documentation

Full documentation available at: [https://pushrelay.com/docs](https://pushrelay.com/docs)

## 🤝 Support

- **Documentation**: [https://pushrelay.com/docs](https://pushrelay.com/docs)
- **Support Tickets**: Create from WordPress admin
- **Email**: support@pushrelay.com

## 📄 License

This plugin is licensed under the GPL v2 or later.

```
This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.
```

## 🎉 Credits

Developed with ❤️ by the PushRelay Team

## 📊 Stats

- **Total Downloads**: Coming soon
- **Active Installations**: Growing daily
- **Average Rating**: ⭐⭐⭐⭐⭐

---

**Made with passion to compete with OneSignal** 🚀
