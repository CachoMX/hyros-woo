<?php
/**
 * Regression test for Hyros_Tracker::handle_refund() partial-refund logic.
 *
 * Covers the sale-level refund flow added in 1.2.0: WC partial refunds are
 * matched to individual Hyros sales by product tag (PUT /sales?isRefunded=true)
 * instead of being smeared across every product by the order-level
 * DELETE /orders?refundedAmount call. Run with: php tests/test-partial-refund.php
 *
 * No PHPUnit: the plugin has no test dependencies and this must stay runnable
 * from a bare PHP CLI.
 */

define('ABSPATH', __DIR__);
define('HYROS_WOO_API_BASE', 'https://api.hyros.com/v1/api/v1.0');
define('HYROS_WOO_VERSION', 'test');

// ---------------------------------------------------------------- WP/WC fakes

function __($text, $domain = null) { return $text; }
function get_option($name, $default = false) {
    return ['hyros_woo_send_cogs' => 'no', 'hyros_woo_track_subscriptions' => 'yes'][$name] ?? $default;
}
function apply_filters($hook, $value, ...$args) { return $value; }
function remove_accents($string) { return $string; }

$GLOBALS['scheduled_events'] = [];
function wp_schedule_single_event($time, $hook, $args = []) {
    $GLOBALS['scheduled_events'][] = ['hook' => $hook, 'args' => $args];
    return true;
}
function wp_next_scheduled($hook, $args = []) { return false; }

$GLOBALS['order_registry'] = [];
function wc_get_order($id) { return $GLOBALS['order_registry'][$id] ?? null; }

class Hyros_Logger {
    public static array $entries = [];
    public static function log(...$args): void { self::$entries[] = $args; }
}

/** Captures every API call instead of hitting Hyros. */
class Hyros_API {
    public array $calls = [];
    public array $sales_response = ['success' => true, 'sales' => [], 'error' => '', 'status_code' => 200];

    public function send_refund(string $order_id, float $amount = 0.0): array {
        $this->calls[] = ['send_refund', $order_id, $amount];
        return ['success' => true, 'hyros_id' => '', 'request_id' => 'del-req', 'error' => '', 'status_code' => 200, 'retryable' => false, 'retry_after' => 0];
    }
    public function get_sales_for_email(string $email): array {
        $this->calls[] = ['get_sales_for_email', $email];
        return $this->sales_response;
    }
    public function refund_sales(array $sale_ids, float $amount = 0.0, string $date = ''): array {
        $this->calls[] = ['refund_sales', $sale_ids, $amount];
        return ['success' => true, 'request_id' => 'put-req', 'error' => '', 'status_code' => 200, 'retryable' => false, 'retry_after' => 0];
    }
    public function send_order(array $payload): array { return ['success' => true, 'hyros_id' => '', 'request_id' => '', 'error' => '']; }
    public function send_cart(array $payload): array { return ['success' => true, 'cart_id' => '', 'hyros_id' => '', 'request_id' => '', 'error' => '']; }
    public function update_order(string $order_id, array $payload): array {
        $this->calls[] = ['update_order', $order_id, $payload];
        return ['success' => true, 'request_id' => '', 'error' => '', 'status_code' => 200, 'retryable' => false, 'retry_after' => 0];
    }
}

class WC_Order_Item_Product {
    public function __construct(
        private string $name,
        private int $qty,
        private float $subtotal,
        private float $total,
        private float $tax,
        private int $product_id,
        private int $variation_id = 0
    ) {}
    public function get_name(): string { return $this->name; }
    public function get_quantity(): int { return $this->qty; }
    public function get_subtotal(): float { return $this->subtotal; }
    public function get_total(): float { return $this->total; }
    public function get_total_tax(): float { return $this->tax; }
    public function get_product_id(): int { return $this->product_id; }
    public function get_variation_id(): int { return $this->variation_id; }
    public function get_product() { return null; }
}

class WC_Order {
    public array $meta = [];
    public function __construct(private int $id, private array $items, private float $total, private float $total_refunded = 0.0) {}
    public function get_id(): int { return $this->id; }
    public function get_items(): array { return $this->items; }
    public function get_total(): float { return $this->total; }
    public function get_total_refunded(): float { return $this->total_refunded; }
    public function get_currency(): string { return 'USD'; }
    public function get_billing_email(): string { return 'buyer@example.com'; }
    public function get_meta($key, $single = true) { return $this->meta[$key] ?? ''; }
    public function update_meta_data($key, $value): void { $this->meta[$key] = $value; }
    public function delete_meta_data($key): void { unset($this->meta[$key]); }
    public function save(): void {}
    public function is_paid(): bool { return true; }
}

