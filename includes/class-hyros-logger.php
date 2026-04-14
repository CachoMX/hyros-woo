<?php
defined('ABSPATH') || exit;

/**
 * Logging for HyrosWoo events.
 * Primary storage: dedicated table.
 * Compatibility fallback: wp_options recent entries.
 */
class Hyros_Logger {

    private const OPTION_KEY       = 'hyros_woo_recent_logs';
    private const MAX_ENTRIES      = 200;
    private const TABLE_SUFFIX     = 'hyros_woo_logs';
    private const RETENTION_DAYS   = 90;
    private const PRUNE_HOOK       = 'hyros_woo_prune_logs';

    public static function init(): void {
        add_action('woocommerce_admin_order_data_after_billing_address', [self::class, 'render_order_meta']);
        add_action(self::PRUNE_HOOK, [self::class, 'prune_old_logs']);
    }

    /**
     * Human-readable labels for event codes.
     */
    private static function event_label(string $event): string {
        $labels = [
            'cart_tracked'            => __('Add to Cart — sent to Hyros', 'hyros-woo'),
            'cart_failed'             => __('Add to Cart — failed', 'hyros-woo'),
            'cart_captured'           => __('Cart saved locally', 'hyros-woo'),
            'abandoned_cart_tracked'  => __('Abandoned cart — sent to Hyros', 'hyros-woo'),
            'abandoned_cart_failed'   => __('Abandoned cart — failed', 'hyros-woo'),
            'sale_tracked'            => __('Purchase — sent to Hyros', 'hyros-woo'),
            'sale_failed'             => __('Purchase — failed', 'hyros-woo'),
            'sale_failed_non_retryable' => __('Purchase — failed (non-retryable)', 'hyros-woo'),
            'sale_skipped_no_consent' => __('Purchase — skipped (no consent)', 'hyros-woo'),
            'sale_skipped_unpaid'    => __('Purchase — skipped (unpaid)', 'hyros-woo'),
            'sale_retry_scheduled'    => __('Purchase — retry scheduled', 'hyros-woo'),
            'sale_permanently_failed' => __('Purchase — permanently failed', 'hyros-woo'),
            'refund_tracked'          => __('Refund — sent to Hyros', 'hyros-woo'),
            'refund_failed'           => __('Refund — failed', 'hyros-woo'),
            'refund_queued'           => __('Refund — queued until sale tracking', 'hyros-woo'),
            'click_failed'            => __('Click — failed', 'hyros-woo'),
            'sale_reversed_after_cancel' => __('Sale reversed after cancel/failed', 'hyros-woo'),
            'sale_reverse_failed'     => __('Sale reverse failed', 'hyros-woo'),
        ];
        return $labels[$event] ?? $event;
    }

    /**
     * Activation hook: ensure table exists and schedule pruning.
     */
    public static function on_activation(): void {
        self::ensure_table_exists();
        if (!wp_next_scheduled(self::PRUNE_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::PRUNE_HOOK);
        }
    }

    /**
     * Deactivation hook: stop pruning schedule.
     */
    public static function on_deactivation(): void {
        wp_unschedule_hook(self::PRUNE_HOOK);
    }

    /**
     * Create/upgrade custom logs table.
     */
    private static function ensure_table_exists(): void {
        global $wpdb;
        $table = self::table_name();
        $collate = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $sql = "CREATE TABLE {$table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            logged_at DATETIME NOT NULL,
            order_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            email VARCHAR(190) NOT NULL DEFAULT '',
            event VARCHAR(120) NOT NULL DEFAULT '',
            detail TEXT NOT NULL,
            meta LONGTEXT NULL,
            PRIMARY KEY  (id),
            KEY order_id (order_id),
            KEY event (event),
            KEY logged_at (logged_at)
        ) {$collate};";

