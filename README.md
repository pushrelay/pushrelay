# 🔔 PushRelay – Push Notifications for WordPress

![WordPress Version](https://img.shields.io/badge/WordPress-6.0%2B-blue.svg)
[![PHP Version](https://img.shields.io/badge/PHP-7.4--8.3-purple.svg)](https://www.php.net/)
[![License](https://img.shields.io/badge/License-GPLv2%2B-green.svg)](https://www.gnu.org/licenses/gpl-2.0.html)
![Status](https://img.shields.io/badge/Status-Stable-success.svg)
[![GitHub Repo](https://img.shields.io/badge/GitHub-pushrelay-black.svg?logo=github)](https://github.com/pushrelay/pushrelay)

PushRelay is a **lightweight, privacy-first WordPress plugin** that lets you send web push notifications to your visitors with **real-time campaign tracking**, **automatic post notifications**, and a **clean, modern admin experience**.

Built for **performance**, **stability**, and **long-term maintainability**.

---

## 🚀 Why PushRelay?

PushRelay is designed for site owners who want **reliable push notifications without bloat**:

- No tracking pixels
- No personal data stored in WordPress
- No page refreshes in the admin UI
- Safe background processing
- Built with long-term stability in mind

---

## ✨ Features

### 🔔 Manual Push Campaigns
Create and send push notifications directly from WordPress.

### 📰 Automatic Post Notifications
Automatically send notifications when new posts are published.

### 🔄 Live Campaign Status Updates
Campaign status updates in real time — **no page refresh required**.

### 📊 Campaign Analytics
Track sent, displayed, clicked notifications, and CTR.

### ⚡ Performance-Focused
Smart caching, safe background processing, and minimal overhead.

### 🔒 Privacy-First
No tracking pixels. No personal data stored in WordPress.

### 🛒 WooCommerce Support
Optional integration for WooCommerce events.

---

## 🧩 Requirements

- WordPress **6.0+**
- PHP **7.4 – 8.3**
- HTTPS enabled (required for web push)
- Modern browser support (Chrome, Edge, Firefox)

---

## 📦 Installation

### From WordPress.org

1. Go to **Plugins → Add New**
2. Search for **PushRelay**
3. Click **Install → Activate**
4. Go to **PushRelay → Settings** to configure

### Manual Installation

1. Download the plugin ZIP
2. Upload to `wp-content/plugins/pushrelay`
3. Activate the plugin
4. Configure settings

---

## ⚙️ Configuration

1. Go to **PushRelay → Settings**
2. Enter your API credentials
3. Configure auto-push behavior
4. Save settings

Once configured, PushRelay is ready to send notifications.

---

## 🔁 Campaign Lifecycle

Campaigns go through the following statuses:

- `queued`
- `processing`
- `sent`
- `completed`
- `failed`

Campaign status updates automatically in the admin UI **without reloading the page**.

---

## 🤖 Auto-Generated Campaigns

When **Auto Push Notifications** are enabled:

- A campaign is automatically created when a post is published
- These campaigns are clearly labeled as **auto-generated**
- They appear alongside manual campaigns for full transparency

---

## 🛡️ Stability & Safety

PushRelay is designed with safety in mind:

- No database schema changes during updates
- No breaking API changes
- Safe background processing
- PHP 8.2 compatible
- WordPress Plugin Review Team compliant

---

## 🧑‍💻 Developer Notes

- No custom REST endpoints added without necessity
- All background tasks are lock-protected
- Debug logs automatically redact sensitive data
- Rate-limited API calls handled gracefully

---

## 🛣️ Roadmap

Planned improvements:

- PHP 8.2 deprecation cleanup (v1.7.1)
- Improved onboarding flow
- Enhanced analytics views
- Optional campaign filters (manual vs auto)
- Advanced segmentation

---

## 📄 License

PushRelay is licensed under the **GNU General Public License v2.0 or later**.

---

## 👤 Author

**PushRelay Team**  
Built with long-term stability and WordPress best practices in mind.

---

## 🤝 Contributing

Contributions, issues, and feature requests are welcome.

- GitHub repository: https://github.com/pushrelay/pushrelay
- Please open an issue before submitting major changes

All contributions should prioritize stability, backward compatibility, and WordPress.org compliance.