/** Refund line items carry negative quantities/totals in WooCommerce. */
class WC_Order_Refund extends WC_Order {
    public function __construct(private int $refund_id, private array $refund_items, private float $refund_total) {
        parent::__construct($refund_id, $refund_items, $refund_total);
    }
    public function get_id(): int { return $this->refund_id; }
    public function get_items(): array { return $this->refund_items; }
    public function get_total(): float { return $this->refund_total; }
}

require_once __DIR__ . '/../includes/class-hyros-tracker.php';

// --------------------------------------------------------------- test harness

$failures = 0;
function check(string $label, $expected, $actual): void {
    global $failures;
    $ok = is_float($expected) || is_float($actual)
        ? abs((float) $expected - (float) $actual) < 0.001
        : $expected === $actual;
    if (!$ok) { $failures++; }
    printf("  %s %-62s expected=%-30s actual=%s\n",
        $ok ? 'PASS' : 'FAIL', $label,
        var_export($expected, true), var_export($actual, true));
}

function sale(string $id, string $tag, int $qty, float $price, float $refunded = 0.0): array {
    return [
        'id'      => $id,
        'orderId' => '1001',
        'quantity' => $qty,
        'price'   => ['price' => $price, 'refunded' => $refunded],
        'product' => ['tag' => $tag, 'name' => 'x'],
    ];
}

/**
 * Build a tracked 5-product order (1001) plus a refund object (2001), register
 * both, and return [order, api].
 */
function scenario(array $refund_items, float $refund_total, array $sales, bool $lookup_ok = true): array {
    $items = [];
    for ($i = 1; $i <= 5; $i++) {
        $items[] = new WC_Order_Item_Product("Product $i", 1, 20.00, 20.00, 0.0, 100 + $i);
    }
    $order = new WC_Order(1001, $items, 100.00);
    $order->meta['_hyros_sale_tracked'] = 'yes';

    $refund = new WC_Order_Refund(2001, $refund_items, -$refund_total);

    $GLOBALS['order_registry'] = [1001 => $order, 2001 => $refund];
    $GLOBALS['scheduled_events'] = [];

    $api = new Hyros_API();
    if ($lookup_ok) {
        $api->sales_response = ['success' => true, 'sales' => $sales, 'error' => '', 'status_code' => 200];
    } else {
        $api->sales_response = ['success' => false, 'sales' => [], 'error' => 'Forbidden', 'status_code' => 403];
    }
    return [$order, $api];
}

/** Call the private send_partial_refund() directly so the API can be faked. */
function run_partial(WC_Order $order, Hyros_API $api, float $amount): array {
    $refund = wc_get_order(2001);
    $m = new ReflectionMethod('Hyros_Tracker', 'send_partial_refund');
    return $m->invoke(null, $api, $order, $refund, $amount);
}

function calls_named(Hyros_API $api, string $name): array {
    return array_values(array_filter($api->calls, fn($c) => $c[0] === $name));
}

// ------------------------- case 1: refund one product out of five (Carlos's case)

echo "\nRefund 1 of 5 products (\$20 of \$100) — must hit only that product's sale\n";

[$order, $api] = scenario(
    [new WC_Order_Item_Product('Product 1', -1, -20.00, -20.00, 0.0, 101)],
    20.00,
    [
        sale('sle-1', '$woocommerce-product-1-20', 1, 20.0),
        sale('sle-2', '$woocommerce-product-2-20', 1, 20.0),
        sale('sle-3', '$woocommerce-product-3-20', 1, 20.0),
        sale('sle-4', '$woocommerce-product-4-20', 1, 20.0),
        sale('sle-5', '$woocommerce-product-5-20', 1, 20.0),
    ]
);
$result = run_partial($order, $api, 20.00);
$puts = calls_named($api, 'refund_sales');
$dels = calls_named($api, 'send_refund');

check('reports success', true, $result['success']);
check('exactly one PUT /sales call', 1, count($puts));
check('the refunded sale is product 1', ['sle-1'], $puts[0][1] ?? []);
check('full-line refund omits the amount (Hyros uses sale price)', 0.0, $puts[0][2] ?? -1);
check('no order-level DELETE call (no remainder)', 0, count($dels));

// ------------------------- case 2: refund 2 units of a qty-5 line (partial amount)

echo "\nRefund 2 of 5 units of one qty-5 line — partial amount on one sale\n";

$qty_item = new WC_Order_Item_Product('Bundle', 5, 100.00, 100.00, 0.0, 300);
$order = new WC_Order(1001, [$qty_item], 100.00);
$order->meta['_hyros_sale_tracked'] = 'yes';
$refund = new WC_Order_Refund(2001, [new WC_Order_Item_Product('Bundle', -2, -40.00, -40.00, 0.0, 300)], -40.00);
$GLOBALS['order_registry'] = [1001 => $order, 2001 => $refund];
$api = new Hyros_API();
$api->sales_response = ['success' => true, 'sales' => [sale('sle-q', '$woocommerce-bundle-20', 5, 100.0)], 'error' => '', 'status_code' => 200];

