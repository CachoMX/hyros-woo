<?php
defined('ABSPATH') || exit;

/**
 * Admin settings page for HyrosWoo.
 */
class Hyros_Settings {

    public static function init(): void {
        add_action('admin_menu',           [self::class, 'register_menu']);
        add_action('admin_enqueue_scripts',[self::class, 'enqueue_assets']);
        add_action('admin_notices',        [self::class, 'notice_permanently_failed']);
        add_action('wp_ajax_hyros_validate_key',      [self::class, 'ajax_validate_key']);
        add_action('wp_ajax_hyros_save_settings',     [self::class, 'ajax_save']);
        add_action('wp_ajax_hyros_get_domain_script', [self::class, 'ajax_get_domain_script']);
    }

    /**
     * Admin notice: alert when orders have permanently failed Hyros tracking.
     */
    public static function notice_permanently_failed(): void {
        $count = (int) get_option('hyros_woo_permanently_failed_count', 0);
        if ($count <= 0 || !current_user_can('manage_options')) {
            return;
        }
        $message = sprintf(
            /* translators: %d: number of failed orders */
            _n(
                '<strong>HyrosWoo:</strong> %d order permanently failed Hyros tracking after all retries. <a href="%s">Review the Activity Log</a> and re-send manually.',
                '<strong>HyrosWoo:</strong> %d orders permanently failed Hyros tracking after all retries. <a href="%s">Review the Activity Log</a> and re-send manually.',
                $count,
                'hyros-woo'
            ),
            $count,
            esc_url(admin_url('admin.php?page=hyros-woo'))
        );
        echo '<div class="notice notice-error"><p>' . wp_kses($message, ['strong' => [], 'a' => ['href' => []]]) . '</p></div>';
    }

    /**
     * Register submenu under WooCommerce.
     */
    public static function register_menu(): void {
        add_submenu_page(
            'woocommerce',
            __('Hyros Integration', 'hyros-woo'),
            __('Hyros', 'hyros-woo'),
            'manage_options',
            'hyros-woo',
            [self::class, 'render_page']
        );
    }

