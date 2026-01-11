📣 PushRelay – Push Notifications for WordPress

PushRelay is a lightweight, privacy-first WordPress plugin that lets you send web push notifications to your visitors with real-time campaign tracking, automatic post notifications, and a clean admin experience.

Built for performance, stability, and long-term maintainability.

✨ Features

🔔 Manual Push Campaigns
Create and send push notifications directly from WordPress.

📝 Automatic Post Notifications
Automatically send notifications when new posts are published.

📊 Live Campaign Status Updates
Campaign status updates in real time — no page refresh required.

📈 Campaign Analytics
Track sent, displayed, clicked notifications and CTR.

⚡ Performance-Focused
Smart caching, safe background processing, and minimal overhead.

🔐 Privacy-First
No tracking pixels, no personal data stored in WordPress.

🧩 WooCommerce Support
Optional integration for WooCommerce events.

🛠 Requirements

WordPress 6.0+

PHP 7.4 – 8.3

HTTPS enabled (required for web push)

Modern browser support (Chrome, Edge, Firefox)

🚀 Installation
From WordPress.org

Go to Plugins → Add New

Search for PushRelay

Click Install → Activate

Go to PushRelay → Settings to configure

Manual Installation

Download the plugin ZIP

Upload to /wp-content/plugins/pushrelay

Activate the plugin

Configure settings

⚙️ Configuration

Go to PushRelay → Settings

Enter your API credentials

Configure auto-push behavior

Save settings

Once configured, PushRelay is ready to send notifications.

📡 Campaign Lifecycle

Campaigns go through the following statuses:

queued

processing

sent

completed

failed

Campaign status updates automatically in the admin UI without reloading the page.

🤖 Auto-Generated Campaigns

When Auto Push Notifications are enabled:

A campaign is automatically created when a post is published

These campaigns are clearly labeled as auto-generated

They appear alongside manual campaigns for full transparency

🧪 Stability & Safety

PushRelay is designed with:

No database schema changes during updates

No breaking API changes

Safe background processing

PHP 8.2+ compatibility

WordPress Plugin Review Team compliance

🧩 Developer Notes

No custom REST endpoints added without necessity

All background tasks are lock-protected

Debug logs automatically redact sensitive data

Rate-limited API calls handled gracefully

🗺 Roadmap

Planned improvements:

PHP 8.2 deprecation cleanup (v1.7.1)

Improved onboarding flow

Enhanced analytics views

Optional campaign filters (manual vs auto)

Advanced segmentation

📄 License

PushRelay is licensed under the GNU General Public License v2.0 or later.

👤 Author

PushRelay Team
Built with long-term stability and WordPress best practices in mind.

🤝 Contributing

Contributions, issues, and feature requests are welcome.

Please open an issue before submitting major changes.
