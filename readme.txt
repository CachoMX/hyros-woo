=== HyrosWoo ===
Contributors: carlosaragon
Tags: woocommerce, hyros, tracking, analytics, subscriptions
Requires at least: 5.8
Tested up to: 6.8
WC requires at least: 6.0
WC tested up to: 9.9
Stable tag: 1.2.1
Requires PHP: 7.4
License: GPL-2.0+
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Production-ready WooCommerce to Hyros server-side tracking — fixes all gaps in the official integration.

== Description ==

HyrosWoo provides a production-ready bridge between WooCommerce and the Hyros attribution platform. It addresses five critical gaps in the official Hyros WooCommerce integration:

1. **Auto Script Injection** — Automatically injects your Hyros tracking script into every page `<head>` without manual theme edits.
2. **WC Subscriptions Support** — Tracks WooCommerce Subscriptions renewal payments as new Hyros sales automatically.
3. **Real-Time Server-Side Tracking** — Fires on `payment_complete`, `processing`, and `completed` order statuses to ensure no sale is missed.
4. **Deduplication** — Stores a `_hyros_tracked` flag on each order so the same sale is never sent to Hyros twice, even across multiple status transitions.
5. **Audit Log** — Every tracked sale and refund is recorded both as a WooCommerce order note and in a searchable global activity log visible in the plugin settings.

= Additional Features =

* Refund tracking via DELETE /orders endpoint
* API key fallback via `HYROS_API_KEY` constant in `wp-config.php`
* Masked API key display — your key is never shown in full in the admin UI
* Zero external dependencies — uses only WordPress and WooCommerce core APIs

== Installation ==

1. Upload the `hyros-woo` folder to `/wp-content/plugins/`.
2. Activate the plugin through the **Plugins** screen in WordPress.
3. Navigate to **WooCommerce → Hyros**, enter your API key, validate it, select your tracking script, and click **Save Settings**.

Optionally, define your API key in `wp-config.php` for added security:

`define('HYROS_API_KEY', 'your-api-key-here');`

== Changelog ==

= 1.2.1 =
* Fix: full-line partial refunds now always send the exact WooCommerce refund amount (`refundedAmount`) instead of letting Hyros refund the sale's own price. Hyros distributes the order's shipping cost into each sale's price, so an amount-less refund also returned each product's shipping share that the customer never got back (e.g. a $5.00 two-product refund on an order with $6.99 shipping was recorded as $8.50 refunded in Hyros).

= 1.2.0 =
* Feature: partial refunds are now reported at the product (sale) level. Refunding one product of a multi-product order marks only that product's Hyros sale as refunded (`PUT /sales?isRefunded=true`), so the order income and per-product refund stats stay accurate. Previously the order-level `DELETE /orders?refundedAmount` call smeared the refunded amount evenly across every product in the order.
* Feature: partial-quantity refunds (e.g. 2 of 5 units of one line) send the exact refunded amount against that line's sale via `refundedAmount`.
* Feature: refunded shipping, fees, and any line that cannot be matched to a Hyros sale are still reported through the order-level partial refund so no amount is ever lost.
* Feature: when the Hyros sales of an order have not been ingested yet (order creation is asynchronous on the Hyros side), the refund is retried via WP-Cron up to 3 times before falling back to the order-level call.
* Dev: `hyros_woo_item_level_refunds` filter to disable sale-level refunds and restore the old order-level behavior.
* Dev: new regression harness `tests/test-partial-refund.php`.

= 1.1.2 =
* Fix: discounted sales were reported to Hyros at $0. `items[].price` sent the line total after discount while `itemDiscount` was sent alongside it, so Hyros subtracted the discount twice. It now sends the gross pre-discount unit price.
* Fix: `orderDiscount` is no longer sent. `WC_Order::get_discount_total()` is the same coupon money already reported per line as `itemDiscount`, and Hyros subtracted both.
* Fix: every line item was tagged with the constant `$hyros-woo`. In Hyros the tag is the product's identity, so an entire catalog collapsed into a single product. Tags are now derived per product as `$woocommerce-<name>-<gross unit price>`, matching the official Hyros WooCommerce integration so sales land on products the account already has. Override with the `hyros_woo_product_tag` filter.
* Fix: product names in non-Latin scripts produced an empty tag slug, collapsing those products together. Accents are transliterated and names with no Latin characters fall back to the product ID.

= 1.1.1 =
* Fix: the Save Settings pre-check rejected tracking scripts served from custom domains (e.g. data.yourstore.com). The client now checks structure only; the server keeps validating the script host against *.hyros.com and the account's verified domains.

= 1.1.0 =
* Security hardening for tracking script validation and admin capabilities.
* Added consent-aware tracking toggle (`Require Marketing Consent`).
* Added script injection toggle for stores using tag managers or theme-level script insertion.
* Improved retry handling with retryable/non-retryable classification and Retry-After support.
* Fixed refund lifecycle to support multiple partial refunds safely.
* Improved abandoned-cart capture reliability and response handling.
* Added dedicated Hyros log table with retention pruning and compatibility fallback.

= 1.0.0 =
* Initial release.
* Server-side order tracking via POST /orders.
* Refund tracking via DELETE /orders.
* WC Subscriptions renewal support.
* Auto script injection into `<head>`.
* Deduplication via `_hyros_tracked` order meta.
* Global activity log (last 200 entries) with per-order view in WC admin.
* API key constant fallback (`HYROS_API_KEY` in wp-config.php).
