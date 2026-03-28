<?php
defined('ABSPATH') || exit;

/**
 * Logging for HyrosWoo events.
 * Stores up to 200 entries in wp_options and adds WC order notes.
 */
class Hyros_Logger {

    private const OPTION_KEY = 'hyros_woo_recent_logs';
    private const MAX_ENTRIES = 200;

    public static function init(): void {
        add_action('woocommerce_admin_order_data_after_billing_address', [self::class, 'render_order_meta']);
    }

    /**
     * Log an event.
     *
     * @param int    $order_id
     * @param string $event   e.g. 'sale_tracked', 'sale_failed', 'refund_tracked', 'refund_failed'
     * @param string $detail  Additional detail. MUST NOT contain API key.
     */
    public static function log(int $order_id, string $event, string $detail = ''): void {
        // Add WC order note.
        $order = wc_get_order($order_id);
        if ($order) {
            $note = sprintf('[HyrosWoo] %s', $event);
            if (!empty($detail)) {
                $note .= ': ' . $detail;
            }
            $order->add_order_note($note);
        }

        // Append to global log.
        $logs = get_option(self::OPTION_KEY, []);
        if (!is_array($logs)) {
            $logs = [];
        }

        $logs[] = [
            'time'     => current_time('mysql'),
            'order_id' => $order_id,
            'event'    => sanitize_text_field($event),
            'detail'   => sanitize_text_field($detail),
        ];

        // Trim to max entries.
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
        $logs = get_option(self::OPTION_KEY, []);
        if (!is_array($logs)) {
            return [];
        }
        return array_slice(array_reverse($logs), 0, $limit);
    }

    /**
     * Render Hyros tracking status in WC admin order detail page.
     *
     * @param \WC_Order $order
     */
    public static function render_order_meta(\WC_Order $order): void {
        $order_id = $order->get_id();
        $status   = get_post_meta($order_id, '_hyros_tracked', true);

        if (empty($status)) {
            return;
        }

        $label_map = [
            'yes'      => '<span class="hyros-ok">&#10003; Tracked</span>',
            'refunded' => '<span class="hyros-error">&#8635; Refunded</span>',
        ];
        $label = isset($label_map[$status]) ? $label_map[$status] : esc_html($status);

        echo '<div class="hyros-order-meta"><strong>Hyros:</strong> ' . $label . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

        // Show this order's log entries.
        $all_logs    = get_option(self::OPTION_KEY, []);
        $order_logs  = array_filter(is_array($all_logs) ? $all_logs : [], function($entry) use ($order_id) {
            return isset($entry['order_id']) && (int) $entry['order_id'] === $order_id;
        });

        if (!empty($order_logs)) {
            echo '<table class="hyros-log-table" style="width:100%;margin-top:6px;font-size:12px;">';
            echo '<thead><tr><th>Time</th><th>Event</th><th>Detail</th></tr></thead><tbody>';
            foreach (array_reverse(array_values($order_logs)) as $entry) {
                $is_failed = strpos($entry['event'], 'failed') !== false;
                $row_class = $is_failed ? ' class="hyros-row-failed"' : '';
                printf(
                    '<tr%s><td>%s</td><td>%s</td><td>%s</td></tr>',
                    $row_class,
                    esc_html($entry['time']),
                    esc_html($entry['event']),
                    esc_html($entry['detail'])
                );
            }
            echo '</tbody></table>';
        }
    }
}