        dbDelta($sql);
    }

    /**
     * Table name helper.
     */
    private static function table_name(): string {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_SUFFIX;
    }

    /**
     * Check whether table exists.
     */
    private static function table_exists(): bool {
        global $wpdb;
        $table = self::table_name();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        return $found === $table;
    }

    /**
     * Prune old logs from table storage.
     */
    public static function prune_old_logs(): void {
        if (!self::table_exists()) {
            return;
        }
        global $wpdb;
        $table = self::table_name();
        $cutoff = gmdate('Y-m-d H:i:s', time() - (self::RETENTION_DAYS * DAY_IN_SECONDS));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE logged_at < %s", $cutoff));
    }

    /**
     * Reduce sensitive fields before persistence.
     */
    private static function normalize_meta(array $meta): array {
        $clean = [];
        foreach ($meta as $key => $value) {
            $normalized_key = sanitize_text_field((string) $key);
            $normalized_value = sanitize_text_field((string) $value);

            if ('phone' === $normalized_key) {
                $digits = preg_replace('/\D+/', '', $normalized_value);
                $normalized_value = strlen($digits) > 4 ? '***' . substr($digits, -4) : '***';
            }

            if ('ip' === $normalized_key) {
                if (filter_var($normalized_value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    $parts = explode('.', $normalized_value);
                    $normalized_value = $parts[0] . '.' . $parts[1] . '.x.x';
                } else {
                    $normalized_value = '';
                }
            }

            $clean[$normalized_key] = $normalized_value;
        }
        return $clean;
    }

    /**
     * Insert into table storage.
     */
    private static function insert_table_entry(array $entry): void {
        if (!self::table_exists()) {
            self::ensure_table_exists();
        }
        if (!self::table_exists()) {
            return;
        }

        global $wpdb;
        $table = self::table_name();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->insert(
            $table,
            [
                'logged_at' => $entry['time'],
                'order_id'  => (int) $entry['order_id'],
                'email'     => (string) $entry['email'],
                'event'     => (string) $entry['event'],
                'detail'    => (string) $entry['detail'],
                'meta'      => !empty($entry['meta']) ? wp_json_encode($entry['meta']) : null,
            ],
            ['%s', '%d', '%s', '%s', '%s', '%s']
        );
    }

    /**
     * Read recent rows from table.
     */
    private static function get_recent_from_table(int $limit): array {
        if (!self::table_exists()) {
            return [];
        }

        global $wpdb;
        $table = self::table_name();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT logged_at, order_id, email, event, detail, meta
                 FROM {$table}
                 ORDER BY id DESC
                 LIMIT %d",
                max(1, $limit)
            ),
            ARRAY_A
        );

        if (!is_array($rows)) {
            return [];
        }

        $mapped = [];
        foreach ($rows as $row) {
            $entry = [
                'time'     => $row['logged_at'] ?? '',
                'order_id' => (int) ($row['order_id'] ?? 0),
                'email'    => sanitize_email((string) ($row['email'] ?? '')),
                'event'    => sanitize_text_field((string) ($row['event'] ?? '')),
                'detail'   => sanitize_text_field((string) ($row['detail'] ?? '')),
            ];
            if (!empty($row['meta'])) {
                $decoded = json_decode((string) $row['meta'], true);
                if (is_array($decoded)) {
                    $entry['meta'] = self::normalize_meta($decoded);
                }
            }
            $mapped[] = $entry;
        }
        return $mapped;
    }

    /**
     * Log an event.
     *
     * @param int    $order_id   Pass 0 for events not tied to an order (e.g. abandoned cart).
     * @param string $event      e.g. 'sale_tracked', 'cart_tracked'.
     * @param string $detail     Additional flat-string detail. MUST NOT contain API key.
     * @param string $email      Lead email, resolved from order when order_id > 0.
     * @param array  $meta       Structured metadata: items_summary, total, currency, phone,
     *                           ip, cart_id, hyros_id, request_id, external_cart_id.
     */
    public static function log(int $order_id, string $event, string $detail = '', string $email = '', array $meta = []): void {
        // Redact the stored API key from any error detail that might contain it.
        $api_key = Hyros_Settings::get_api_key();
        if (!empty($api_key) && strpos($detail, $api_key) !== false) {
            $detail = str_replace($api_key, '[REDACTED]', $detail);
        }

        if ($order_id > 0) {
            $order = wc_get_order($order_id);
            if ($order) {
                $note = sprintf('[HyrosWoo] %s', self::event_label($event));
                if (!empty($detail)) {
                    $note .= ': ' . $detail;
                }
                $order->add_order_note($note);
                if (empty($email)) {
                    $email = $order->get_billing_email();
                }
            }
        }

        $logs = get_option(self::OPTION_KEY, []);
        if (!is_array($logs)) {
            $logs = [];
        }

        $entry = [
            'time'     => current_time('mysql'),
            'order_id' => $order_id,
            'email'    => sanitize_email($email),
            'event'    => sanitize_text_field($event),
            'detail'   => sanitize_text_field($detail),
        ];

        // Store structured meta.
        if (!empty($meta)) {
            $entry['meta'] = self::normalize_meta($meta);
        }

        if ('sale_permanently_failed' === $event) {
            $failed = (int) get_option('hyros_woo_permanently_failed_count', 0);
            update_option('hyros_woo_permanently_failed_count', $failed + 1, false);
        }

        self::insert_table_entry($entry);

        // Keep a compact compatibility copy in wp_options.
        $logs[] = $entry;
        if (count($logs) > self::MAX_ENTRIES) {
            $logs = array_slice($logs, -self::MAX_ENTRIES);
        }
        update_option(self::OPTION_KEY, $logs, false);
    }

    /**
     * Get recent log entries (newest first).
     *
     * @param int $limit
     * @return array
     */
    public static function get_recent(int $limit = 50): array {
        $table_logs = self::get_recent_from_table($limit);
        if (!empty($table_logs)) {
            return $table_logs;
        }

        $logs = get_option(self::OPTION_KEY, []);
        if (!is_array($logs)) {
            return [];
        }
        return array_slice(array_reverse($logs), 0, $limit);
    }

    /**
     * Get recent logs for one order.
     */
    public static function get_recent_for_order(int $order_id, int $limit = 50): array {
        if ($order_id <= 0) {
            return [];
        }

        if (self::table_exists()) {
            global $wpdb;
            $table = self::table_name();
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT logged_at, order_id, email, event, detail, meta
                     FROM {$table}
                     WHERE order_id = %d
                     ORDER BY id DESC
                     LIMIT %d",
                    $order_id,
                    max(1, $limit)
                ),
                ARRAY_A
            );

            if (is_array($rows) && !empty($rows)) {
                $mapped = [];
                foreach ($rows as $row) {
                    $entry = [
                        'time'     => $row['logged_at'] ?? '',
                        'order_id' => (int) ($row['order_id'] ?? 0),
                        'email'    => sanitize_email((string) ($row['email'] ?? '')),
                        'event'    => sanitize_text_field((string) ($row['event'] ?? '')),
                        'detail'   => sanitize_text_field((string) ($row['detail'] ?? '')),
                    ];
                    if (!empty($row['meta'])) {
                        $decoded = json_decode((string) $row['meta'], true);
                        if (is_array($decoded)) {
                            $entry['meta'] = self::normalize_meta($decoded);
                        }
                    }
                    $mapped[] = $entry;
                }
                return $mapped;
            }
        }

        $all_logs = get_option(self::OPTION_KEY, []);
        if (!is_array($all_logs)) {
            return [];
        }
        $order_logs = array_filter($all_logs, static function ($entry) use ($order_id): bool {
            return isset($entry['order_id']) && (int) $entry['order_id'] === $order_id;
        });
        return array_slice(array_reverse(array_values($order_logs)), 0, $limit);
    }

    /**
     * Return display-ready structured data for a log entry.
     * Supports both new entries (with 'meta' key) and legacy flat-string entries.
     *
     * @param array $entry
     * @return array{label: string, items_summary: string, total: string, currency: string,
     *               phone: string, ip: string, cart_id: string, hyros_id: string, detail: string}
     */
    public static function parse_detail_for_display(array $entry): array {
        $event = $entry['event'] ?? '';
        $base  = [
            'label'         => self::event_label($event),
            'items_summary' => '',
            'total'         => '',
            'currency'      => '',
            'phone'         => '',
            'ip'            => '',
            'cart_id'       => '',
            'hyros_id'      => '',
            'detail'        => $entry['detail'] ?? '',
        ];

        // New-format entry: has structured meta.
        if (!empty($entry['meta']) && is_array($entry['meta'])) {
            return array_merge($base, [
                'items_summary' => $entry['meta']['items_summary'] ?? '',
                'total'         => $entry['meta']['total'] ?? '',
                'currency'      => $entry['meta']['currency'] ?? '',
                'phone'         => $entry['meta']['phone'] ?? '',
                'ip'            => $entry['meta']['ip'] ?? '',
                'cart_id'       => $entry['meta']['cart_id'] ?? '',
                'hyros_id'      => $entry['meta']['hyros_id'] ?? '',
            ]);
        }

        // Legacy-format entry: try to parse flat detail string.
        $detail = $entry['detail'] ?? '';
        $parsed = $base;

        if (preg_match('/Hyros ID:\s*([^\s|]+)/', $detail, $m)) {
            $parsed['hyros_id'] = $m[1];
        }
        if (preg_match('/cartId:\s*([^\s|]+)/', $detail, $m)) {
            $parsed['cart_id'] = $m[1];
        }
        // Items summary: everything after the last pipe (if any).
        if (strpos($detail, '|') !== false) {
            $parts = explode('|', $detail);
            $parsed['items_summary'] = trim(end($parts));
        }

        return $parsed;
    }

    /**
     * Row CSS class based on event type.
     *
     * @param string $event
     * @return string
     */
    public static function row_class(string $event): string {
        if (strpos($event, 'failed') !== false || strpos($event, 'permanently') !== false) {
            return 'hyros-log-row--failed';
        }
        if (strpos($event, 'retry') !== false) {
            return 'hyros-log-row--retry';
        }
        if (strpos($event, 'tracked') !== false) {
            return 'hyros-log-row--success';
        }
        return 'hyros-log-row--info';
    }

    /**
     * Render Hyros tracking status in WC admin order detail page.
     *
     * @param \WC_Order $order
     */
    public static function render_order_meta(\WC_Order $order): void {
        $order_id = $order->get_id();
        $status   = $order->get_meta('_hyros_tracked', true);

        if (empty($status)) {
            return;
        }

        echo '<div class="hyros-order-meta"><strong>' . esc_html__('Hyros', 'hyros-woo') . ':</strong> ';
        if ('yes' === $status) {
            echo '<span class="hyros-ok">&#10003; ' . esc_html__('Tracked', 'hyros-woo') . '</span>';
        } elseif ('refunded' === $status) {
            echo '<span class="hyros-error">&#8635; ' . esc_html__('Refunded', 'hyros-woo') . '</span>';
        } else {
            echo esc_html($status);
        }
        echo '</div>';

        $order_logs = self::get_recent_for_order($order_id, 50);

        if (!empty($order_logs)) {
            echo '<table class="hyros-log-table" style="width:100%;margin-top:6px;font-size:12px;">';
            echo '<thead><tr><th>' . esc_html__('Time', 'hyros-woo') . '</th><th>' . esc_html__('Event', 'hyros-woo') . '</th><th>' . esc_html__('Detail', 'hyros-woo') . '</th></tr></thead><tbody>';
            foreach ($order_logs as $entry) {
                $parsed    = self::parse_detail_for_display($entry);
                $row_class = self::row_class($entry['event'] ?? '');
                $summary   = $parsed['items_summary'] ?: $parsed['detail'];
                printf(
                    '<tr class="%s"><td>%s</td><td>%s</td><td>%s</td></tr>',
                    esc_attr($row_class),
                    esc_html($entry['time']),
                    esc_html($parsed['label']),
                    esc_html($summary)
                );
            }
            echo '</tbody></table>';
        }
    }
}