    /**
     * Enqueue admin CSS and JS on our settings page only.
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
        wp_enqueue_script(
            'hyros-woo-admin',
            HYROS_WOO_PLUGIN_URL . 'admin/js/hyros-woo-admin.js',
            ['jquery'],
            HYROS_WOO_VERSION,
            true
        );
        wp_localize_script('hyros-woo-admin', 'hyrosAdmin', [
            'ajaxUrl'             => admin_url('admin-ajax.php'),
            'nonce'               => wp_create_nonce('hyros_woo_nonce'),
            'savedDomain'         => get_option('hyros_woo_selected_domain', ''),
            'hasKey'              => !empty(self::get_api_key()) ? '1' : '',
            'maskedKey'           => self::mask_api_key(self::get_api_key()),
            'debug'               => defined('WP_DEBUG') && WP_DEBUG ? '1' : '',
            'allowScriptEditing'  => current_user_can('unfiltered_html') ? '1' : '',
            'i18n'        => [
                'validating'    => __('Validating...', 'hyros-woo'),
                'validateKey'   => __('Validate Key', 'hyros-woo'),
                'loading'       => __('Loading...', 'hyros-woo'),
                'scriptLoaded'  => __('Script loaded.', 'hyros-woo'),
                'couldNotLoad'  => __('Could not load script.', 'hyros-woo'),
                'requestFailed' => __('Request failed.', 'hyros-woo'),
                'saving'        => __('Saving...', 'hyros-woo'),
                'saveSettings'  => __('Save Settings', 'hyros-woo'),
                'saveFailed'    => __('Save failed.', 'hyros-woo'),
                'connected'     => __('Connected', 'hyros-woo'),
                'notConnected'  => __('Not connected', 'hyros-woo'),
                'checking'      => __('Checking...', 'hyros-woo'),
                'enterApiKey'   => __('Please enter an API key.', 'hyros-woo'),
                'invalidScript' => __('Invalid script format. Please load it via API/domain validation.', 'hyros-woo'),
                'view'          => __('View', 'hyros-woo'),
                'hide'          => __('Hide', 'hyros-woo'),
            ],
        ]);
    }

    /**
     * Render the settings page.
     */
    public static function render_page(): void {
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions.', 'hyros-woo'));
        }
        require_once HYROS_WOO_PLUGIN_DIR . 'admin/views/settings-page.php';
    }

    /**
     * Get the configured API key (constant takes precedence).
     */
    public static function get_api_key(): string {
        if (defined('HYROS_API_KEY') && !empty(HYROS_API_KEY)) {
            return HYROS_API_KEY;
        }
        return get_option('hyros_woo_api_key', '');
    }

    /**
     * Return a masked version of the API key for display.
     */
    public static function mask_api_key(string $key): string {
        if (empty($key)) {
            return '';
        }
        return substr($key, 0, 6) . str_repeat('*', 20);
    }

    /**
     * Check whether a submitted value looks like the masked placeholder.
     */
    private static function is_masked_placeholder(string $value): bool {
        return strlen($value) === 26
            && substr($value, 6) === str_repeat('*', 20);
    }

    /**
     * Validate that script content matches the expected Hyros tracking script format.
     *
     * We intentionally accept only one script block that sets script.src to a Hyros host.
     * This prevents arbitrary JavaScript persistence in wp_options.
     */
    public static function is_valid_tracking_script(string $script_content): bool {
        $script_content = trim($script_content);
        if ('' === $script_content) {
            return true;
        }

        if (1 !== preg_match('#^\s*<script\b[^>]*>([\s\S]*)</script>\s*$#i', $script_content, $matches)) {
            return false;
        }

        // Prevent script stacking like "</script><script>...".
        if (substr_count(strtolower($script_content), '<script') !== 1 || substr_count(strtolower($script_content), '</script>') !== 1) {
            return false;
        }

        $body = $matches[1];
        if (1 !== preg_match('#script\.src\s*=\s*["\']([^"\']+)["\']#i', $body, $src_match)) {
            return false;
        }

        $src = trim($src_match[1]);
        $parsed = wp_parse_url($src);
        if (empty($parsed['scheme']) || empty($parsed['host'])) {
            return false;
        }
        if ('https' !== strtolower((string) $parsed['scheme'])) {
            return false;
        }

        $host = strtolower((string) $parsed['host']);
        return ('hyros.com' === $host || substr($host, -10) === '.hyros.com');
    }

    // -------------------------------------------------------------------------
    // AJAX handlers
    // -------------------------------------------------------------------------

    /**
     * AJAX: validate an API key, then return domains + account info.
     */
    public static function ajax_validate_key(): void {
        check_ajax_referer('hyros_woo_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Insufficient permissions.', 'hyros-woo')]);
            return;
        }

        $api_key = sanitize_text_field(wp_unslash($_POST['api_key'] ?? ''));
        if (empty($api_key)) {
            wp_send_json_error(['message' => __('API key is required.', 'hyros-woo')]);
            return;
        }

        // Resolve masked placeholder to the real stored key.
        $stored_key = self::get_api_key();
        if (self::is_masked_placeholder($api_key)) {
            $api_key = $stored_key;
        }

        if (!Hyros_API::validate_key($api_key)) {
            wp_send_json_error(['message' => __('API key validation failed. Please check your key.', 'hyros-woo')]);
            return;
        }

        // Save valid key (skip if it's still the stored value).
        if ($api_key !== $stored_key) {
            update_option('hyros_woo_api_key', $api_key, false);
        }

        $api     = new Hyros_API(self::get_api_key());
        $payload = ['message' => __('API key valid.', 'hyros-woo')];

        // Fetch account info and cache it.
        $account_result = $api->get_account_info();
        if ($account_result['success']) {
            set_transient('hyros_woo_account_info', $account_result['data'], HOUR_IN_SECONDS);
            $payload['account'] = $account_result['data'];
        }

        // Fetch domains.
        $domains_result = $api->get_domains();
        if ($domains_result['success'] && !empty($domains_result['domains'])) {
            $payload['domains']  = $domains_result['domains'];
            $payload['message'] .= ' ' . __('Select a domain to load its tracking script.', 'hyros-woo');
        } else {
            // No domains — load default tracking script.
            $script_result = $api->get_tracking_script();
            if ($script_result['success'] && !empty($script_result['script'])) {
                if (!self::is_valid_tracking_script($script_result['script'])) {
                    wp_send_json_error(['message' => __('Loaded tracking script failed security validation.', 'hyros-woo')]);
                    return;
                }
                update_option('hyros_woo_selected_script', $script_result['script'], false);
                $payload['script']   = $script_result['script'];
                $payload['message'] .= ' ' . __('Tracking script loaded.', 'hyros-woo');
            }
        }

        wp_send_json_success($payload);
    }

    /**
     * AJAX: fetch tracking script for a specific domain and save it.
     */
    public static function ajax_get_domain_script(): void {
        check_ajax_referer('hyros_woo_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Insufficient permissions.', 'hyros-woo')]);
            return;
        }

        $domain = sanitize_text_field(wp_unslash($_POST['domain'] ?? ''));
        $api    = new Hyros_API(self::get_api_key());
        $result = $api->get_tracking_script($domain);

        if ($result['success'] && !empty($result['script'])) {
            if (!self::is_valid_tracking_script($result['script'])) {
                wp_send_json_error(['message' => __('Loaded tracking script failed security validation.', 'hyros-woo')]);
                return;
            }
            update_option('hyros_woo_selected_script', $result['script'], false);
            update_option('hyros_woo_selected_domain', $domain, false);
            wp_send_json_success([
                'message' => __('Tracking script loaded.', 'hyros-woo'),
                'script'  => $result['script'],
            ]);
            return;
        }

        wp_send_json_error(['message' => __('Could not load tracking script for this domain.', 'hyros-woo')]);
    }

    /**
     * AJAX: save settings.
     */
    public static function ajax_save(): void {
        check_ajax_referer('hyros_woo_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Insufficient permissions.', 'hyros-woo')]);
            return;
        }

        $api_key        = sanitize_text_field(wp_unslash($_POST['api_key'] ?? ''));
        $script_content = wp_unslash($_POST['script_content'] ?? '');

        if (!self::is_valid_tracking_script($script_content)) {
            wp_send_json_error(['message' => __('Invalid tracking script format. Load it via API/domain validation.', 'hyros-woo')]);
            return;
        }

        // On multisite (no unfiltered_html) do not allow manual script edits.
        if (!current_user_can('unfiltered_html')) {
            $existing_script = (string) get_option('hyros_woo_selected_script', '');
            if ($script_content !== $existing_script) {
                wp_send_json_error(['message' => __('Manual script edits are disabled for this account. Validate API key/domain to refresh script.', 'hyros-woo')]);
                return;
            }
        }

        if (!empty($api_key) && !self::is_masked_placeholder($api_key)) {
            update_option('hyros_woo_api_key', $api_key, false);
        }

        update_option('hyros_woo_selected_script', $script_content, false);

        // Feature toggles.
        update_option('hyros_woo_track_sales',         !empty($_POST['track_sales'])         ? 'yes' : 'no', false);
        update_option('hyros_woo_track_subscriptions', !empty($_POST['track_subscriptions'])  ? 'yes' : 'no', false);
        update_option('hyros_woo_track_add_to_cart',   !empty($_POST['track_add_to_cart'])    ? 'yes' : 'no', false);
        update_option('hyros_woo_send_cogs',           !empty($_POST['send_cogs'])            ? 'yes' : 'no', false);
        update_option('hyros_woo_inject_script',       !empty($_POST['inject_script'])        ? 'yes' : 'no', false);
        update_option('hyros_woo_require_consent',     !empty($_POST['require_consent'])      ? 'yes' : 'no', false);

        if (empty(trim($script_content))) {
            wp_send_json_success([
                'message' => __('Settings saved — but no tracking script was provided. Validate your API key to auto-load it.', 'hyros-woo'),
                'warning' => true,
            ]);
            return;
        }

        wp_send_json_success(['message' => __('Settings saved.', 'hyros-woo')]);
    }
}
