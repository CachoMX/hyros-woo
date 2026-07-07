<?php
defined('ABSPATH') || exit;

/**
 * WooCommerce event hooks → Hyros API calls.
 */
class Hyros_Tracker {

    /** Maximum number of WP-Cron retries after an initial failure. */
    private const MAX_RETRIES = 3;

    /** Delay in seconds between each retry attempt. */
    private const RETRY_DELAYS = [300, 1800, 7200]; // 5 min, 30 min, 2 hr

    /** Lock time-to-live to avoid stuck lock states after fatal errors. */
    private const ORDER_LOCK_TTL = 900;

    /** Guest AJAX cooldown (seconds) for abandoned cart capture endpoint. */
    private const GUEST_CAPTURE_COOLDOWN = 300;

    /**
     * In-process re-entry guard. Prevents double-fire when $order->save()
     * triggers status-change hooks within the same PHP request.
     *
     * @var array<int, bool>
     */
    private static array $in_progress = [];

    public static function init(): void {
        $track_sales = get_option('hyros_woo_track_sales', 'yes') === 'yes';
        $track_subs  = get_option('hyros_woo_track_subscriptions', 'yes') === 'yes';
        $track_atc   = get_option('hyros_woo_track_add_to_cart', 'yes') === 'yes';
        $inject_script = get_option('hyros_woo_inject_script', 'yes') === 'yes';

        // Retry handler is always registered; script injection is configurable.
        add_action('hyros_woo_retry_sale', [self::class, 'handle_retry'], 10, 1);
        if ($inject_script) {
            add_action('wp_head', [self::class, 'inject_script'], 1);
        }

        if ($track_sales) {
            add_action('woocommerce_payment_complete',         [self::class, 'handle_sale'],   10, 1);
            add_action('woocommerce_order_status_processing',  [self::class, 'handle_sale'],   10, 1);
            add_action('woocommerce_order_status_completed',   [self::class, 'handle_sale'],   10, 1);
            add_action('woocommerce_order_status_refunded',    [self::class, 'handle_refund'], 10, 1);
            add_action('woocommerce_order_partially_refunded', [self::class, 'handle_refund'], 10, 2);
            add_action('woocommerce_order_status_cancelled',   [self::class, 'handle_canceled_or_failed'], 10, 1);
            add_action('woocommerce_order_status_failed',      [self::class, 'handle_canceled_or_failed'], 10, 1);
        }

        if ($track_subs) {
            add_action('woocommerce_subscription_renewal_payment_complete', [self::class, 'handle_renewal'], 10, 1);
        }

        if ($track_atc) {
            add_action('woocommerce_add_to_cart',          [self::class, 'handle_add_to_cart'],          10, 6);
            add_action('woocommerce_checkout_order_created', [self::class, 'capture_cart_events'],        10, 1);
            add_action('wp_enqueue_scripts',               [self::class, 'enqueue_checkout_script']);
            add_action('wp_ajax_hyros_capture_abandoned_cart',        [self::class, 'ajax_capture_abandoned_cart']);
            add_action('wp_ajax_nopriv_hyros_capture_abandoned_cart', [self::class, 'ajax_capture_abandoned_cart']);
        }
    }

    /**
     * Track a sale. Deduplicates via _hyros_tracked order meta.
     * Schedules a WP-Cron retry if the API call fails.
     *
     * @param int $order_id
     */
    public static function handle_sale(int $order_id): void {
        // Guard set BEFORE any DB access to prevent double-fire from concurrent hooks.
        if (isset(self::$in_progress[$order_id])) {
            return;
        }
        self::$in_progress[$order_id] = true;

        $order = wc_get_order($order_id);
        $already_tracked = $order && (
            $order->get_meta('_hyros_sale_tracked', true) === 'yes'
            || $order->get_meta('_hyros_tracked', true) === 'yes'
        );
        if (!$order || $already_tracked) {
            unset(self::$in_progress[$order_id]);
            return;
        }

        if (self::is_renewal_order($order_id) && get_option('hyros_woo_track_subscriptions', 'yes') !== 'yes') {
            unset(self::$in_progress[$order_id]);
            return;
        }

        if (!self::has_tracking_consent()) {
            Hyros_Logger::log($order_id, 'sale_skipped_no_consent', __('Marketing consent not granted.', 'hyros-woo'));
            unset(self::$in_progress[$order_id]);
            return;
        }

        if (!$order->is_paid()) {
            Hyros_Logger::log($order_id, 'sale_skipped_unpaid', __('Order is not marked as paid.', 'hyros-woo'));
            unset(self::$in_progress[$order_id]);
            return;
        }

        if (!self::acquire_order_lock($order_id)) {
            unset(self::$in_progress[$order_id]);
            return;
        }

        $api = self::get_api();
        if (null === $api) {
            self::release_order_lock($order_id);
            unset(self::$in_progress[$order_id]);
            return;
        }

        $result = self::send_sale($api, $order);

        if (!$result['success']) {
            if (!empty($result['retryable']) && !wp_next_scheduled('hyros_woo_retry_sale', [$order_id])) {
                $delay = self::get_retry_delay_from_result(0, $result);
                wp_schedule_single_event(time() + $delay, 'hyros_woo_retry_sale', [$order_id]);
                Hyros_Logger::log($order_id, 'sale_retry_scheduled', sprintf('Retry #1 in %d seconds.', $delay));
            } elseif (empty($result['retryable'])) {
                Hyros_Logger::log($order_id, 'sale_failed_non_retryable', $result['error'] ?? __('Non-retryable Hyros error.', 'hyros-woo'));
            }
        }

        self::release_order_lock($order_id);
        unset(self::$in_progress[$order_id]);
    }