$result = run_partial($order, $api, 40.00);
$puts = calls_named($api, 'refund_sales');

check('reports success', true, $result['success']);
check('one PUT /sales call', 1, count($puts));
check('partial amount forwarded', 40.00, $puts[0][2] ?? -1);
check('no order-level DELETE', 0, count(calls_named($api, 'send_refund')));

// ------------------------- case 3: refund includes shipping — remainder goes order-level

echo "\nRefund \$20 product + \$5 shipping — remainder \$5 goes order-level\n";

[$order, $api] = scenario(
    [new WC_Order_Item_Product('Product 1', -1, -20.00, -20.00, 0.0, 101)],
    25.00,
    [sale('sle-1', '$woocommerce-product-1-20', 1, 20.0), sale('sle-2', '$woocommerce-product-2-20', 1, 20.0)]
);
$result = run_partial($order, $api, 25.00);
$dels = calls_named($api, 'send_refund');

check('reports success', true, $result['success']);
check('one order-level DELETE for the remainder', 1, count($dels));
check('remainder amount is 5.00', 5.00, $dels[0][2] ?? -1);

// ------------------------- case 4: sales not ingested yet — asks for a retry

echo "\nHyros sales not ingested yet — must request a retry, send nothing\n";

[$order, $api] = scenario(
    [new WC_Order_Item_Product('Product 1', -1, -20.00, -20.00, 0.0, 101)],
    20.00,
    [] // lead has no sales yet
);
$result = run_partial($order, $api, 20.00);

check('not successful yet', false, $result['success']);
check('flags needs_retry', true, !empty($result['needs_retry']));
check('no refund calls made', 0, count(calls_named($api, 'refund_sales')) + count(calls_named($api, 'send_refund')));

// ------------------------- case 5: retries exhausted — falls back to order-level

echo "\nRetries exhausted — falls back to DELETE ?refundedAmount so nothing is lost\n";

[$order, $api] = scenario(
    [new WC_Order_Item_Product('Product 1', -1, -20.00, -20.00, 0.0, 101)],
    20.00,
    []
);
$order->meta['_hyros_refund_retry_2001'] = 3; // MAX_RETRIES reached
$result = run_partial($order, $api, 20.00);
$dels = calls_named($api, 'send_refund');

check('reports success (fallback)', true, $result['success']);
check('order-level DELETE with the full amount', 20.00, $dels[0][2] ?? -1);

// ------------------------- case 6: sales lookup fails (e.g. key lacks Get Sales role)

echo "\nGET /sales fails — falls back to order-level refund immediately\n";

[$order, $api] = scenario(
    [new WC_Order_Item_Product('Product 1', -1, -20.00, -20.00, 0.0, 101)],
    20.00,
    [],
    false
);
$result = run_partial($order, $api, 20.00);
$dels = calls_named($api, 'send_refund');

check('reports success (fallback)', true, $result['success']);
check('order-level DELETE with the full amount', 20.00, $dels[0][2] ?? -1);
check('no PUT /sales attempted', 0, count(calls_named($api, 'refund_sales')));

// ------------------------- case 7: amount-only refund (no line items)

echo "\nAmount-only refund (no line items) — straight to order-level\n";

[$order, $api] = scenario([], 15.00, [sale('sle-1', '$woocommerce-product-1-20', 1, 20.0)]);
$result = run_partial($order, $api, 15.00);
$dels = calls_named($api, 'send_refund');

check('reports success', true, $result['success']);
check('order-level DELETE with the amount', 15.00, $dels[0][2] ?? -1);
check('sales never queried', 0, count(calls_named($api, 'get_sales_for_email')));

// ------------------------- case 8: already-refunded sale is skipped

echo "\nSecond refund of the same product — must pick the un-refunded sale\n";

[$order, $api] = scenario(
    [new WC_Order_Item_Product('Product 1', -1, -20.00, -20.00, 0.0, 101)],
    20.00,
    [
        sale('sle-old', '$woocommerce-product-1-20', 1, 20.0, 20.0), // refunded earlier
        sale('sle-new', '$woocommerce-product-1-20', 1, 20.0),
    ]
);
$result = run_partial($order, $api, 20.00);
$puts = calls_named($api, 'refund_sales');

check('refunds the un-refunded duplicate', ['sle-new'], $puts[0][1] ?? []);

echo "\n" . ($failures === 0 ? "All checks passed.\n" : "$failures check(s) failed.\n");
exit($failures === 0 ? 0 : 1);
