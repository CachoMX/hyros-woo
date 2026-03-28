<?php
defined('ABSPATH') || exit;

/**
 * WooCommerce event hooks → Hyros API calls.
 */
class Hyros_Tracker {

    public static function init(): void {
        add_action('woocommerce_payment_complete', [self::class, 'handle_sale'], 10, 1);
        add_action('woocommerce_order_status_processing', [self::class, 'handle_sale'], 10, 1);
        add_action('woocommerce_order_status_completed', [self::class, 'handle_sale'], 10, 1);
        add_action('woocommerce_subscription_renewal_payment_complete', [self::class, 'handle_renewal'], 10, 1);
        add_action('woocommerce_order_status_refunded', [self::class, 'handle_refund'], 10, 1);
        add_action('wp_head', [self::class, 'inject_script'], 1);
    }

    /**
     * Track a sale. Deduplicates via _hyros_tracked order meta.
     *
     * @param int $order_id
     */
    public static function handle_sale(int $order_id): void {
        // Deduplication check.
        if (get_post_meta($order_id, '_hyros_tracked', true) === 'yes') {
            return;
        }

        $api = self::get_api();
        if (null === $api) {
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        // Build items array.
        $items = [];
        foreach ($order->get_items() as $item) {
            /** @var WC_Order_Item_Product $item */
            $qty     = (int) $item->get_quantity();
            $total   = (float) $item->get_total();
            $price   = $qty > 0 ? round($total / $qty, 4) : $total;
            $items[] = [
                'name'       => $item->get_name(),
                'price'      => $price,
                'externalId' => (string) $item->get_product_id(),
                'quantity'   => $qty,
            ];
        }

        // Purchase time.
        $date_paid     = $order->get_date_paid();
        $purchase_time = $date_paid ? $date_paid->getTimestamp() : time();

        $payload = [
            'email'        => $order->get_billing_email(),
            'items'        => $items,
            'orderId'      => (string) $order_id,
            'purchaseTime' => $purchase_time,
            'currency'     => $order->get_currency(),
        ];

        $result = $api->send_order($payload);

        Hyros_Logger::log(
            $order_id,
            $result['success'] ? 'sale_tracked' : 'sale_failed',
            $result['success'] ? '' : $result['error']
        );

        if ($result['success']) {
            update_post_meta($order_id, '_hyros_tracked', 'yes');
            $order->save();
        }
    }

    /**
     * Track a subscription renewal by delegating to handle_sale.
     *
     * @param \WC_Subscription $subscription
     */
    public static function handle_renewal($subscription): void {
        $last_order = $subscription->get_last_order('all');
        if ($last_order) {
            self::handle_sale($last_order->get_id());
        }
    }

    /**
     * Track a refund.
     *
     * @param int $order_id
     */
    public static function handle_refund(int $order_id): void {
        if (get_post_meta($order_id, '_hyros_tracked', true) !== 'yes') {
            return;
        }

        $api = self::get_api();
        if (null === $api) {
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        $amount = (float) $order->get_total();
        $result = $api->send_refund((string) $order_id, $amount);

        Hyros_Logger::log(
            $order_id,
            $result['success'] ? 'refund_tracked' : 'refund_failed',
            $result['success'] ? '' : $result['error']
        );

        if ($result['success']) {
            update_post_meta($order_id, '_hyros_tracked', 'refunded');
        }
    }

    /**
     * Inject the selected Hyros script into <head>.
     */
    public static function inject_script(): void {
        $script_content = get_option('hyros_woo_selected_script', '');
        if (empty($script_content)) {
            return;
        }
        // Output raw — Hyros provides validated JS.
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo $script_content . "\n";
    }

    /**
     * Get an API instance using the configured key.
     * Checks HYROS_API_KEY constant (wp-config fallback) then DB option.
     *
     * @return Hyros_API|null
     */
    private static function get_api(): ?Hyros_API {
        if (defined('HYROS_API_KEY') && !empty(HYROS_API_KEY)) {
            $key = HYROS_API_KEY;
        } else {
            $key = get_option('hyros_woo_api_key', '');
        }

        if (empty($key)) {
            return null;
        }

        return new Hyros_API($key);
    }
}
