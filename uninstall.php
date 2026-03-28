<?php
defined('WP_UNINSTALL_PLUGIN') || exit;

delete_option('hyros_woo_api_key');
delete_option('hyros_woo_selected_script');
delete_option('hyros_woo_selected_script_id');
delete_option('hyros_woo_recent_logs');

// Bulk delete order meta
global $wpdb;
$wpdb->delete($wpdb->postmeta, ['meta_key' => '_hyros_tracked']); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
