<?php
/**
 * Ultrax Debug Uninstall
 * 
 * Cleanup all plugin data when deleted (not deactivated).
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Delete options
delete_option('ultrax_debug_token_hash');
delete_option('ultrax_debug_enabled_until');
delete_option('ultrax_debug_ip_whitelist');

// Delete transients (pattern matching for rate limits)
global $wpdb;
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_ultrax_debug_%'");
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_ultrax_debug_%'");

// Drop log table
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}ultrax_debug_log");
