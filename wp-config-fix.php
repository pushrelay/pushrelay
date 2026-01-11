<?php
/**
 * PushRelay - wp-config.php Configuration Fix
 * 
 * Add these lines to your wp-config.php file BEFORE the line:
 * "That's all, stop editing! Happy publishing."
 * 
 * This will fix the deprecated warnings and header errors.
 *
 * @package PushRelay
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ============================================
// COPY FROM HERE
// ============================================

// Disable deprecated warnings in production
// phpcs:disable WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_error_reporting
// phpcs:disable Squiz.PHP.DiscouragedFunctions.Discouraged
if (!defined('WP_DEBUG') || !WP_DEBUG) {
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);
    @ini_set('display_errors', '0'); // phpcs:ignore WordPress.PHP.IniSet.display_errors_Disallowed
}
// phpcs:enable

// Enable debug logging (optional - for troubleshooting)
// Logs will be saved to /wp-content/debug.log
define('WP_DEBUG', false); // Set to true only when debugging
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);

// ============================================
// STOP COPYING HERE
// ============================================

/**
 * Alternative solution - PHP.ini configuration
 * 
 * If you have access to php.ini, you can also add:
 * 
 * error_reporting = E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING
 * display_errors = Off
 * log_errors = On
 * error_log = /path/to/your/error.log
 */

/**
 * For .htaccess (if you don't have access to php.ini):
 * 
 * php_value error_reporting 22519
 * php_flag display_errors Off
 * php_flag log_errors On
 */
