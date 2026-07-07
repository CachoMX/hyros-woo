<?php
defined('WP_UNINSTALL_PLUGIN') || exit;

delete_option('hyros_woo_api_key');
delete_option('hyros_woo_selected_script');
delete_option('hyros_woo_selected_domain');
delete_option('hyros_woo_allowed_domains');
delete_option('hyros_woo_recent_logs');
delete_option('hyros_woo_permanently_failed_count');
delete_option('hyros_woo_track_sales');
delete_option('hyros_woo_track_subscriptions');
delete_option('hyros_woo_track_add_to_cart');
delete_option('hyros_woo_send_cogs');
delete_option('hyros_woo_inject_script');
delete_option('hyros_woo_require_consent');
delete_transient('hyros_woo_account_info');

// Remove all scheduled retry events regardless of their $order_id argument.
wp_unschedule_hook('hyros_woo_retry_sale');
wp_unschedule_hook('hyros_woo_prune_logs');

global $wpdb;

$meta_keys = [
    '_hyros_tracked',
    '_hyros_sale_tracked',
    '_hyros_retry_count',
    '_hyros_cart_id',
    '_hyros_cart_events',
    '_hyros_refund_event_ids',
    '_hyros_refunded_total',
    '_hyros_last_refund_at',
    '_hyros_cancel_failed_compensated',
    '_hyros_pending_refunds',
];

foreach ($meta_keys as $meta_key) {
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $wpdb->delete($wpdb->postmeta, ['meta_key' => $meta_key]);
}

// HPOS storage (WooCommerce 8.0+).
$hpos_table = $wpdb->prefix . 'wc_orders_meta';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $hpos_table)) === $hpos_table) {
    foreach ($meta_keys as $meta_key) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->delete($hpos_table, ['meta_key' => $meta_key]);
    }
}

$logs_table = $wpdb->prefix . 'hyros_woo_logs';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $logs_table)) === $logs_table) {
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $wpdb->query("DROP TABLE {$logs_table}");
}
