# Changelog

All notable changes to PushRelay will be documented in this file.

## [1.7.0] - 2026-01-08

### Consolidation & Hardening Release

**Campaign UX Improvements:**
- Campaign status now updates automatically without page refresh
- Campaign dashboard widgets now update automatically
- Fixed dashboard widgets not updating after campaign creation
- Fixed Processing widget not updating after campaign status changes
- Widgets now sync on page load and after every status change
- Campaigns with "processing" or "queued" status poll every 20 seconds
- Polling stops automatically when status becomes terminal (sent, completed, failed)
- Campaign list updates immediately after creation (no manual refresh)
- Auto-generated campaigns display with "auto" badge and tooltip
- Processing, Sent, Displayed, Clicked counters sync with status changes
- Success notices auto-dismiss after 5 seconds

**Security & Compliance:**
- Improved database query safety with proper prepare() usage
- Added table existence checks before queries
- PHP 8.1+ compatibility improvements (null guards for string functions)
- Better PHPCS compliance with proper ignore comments

**API Resilience:**
- Improved error classification (NOTICE/WARNING/ERROR by status code)
- Rate limit detection with automatic 60-second backoff (HTTP 429)
- Single retry for idempotent requests only (GET/HEAD/OPTIONS)
- Sensitive data automatically redacted from logs

**Internal Diagnostics:**
- Self-test routine for API, database, cron, and service worker
- Structured diagnostic export for support troubleshooting
- No UI changes - internal use only

**Log Hygiene:**
- Reduced log retention to prevent unbounded growth
- API keys, tokens, and credentials never logged
- Cleaner log output by severity level

### Unchanged
- No database schema changes
- No option name changes
- No hook name changes
- No REST API changes
- No breaking changes
- No user action required
- Safe upgrade from 1.6.x

## [1.6.3] - 2026-01-08

### Fixed
- Fixed campaigns list requiring manual refresh after creation
- Campaign list now updates automatically after creating a campaign
- Removed misleading "Click Refresh" message

### Details
- Campaign redirect already bypassed cache; message was outdated
- No user action required
- Refresh button still works for manual refresh

### Unchanged
- No user interface redesign
- No database schema changes
- No API changes
- No breaking changes

## [1.6.2] - 2026-01-07

### Internal Improvements Only

**Observability:**
- Added diagnostic export method for support troubleshooting
- Structured, machine-readable output (JSON-ready)
- No UI changes - internal use only

**Log Hygiene:**
- Reduced maximum log retention from 1000 to 500 entries
- Added automatic redaction of sensitive data (API keys, tokens, credentials)
- Prevents unbounded log growth

**API Resilience:**
- Added rate limit detection and automatic backoff (HTTP 429)
- Requests paused for 60 seconds after rate limit
- Logged at WARNING level (not ERROR)

**Cron Safety:**
- Added overlap prevention for queue processing
- Detects and clears stale locks from stuck jobs
- Fails silently with NOTICE-level logging

### Unchanged
- No user-visible changes
- No database schema changes
- No option name changes
- No hook or filter changes
- No REST API changes
- No breaking changes

## [1.6.1] - 2026-01-07

### Fixed
- Corrected log level classification for API responses (404 now NOTICE, 401/403/429 now WARNING, 5xx remains ERROR)
- Prevented database errors when queue table has legacy schema
- Added missing parameter validation before API requests
- Resolved potential fatal error when processing queue with missing columns

### Improved
- Added retry logic for transient API failures (5xx, timeouts) on read-only requests
- Added graceful handling for malformed JSON responses
- Added column existence checks for backward compatibility
- Added safeguard for translation loading timing

### Internal
- Added diagnostic methods for health check integration (no UI changes)

### Unchanged
- No user interface changes
- No database schema changes
- No public API changes
- No hook or filter changes
- No breaking changes

## [1.6.0] - 2024-12-22

### Added
- Initial stable release
- Campaign management
- Subscriber management
- Analytics dashboard
- WooCommerce integration
- Service worker support
- Health check monitoring
- Debug logging
- Segmentation
- Shortcodes

### Technical
- Requires WordPress 5.8+
- Requires PHP 7.4+
- WordPress Coding Standards compliant

---

## Upgrade Notices

### 1.7.0
Consolidation and hardening release. Campaign UX improvements, security fixes, PHP 8.1+ compatibility. Auto campaigns now have clear badges. No breaking changes. Safe upgrade from 1.6.x.

### 1.6.3
Fixes campaign list not refreshing after changes. No user action required. No breaking changes.

### 1.6.2
Internal improvements only. Better logging, diagnostics, and reliability. No user-visible changes. Safe upgrade.

### 1.6.1
Maintenance release. Fixes logging and improves API resilience. Safe upgrade with no breaking changes.

### 1.6.0
Initial stable release.
