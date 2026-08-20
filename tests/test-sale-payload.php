<?php
/**
 * Regression test for the Hyros /orders payload built by Hyros_Tracker::send_sale().
 *
 * Reproduces WooCommerce order #491520 from checkout.toponefutures.com, where a
 * $76.30 sale was reported to Hyros as $0. Run with:  php tests/test-sale-payload.php
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
function wc_get_order($id) { return null; }
function apply_filters($hook, $value) { return $value; }
/** Stand-in for WordPress' remove_accents(), covering the Latin-1 range. */
function remove_accents($string) {
    return strtr($string, [
        'á'=>'a','à'=>'a','â'=>'a','ä'=>'a','ã'=>'a','å'=>'a',
        'é'=>'e','è'=>'e','ê'=>'e','ë'=>'e',
        'í'=>'i','ì'=>'i','î'=>'i','ï'=>'i',
        'ó'=>'o','ò'=>'o','ô'=>'o','ö'=>'o','õ'=>'o',
        'ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u',
        'ñ'=>'n','ç'=>'c',
        'Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ñ'=>'N','Ü'=>'U','Ç'=>'C',
    ]);
}

class Hyros_Logger {
    public static function log(...$args): void {}
}

/** Captures the payload instead of calling Hyros. */
class Hyros_API {
    public array $sent = [];
    public function send_order(array $payload): array {
        $this->sent = $payload;
        return ['success' => true, 'hyros_id' => 'test', 'request_id' => 'test', 'error' => ''];
    }
    public function send_cart(array $payload): array {
        return ['success' => true, 'cart_id' => '', 'hyros_id' => '', 'request_id' => '', 'error' => ''];
    }
}

class WC_Order_Item_Product {
    public function __construct(
        private string $name,
        private int $qty,
        private float $subtotal,   // gross, before discount, excl. tax
        private float $total,      // net, after discount, excl. tax
        private float $tax,
        private int $product_id
    ) {}
    public function get_name(): string { return $this->name; }
    public function get_quantity(): int { return $this->qty; }
    public function get_subtotal(): float { return $this->subtotal; }
    public function get_total(): float { return $this->total; }
    public function get_total_tax(): float { return $this->tax; }
    public function get_product_id(): int { return $this->product_id; }
    public function get_product() { return null; }
}

class WC_Order {
    private array $meta = [];
    public function __construct(private int $id, private array $items, private float $discount_total, private float $total) {}
    public function get_id(): int { return $this->id; }
    public function get_items(): array { return $this->items; }
    public function get_total(): float { return $this->total; }
    public function get_discount_total(): float { return $this->discount_total; }
    public function get_shipping_total(): float { return 0.0; }
    public function get_currency(): string { return 'USD'; }
    public function get_billing_email(): string { return 'vanheesneel@gmail.com'; }
    public function get_billing_first_name(): string { return 'Neel'; }
    public function get_billing_last_name(): string { return 'Van Hees'; }
    public function get_billing_phone(): string { return '+320472936367'; }
    public function get_customer_ip_address(): string { return '2a02:a03f:87de:9901:551c:f2c6:8c53:9f1f'; }
    public function get_date_paid() { return null; }
    public function get_meta($key, $single = true) { return $this->meta[$key] ?? ''; }
    public function update_meta_data($key, $value): void { $this->meta[$key] = $value; }
    public function delete_meta_data($key): void { unset($this->meta[$key]); }
    public function save(): void {}
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
    printf("  %s %-58s expected=%-22s actual=%s\n",
        $ok ? 'PASS' : 'FAIL', $label,
        var_export($expected, true), var_export($actual, true));
}

function build_payload(WC_Order $order): array {
    $api = new Hyros_API();
    $m = new ReflectionMethod('Hyros_Tracker', 'send_sale');
    $m->invoke(null, $api, $order);
    return $api->sent;
}

// ------------------------------------------------- case 1: real order #491520
// Woo: Elite Daily | $50,000 | Tradovate/Ninjatrader — $218.00 x1, $141.70
// discount, line total $76.30. Coupon "2.0" = $130.80. Order total = $76.30.

echo "\nWooCommerce order #491520 — single line item, order total \$76.30\n";

$order = new WC_Order(
    491520,
    [new WC_Order_Item_Product('Elite Daily | $50,000 | Tradovate/Ninjatrader', 1, 218.00, 76.30, 0.0, 12345)],
    130.80,
    76.30
);
$payload = build_payload($order);
$item    = $payload['items'][0];

check('items[0].price is the GROSS unit price', 218.00, $item['price']);
check('items[0].itemDiscount is the line discount', 141.70, $item['itemDiscount'] ?? 0.0);
check('net Hyros will record equals the Woo order total',
    76.30, (float) $item['price'] - (float) ($item['itemDiscount'] ?? 0));
check('orderDiscount absent (already covered by itemDiscount)',
    false, array_key_exists('orderDiscount', $payload));

// --------------------------------- case 2: two products must not share a tag

echo "\nTwo distinct products in one order — product identity must differ\n";

$order2 = new WC_Order(
    999001,
    [
        new WC_Order_Item_Product('Elite Daily | $50,000 | Tradovate/Ninjatrader', 1, 218.00, 76.30, 0.0, 12345),
        new WC_Order_Item_Product('Activation Fee ACCESS', 1, 189.00, 189.00, 0.0, 67890),
    ],
    141.70,
    265.30
);
$payload2 = build_payload($order2);

check('the two line items carry different tags', true,
    ($payload2['items'][0]['tag'] ?? null) !== ($payload2['items'][1]['tag'] ?? null));
check('tag is not the shared literal $hyros-woo', false,
    ($payload2['items'][0]['tag'] ?? '') === '$hyros-woo');
check('undiscounted line keeps its full price', 189.00, $payload2['items'][1]['price']);
check('order net equals Woo order total', 265.30,
    array_sum(array_map(
        fn($i) => (float) $i['price'] - (float) ($i['itemDiscount'] ?? 0),
        $payload2['items']
    )));

// ------------------------- case 3: non-English catalogs (plugin ships to any store)

echo "\nNon-ASCII product names — the plugin runs on stores in any language\n";

function tag_for(string $name, float $price, int $product_id = 555): string {
    $order = new WC_Order(999002, [new WC_Order_Item_Product($name, 1, $price, $price, 0.0, $product_id)], 0.0, $price);
    return build_payload($order)['items'][0]['tag'];
}

check('accents are transliterated, not deleted',
    '$woocommerce-camiseta-nino-cafe-20', tag_for('Camiseta Niño Café', 20.00));
check('a non-Latin name still yields an identifying slug',
    '$woocommerce-product-777-1000', tag_for('日本語商品', 1000.00, 777));
check('two products differing only by accent stay distinct from each other',
    true, tag_for('Piñata', 10.00, 1) !== tag_for('Pinata Deluxe', 10.00, 2));

echo "\n" . ($failures === 0 ? "All checks passed.\n" : "$failures check(s) failed.\n");
exit($failures === 0 ? 0 : 1);
