<?php
/**
 * Plugin Name: HyrosWoo
 * Plugin URI:  https://github.com/CachoMX/hyros-woo
 * Description: Production-ready WooCommerce to Hyros server-side tracking. Fixes all gaps in the official integration: auto script injection, WC Subscriptions support, real-time tracking, deduplication, and audit log.
 * Version:     1.0.0
 * Author:      VIXI LLC
 * Author URI:  https://vixi.agency
 * License:     GPL-2.0+
 * Text Domain: hyros-woo
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 6.0
 */
defined('ABSPATH') || exit;
define('HYROS_WOO_VERSION', '1.0.0');
define('HYROS_WOO_PLUGIN_FILE', __FILE__);
define('HYROS_WOO_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('HYROS_WOO_PLUGIN_URL', plugin_dir_url(__FILE__));
define('HYROS_WOO_API_BASE', 'https://api.hyros.com/v1/api/v1.0');

spl_autoload_register(function(string $class): void {
    $map = [
        'Hyros_API'      => 'includes/class-hyros-api.php',
        'Hyros_Tracker'  => 'includes/class-hyros-tracker.php',
        'Hyros_Settings' => 'includes/class-hyros-settings.php',
        'Hyros_Logger'   => 'includes/class-hyros-logger.php',
    ];
    if (isset($map[$class])) {
        require_once HYROS_WOO_PLUGIN_DIR . $map[$class];
    }
});

add_action('plugins_loaded', function(): void {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function(): void {
            echo '<div class="notice notice-error"><p><strong>HyrosWoo</strong> requires WooCommerce to be active.</p></div>';
        });
        return;
    }
    Hyros_Logger::init();
    Hyros_Settings::init();
    Hyros_Tracker::init();
});
