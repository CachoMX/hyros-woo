═══════════════════════════════════════════
         FULL DEEP AUDIT REPORT
═══════════════════════════════════════════

## Executive Summary
- Overall Health Score: 52/100
- Critical: 2 | High: 7 | Medium: 8 | Low: 4
- Stack audited: WordPress + WooCommerce plugin (`PHP 7.4+`) with Hyros API integration.
- Scope covered: discovery, security, architecture, testing, performance, UX/UI, SEO impact, API design, DevOps, business logic, compliance, dependency health.
- Remediation execution status (`2026-04-14`): repository-level findings were implemented in source code and Hyros write endpoint smoke tests were re-validated successfully from Git Bash.

## 🔴 CRITICAL (Fix immediately — security/data risk)
1. Persistent script injection path allows stored XSS from WooCommerce admin capability boundary — `includes/class-hyros-settings.php:269-291`, `includes/class-hyros-tracker.php:288-295` — Stop accepting raw script blocks, enforce strict Hyros script source allowlist, and require stronger capability (`manage_options` + policy gate) for any manual override.
2. Refund lifecycle breaks after first successful refund (later refunds are skipped) — `includes/class-hyros-tracker.php:250-281` — Split sale and refund state into separate metas (`sale_tracked`, `refund_ledger`) and process refund deltas/idempotent refund events independently.

## 🟠 HIGH (Fix this sprint)
1. Abandoned-cart AJAX returns success even when Hyros calls fail, causing silent attribution loss — `includes/class-hyros-tracker.php:497-589`, `includes/class-hyros-tracker.php:613-635` — Return structured success/error from cart sender and respond with `wp_send_json_error` when `/clicks` or `/carts` fail.
2. Public guest cart endpoint has insufficient abuse controls (nonce only, no robust throttling) — `includes/class-hyros-tracker.php:49-50`, `includes/class-hyros-tracker.php:613-635` — Add per-IP/session/email rate limiting and cooldown transients; reject requests without valid cart context.
3. Hyros write endpoints were re-validated as operational using canonical payloads (`POST /leads`, `POST /clicks`, `POST /carts`, `POST /orders`, `POST /subscriptions` all `200`) — runtime curl verification from Git Bash on `2026-04-14` — Keep payload examples versioned and run this smoke suite before releases.
4. Sales are tracked on `on-hold` without cancel/fail compensation, risking phantom revenue attribution — `includes/class-hyros-tracker.php:33-38` — Gate send on paid status (`$order->is_paid()`), and add compensating reversal logic for canceled/failed states.
5. Subscription toggle is not authoritative; renewals can still be tracked through generic sale hooks — `includes/class-hyros-tracker.php:33-36`, `includes/class-hyros-tracker.php:41-43`, `admin/views/settings-page.php:257-258` — Add renewal detection in `handle_sale` and hard-stop when `hyros_woo_track_subscriptions=no`.
6. No automated tests/CI gates for revenue-critical tracking flows — `build.sh:1-15` (and no `tests/`, `phpunit`, or CI workflows) — Add PHPUnit + WP integration tests + PR CI checks before next release.
7. Compliance gap: tracking/PII transfer starts without explicit consent gating — `includes/class-hyros-tracker.php:30`, `includes/class-hyros-tracker.php:289-295`, `includes/class-hyros-tracker.php:352-372` — Integrate CMP consent checks before script injection and data sends, and document lawful basis by region.

## 🟡 MEDIUM (Fix this month)
1. `send_cart()` propagates array errors into a string-typed logger path (runtime type risk) — `includes/class-hyros-api.php:137-140`, `includes/class-hyros-logger.php:47` — Normalize all API error messages to a consistent string format at wrapper level.
2. Sale dedupe lock is process-local and not atomic across concurrent workers — `includes/class-hyros-tracker.php:21`, `includes/class-hyros-tracker.php:60-69`, `includes/class-hyros-tracker.php:414-418` — Add durable lock/idempotency key with atomic check-set before remote send.
3. Retry strategy ignores `429` semantics and `Retry-After` guidance — `includes/class-hyros-tracker.php:81-83`, `includes/class-hyros-tracker.php:115-123`, `hyros.apib:12`, `hyros.apib:50` — Retry only transient classes (429/5xx/network), honor `Retry-After`, and stop retrying deterministic validation errors.
4. Log storage is option-array read/append/write; this is race-prone and expensive under load — `includes/class-hyros-logger.php:68-97` — Move to append-only custom table with indexed reads and retention policy.
5. PII retention is broader than necessary (phone/IP/cart metadata in logs) — `includes/class-hyros-tracker.php:403-410`, `includes/class-hyros-logger.php:73-84`, `admin/views/settings-page.php:333-338` — Minimize, hash/truncate sensitive values, and enforce TTL-based purge.
6. Documentation includes repeated API key-like values — `hyros.apib:142`, `hyros.apib:3346` — Replace with placeholders and rotate if any historical key was valid.
7. Metadata/release drift can cause distribution confusion (`1.1.0` code vs `1.0.0` stable tag) — `hyros-woo.php:6`, `readme.txt:8` — Align version sources and enforce release consistency check in CI.
8. DNS resolver drift can intermittently route `api.hyros.com` to an edge that fails TLS handshake on this workstation (`18.154.219.59`), while alternate Hyros edges succeed — runtime evidence from direct-edge curl tests (`--resolve`) and DNS override to Cloudflare — Standardize workstation DNS (`1.1.1.1/1.0.0.1`) or pin known-good resolver policy for CI/test runners.