    /**
     * WP-Cron handler: retry a previously failed sale tracking.
     * Automatically re-schedules with increasing delays up to MAX_RETRIES.
     *
     * @param int $order_id
     */
    public static function handle_retry(int $order_id): void {
        if (isset(self::$in_progress[$order_id])) {
            return;
        }

        $order = wc_get_order($order_id);
        $already_tracked = $order && (
            $order->get_meta('_hyros_sale_tracked', true) === 'yes'
            || $order->get_meta('_hyros_tracked', true) === 'yes'
        );
        if (!$order || $already_tracked) {
            return; // Already tracked by the time the retry ran.
        }

        if (!self::has_tracking_consent()) {
            Hyros_Logger::log($order_id, 'sale_skipped_no_consent', __('Marketing consent not granted.', 'hyros-woo'));
            return;
        }

        if (!$order->is_paid()) {
            Hyros_Logger::log($order_id, 'sale_skipped_unpaid', __('Retry skipped because order is unpaid.', 'hyros-woo'));
            return;
        }

        if (!self::acquire_order_lock($order_id)) {
            return;
        }

        $api = self::get_api();
        if (null === $api) {
            self::release_order_lock($order_id);
            return;
        }

        self::$in_progress[$order_id] = true;

        $count  = (int) $order->get_meta('_hyros_retry_count', true);
        $result = self::send_sale($api, $order);

        if (!$result['success']) {
            if (!empty($result['retryable'])) {
                $count++;
                $order->update_meta_data('_hyros_retry_count', $count);

                if ($count < self::MAX_RETRIES) {
                    $delay = self::get_retry_delay_from_result($count, $result);
                    $order->save();
                    wp_schedule_single_event(time() + $delay, 'hyros_woo_retry_sale', [$order_id]);
                    Hyros_Logger::log(
                        $order_id,
                        'sale_retry_scheduled',
                        sprintf('Retry %d/%d in %ds.', $count + 1, self::MAX_RETRIES, $delay)
                    );
                } else {
                    $order->save();
                    Hyros_Logger::log(
                        $order_id,
                        'sale_permanently_failed',
                        sprintf('Gave up after %d retries. Manual action required.', self::MAX_RETRIES)
                    );
                }
            } else {
                $order->update_meta_data('_hyros_retry_count', 'non_retryable');
                $order->save();
                Hyros_Logger::log(
                    $order_id,
                    'sale_permanently_failed',
                    $result['error'] ?? __('Non-retryable Hyros error.', 'hyros-woo')
                );
            }
        }

        self::release_order_lock($order_id);
        unset(self::$in_progress[$order_id]);
    }

    /**
     * Capture an add-to-cart event into the WC session for later Hyros reporting.
     * Signature matches woocommerce_add_to_cart (6 args).
     *
     * @param string $cart_item_key
     * @param int    $product_id
     * @param int    $quantity
     * @param int    $variation_id
     * @param mixed  $variation
     * @param mixed  $cart_item_data
     */
    public static function handle_add_to_cart(
        string $cart_item_key,
        int $product_id,
        int $quantity,
        int $variation_id,
        $variation,
        $cart_item_data
    ): void {
        if (!self::has_tracking_consent()) {
            return;
        }

        if (!WC()->session) {
            return;
        }

        $use_id  = $variation_id > 0 ? $variation_id : $product_id;
        $product = wc_get_product($use_id);
        if (!$product) {
            return;
        }

        $events   = WC()->session->get('hyros_cart_events', []);
        $events[] = [
            'product_id' => $product_id,
            'name'       => $product->get_name(),
            'price'      => (float) wc_get_price_excluding_tax($product),
            'quantity'   => $quantity,
            'sku'        => $product->get_sku(),
            'timestamp'  => time(),
        ];
        WC()->session->set('hyros_cart_events', $events);

        // Logged-in users: email is already known — send the cart to Hyros immediately.
        // Guard via hyros_cart_id so we only send once per session (not on every add).
        if (is_user_logged_in() && !WC()->session->get('hyros_cart_id')) {
            $user = wp_get_current_user();
            if (!empty($user->user_email)) {
                self::send_cart_for_email($user->user_email);
            }
        }
    }

