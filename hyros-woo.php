<?php
/**
 * Plugin Name: HyrosWoo
 * Plugin URI:  https://github.com/CachoMX/hyros-woo
 * Description: Production-ready WooCommerce to Hyros server-side tracking. Fixes all gaps in the official integration: auto script injection, WC Subscriptions support, real-time tracking, deduplication, and audit log.
 * Version:     1.2.1
 * Author:      Carlos Aragon
 * Author URI:  https://carlosaragon.online
 * License:     GPL-2.0+
 * Text Domain: hyros-woo
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 6.0
 * WC tested up to: 9.9
 */
defined('ABSPATH') || exit;
define('HYROS_WOO_VERSION', '1.2.1');
define('HYROS_WOO_PLUGIN_FILE', __FILE__);
define('HYROS_WOO_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('HYROS_WOO_PLUGIN_URL', plugin_dir_url(__FILE__));
define('HYROS_WOO_API_BASE', 'https://api.hyros.com/v1/api/v1.0');

// Declare HPOS (High-Performance Order Storage) compatibility before WooCommerce loads.
add_action('before_woocommerce_init', function(): void {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            HYROS_WOO_PLUGIN_FILE,
            true
        );
    }
});

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

register_activation_hook(HYROS_WOO_PLUGIN_FILE, static function(): void {
    Hyros_Logger::on_activation();
});

register_deactivation_hook(HYROS_WOO_PLUGIN_FILE, static function(): void {
    Hyros_Logger::on_deactivation();
});

add_action('plugins_loaded', function(): void {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function(): void {
            echo '<div class="notice notice-error"><p>' .
                '<strong>' . esc_html__('HyrosWoo', 'hyros-woo') . '</strong> ' .
                esc_html__('requires WooCommerce to be active.', 'hyros-woo') .
                '</p></div>';
        });
        return;
    }
    Hyros_Logger::init();
    Hyros_Settings::init();
    Hyros_Tracker::init();
});
