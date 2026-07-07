=== HyrosWoo ===
Contributors: carlosaragon
Tags: woocommerce, hyros, tracking, analytics, subscriptions
Requires at least: 5.8
Tested up to: 6.8
WC requires at least: 6.0
WC tested up to: 9.9
Stable tag: 1.1.0
Requires PHP: 7.4
License: GPL-2.0+
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Production-ready WooCommerce to Hyros server-side tracking — fixes all gaps in the official integration.

== Description ==

HyrosWoo provides a robust, production-ready bridge between WooCommerce and the Hyros attribution platform. It addresses five critical gaps in the official Hyros WooCommerce integration:

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
