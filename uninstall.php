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
$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", '_transient_ultrax_debug_%'));
$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", '_transient_timeout_ultrax_debug_%'));

// Drop log table
$wpdb->query($wpdb->prepare("DROP TABLE IF EXISTS %i", $wpdb->prefix . 'ultrax_debug_log'));

// Remove mu-plugin
$mu_file = ABSPATH . 'wp-content/mu-plugins/ultrax-debug-logging.php';
if (file_exists($mu_file)) {
    @unlink($mu_file);
}
