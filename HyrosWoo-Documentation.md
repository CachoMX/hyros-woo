# HyrosWoo — Plugin Documentation

**Version:** 1.1.0
**Author:** Carlos Aragon
**License:** GPL-2.0+
**Text Domain:** `hyros-woo`

**Compatibility**

| Requirement | Minimum | Tested up to |
|---|---|---|
| WordPress | 5.8 | 6.8 |
| WooCommerce | 6.0 | 9.9 |
| PHP | 7.4 | — |
| HPOS (Custom Order Tables) | Yes — declared compatible | — |

---

## Table of Contents

1. [Overview](#1-overview)
2. [Architecture](#2-architecture)
3. [File Structure](#3-file-structure)
4. [Bootstrap & Lifecycle](#4-bootstrap--lifecycle)
5. [Settings & Configuration](#5-settings--configuration)
6. [Sale Tracking — `POST /orders`](#6-sale-tracking--post-orders)
7. [Add to Cart Tracking — `POST /carts` + `POST /clicks`](#7-add-to-cart-tracking--post-carts--post-clicks)
8. [Refund Tracking — `DELETE /orders`](#8-refund-tracking--delete-orders)
9. [Cancel / Failed Compensation](#9-cancel--failed-compensation)
10. [Subscription Renewals](#10-subscription-renewals)
11. [Tracking Script Injection](#11-tracking-script-injection)
12. [Retry Logic & Distributed Locks](#12-retry-logic--distributed-locks)
13. [Consent Gating](#13-consent-gating)
14. [Logging & Audit Trail](#14-logging--audit-trail)
15. [Order Meta Reference](#15-order-meta-reference)
16. [WordPress Options Reference](#16-wordpress-options-reference)
17. [Hyros API Client](#17-hyros-api-client)
18. [Security Model](#18-security-model)
19. [Uninstall](#19-uninstall)
20. [Special Note for the Hyros Team — Add to Cart Implementation Guide](#20-special-note-for-the-hyros-team--add-to-cart-implementation-guide)

---

## 1. Overview

HyrosWoo is a production-ready WooCommerce → Hyros server-side tracking plugin. It addresses gaps in the official Hyros WooCommerce integration:

| Gap in the official integration | HyrosWoo solution |
|---|---|
| Manual tracking-script install in theme `<head>` | Auto-injection from `wp_head` (toggleable) |
| WC Subscriptions renewals not tracked | Native `woocommerce_subscription_renewal_payment_complete` hook |
| Single-status tracking (race conditions) | Fires on `payment_complete`, `processing`, **and** `completed` with deduplication |
| Duplicate sales sent to Hyros | `_hyros_sale_tracked` order meta + in-process re-entry guard + DB lock |
| No visibility on what was sent | Custom `wp_hyros_woo_logs` table (90-day retention) + per-order WC notes |
| No abandoned-cart capture | Server-side `/carts` + `/clicks` for both logged-in users and guests |
| No refund tracking | `DELETE /orders` with multi-partial-refund support |
| No retry on transient failures | WP-Cron retries (3 attempts, exponential backoff, `Retry-After` aware) |
| No consent gating | Optional `Require Marketing Consent` toggle (GDPR-friendly) |

The plugin has **zero external dependencies** — it uses only WordPress core, WooCommerce core, and the Hyros REST API.

---

## 2. Architecture

```
┌──────────────────────────────────────────────────────────────────────┐
│                         WordPress / WooCommerce                       │
│                                                                       │
│  ┌──────────────┐   ┌──────────────┐   ┌───────────────────────────┐ │
│  │  Add-to-cart │   │ Order status │   │  Refund / Cancel / Fail   │ │
│  │   hook       │   │   hooks      │   │    hooks                  │ │
│  └──────┬───────┘   └──────┬───────┘   └────────────┬──────────────┘ │
│         │                  │                        │                 │
│         ▼                  ▼                        ▼                 │
│  ┌──────────────────────────────────────────────────────────────┐    │
│  │                     Hyros_Tracker                             │    │
│  │  - Consent gate / paid gate                                   │    │
│  │  - Re-entry guard (per request) + DB lock (per order, 15m)    │    │
│  │  - Build payloads (orders / carts / clicks / refunds)         │    │
│  │  - Schedule WP-Cron retries on transient failures             │    │
│  └────────────────────────┬─────────────────────────────────────┘    │
│                           ▼                                           │
│  ┌──────────────────────────────────────────────────────────────┐    │
│  │                       Hyros_API                               │    │
│  │  POST /orders   POST /carts   POST /clicks   DELETE /orders   │    │
│  │  GET /domains   GET /tracking-script   GET /user-info         │    │
│  └────────────────────────┬─────────────────────────────────────┘    │
│                           ▼                                           │
│                  https://api.hyros.com/v1/api/v1.0                    │
└──────────────────────────────────────────────────────────────────────┘
                            │
                            ▼
┌──────────────────────────────────────────────────────────────────────┐
│                       Hyros_Logger                                    │
│   custom table  wp_hyros_woo_logs  (preferred)                        │
│   fallback      wp_options 'hyros_woo_logs' (last 200 entries)        │
│   per-order     WooCommerce order notes (private)                     │
└──────────────────────────────────────────────────────────────────────┘
```

Four classes, single-responsibility:

| Class | Responsibility |
|---|---|
| `Hyros_Tracker` | All WC hooks, payload assembly, retries, locks, consent |
| `Hyros_API` | Thin REST client (one method per Hyros endpoint) |
| `Hyros_Settings` | Admin UI, AJAX validation, options sanitization |
| `Hyros_Logger` | Audit log (table + fallback), pruning, redaction |

---

## 3. File Structure

```
hyros-woo/
├── hyros-woo.php                    # Bootstrap, HPOS declaration, autoloader
├── readme.txt                       # WordPress plugin readme
├── uninstall.php                    # Cleanup on plugin deletion
├── includes/
│   ├── class-hyros-api.php          # REST client (367 lines)
│   ├── class-hyros-tracker.php      # All WC hooks + ATC + retries (1,222 lines)
│   ├── class-hyros-settings.php     # Admin page + AJAX (405 lines)
│   └── class-hyros-logger.php       # Audit log (494 lines)
└── admin/
    ├── views/
    │   └── settings-page.php        # Settings UI (418 lines)
    ├── js/
    │   └── hyros-woo-admin.js       # Admin JS (validate / select domain)
    ├── css/
    │   └── hyros-woo-admin.css      # Admin styles
    └── images/
        └── hyros-logo.svg
```

---

## 4. Bootstrap & Lifecycle

### `hyros-woo.php` (bootstrap)

```php
define('HYROS_WOO_VERSION', '1.1.0');
define('HYROS_WOO_API_BASE', 'https://api.hyros.com/v1/api/v1.0');

// 1. Declare HPOS compatibility (before WC loads)
add_action('before_woocommerce_init', function () {
    \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
        'custom_order_tables', HYROS_WOO_PLUGIN_FILE, true
    );
});

// 2. SPL autoloader for the four core classes
spl_autoload_register(function (string $class) { /* ... */ });

// 3. Activation / deactivation
register_activation_hook   (HYROS_WOO_PLUGIN_FILE, fn() => Hyros_Logger::on_activation());
register_deactivation_hook (HYROS_WOO_PLUGIN_FILE, fn() => Hyros_Logger::on_deactivation());

// 4. Init when WC is loaded
add_action('plugins_loaded', function () {
    if (!class_exists('WooCommerce')) {
        // admin notice and bail
        return;
    }
    Hyros_Logger::init();
    Hyros_Settings::init();
    Hyros_Tracker::init();
});
```

**Activation** creates the custom logs table (`wp_hyros_woo_logs`) and schedules the daily pruning cron.
**Deactivation** unschedules the pruning cron (the table itself is preserved until uninstall).
**Uninstall** drops the table and removes all options & order meta — see [Uninstall](#19-uninstall).

---

## 5. Settings & Configuration

### Admin page

`WooCommerce → Hyros` (`admin.php?page=hyros-woo`).

The page is rendered by `admin/views/settings-page.php` and contains:

1. **API Key** — text input. After validation, the key is masked (`abc1********wxyz`).
2. **Validate API Key** button — AJAX call to `GET /leads`; on success enables domain selector.
3. **Account Info card** — shows `userProfile` and `trueTrackingData` from `GET /user-info`:
   - Email, name, company, timezone
   - Inbound currency, attribution timeframe, sale grouping window
   - Recurring products, EU tracking, organic-source attribution rules
4. **Domain selector** — populated from `GET /domains` (whitelisted into `hyros_woo_allowed_domains`). Selecting a domain triggers `GET /tracking-script?domain=…` and stores the script in `hyros_woo_selected_script`.
5. **Six tracking toggles** (see below).
6. **Activity log** (last 20 entries, with a "View all" link).

### Six tracking toggles

| Option | Default | Effect |
|---|---|---|
| `hyros_woo_track_sales` | `yes` | Hooks `payment_complete` / `processing` / `completed` / `refunded` / `partially_refunded` / `cancelled` / `failed` |
| `hyros_woo_track_subscriptions` | `yes` | Hooks `woocommerce_subscription_renewal_payment_complete` |
| `hyros_woo_track_add_to_cart` | `yes` | Hooks `woocommerce_add_to_cart`, enqueues checkout JS, registers AJAX endpoints |
| `hyros_woo_send_cogs` | `no` | Adds `costOfGoods` per line item from `_wc_cog_cost` product meta |
| `hyros_woo_inject_script` | `yes` | Adds `wp_head` action to inject the Hyros tracking script |
| `hyros_woo_require_consent` | `no` | Skips all tracking unless WC marketing consent is recorded |

### API key sources (priority order)

```php
defined('HYROS_API_KEY') && !empty(HYROS_API_KEY)   // 1. wp-config.php constant
    ? HYROS_API_KEY
    : get_option('hyros_woo_api_key', '');           // 2. saved setting
```

Defining the key as a PHP constant keeps it out of the database — recommended for production.

### Tracking-script validation

`Hyros_Settings::is_valid_tracking_script()` only accepts scripts whose `<script src="…">` host is:
- `hyros.com` (any subdomain), OR
- a domain present in the account's `hyros_woo_allowed_domains` whitelist.

This prevents an attacker with admin access from injecting arbitrary JS via the option.

---

## 6. Sale Tracking — `POST /orders`

### Hooks

```php
add_action('woocommerce_payment_complete',        [self::class, 'handle_sale']);
add_action('woocommerce_order_status_processing', [self::class, 'handle_sale']);
add_action('woocommerce_order_status_completed',  [self::class, 'handle_sale']);
```

Firing on three statuses ensures **no sale is missed** regardless of the payment gateway's flow (Stripe instantly hits `processing`; manual gateways hit `completed`; some gateways only fire `payment_complete`).

### Deduplication chain

```
1.  Per-request re-entry guard       self::$in_progress[$order_id]
2.  Order meta sale flag             _hyros_sale_tracked === 'yes'
3.  Distributed DB lock              add_option(_hyros_track_lock_{id}, time(), …, false)
                                     auto-expires after 900s (ORDER_LOCK_TTL)
```

Layer 1 prevents the same PHP request from double-firing when `$order->save()` triggers status-change hooks. Layer 2 ensures the same order is never sent twice across requests. Layer 3 prevents two concurrent web workers from simultaneously processing the same order (e.g., gateway IPN + status-change hook racing).

### Payload (`POST /orders`)

```json
{
  "email": "buyer@example.com",
  "items": [
    {
      "name": "Premium Coaching",
      "price": 197.00,
      "externalId": "42",
      "quantity": 1,
      "tag": "$woocommerce-premium-coaching-197",
      "taxes": 19.70,
      "itemDiscount": 10.00,
      "costOfGoods": 35.00
    }
  ],
  "orderId": "12345",
  "date": "2026-04-27T18:30:00+00:00",
  "currency": "USD",
  "firstName": "Carlos",
  "lastName": "Aragon",
  "phoneNumbers": ["+15551234567"],
  "leadIps": ["203.0.113.45"],
  "shippingCost": 12.50,
  "cartId": "d49b708b-8e2e-4a91-a8b0-fa901a8c0a7f",
  "externalSubscriptionId": "999"
}
```

| Field | Source | Notes |
|---|---|---|
| `email` | `$order->get_billing_email()` | Required by Hyros |
| `items[].price` | `(item_subtotal / qty)` rounded to 4dp | Gross per-unit price, before discount, excluding tax. Hyros records `price − itemDiscount`, so sending the discounted total here subtracts the discount twice |
| `items[].taxes` | `(item_total_tax / qty)` rounded | Per-unit, only if > 0 |
| `items[].itemDiscount` | `(subtotal − total) / qty` rounded | Per-unit, only if > 0 |
| `items[].costOfGoods` | `_wc_cog_cost` product meta | Only if `hyros_woo_send_cogs` enabled and value > 0 |
| `items[].tag` | `$woocommerce-<name-slug>-<gross unit price>` | The tag is the product's identity in Hyros, not a source marker: items sharing a tag collapse into one product. Matches the official Hyros WooCommerce integration so sales land on products the account already has. Override with the `hyros_woo_product_tag` filter |
| `orderId` | `(string) $order->get_id()` | Used as Hyros' externalOrderId — also keys the `DELETE /orders` refund call |
| `date` | `$order->get_date_paid()->format('c')` | ISO 8601 — falls back to `gmdate('c')` if absent |
| `phoneNumbers` | `$order->get_billing_phone()` | Stripped to `[0-9+]`; only sent if non-empty |
| `leadIps` | `$order->get_customer_ip_address()` | Helps Hyros match to ad clicks |
| `shippingCost` | `$order->get_shipping_total()` | Only if > 0 |
| `orderDiscount` | not sent | `get_discount_total()` is the same coupon money already reported per line as `itemDiscount`, and Hyros subtracts both |
| `cartId` | `_hyros_cart_id` order meta or fresh `/carts` send | Links the sale to the previously-tracked cart |
| `externalSubscriptionId` | `WC_Subscription` parent ID | Only for renewal orders |

### Response handling

`Hyros_API::send_order()` parses the response body's `message` array (Hyros returns IDs as `"key: value"` strings) and surfaces:

- `external_order_id` (preferred) → stored as `hyros_id` in the log
- `order_id`, `lead_id`, or `request_id` (fallbacks)

On success: `_hyros_sale_tracked = 'yes'`, `_hyros_tracked = 'yes'`, retry counter cleared, queued refunds flushed.

On failure: payload is **not** persisted; a retry is scheduled (if retryable) — see [Retry Logic](#12-retry-logic--distributed-locks).

---

## 7. Add to Cart Tracking — `POST /carts` + `POST /clicks`

This is the centerpiece of HyrosWoo. **A dedicated implementation guide for the Hyros team is in [Section 20](#20-special-note-for-the-hyros-team--add-to-cart-implementation-guide).** This section documents what the plugin does internally.

### Hooks (registered when `hyros_woo_track_add_to_cart === 'yes'`)

```php
add_action('woocommerce_add_to_cart',           [self::class, 'handle_add_to_cart'],   10, 6);
add_action('woocommerce_checkout_order_created', [self::class, 'capture_cart_events'], 10, 1);
add_action('wp_enqueue_scripts',                [self::class, 'enqueue_checkout_script']);
add_action('wp_ajax_hyros_capture_abandoned_cart',        [self::class, 'ajax_capture_abandoned_cart']);
add_action('wp_ajax_nopriv_hyros_capture_abandoned_cart', [self::class, 'ajax_capture_abandoned_cart']);
```

### Two-track flow

The plugin handles **logged-in users** and **guests** differently because the email is known at different times:

```
                  Add to Cart (any user)
                          │
                          ▼
            ┌─────────────────────────────┐
            │   handle_add_to_cart()      │
            │   - Append event to         │
            │     WC()->session           │
            │     ['hyros_cart_events']   │
            └─────────────┬───────────────┘
                          │
              ┌───────────┴───────────┐
              ▼                       ▼
     [Logged-in user]            [Guest user]
     email known now              email unknown
              │                       │
              ▼                       ▼
   send_cart_for_email()    Wait for checkout page
     POST /clicks                  (#billing_email blur)
     POST /carts                       │
              │                       ▼
              ▼              AJAX hyros_capture_abandoned_cart
   hyros_cart_id stored        send_cart_for_email()
   in WC session                 POST /clicks  + POST /carts
                                     │
                                     ▼
                          hyros_cart_id stored in session
              │                       │
              └───────────┬───────────┘
                          ▼
            woocommerce_checkout_order_created
            capture_cart_events()
            - moves hyros_cart_id   → order meta _hyros_cart_id
            - moves cart_events     → order meta _hyros_cart_events

                          ▼
              On payment / status change
              handle_sale() → maybe_send_cart()
            - if _hyros_cart_id exists: reuse
            - else: POST /carts now and capture id
              POST /orders with cartId
```

### `handle_add_to_cart` — session-level capture

Every add-to-cart appends one entry to `WC()->session['hyros_cart_events']`:

```php
[
  'product_id' => 42,
  'name'       => 'Premium Coaching',
  'price'      => 197.0,         // wc_get_price_excluding_tax
  'quantity'   => 1,
  'sku'        => 'COACH-01',
  'timestamp'  => 1714232400,
]
```

**Logged-in shortcut:** if the user is authenticated AND no `hyros_cart_id` is yet stored in the session, it immediately calls `send_cart_for_email($user->user_email)` to fire `/clicks` + `/carts`. The `hyros_cart_id` guard ensures we send only **once per session**, not on every add.

### `send_cart_for_email()` — server-side click + cart

This single private method is reused by both the logged-in and guest paths.

**Step 1 — `POST /clicks`** so Hyros can attribute the lead to a traffic source. (For logged-in users the JS tracker never fires; for guests we have the browser context from the AJAX request.)

```json
{
  "email":       "buyer@example.com",
  "referrerUrl": "https://google.com/...",
  "sessionId":   "<WC_session->get_customer_id()>",
  "tag":         "!clicked-atc",
  "isOrganic":   true,
  "date":        "2026-04-27T18:30:00+00:00",
  "ip":          "203.0.113.45",
  "userAgent":   "Mozilla/5.0 …"
}
```

IP is resolved server-side via `WC_Geolocation::get_ip_address()` (handles Cloudflare, X-Forwarded-For). **Client-supplied IP is never trusted.**

**Step 2 — `POST /carts`** with the current `WC()->cart` contents:

```json
{
  "email":    "buyer@example.com",
  "items":    [{ "name": "...", "price": 197.0, "quantity": 1, "externalId": "42", "sku": "COACH-01" }],
  "date":     "2026-04-27T18:30:00+00:00",
  "currency": "USD"
}
```

On success, Hyros returns the `external_cart_id`, which is stored in `WC()->session['hyros_cart_id']`.

If `/clicks` fails, `/carts` is **not** attempted (no point sending a cart that can't be attributed). Both failures are logged.

### Guest checkout JS (`enqueue_checkout_script`)

When `is_checkout()`, the plugin enqueues a small inline jQuery script (no external file). It exposes `hyrosCheckout` via `wp_localize_script`:

```js
hyrosCheckout = {
  ajaxUrl:      "/wp-admin/admin-ajax.php",
  nonce:        "<wp_create_nonce('hyros_checkout_nonce')>",
  captureToken: "<HMAC-SHA256(cartHash + '|' + sessionId, captureSecret)>",
  cartHash:     "<WC()->cart->get_cart_hash()>"
}
```

The script:
1. Listens for `blur` on `#billing_email` (delegated, since WC re-renders fragments).
2. Re-checks on `updated_checkout` event.
3. Fires immediately on page load if the field is already populated (autofill, returning visitors).
4. Sends one AJAX `POST` per session (guarded by `var sent = false` flag, reset on network failure).

### `ajax_capture_abandoned_cart` — guest endpoint

Steps performed (in order):

1. **Nonce check** — `check_ajax_referer('hyros_checkout_nonce', 'nonce')`.
2. **Email validation** — `is_email()`.
3. **Cart non-empty check.**
4. **HMAC capture-token validation** — token must equal `hash_hmac('sha256', cart_hash + '|' + session_id, capture_secret)` where `capture_secret` is generated and stored in WC session on first checkout view. **Prevents bots and replay from arbitrary IPs.**
5. **Rate limit (cooldown)** — 300s `set_transient` per session+IP+email AND per-IP. If either transient is set, return error.
6. **Distributed lock** — `add_option('hyros_guest_capture_lock_…', time(), '', false)` with 30s TTL. Prevents the same email from triggering parallel cart sends from the same browser.
7. **Idempotency check** — if `WC()->session['hyros_cart_id']` is already set, return `{already_tracked: true}` and exit.
8. **Apply cooldown transients** (after lock acquired, before `/clicks` + `/carts`).
9. **Call `send_cart_for_email($email, $click_context)`.**
10. **Always release lock** in `finally {}`.

Click context fields accepted from the client (sanitized): `referrer_url`, `user_agent`. **IP is never trusted from the client** — always resolved server-side.

### `capture_cart_events` — checkout → order meta handoff

Fires on `woocommerce_checkout_order_created`. Moves data from the (volatile) WC session into permanent order meta:

| Session key | Order meta |
|---|---|
| `hyros_cart_id` | `_hyros_cart_id` |
| `hyros_cart_events` | `_hyros_cart_events` |

The session events are then cleared. This is critical because the `handle_sale()` path runs in a different request (gateway IPN, async cron) where `WC()->session` may be empty.

### `maybe_send_cart` — last-chance cart send before sale

Called from `send_sale()` immediately before building the `POST /orders` payload:

```
1. If _hyros_cart_id already set → reuse it (skip /carts).
2. Else if _hyros_cart_events exists → POST /carts now,
   store returned id, clear events from order meta.
3. Either way → return the cart_id (or '') for inclusion in the order payload.
```

This guarantees that the order is **always linked to its cart** — even if the cart was never sent during the browsing phase (e.g., a logged-in user who started checkout immediately, or a guest who never touched the email field before completing checkout).

### Storage location — why WC session vs order meta?

| Phase | Storage | Why |
|---|---|---|
| Pre-checkout | `WC()->session['hyros_cart_events']` | Order doesn't exist yet; session lives for the visit |
| Checkout submit | `_hyros_cart_events` order meta | Permanent, survives async sale tracking |
| After cart sent to Hyros | `WC()->session['hyros_cart_id']` then `_hyros_cart_id` order meta | Avoids re-sending |

---

## 8. Refund Tracking — sale-level `PUT /sales` with order-level fallback

### Hooks

```php
add_action('woocommerce_order_status_refunded',    [self::class, 'handle_refund'], 10, 1);
add_action('woocommerce_order_partially_refunded', [self::class, 'handle_refund'], 10, 2);
```

The 2-arg variant supplies a `$refund_id` for **partial** refunds — full refunds use `$refund_id = 0`.

### Why sale-level (1.2.0)

`DELETE /orders/{id}?refundedAmount=X` distributes X **evenly across every sale of the order** (verified empirically against the live API: a $20 refund on a 5×$20 order left `refunded: 4` on each of the 5 sales). For a partial refund of one product that misattributes the refund. Since 1.2.0 a partial refund with line items is instead applied to the exact sales of the refunded products.

### Partial refund flow (`send_partial_refund()`)

1. `collect_refund_lines()` reads the `WC_Order_Refund` line items and rebuilds each product's Hyros tag with the same `build_product_tag()` used at sale time (tag = product identity in Hyros).
2. `resolve_order_sales()` calls `GET /sales?emails={billing_email}` and keeps the sales whose `orderId` matches this WC order, grouped by product tag.
3. Per refunded line:
   * whole line refunded → `PUT /sales?ids={saleId}&isRefunded=true` (Hyros refunds the sale's own price),
   * partial quantity (e.g. 2 of 5 units) → `PUT /sales?ids={saleId}&isRefunded=true&refundedAmount={amount}` (verified: leaves `refunded: {amount}` on the sale without touching its price).
4. Whatever the sale-level pass could not cover (shipping, fees, unmatched lines) goes out as `DELETE /orders/{id}?refundedAmount={remainder}`.

### Fallbacks — a refund is never lost

| Situation | Behavior |
|---|---|
| Refund has no line items (amount-only) | Order-level `DELETE ?refundedAmount` directly |
| `GET /sales` fails (key lacks the *Get Sales* role, transport error) | Order-level fallback immediately |
| Sales not ingested yet (Hyros order creation is async) | One-shot WP-Cron retry (`hyros_woo_retry_refund`, 300 s) up to 3 attempts, then order-level fallback |
| `PUT /sales` fails | Order-level fallback with the full amount |
| Sale already refunded (second refund of the same product) | Picks the next un-refunded sale with the same tag |

Disable sale-level refunds entirely (restores pre-1.2.0 behavior) with:

```php
add_filter('hyros_woo_item_level_refunds', '__return_false');
```

### Multi-partial-refund safety

`_hyros_refund_event_ids` (array of refund IDs) deduplicates partial refunds — the same `WC_Order_Refund` is never sent twice.
`_hyros_refunded_total` (cumulative amount) is used to compute the **delta** for full refunds arriving after partials.

### Pending refund queue

If a refund happens **before** the sale was tracked (rare race condition), `queue_pending_refund()` stores the refund ID in `_hyros_pending_refunds`. After `send_sale()` succeeds, `flush_pending_refunds()` retries each queued refund.

### Requests

```
PUT    /v1/api/v1.0/sales?ids={saleIds}&isRefunded=true[&refundedAmount={amount}][&refundedDate={iso}]
DELETE /v1/api/v1.0/orders/{orderId}?refundedAmount={amount}     (fallback / remainder)
```

`orderId` is the same WC order ID used in `POST /orders`. Sale ids come from `GET /sales?emails=`. No body on either call.

---

## 9. Cancel / Failed Compensation

### Hooks

```php
add_action('woocommerce_order_status_cancelled', [self::class, 'handle_canceled_or_failed']);
add_action('woocommerce_order_status_failed',    [self::class, 'handle_canceled_or_failed']);
```

### Logic

If a sale was **already tracked** but the order later moves to `cancelled` or `failed` **without** being paid, `handle_canceled_or_failed()` reverses the sale by sending a `DELETE /orders` for the full order total.

Idempotency guard: `_hyros_cancel_failed_compensated = 'yes'` on success.

This catches the edge case where a gateway briefly marks an order `processing` (firing the sale hook) and then later flips it to `failed` after a chargeback or fraud check.

---

## 10. Subscription Renewals

### Hook

```php
add_action('woocommerce_subscription_renewal_payment_complete',
    [self::class, 'handle_renewal'], 10, 1);
```

### Logic

```php
public static function handle_renewal($subscription): void {
    $last_order = $subscription->get_last_order('all');
    if ($last_order) {
        self::handle_sale($last_order->get_id());
    }
}
```

The renewal order is treated as a fresh sale, with `externalSubscriptionId` (parent subscription ID) added to the payload so Hyros can group it with the original purchase.

`is_renewal_order()` uses `wcs_order_contains_renewal()` (WC Subscriptions helper) when available.

---

## 11. Tracking Script Injection

### Hook

```php
if ($inject_script) {
    add_action('wp_head', [self::class, 'inject_script'], 1);
}
```

### Logic

```php
public static function inject_script(): void {
    if (!self::has_tracking_consent())  return;

    $script = get_option('hyros_woo_selected_script', '');
    if (empty($script)) return;

    if (!Hyros_Settings::is_valid_tracking_script($script)) return;

    echo $script . "\n";
}
```

### Where the script comes from

When the admin saves their domain selection, the plugin calls `GET /tracking-script?domain=…` server-side and stores the returned HTML in `hyros_woo_selected_script`. The script is **never derived from client input** — it is fetched fresh from Hyros during admin save and re-validated at injection time.

`is_valid_tracking_script()` parses the HTML and accepts only `<script src="…">` tags whose host is on `*.hyros.com` or in the account's `hyros_woo_allowed_domains` whitelist.

### When to disable

Toggle off `hyros_woo_inject_script` if:
- The store uses a tag manager (GTM) and the script is loaded there.
- The theme already has the script in `header.php` (legacy installs).
- A page-builder plugin manages global scripts.

---

## 12. Retry Logic & Distributed Locks

### Constants

```php
const MAX_RETRIES        = 3;
const RETRY_DELAYS       = [300, 1800, 7200]; // 5m, 30m, 2h
const ORDER_LOCK_TTL     = 900;               // 15 minutes
const GUEST_CAPTURE_COOLDOWN = 300;           // 5 minutes
```

### Retry classification

`Hyros_API::request()` classifies HTTP responses as `retryable` or not:

| Status | Retryable? |
|---|---|
| 200, 201, 204 | n/a (success) |
| 408, 425, 429, 500, 502, 503, 504 | **Yes** |
| 400, 401, 403, 404, 422 | **No** (non-retryable) |
| Network error / WP_Error | **Yes** |

`Retry-After` headers (seconds or HTTP date) are honored — `get_retry_delay_from_result()` overrides the default exponential backoff when present.

### Retry flow

```
send_sale() fails
    │
    ├── retryable=true  → wp_schedule_single_event('hyros_woo_retry_sale', delay=300s)
    │                     ↓ (cron fires later)
    │                     handle_retry()
    │                       ├── increment _hyros_retry_count
    │                       ├── if count < 3 → schedule next (delay=1800s, 7200s)
    │                       └── if count == 3 → log 'sale_permanently_failed',
    │                                            set _hyros_retry_count='non_retryable'
    │
    └── retryable=false → log 'sale_failed_non_retryable', stop
```

The retry handler `handle_retry` is **always registered** (even when sales tracking is toggled off) so already-scheduled retries continue to run after a settings change.

### Distributed order lock

```php
private static function acquire_order_lock(int $order_id): bool {
    $key = '_hyros_track_lock_' . $order_id;
    $existing = (int) get_option($key, 0);
    if ($existing > 0 && (time() - $existing) < self::ORDER_LOCK_TTL) {
        return false;  // Lock held by another request
    }
    delete_option($key);
    return add_option($key, time(), '', false);  // autoload=false
}
```

`add_option()` with `autoload=false` is atomic (uses `INSERT … ON DUPLICATE KEY UPDATE` semantics in `wp_options`). The 900s TTL ensures locks self-heal after a fatal PHP error or worker crash.

---

## 13. Consent Gating

### Option

```php
$require_consent = get_option('hyros_woo_require_consent', 'no') === 'yes';
```

### Check

`has_tracking_consent()` returns `true` if either:
1. The toggle is **off** (default — backwards-compatible).
2. The toggle is **on** AND `wc_string_to_bool($order->get_meta('_marketing_optin'))` is true, OR the user's WC customer record has marketing consent recorded.

### Effects when consent is missing

| Action | Behavior |
|---|---|
| `wp_head` script injection | Skipped — no third-party script in `<head>` |
| `handle_sale` | Logged as `sale_skipped_no_consent`, retried later when consent recorded |
| `handle_add_to_cart` | Returns immediately — nothing captured into session |
| `enqueue_checkout_script` | Not enqueued — guest capture script absent |
| `send_cart_for_email` | Returns `{success: false, error: 'consent not granted'}` |

Refunds are **not** consent-gated — if a sale was tracked while consent was granted, a later refund still fires regardless of consent withdrawal. (Correct behavior: refunds correct prior data.)

---

## 14. Logging & Audit Trail

### Three storage layers

```
┌──────────────────────────────────────────────────────────────┐
│ 1. wp_hyros_woo_logs  (custom table, preferred)              │
│    - event_id (PK)                                           │
│    - order_id (indexed)                                      │
│    - event       e.g. 'sale_tracked', 'cart_failed'          │
│    - status      'success' | 'failure' | 'info'              │
│    - email                                                   │
│    - detail      free-form text                              │
│    - meta        JSON (items_summary, totals, hyros_id, …)   │
│    - created_at  (indexed)                                   │
└──────────────────────────────────────────────────────────────┘
                       fallback when table is missing
                       (multisite, broken installs)
                       ▼
┌──────────────────────────────────────────────────────────────┐
│ 2. wp_options['hyros_woo_logs']                              │
│    Last 200 entries (FIFO)                                   │
└──────────────────────────────────────────────────────────────┘

In addition, every order-scoped event is also written as a
WooCommerce private order note for in-context visibility.
```

### Retention

Daily WP-Cron job `hyros_woo_logs_prune` deletes rows older than **90 days** from the custom table. The `wp_options` fallback is naturally bounded at 200 entries.

### Event labels

| Event | Layer | Meaning |
|---|---|---|
| `sale_tracked` | order + global | `POST /orders` succeeded |
| `sale_failed` | order + global | `POST /orders` failed (will retry if retryable) |
| `sale_retry_scheduled` | order | WP-Cron retry scheduled |
| `sale_skipped_no_consent` | order | Marketing consent missing |
| `sale_skipped_unpaid` | order | Order is not paid |
| `sale_failed_non_retryable` | order + global | 4xx error — won't retry |
| `sale_permanently_failed` | order + global | Gave up after 3 retries |
| `sale_reversed_after_cancel` | order | Cancelled/failed unpaid order reversed via DELETE |
| `cart_tracked` | order or global | `POST /carts` succeeded |
| `cart_failed` | order or global | `POST /carts` failed |
| `cart_captured` | order | `_hyros_cart_events` saved at checkout |
| `click_failed` | global | `POST /clicks` failed |
| `abandoned_cart_failed` | global | Guest AJAX capture endpoint failed |
| `refund_tracked` | order + global | `DELETE /orders` succeeded |
| `refund_failed` | order + global | `DELETE /orders` failed |
| `refund_queued` | order | Refund queued until sale is tracked |

### Redaction

The logger redacts the API key from any logged URL or detail string before persisting (regex strips `Bearer …`, `?key=…`, `API-Key: …`).

---

## 15. Order Meta Reference

| Meta key | Type | Set by | Purpose |
|---|---|---|---|
| `_hyros_sale_tracked` | `'yes'` | `send_sale()` | Sale dedup flag (v1.1.0) |
| `_hyros_tracked` | `'yes'` | `send_sale()` | Sale dedup flag (legacy v1.0.0 — kept for back-compat) |
| `_hyros_retry_count` | int / `'non_retryable'` | `handle_retry()` | Retry counter |
| `_hyros_cart_id` | string | `capture_cart_events()` / `maybe_send_cart()` | Hyros' `external_cart_id` |
| `_hyros_cart_events` | array | `capture_cart_events()` | Pending ATC events to send before sale |
| `_hyros_refund_event_ids` | int[] | `handle_refund()` | Processed partial refund IDs (dedup) |
| `_hyros_refunded_total` | string (decimal) | `handle_refund()` | Cumulative refunded amount sent |
| `_hyros_last_refund_at` | ISO 8601 | `handle_refund()` | Timestamp of last refund |
| `_hyros_cancel_failed_compensated` | `'yes'` | `handle_canceled_or_failed()` | Cancel-reversal idempotency |
| `_hyros_pending_refunds` | array | `queue_pending_refund()` | Refunds awaiting sale tracking |

All meta is HPOS-aware (read/written via `$order->get_meta()` / `update_meta_data()` / `save()`).

---

## 16. WordPress Options Reference

| Option | Type | Default | Description |
|---|---|---|---|
| `hyros_woo_api_key` | string | `''` | Hyros API key (overridden by `HYROS_API_KEY` constant) |
| `hyros_woo_selected_domain` | string | `''` | Selected domain for tracking script |
| `hyros_woo_selected_script` | string | `''` | Cached tracking script HTML (server-fetched, validated) |
| `hyros_woo_allowed_domains` | string[] | `[]` | Whitelist from `GET /domains` |
| `hyros_woo_account_info` | array | `[]` | Cached `GET /user-info` response |
| `hyros_woo_track_sales` | `'yes' \| 'no'` | `'yes'` | Master toggle for sales/refunds/cancel/fail |
| `hyros_woo_track_subscriptions` | `'yes' \| 'no'` | `'yes'` | Track WC Subscriptions renewals |
| `hyros_woo_track_add_to_cart` | `'yes' \| 'no'` | `'yes'` | Track ATC + abandoned cart capture |
| `hyros_woo_send_cogs` | `'yes' \| 'no'` | `'no'` | Include `costOfGoods` in line items |
| `hyros_woo_inject_script` | `'yes' \| 'no'` | `'yes'` | Auto-inject script into `<head>` |
| `hyros_woo_require_consent` | `'yes' \| 'no'` | `'no'` | Consent-gate all tracking |
| `hyros_woo_logs` | array | `[]` | Last 200 log entries (fallback only) |
| `hyros_woo_permanently_failed_count` | int | `0` | Counter shown in admin badge |
| `_hyros_track_lock_{order_id}` | int (timestamp) | — | Distributed lock for sale tracking |
| `hyros_guest_capture_lock_{hash}` | int | — | Lock for guest AJAX capture |

Transients (auto-expiring):
- `hyros_guest_capture_{hash}` — 300s cooldown (per session+email+IP)
- `hyros_guest_capture_ip_{hash}` — 300s cooldown (per IP, anti-bot rotation)

---

## 17. Hyros API Client

### `Hyros_API` class

| Method | HTTP | Endpoint | Used by |
|---|---|---|---|
| `send_order(payload)` | POST | `/orders` | `handle_sale`, `handle_retry` |
| `send_refund(orderId, amount)` | DELETE | `/orders/{id}?refundedAmount={n}` | `handle_refund`, `handle_canceled_or_failed` |
| `send_click(payload)` | POST | `/clicks` | `send_cart_for_email` |
| `send_cart(payload)` | POST | `/carts` | `send_cart_for_email`, `maybe_send_cart` |
| `get_domains()` | GET | `/domains` | Settings: domain selector |
| `get_tracking_script(domain)` | GET | `/tracking-script?domain=…` | Settings: cache script on save |
| `get_account_info()` | GET | `/user-info` | Settings: account info card |
| `validate_key(key)` (static) | GET | `/leads` | Settings: AJAX validate API key |

### Auth header

```
API-Key: <api_key>
Content-Type: application/json
Accept: application/json
```

### Base URL

```php
HYROS_WOO_API_BASE = 'https://api.hyros.com/v1/api/v1.0';
```

### Response normalization

Every method returns a uniform array:

```php
[
  'success'     => bool,
  'error'       => string,         // empty on success
  'status_code' => int,
  'retryable'   => bool,
  'retry_after' => int,            // seconds, 0 if not present
  // method-specific fields:
  'hyros_id'    => string,         // /orders, /carts
  'request_id'  => string,
  'cart_id'     => string,         // /carts
  'data'        => array,          // raw decoded body (for debugging)
]
```

### `extract_hyros_id()` parser

Hyros returns IDs inside `body.message[]` as strings like `"external_cart_id: d49b…"` and `"request_id: 78c6…"`. The parser builds a key→value map and returns the first match in priority order:

```
external_cart_id  →  external_order_id  →  order_id  →  lead_id  →  request_id
```

This single helper is reused by `send_order()` and `send_cart()` so both endpoints surface the most useful Hyros entity ID consistently.

### Timeouts

```
timeout      = 15s
sslverify    = true
redirection  = 0    (Hyros API should never redirect)
```

---

## 18. Security Model

| Surface | Protection |
|---|---|
| API key in DB | Optional override via `HYROS_API_KEY` PHP constant; masked in admin UI; redacted from logs |
| Tracking-script option | Validated against `hyros.com` + account domain whitelist on every save AND every render |
| Admin AJAX (validate key, save settings) | `current_user_can('manage_woocommerce')` + WP nonce |
| Guest cart-capture AJAX | WP nonce + HMAC-SHA256 capture token bound to session+cart hash + per-IP/per-email cooldown + DB lock |
| Client IP | Always resolved server-side via `WC_Geolocation::get_ip_address()` — client-supplied IP rejected |
| User input in payloads | Sanitized via `sanitize_email`, `sanitize_text_field`, `esc_url_raw`, `wp_unslash`, `is_email`, regex |
| SQL | All DB writes use `$wpdb->insert/update/delete` with placeholders or `add_option/update_option` |
| Output | No echoed user input — only server-fetched & validated tracking-script content is echoed in `wp_head` |
| Webhooks/IPN | None — plugin only consumes WC's internal hooks |

**HPOS-safe.** All order reads/writes go through `wc_get_order()` and `$order->get_meta()` / `update_meta_data()` / `save()`.

---

## 19. Uninstall

`uninstall.php` runs only when the user clicks **Delete** on the plugin (not on deactivation):

```sql
-- 1. Drop the custom logs table
DROP TABLE IF EXISTS wp_hyros_woo_logs;

-- 2. Delete every plugin option (all 10 wp_options keys)
DELETE FROM wp_options WHERE option_name LIKE 'hyros_woo_%';
DELETE FROM wp_options WHERE option_name LIKE '_hyros_track_lock_%';

-- 3. Delete every order meta key (legacy postmeta + HPOS table)
DELETE FROM wp_postmeta            WHERE meta_key LIKE '_hyros_%';
DELETE FROM wp_wc_orders_meta      WHERE meta_key LIKE '_hyros_%';
```

**Activation/deactivation do not touch data** — only uninstall does.

---

## 20. Special Note for the Hyros Team — Add to Cart Implementation Guide

This section is a self-contained guide for replicating HyrosWoo's Add to Cart tracking inside the official Hyros WooCommerce integration.

### 20.1 Why ATC matters

Hyros' attribution model improves substantially when carts are tracked:

- The **cart event** is what Hyros uses to bind a lead's email to a session (especially for guest checkouts).
- The **click event** (`!clicked-atc`) is what Hyros uses to attribute the lead to the original ad/source.
- The **`cartId`** in the order payload links the eventual sale to its full pre-purchase journey.

Without these, Hyros can only attribute customers who arrive with a known email or who complete checkout in the same session as the click that brought them.

### 20.2 Two distinct flows

There is no "one ATC implementation." Logged-in users and guests are fundamentally different because the **email is known at different times**:

| User type | Email known at | Implementation |
|---|---|---|
| **Logged-in** | Add-to-cart time | Server-side immediate `/clicks` + `/carts` |
| **Guest** | Checkout email field | Client-side JS triggers AJAX → server `/clicks` + `/carts` |

A correct ATC implementation **must handle both**.

### 20.3 Logged-in user flow

```
User clicks "Add to cart"
        │
        ▼
WC fires woocommerce_add_to_cart hook (6 args)
        │
        ▼
Plugin appends event to WC()->session['hyros_cart_events']
        │
        ▼
If first add this session AND no hyros_cart_id stored:
  - Build /clicks payload from $_SERVER (referrer, IP, UA)
    - email     = wp_get_current_user()->user_email
    - tag       = '!clicked-atc'
    - isOrganic = true
    - sessionId = WC()->session->get_customer_id()
  - POST /clicks
  - If success → POST /carts with WC()->cart contents
  - Store returned cart_id in WC()->session['hyros_cart_id']
```

The `hyros_cart_id` guard ensures we send `/clicks` + `/carts` **once per session**, not on every add. Subsequent adds just append to the events list — the cart payload is refreshed at sale time via `maybe_send_cart()`.

### 20.4 Guest user flow

The guest doesn't give us an email until they reach the checkout page, so we capture in two phases:

#### Phase A — In-browser (every cart change)

The plugin enqueues this inline JS only on `is_checkout()`:

```js
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
            capture_token: hyrosCheckout.captureToken,
            cart_hash:     hyrosCheckout.cartHash,
            referrer_url:  document.referrer || window.location.href,
            user_agent:    navigator.userAgent
        }, function(response) {
            if (!response || response.success !== true) sent = false;
        }).fail(function() { sent = false; });
    }
    $(document).ready(function() {
        $(document.body).on('blur.hyrosWoo', '#billing_email', function() {
            captureCart($.trim($(this).val()));
        });
        $(document.body).on('updated_checkout.hyrosWoo', function() {
            var v = $.trim($('#billing_email').val() || '');
            if (v) captureCart(v);
        });
        var existing = $.trim($('#billing_email').val() || '');
        if (existing) captureCart(existing);  // autofill / returning visitors
    });
})(jQuery);
```

Three triggers (delegated event handlers, because WC re-renders fragments):

1. `blur` on `#billing_email` — the moment they tab away.
2. `updated_checkout` event — after WC AJAX-refreshes fields.
3. On DOM-ready, if the field is already populated (autofill, returning visitor).

Localize via `wp_localize_script`:

```php
wp_localize_script('hyros-checkout', 'hyrosCheckout', [
    'ajaxUrl'      => admin_url('admin-ajax.php'),
    'nonce'        => wp_create_nonce('hyros_checkout_nonce'),
    'captureToken' => hash_hmac('sha256', $cart_hash . '|' . $session_id, $capture_secret),
    'cartHash'     => WC()->cart->get_cart_hash(),
]);
```

The **HMAC capture token** is the single most important security control — without it, anyone can hit your AJAX endpoint and create cart events for arbitrary emails. The token must:

- Use a per-session secret (generated and stored in `WC()->session['hyros_capture_secret']` on first checkout view).
- Bind to the current `cart_hash` (cart contents) AND `session_id`.
- Be re-validated server-side on every AJAX call.

#### Phase B — Server-side AJAX endpoint

`wp_ajax_hyros_capture_abandoned_cart` + `wp_ajax_nopriv_hyros_capture_abandoned_cart` (both — guests are unauthenticated).

Required validations, in order:

1. ✅ **WP nonce** — `check_ajax_referer('hyros_checkout_nonce', 'nonce')`
2. ✅ **Email format** — `is_email()`
3. ✅ **Cart non-empty**
4. ✅ **HMAC capture token** — bound to session + cart hash
5. ✅ **Per-session+IP+email cooldown** — 300s transient
6. ✅ **Per-IP cooldown** — 300s transient (anti-bot rotation)
7. ✅ **Distributed lock** — `add_option(…, autoload=false)`, 30s TTL
8. ✅ **Idempotency** — if `hyros_cart_id` already in session, return early
9. ✅ Apply cooldown transients **before** doing the API calls (so abusers eat the cooldown even if they hammer the endpoint)
10. ✅ Resolve **IP server-side** — never trust client-supplied IP
11. ✅ Always release lock in `finally {}`

Request body (sanitize each field):

```
action         = hyros_capture_abandoned_cart
nonce          = <wp_create_nonce>
email          = sanitize_email
capture_token  = sanitize_text_field
cart_hash      = sanitize_text_field
referrer_url   = sanitize_text_field (will be esc_url_raw before sending)
user_agent     = sanitize_text_field
```

### 20.5 The `/clicks` + `/carts` pair

Both server-side paths converge on a single helper that sends two requests in order:

#### `POST /clicks` — first

```json
{
  "email":       "buyer@example.com",
  "referrerUrl": "https://google.com/search?q=...",
  "sessionId":   "<WC session customer ID, or md5(email+date) for sessionless>",
  "tag":         "!clicked-atc",
  "isOrganic":   true,
  "date":        "2026-04-27T18:30:00+00:00",
  "ip":          "203.0.113.45",
  "userAgent":   "Mozilla/5.0 ..."
}
```

**If `/clicks` fails, do not call `/carts`.** A cart with no traffic source attached is less useful than no cart at all — the lead can be re-attributed when the order eventually fires.

#### `POST /carts` — second

```json
{
  "email":    "buyer@example.com",
  "items": [
    { "name": "Premium Coaching", "price": 197.0, "quantity": 1, "externalId": "42", "sku": "COACH-01" }
  ],
  "date":     "2026-04-27T18:30:00+00:00",
  "currency": "USD"
}
```

Item shape recommendation: `{ name, price (excl tax), quantity, externalId (product_id as string), sku }`.

**Capture the response's `external_cart_id`** and store it in WC session as `hyros_cart_id`. This is the same value you'll send as `cartId` in the eventual `/orders` payload.

### 20.6 Order handoff

On `woocommerce_checkout_order_created`, copy session → order meta:

```php
function capture_cart_events(\WC_Order $order): void {
    if (!WC()->session) return;
    if ($cart_id = WC()->session->get('hyros_cart_id', '')) {
        $order->update_meta_data('_hyros_cart_id', $cart_id);
    }
    if ($events = WC()->session->get('hyros_cart_events', [])) {
        $order->update_meta_data('_hyros_cart_events', $events);
        WC()->session->set('hyros_cart_events', []);
    }
    $order->save();
}
```

Then, immediately before sending the `/orders` payload (in your `payment_complete` handler), include the `cartId`:

```php
$cart_id = $order->get_meta('_hyros_cart_id', true);

// If we have ATC events but never managed to send /carts (e.g., guest never typed email
// before completing checkout), send /carts now and capture the cart_id.
if (empty($cart_id) && ($events = $order->get_meta('_hyros_cart_events', true))) {
    $result = $api->send_cart([/* … */]);
    if ($result['success']) {
        $cart_id = $result['cart_id'];
        $order->delete_meta_data('_hyros_cart_events');
        $order->save();
    }
}

$order_payload['cartId'] = $cart_id;
$api->send_order($order_payload);
```

### 20.7 Pitfalls to avoid

| Pitfall | Consequence | Mitigation |
|---|---|---|
| Storing cart events only in `WC()->session` | Lost when order is processed in async cron / IPN context | Copy to order meta on `woocommerce_checkout_order_created` |
| Trusting client-submitted IP | Attribution poisoning, GDPR risk | Resolve via `WC_Geolocation::get_ip_address()` |
| No HMAC on guest AJAX endpoint | Anyone can spam fake carts for arbitrary emails | HMAC bound to session + cart_hash |
| Sending `/carts` before `/clicks` | Hyros has no source to attribute the lead to | Send `/clicks` first, abort on failure |
| Re-sending `/carts` on every add-to-cart | API spam, duplicate carts in Hyros | `hyros_cart_id` session guard — send once, append to events list afterward |
| Firing client-side from `wp_head` | Doesn't work for logged-in users (most plugins skip them) and breaks for ad-blockers | Server-side for logged-in; server-side via AJAX for guests |
| Sending stale cart payload | Final cart in `/orders` doesn't match `/carts` | Refresh via `maybe_send_cart()` at sale time, OR rely on the captured `cartId` and link without re-sending |
| Forgetting consent gate | GDPR violation in EU | `has_tracking_consent()` check at every entry point |
| No retry on 429/5xx | Lost data on transient API errors | WP-Cron with exponential backoff, honor `Retry-After` |
| No deduplication | Same cart sent multiple times | Session-level `hyros_cart_id` flag; order-level `_hyros_cart_id` meta |

### 20.8 Reference implementation

The complete reference implementation is in `includes/class-hyros-tracker.php`:

- `handle_add_to_cart()` — lines 215–268
- `send_cart_for_email()` — lines 698–840 (the unified `/clicks` + `/carts` helper)
- `enqueue_checkout_script()` — lines 924–975
- `checkout_inline_js()` — lines 977–1025 (the inline JS quoted above)
- `ajax_capture_abandoned_cart()` — lines 866–922
- `capture_cart_events()` — lines 270–296
- `maybe_send_cart()` — lines 643–696

The full plugin source is under GPL-2.0+, and you are welcome to adapt any of it directly into the official integration.

---

## Contact

**Author:** Carlos Aragon