    /**
     * At checkout order creation, move cart events from the WC session into order meta
     * so they survive into the asynchronous sale-tracking context.
     *
     * @param \WC_Order $order
     */
    public static function capture_cart_events(\WC_Order $order): void {
        if (!WC()->session) {
            return;
        }

        $dirty = false;

        // Preserve any cart_id already sent to Hyros (logged-in send or guest JS capture).
        $cart_id = WC()->session->get('hyros_cart_id', '');
        if (!empty($cart_id)) {
            $order->update_meta_data('_hyros_cart_id', $cart_id);
            $dirty = true;
        }

        $events = WC()->session->get('hyros_cart_events', []);
        if (!empty($events)) {
            $order->update_meta_data('_hyros_cart_events', $events);
            WC()->session->set('hyros_cart_events', []);
            $dirty = true;
            Hyros_Logger::log($order->get_id(), 'cart_captured', sprintf('%d add-to-cart event(s) saved.', count($events)));
        }

        if ($dirty) {
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
     * Track a full or partial refund.
     * $refund_id is provided by woocommerce_order_partially_refunded (2 args).
     * For full refunds (woocommerce_order_status_refunded), $refund_id is 0.
     *
     * @param int $order_id
     * @param int $refund_id  WC_Order_Refund ID for partial refunds; 0 for full.
     * @return bool True when processed (or no-op), false when retry may be needed.
     */
    public static function handle_refund(int $order_id, int $refund_id = 0): bool {
        $order = wc_get_order($order_id);
        if (!$order) {
            return false;
        }

        // Only report refunds for orders that were previously tracked.
        $sale_tracked = (
            $order->get_meta('_hyros_sale_tracked', true) === 'yes'
            || $order->get_meta('_hyros_tracked', true) === 'yes'
        );
        if (!$sale_tracked) {
            self::queue_pending_refund($order, $refund_id);
            return false;
        }

        $processed_refunds = $order->get_meta('_hyros_refund_event_ids', true);
        if (!is_array($processed_refunds)) {
            $processed_refunds = [];
        }
        if ($refund_id > 0 && in_array($refund_id, $processed_refunds, true)) {
            return true;
        }

        $already_refunded = (float) $order->get_meta('_hyros_refunded_total', true);

        $api = self::get_api();
        if (null === $api) {
            return false;
        }

        // For partial refunds use the specific refund amount, not the cumulative total.
        if ($refund_id > 0) {
            $refund_obj = wc_get_order($refund_id);
            $amount     = $refund_obj ? abs((float) $refund_obj->get_total()) : max(0, (float) $order->get_total_refunded() - $already_refunded);
        } else {
            $amount = max(0, (float) $order->get_total_refunded() - $already_refunded);
        }
        if ($amount <= 0) {
            return true;
        }

        $result = $api->send_refund((string) $order_id, $amount);

        $detail = $result['error'];
        $meta   = [];
        if ($result['success']) {
            $detail = !empty($result['request_id'])
                ? 'Request ID: ' . $result['request_id']
                : __('Refund accepted by Hyros.', 'hyros-woo');
            $meta = [
                'request_id' => $result['request_id'] ?? '',
                'total'      => (string) $amount,
                'currency'   => $order->get_currency(),
            ];
        }

        Hyros_Logger::log(
            $order_id,
            $result['success'] ? 'refund_tracked' : 'refund_failed',
            $detail,
            '',
            $meta
        );

        if ($result['success']) {
            if ($refund_id > 0) {
                $processed_refunds[] = $refund_id;
                $order->update_meta_data('_hyros_refund_event_ids', array_values(array_unique(array_map('intval', $processed_refunds))));
            }

            $order->update_meta_data('_hyros_refunded_total', (string) ($already_refunded + $amount));
            $order->update_meta_data('_hyros_last_refund_at', gmdate('c'));
            $order->save();
            return true;
        }

        return false;
    }

    /**
     * Compensate already-tracked sales that later move into canceled/failed without payment.
     *
     * @param int $order_id
     */
    public static function handle_canceled_or_failed(int $order_id): void {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        $sale_tracked = (
            $order->get_meta('_hyros_sale_tracked', true) === 'yes'
            || $order->get_meta('_hyros_tracked', true) === 'yes'
        );
        if (!$sale_tracked) {
            return;
        }

        if ($order->is_paid()) {
            return;
        }

        if ($order->get_meta('_hyros_cancel_failed_compensated', true) === 'yes') {
            return;
        }

        $api = self::get_api();
        if (null === $api) {
            return;
        }

        $result = $api->send_refund((string) $order_id, (float) $order->get_total());
        Hyros_Logger::log(
            $order_id,
            $result['success'] ? 'sale_reversed_after_cancel' : 'sale_reverse_failed',
            $result['success'] ? __('Unpaid canceled/failed order reversed in Hyros.', 'hyros-woo') : ($result['error'] ?? '')
        );

        if ($result['success']) {
            $order->update_meta_data('_hyros_cancel_failed_compensated', 'yes');
            $order->save();
        }
    }

    /**
     * Queue refund events that happen before sale tracking succeeds.
     */
    private static function queue_pending_refund(\WC_Order $order, int $refund_id): void {
        $pending = $order->get_meta('_hyros_pending_refunds', true);
        if (!is_array($pending)) {
            $pending = [];
        }

        foreach ($pending as $entry) {
            $queued_refund_id = isset($entry['refund_id']) ? (int) $entry['refund_id'] : 0;
            if ($queued_refund_id === $refund_id) {
                return;
            }
        }

        $pending[] = [
            'refund_id' => $refund_id,
            'queued_at' => time(),
        ];
        $order->update_meta_data('_hyros_pending_refunds', $pending);
        $order->save();

        Hyros_Logger::log(
            $order->get_id(),
            'refund_queued',
            $refund_id > 0
                ? sprintf('Queued partial refund #%d until sale is tracked.', $refund_id)
                : __('Queued refund event until sale is tracked.', 'hyros-woo')
        );
    }

    /**
     * Flush previously queued refund events once sale tracking succeeds.
     */
    private static function flush_pending_refunds(\WC_Order $order): void {
        $pending = $order->get_meta('_hyros_pending_refunds', true);
        if (empty($pending) || !is_array($pending)) {
            return;
        }

        $remaining = [];
        foreach ($pending as $entry) {
            $refund_id = isset($entry['refund_id']) ? (int) $entry['refund_id'] : 0;
            if (!self::handle_refund((int) $order->get_id(), $refund_id)) {
                $remaining[] = [
                    'refund_id' => $refund_id,
                    'queued_at' => time(),
                ];
            }
        }

        if (empty($remaining)) {
            $order->delete_meta_data('_hyros_pending_refunds');
        } else {
            $order->update_meta_data('_hyros_pending_refunds', $remaining);
        }
        $order->save();
    }

    /**
     * Inject the selected Hyros script into <head>.
     * Content is fetched from Hyros API server-side at save time — never from client input.
     */
    public static function inject_script(): void {
        if (!self::has_tracking_consent()) {
            return;
        }

        $script_content = get_option('hyros_woo_selected_script', '');
        if (empty($script_content)) {
            return;
        }

        if (!Hyros_Settings::is_valid_tracking_script((string) $script_content)) {
            return;
        }
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo $script_content . "\n";
    }

    /**
     * Build payload, call the API, log the result, and persist the tracked flag on success.
     * Used by both handle_sale() and handle_retry() to avoid duplicated logic.
     *
     * @param Hyros_API  $api
     * @param \WC_Order  $order
     * @return array{success: bool, error: string}
     */
    private static function send_sale(Hyros_API $api, \WC_Order $order): array {
        $order_id  = $order->get_id();
        $items     = [];
        $send_cogs = get_option('hyros_woo_send_cogs', 'no') === 'yes';

        foreach ($order->get_items() as $item) {
            /** @var WC_Order_Item_Product $item */
            $qty           = (int) $item->get_quantity();
            $item_total    = (float) $item->get_total();           // after discount, excl. tax
            $item_tax      = (float) $item->get_total_tax();       // tax on this line
            $item_subtotal = (float) $item->get_subtotal();        // before discount
            $discount      = $qty > 0 ? round(($item_subtotal - $item_total) / $qty, 4) : 0;
            $price         = $qty > 0 ? round($item_total / $qty, 4) : $item_total;
            $tax_per_unit  = $qty > 0 ? round($item_tax / $qty, 4) : 0;

            $line_item = [
                'name'       => $item->get_name(),
                'price'      => $price,
                'externalId' => (string) $item->get_product_id(),
                'quantity'   => $qty,
                'tag'        => '$hyros-woo',
            ];
            if ($tax_per_unit > 0) {
                $line_item['taxes'] = $tax_per_unit;
            }
            if ($discount > 0) {
                $line_item['itemDiscount'] = $discount;
            }
            if ($send_cogs) {
                $product = $item->get_product();
                if ($product) {
                    // Reads cost set by WooCommerce Cost of Goods (SkyVerge) or similar plugins.
                    $cogs = (float) $product->get_meta('_wc_cog_cost', true);
                    if ($cogs > 0) {
                        $line_item['costOfGoods'] = round($cogs, 4);
                    }
                }
            }
            $items[] = $line_item;
        }

        $date_paid    = $order->get_date_paid();
        $date_iso     = $date_paid ? $date_paid->format('c') : gmdate('c');

        // Send pending cart events first so we can link them via cartId.
        $cart_id = self::maybe_send_cart($api, $order);

        $payload = [
            'email'     => $order->get_billing_email(),
            'items'     => $items,
            'orderId'   => (string) $order_id,
            'date'      => $date_iso,
            'currency'  => $order->get_currency(),
            'firstName' => $order->get_billing_first_name(),
            'lastName'  => $order->get_billing_last_name(),
        ];

        // Phone number — Hyros uses it for attribution matching.
        $phone = $order->get_billing_phone();
        if (!empty($phone)) {
            $payload['phoneNumbers'] = [preg_replace('/[^0-9+]/', '', $phone)];
        }

        // Customer IP — helps Hyros match the lead to ad clicks.
        $customer_ip = $order->get_customer_ip_address();
        if (!empty($customer_ip)) {
            $payload['leadIps'] = [$customer_ip];
        }

        // Order-level financials.
        $shipping = (float) $order->get_shipping_total();
        if ($shipping > 0) {
            $payload['shippingCost'] = $shipping;
        }

        $order_discount = (float) $order->get_discount_total();
        if ($order_discount > 0) {
            $payload['orderDiscount'] = $order_discount;
        }

        if (!empty($cart_id)) {
            $payload['cartId'] = $cart_id;
        }

        if (self::is_renewal_order($order_id)) {
            $subscription_id = self::get_subscription_id_for_order($order);
            if (!empty($subscription_id)) {
                $payload['externalSubscriptionId'] = $subscription_id;
            }
        }

        $result = $api->send_order($payload);

        // Build items summary for the activity log.
        $items_summary_parts = [];
        foreach ($order->get_items() as $item) {
            $items_summary_parts[] = $item->get_name() . ' x' . (int) $item->get_quantity();
        }
        $items_summary = implode(', ', $items_summary_parts);

        Hyros_Logger::log(
            $order_id,
            $result['success'] ? 'sale_tracked' : 'sale_failed',
            $result['success'] ? '' : $result['error'],
            $order->get_billing_email(),
            $result['success'] ? [
                'items_summary' => $items_summary,
                'total'         => (string) $order->get_total(),
                'currency'      => $order->get_currency(),
                'cart_id'       => $cart_id,
                'hyros_id'      => $result['hyros_id'] ?? '',
                'request_id'    => $result['request_id'] ?? '',
            ] : []
        );

        if ($result['success']) {
            $order->update_meta_data('_hyros_sale_tracked', 'yes');
            $order->update_meta_data('_hyros_tracked', 'yes');
            $order->update_meta_data('_hyros_retry_count', '');
            $order->save();
            self::flush_pending_refunds($order);
        }

        return $result;
    }

    /**
     * Send any captured add-to-cart events to Hyros /carts and return the cartId.
     * Clears the events from order meta after a successful send so they aren't re-sent on retry.
     *
     * @param Hyros_API $api
     * @param \WC_Order $order
     * @return string cartId or empty string
     */
    private static function maybe_send_cart(Hyros_API $api, \WC_Order $order): string {
        // Cart was already sent before checkout (logged-in or guest JS capture) — reuse the id.
        $existing_cart_id = $order->get_meta('_hyros_cart_id', true);
        if (!empty($existing_cart_id)) {
            return (string) $existing_cart_id;
        }

        $events = $order->get_meta('_hyros_cart_events', true);
        if (empty($events) || !is_array($events)) {
            return '';
        }

        // Use the timestamp of the earliest add-to-cart event.
        $timestamps = array_column($events, 'timestamp');
        $earliest   = !empty($timestamps) ? min($timestamps) : time();

        $items = [];
        foreach ($events as $event) {
            $item = [
                'name'     => $event['name'],
                'price'    => $event['price'],
                'quantity' => $event['quantity'],
            ];
            if (!empty($event['product_id'])) {
                $item['externalId'] = (string) $event['product_id'];
            }
            if (!empty($event['sku'])) {
                $item['sku'] = $event['sku'];
            }
            $items[] = $item;
        }

        $payload = [
            'items'    => $items,
            'email'    => $order->get_billing_email(),
            'date'     => gmdate('c', $earliest),
            'currency' => $order->get_currency(),
        ];

        $result = $api->send_cart($payload);

        Hyros_Logger::log(
            $order->get_id(),
            $result['success'] ? 'cart_tracked' : 'cart_failed',
            $result['success']
                ? sprintf('Hyros ID: %s | add-to-cart at %s', $result['hyros_id'] ?: 'n/a', gmdate('Y-m-d H:i:s', $earliest))
                : $result['error']
        );

        if ($result['success']) {
            $order->delete_meta_data('_hyros_cart_events');
            $order->save();
            return $result['cart_id'];
        }

        return '';
    }

    /**
     * Build the current WC cart as a Hyros payload and send it to /carts.
     * Also sends a /clicks event so Hyros can attribute the lead to a traffic source.
     * Stores the returned cart_id in the WC session on success.
     *
     * @param string $email
     * @param array  $click_context  Optional browser context: referrer_url, ip, user_agent.
     */
    private static function send_cart_for_email(string $email, array $click_context = []): array {
        if (!self::has_tracking_consent()) {
            return ['success' => false, 'cart_id' => '', 'error' => __('Marketing consent not granted.', 'hyros-woo')];
        }

        if (empty($email) || !is_email($email) || !WC()->cart || WC()->cart->is_empty()) {
            return ['success' => false, 'cart_id' => '', 'error' => __('Missing valid email/cart context.', 'hyros-woo')];
        }

        $api = self::get_api();
        if (!$api) {
            return ['success' => false, 'cart_id' => '', 'error' => __('Missing Hyros API key.', 'hyros-woo')];
        }

        $items = [];
        foreach (WC()->cart->get_cart() as $item) {
            /** @var \WC_Product $product */
            $product = $item['data'];
            if (!$product) {
                continue;
            }
            $items[] = [
                'name'       => $product->get_name(),
                'price'      => (float) wc_get_price_excluding_tax($product),
                'quantity'   => (int) $item['quantity'],
                'externalId' => (string) $item['product_id'],
                'sku'        => $product->get_sku(),
            ];
        }

        if (empty($items)) {
            return ['success' => false, 'cart_id' => '', 'error' => __('Cart has no items.', 'hyros-woo')];
        }

        // Send /clicks so Hyros can attribute this lead to a traffic source.
        // For logged-in users the JS script never fires, so we send it server-side.
        $referrer_url = !empty($click_context['referrer_url'])
            ? $click_context['referrer_url']
            : (isset($_SERVER['HTTP_REFERER']) ? esc_url_raw(wp_unslash($_SERVER['HTTP_REFERER'])) : get_home_url());

        // Use WooCommerce's geolocation helper which correctly handles proxy headers.
        $ip = class_exists('WC_Geolocation') ? WC_Geolocation::get_ip_address() : self::get_client_ip();

        $user_agent = !empty($click_context['user_agent'])
            ? $click_context['user_agent']
            : (isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '');

        $session_id = WC()->session ? (string) WC()->session->get_customer_id() : md5($email . gmdate('Y-m-d'));

        $click_payload = [
            'email'        => $email,
            'referrerUrl'  => $referrer_url,
            'sessionId'    => $session_id,
            'tag'          => '!clicked-atc',
            'isOrganic'    => true,
            'date'         => gmdate('c'),
        ];
        if (!empty($ip)) {
            $click_payload['ip'] = $ip;
        }
        if (!empty($user_agent)) {
            $click_payload['userAgent'] = $user_agent;
        }

        $click_result = $api->send_click($click_payload);
        if (!$click_result['success']) {
            Hyros_Logger::log(0, 'click_failed', $click_result['error'] ?? __('Click tracking failed.', 'hyros-woo'), $email);
            return [
                'success' => false,
                'cart_id' => '',
                'error'   => (string) ($click_result['error'] ?? __('Click tracking failed.', 'hyros-woo')),
            ];
        }

        // Send /carts.
        $result = $api->send_cart([
            'email'    => $email,
            'items'    => $items,
            'date'     => gmdate('c'),
            'currency' => get_woocommerce_currency(),
        ]);

        $product_names = implode(', ', array_column($items, 'name'));

        if ($result['success']) {
            if (!empty($result['cart_id']) && WC()->session) {
                WC()->session->set('hyros_cart_id', $result['cart_id']);
            }
            Hyros_Logger::log(
                0,
                'cart_tracked',
                '',
                $email,
                [
                    'items_summary' => $product_names,
                    'currency'      => get_woocommerce_currency(),
                    'hyros_id'      => $result['hyros_id'] ?? '',
                    'cart_id'       => $result['cart_id'] ?? '',
                    'request_id'    => $result['request_id'] ?? '',
                ]
            );
            return ['success' => true, 'cart_id' => (string) ($result['cart_id'] ?? ''), 'error' => ''];
        } else {
            Hyros_Logger::log(0, 'cart_failed', $result['error'] ?? 'Unknown error', $email);
            return ['success' => false, 'cart_id' => '', 'error' => (string) ($result['error'] ?? __('Unknown cart error.', 'hyros-woo'))];
        }
    }

    /**
     * Get the real client IP, respecting common proxy headers.
     *
     * @return string
     */
    private static function get_client_ip(): string {
        $headers = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];
        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = trim(explode(',', sanitize_text_field(wp_unslash($_SERVER[$header])))[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }
        return '';
    }

    /**
     * AJAX: capture abandoned cart for a guest who entered their email on checkout.
     * Also handles the no-op case when the cart was already sent (logged-in user).
     */
    public static function ajax_capture_abandoned_cart(): void {
        check_ajax_referer('hyros_checkout_nonce', 'nonce');

        $email = sanitize_email(wp_unslash($_POST['email'] ?? ''));
        if (!is_email($email)) {
            wp_send_json_error(['message' => 'Invalid email.']);
            return;
        }

        if (!WC()->cart || WC()->cart->is_empty()) {
            wp_send_json_error(['message' => 'Cart is empty.']);
            return;
        }

        $capture_token = sanitize_text_field(wp_unslash($_POST['capture_token'] ?? ''));
        $cart_hash = sanitize_text_field(wp_unslash($_POST['cart_hash'] ?? ''));
        if (!self::is_valid_guest_capture_token($capture_token, $cart_hash)) {
            wp_send_json_error(['message' => __('Invalid cart capture token.', 'hyros-woo')]);
            return;
        }

        $session_rate_key = self::build_guest_capture_rate_key($email);
        $ip_rate_key = self::build_guest_ip_rate_key();
        if (get_transient($session_rate_key) || get_transient($ip_rate_key)) {
            wp_send_json_error(['message' => __('Please wait before trying again.', 'hyros-woo')]);
            return;
        }

        $lock_key = self::build_guest_capture_lock_key($email);
        if (!self::acquire_guest_capture_lock($lock_key)) {
            wp_send_json_error(['message' => __('Cart capture already in progress. Please retry in a moment.', 'hyros-woo')]);
            return;
        }

        // Apply cooldown immediately after lock acquisition to reduce burst abuse.
        set_transient($session_rate_key, 1, self::GUEST_CAPTURE_COOLDOWN);
        set_transient($ip_rate_key, 1, self::GUEST_CAPTURE_COOLDOWN);

        // Already captured this session — nothing to do.
        if (WC()->session && WC()->session->get('hyros_cart_id')) {
            self::release_guest_capture_lock($lock_key);
            wp_send_json_success(['already_tracked' => true]);
            return;
        }

        $click_context = [
            'referrer_url' => sanitize_text_field(wp_unslash($_POST['referrer_url'] ?? '')),
            'user_agent'   => sanitize_text_field(wp_unslash($_POST['user_agent'] ?? '')),
            // IP always resolved server-side — never trust client-submitted IP.
        ];

        try {
            $result = self::send_cart_for_email($email, $click_context);
            if (!$result['success']) {
                Hyros_Logger::log(0, 'abandoned_cart_failed', (string) ($result['error'] ?? __('Unknown cart capture error.', 'hyros-woo')), $email);
                wp_send_json_error(['message' => __('Could not track cart right now. Please try again shortly.', 'hyros-woo')]);
                return;
            }

            wp_send_json_success([
                'tracked' => true,
                'cart_id' => $result['cart_id'] ?? '',
            ]);
        } finally {
            self::release_guest_capture_lock($lock_key);
        }
    }

    /**
     * Enqueue lightweight checkout JS for guest abandoned-cart capture.
     * Listens to blur on #billing_email and fires an AJAX call with the cart.
     */
    public static function enqueue_checkout_script(): void {
        if (!is_checkout()) {
            return;
        }

        if (!self::has_tracking_consent()) {
            return;
        }

        $api_key = defined('HYROS_API_KEY') && !empty(HYROS_API_KEY)
            ? HYROS_API_KEY
            : get_option('hyros_woo_api_key', '');

        if (empty($api_key)) {
            return;
        }

        $session_id = WC()->session ? (string) WC()->session->get_customer_id() : '';
        $cart_hash = WC()->cart ? (string) WC()->cart->get_cart_hash() : '';
        $capture_token = '';
        if (WC()->session && '' !== $session_id && '' !== $cart_hash) {
            $capture_secret = (string) WC()->session->get('hyros_capture_secret');
            if ('' === $capture_secret) {
                $capture_secret = wp_generate_password(32, false, false);
                WC()->session->set('hyros_capture_secret', $capture_secret);
            }
            $capture_token = hash_hmac('sha256', $cart_hash . '|' . $session_id, $capture_secret);
        }

        wp_register_script('hyros-checkout', '', ['jquery'], HYROS_WOO_VERSION, true);
        wp_enqueue_script('hyros-checkout');
        wp_localize_script('hyros-checkout', 'hyrosCheckout', [
            'ajaxUrl'      => admin_url('admin-ajax.php'),
            'nonce'        => wp_create_nonce('hyros_checkout_nonce'),
            'captureToken' => $capture_token,
            'cartHash'     => $cart_hash,
        ]);
        wp_add_inline_script('hyros-checkout', self::checkout_inline_js());
    }

    /**
     * Returns the inline JS for checkout cart capture.
     *
     * @return string
     */
    private static function checkout_inline_js(): string {
        return <<<'JS'
(function($) {
    var sent = false;

    function captureCart(email) {
        if (sent) return;
        if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) return;
        sent = true;
        $.post(hyrosCheckout.ajaxUrl, {
            action:        'hyros_capture_abandoned_cart',
            nonce:         hyrosCheckout.nonce,
            email:         email,
            capture_token: hyrosCheckout.captureToken || '',
            cart_hash:     hyrosCheckout.cartHash || '',
            referrer_url:  document.referrer || window.location.href,
            user_agent:    navigator.userAgent
        }, function(response) {
            if (window.console) console.log('[HyrosWoo] Abandoned cart capture:', response);
            if (!response || response.success !== true) {
                sent = false;
            }
        }).fail(function() {
            sent = false; // allow retry on network failure
        });
    }

    $(document).ready(function() {
        // Delegated blur handler: WooCommerce can replace checkout fields dynamically.
        $(document.body).on('blur.hyrosWoo', '#billing_email', function() {
            captureCart($.trim($(this).val()));
        });

        // Re-check when WooCommerce refreshes checkout fragments.
        $(document.body).on('updated_checkout.hyrosWoo', function() {
            var existingEmail = $.trim($('#billing_email').val() || '');
            if (existingEmail) {
                captureCart(existingEmail);
            }
        });

        // Fire immediately if the field already has a value (autofill / returning visitor).
        var existing = $.trim($('#billing_email').val() || '');
        if (existing) {
            captureCart(existing);
        }
    });
})(jQuery);
JS;
    }

    /**
     * Compute retry delay with support for Retry-After when present.
     *
     * @param int   $retry_count Zero-based retry count.
     * @param array $result
     * @return int
     */
    private static function get_retry_delay_from_result(int $retry_count, array $result): int {
        $default_delay = self::RETRY_DELAYS[$retry_count] ?? 7200;
        $retry_after = isset($result['retry_after']) ? (int) $result['retry_after'] : 0;
        if ($retry_after > 0) {
            return max(5, $retry_after);
        }
        return $default_delay;
    }

    /**
     * Build transient key for guest abandoned-cart throttling.
     */
    private static function build_guest_capture_rate_key(string $email): string {
        $session_id = WC()->session ? (string) WC()->session->get_customer_id() : '';
        $ip = class_exists('WC_Geolocation') ? WC_Geolocation::get_ip_address() : self::get_client_ip();
        return 'hyros_guest_capture_' . md5(strtolower($email) . '|' . $session_id . '|' . $ip);
    }

    /**
     * Build lock key for abandoned-cart capture requests.
     */
    private static function build_guest_capture_lock_key(string $email): string {
        $session_id = WC()->session ? (string) WC()->session->get_customer_id() : '';
        $ip = class_exists('WC_Geolocation') ? WC_Geolocation::get_ip_address() : self::get_client_ip();
        return 'hyros_guest_capture_lock_' . md5(strtolower($email) . '|' . $session_id . '|' . $ip);
    }

    /**
     * Acquire short-lived lock for abandoned-cart capture endpoint.
     */
    private static function acquire_guest_capture_lock(string $lock_key): bool {
        $existing = (int) get_option($lock_key, 0);
        if ($existing > 0 && (time() - $existing) < 30) {
            return false;
        }
        if ($existing > 0) {
            delete_option($lock_key);
        }
        return add_option($lock_key, time(), '', false);
    }

    /**
     * Release abandoned-cart capture lock.
     */
    private static function release_guest_capture_lock(string $lock_key): void {
        delete_option($lock_key);
    }

    /**
     * Build IP-level rate key to reduce bot rotation abuse.
     */
    private static function build_guest_ip_rate_key(): string {
        $ip = class_exists('WC_Geolocation') ? WC_Geolocation::get_ip_address() : self::get_client_ip();
        return 'hyros_guest_capture_ip_' . md5((string) $ip);
    }

    /**
     * Validate cart capture token bound to Woo session + cart hash.
     */
    private static function is_valid_guest_capture_token(string $token, string $cart_hash): bool {
        if ('' === $token || '' === $cart_hash || !WC()->session || !WC()->cart) {
            return false;
        }

        $session_id = (string) WC()->session->get_customer_id();
        $capture_secret = (string) WC()->session->get('hyros_capture_secret');
        $server_cart_hash = (string) WC()->cart->get_cart_hash();
        if ('' === $session_id || '' === $capture_secret || '' === $server_cart_hash) {
            return false;
        }

        if (!hash_equals($server_cart_hash, $cart_hash)) {
            return false;
        }

        $expected = hash_hmac('sha256', $server_cart_hash . '|' . $session_id, $capture_secret);
        return hash_equals($expected, $token);
    }

    /**
     * Determine whether we should track based on consent settings.
     */
    private static function has_tracking_consent(): bool {
        if (get_option('hyros_woo_require_consent', 'no') !== 'yes') {
            return true;
        }

        if (function_exists('wp_has_consent') && wp_has_consent('marketing')) {
            return true;
        }

        $cookie = isset($_COOKIE['wp_consent_marketing']) ? strtolower(sanitize_text_field(wp_unslash($_COOKIE['wp_consent_marketing']))) : '';
        if (in_array($cookie, ['yes', '1', 'allow', 'granted'], true)) {
            return true;
        }

        return (bool) apply_filters('hyros_woo_has_tracking_consent', false);
    }

    /**
     * Renewal order detection for WooCommerce Subscriptions.
     */
    private static function is_renewal_order(int $order_id): bool {
        if (function_exists('wcs_order_contains_renewal')) {
            return (bool) wcs_order_contains_renewal($order_id);
        }
        return false;
    }

    /**
     * Resolve external subscription id for renewal orders.
     */
    private static function get_subscription_id_for_order(\WC_Order $order): string {
        if (!function_exists('wcs_get_subscriptions_for_order')) {
            return '';
        }

        $subscriptions = wcs_get_subscriptions_for_order($order, ['order_type' => ['renewal', 'parent']]);
        if (empty($subscriptions) || !is_array($subscriptions)) {
            return '';
        }

        $subscription = reset($subscriptions);
        if (!$subscription || !is_object($subscription) || !method_exists($subscription, 'get_id')) {
            return '';
        }

        return (string) $subscription->get_id();
    }

    /**
     * Distributed order lock to prevent concurrent duplicate sends.
     */
    private static function acquire_order_lock(int $order_id): bool {
        $option_key = self::get_lock_option_key($order_id);
        $existing = (int) get_option($option_key, 0);
        if ($existing > 0 && (time() - $existing) < self::ORDER_LOCK_TTL) {
            return false;
        }

        if ($existing > 0) {
            delete_option($option_key);
        }

        return add_option($option_key, time(), '', false);
    }

    /**
     * Release distributed order lock.
     */
    private static function release_order_lock(int $order_id): void {
        delete_option(self::get_lock_option_key($order_id));
    }

    /**
     * Lock option key.
     */
    private static function get_lock_option_key(int $order_id): string {
        return 'hyros_woo_sale_lock_' . $order_id;
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
