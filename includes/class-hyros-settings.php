<?php
defined('ABSPATH') || exit;

/**
 * Admin settings page for HyrosWoo.
 */
class Hyros_Settings {

    public static function init(): void {
        add_action('admin_menu', [self::class, 'register_menu']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_assets']);
        add_action('wp_ajax_hyros_validate_key', [self::class, 'ajax_validate_key']);
        add_action('wp_ajax_hyros_fetch_scripts', [self::class, 'ajax_fetch_scripts']);
        add_action('wp_ajax_hyros_save_settings', [self::class, 'ajax_save']);
    }

    /**
     * Register submenu under WooCommerce.
     */
    public static function register_menu(): void {
        add_submenu_page(
            'woocommerce',
            __('Hyros Integration', 'hyros-woo'),
            __('Hyros', 'hyros-woo'),
            'manage_woocommerce',
            'hyros-woo',
            [self::class, 'render_page']
        );
    }

    /**
     * Enqueue admin CSS on our settings page only.
     *
     * @param string $hook
     */
    public static function enqueue_assets(string $hook): void {
        if (strpos($hook, 'hyros-woo') === false) {
            return;
        }
        wp_enqueue_style(
            'hyros-woo-admin',
            HYROS_WOO_PLUGIN_URL . 'admin/css/hyros-woo-admin.css',
            [],
            HYROS_WOO_VERSION
        );
    }

    /**
     * Render the settings page.
     */
    public static function render_page(): void {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('Insufficient permissions.', 'hyros-woo'));
        }
        require_once HYROS_WOO_PLUGIN_DIR . 'admin/views/settings-page.php';
    }

    /**
     * Get the configured API key (constant takes precedence).
     *
     * @return string
     */
    public static function get_api_key(): string {
        if (defined('HYROS_API_KEY') && !empty(HYROS_API_KEY)) {
            return HYROS_API_KEY;
        }
        return get_option('hyros_woo_api_key', '');
    }

    /**
     * Return a masked version of the API key for display.
     *
     * @param string $key
     * @return string
     */
    public static function mask_api_key(string $key): string {
        if (empty($key)) {
            return '';
        }
        return substr($key, 0, 6) . str_repeat('*', 20);
    }

    // -------------------------------------------------------------------------
    // AJAX handlers
    // -------------------------------------------------------------------------

    /**
     * AJAX: validate an API key.
     */
    public static function ajax_validate_key(): void {
        check_ajax_referer('hyros_woo_nonce', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Insufficient permissions.', 'hyros-woo')]);
        }

        $api_key = sanitize_text_field(wp_unslash($_POST['api_key'] ?? ''));
        if (empty($api_key)) {
            wp_send_json_error(['message' => __('API key is required.', 'hyros-woo')]);
        }

        $valid = Hyros_API::validate_key($api_key);
        if ($valid) {
            wp_send_json_success(['message' => __('API key is valid.', 'hyros-woo')]);
        } else {
            wp_send_json_error(['message' => __('API key validation failed. Please check your key.', 'hyros-woo')]);
        }
    }

    /**
     * AJAX: fetch available scripts from Hyros (rate-limited).
     */
    public static function ajax_fetch_scripts(): void {
        check_ajax_referer('hyros_woo_nonce', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Insufficient permissions.', 'hyros-woo')]);
        }

        // Rate limit: 60 second cooldown.
        if (get_transient('hyros_fetch_scripts_cooldown')) {
            wp_send_json_error(['message' => __('Please wait before refreshing scripts.', 'hyros-woo')]);
        }

        $api_key = sanitize_text_field(wp_unslash($_POST['api_key'] ?? ''));
        if (empty($api_key)) {
            $api_key = self::get_api_key();
        }
        if (empty($api_key)) {
            wp_send_json_error(['message' => __('No API key configured.', 'hyros-woo')]);
        }

        $api    = new Hyros_API($api_key);
        $result = $api->get_scripts();

        set_transient('hyros_fetch_scripts_cooldown', 1, 60);

        if (!$result['success']) {
            wp_send_json_error(['message' => $result['error']]);
        }

        // Return only id + name to the frontend (never expose script content via AJAX).
        $safe = array_map(function(array $s): array {
            return [
                'id'   => isset($s['id']) ? sanitize_text_field($s['id']) : '',
                'name' => isset($s['name']) ? sanitize_text_field($s['name']) : '',
            ];
        }, $result['scripts']);

        wp_send_json_success(['scripts' => $safe]);
    }

    /**
     * AJAX: save settings.
     */
    public static function ajax_save(): void {
        check_ajax_referer('hyros_woo_nonce', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Insufficient permissions.', 'hyros-woo')]);
        }

        $api_key        = sanitize_text_field(wp_unslash($_POST['api_key'] ?? ''));
        $script_content = wp_kses_post(wp_unslash($_POST['script_content'] ?? ''));
        $script_id      = sanitize_text_field(wp_unslash($_POST['script_id'] ?? ''));

        if (!empty($api_key)) {
            // Only save if it's not the masked placeholder.
            if (strpos($api_key, '****') === false) {
                update_option('hyros_woo_api_key', $api_key);
            }
        }

        update_option('hyros_woo_selected_script', $script_content);
        update_option('hyros_woo_selected_script_id', $script_id);

        wp_send_json_success(['message' => __('Settings saved.', 'hyros-woo')]);
    }
}