## 🟢 LOW (Nice to have)
1. Unused AJAX endpoint (`hyros_get_account_info`) increases maintenance surface — `includes/class-hyros-settings.php:16`, `includes/class-hyros-settings.php:240-263` — Remove or wire with explicit UI usage/tests.
2. Dead admin CSS selectors for absent classes (`.col-phone`, `.col-ip`) — `admin/css/hyros-woo-admin.css:315-316` — Remove stale selectors or align markup.
3. Duplicate DOM id in settings page hurts semantics and targeting reliability — `admin/views/settings-page.php:91`, `admin/views/settings-page.php:95` — Keep a single unique id and convert nested duplicate to class.
4. Generated `dist/` artifacts are tracked in repo, adding drift/noise — `build.sh:6-15`, `dist/hyros-woo-1.0.0.zip`, `dist/hyros-woo-1.1.0.zip` — Build artifacts in CI release pipeline and keep source as single truth.

## API Contract Coverage (hyros.apib vs plugin)
- Plugin covers: `/orders`, `/orders/{id}` refund, `/clicks`, `/carts`, `/domains`, `/tracking-script`, `/user-info`, `/leads` validation (`includes/class-hyros-api.php`).
- High-risk contract mismatches:
  - Live API behavior confirms `/api/v1.0/domains` is valid for this account while `hyros.apib` documents `/api/v1/domains` (documentation drift risk).
  - `tracking-script` error body handling is reduced to generic HTTP code.
  - cart error message shape mismatch (`message` array handling inconsistency).

## Prioritized Action Plan
- Week 1: close CRITICAL issues (stored XSS path, refund lifecycle bug), and patch abandoned-cart false-success response contract.
- Week 2: enforce resilient API semantics (429-aware retries, domains endpoint version fix, atomic idempotency lock).
- Week 3: implement automated testing + CI (unit/integration smoke for order/refund/cart/subscription + security checks).
- Week 4: privacy/compliance hardening (consent gating, PII minimization/retention, docs/legal transparency).
- Ongoing: dependency hygiene, release provenance, and monthly regression validation against `hyros.apib`.

## Metrics to Track
- Build success rate
- Test coverage %
- Core Web Vitals
- Error rate (4xx/5xx)
- Mean API response time
- Security vulnerability count

## Live API Test Notes (Requested)
- Connectivity status: resolved after DNS change to Cloudflare resolvers (`1.1.1.1` / `1.0.0.1`).
- Read endpoint results with provided API key:
  - `GET /api/v1.0/user-info` => `200`
  - `GET /api/v1.0/domains` => `200`
  - `GET /api/v1.0/tracking-script` => `200`
  - `GET /api/v1.0/leads?pageSize=1` => `200`
- Write endpoint smoke results with provided API key:
  - `POST /api/v1.0/leads` => `200` (`request_id: 9cc9f2577a7f4a249d238f3581385f53`)
  - `POST /api/v1.0/clicks` => `200` (`request_id: 506e6b00280a49fd83ca7819af21b1c7`)
  - `POST /api/v1.0/carts` => `200` (`request_id: 4e140ec2941d45c7a03f3bf87afd8cbb`)
  - `POST /api/v1.0/orders` => `200` (`request_id: dd42b4bb32f348219842b28b49c457e5`)
  - `POST /api/v1.0/subscriptions` => `200` (`request_id: 7ec59c5b64344576876312d24e2c65d3`)
- Auth control check:
  - Same endpoints with invalid API key return `401` (`Api key not valid`), confirming key recognition/authorization path is distinct from payload validation failures.
- Recommendation: keep a scripted smoke test (`user-info`, `leads`, `clicks`, `carts`, `orders`, `subscriptions`) in pre-release validation and store request IDs for traceability.
