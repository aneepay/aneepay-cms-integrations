# Changelog

All notable changes to the AneePay CMS integrations — WooCommerce, OpenCart 3.x
and PrestaShop 1.7/8 — are documented here. The three plugins share one version
and are released together.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

> Releases before `1.3.0` were published without git tags. Their dates and scope
> are reconstructed from the commit history, so grouping into versions is
> approximate; the first tagged release is `v1.3.0-beta`.

## [Unreleased]

### Added

- A manual **Sync** tool on every settings page: paste an AneePay payment ID
  (order ID optional) and the plugin fetches that payment from the API and
  applies its status immediately. This is the escape hatch for "the buyer paid,
  the order is still pending", because webhooks only ever deliver `success` and
  there is no replay endpoint.
- No second payment for the same order. A per-order lock (a WC option, an
  OpenCart/PrestaShop mapping row in a `creating` state) rejects a parallel
  request, and when the outcome of `create_payment` is unknown (transport error
  or an unreadable response) the plugin looks the payment up in
  `GET /accounts/{id}/payments` for the last hour, matches it by amount and
  order reference and reuses it. A blind retry is never issued, because the API
  has no `Idempotency-Key`.
- A created payment is never lost when the local bookkeeping fails: if storing
  the mapping throws (or WooCommerce cannot save the order meta), the plugin
  logs the payment ID and checkout URL at error level, releases the creation
  lock, leaves a note on the order and still sends the buyer to the checkout.
  The webhook resolves such an order by the `Order #N` description, and
  **Reconcile** reports the payment if the order still stays pending. The order
  is deliberately not cancelled, because the buyer already has a payable
  invoice.
- A manual **Reconcile** tool on every settings page: it reads the account
  payment list for the last N days (1–30), applies the status of every payment
  that matches an order and reports `checked` / `matched` / `updated` /
  `unmatched`. A webhook is delivered once and never replayed, so this is the
  only way to find a delivery that was lost for good.
- `./scripts/pack.sh check` — verifies the single version source and lints all
  plugins without packaging anything.
- `dist/SHA256SUMS` with the checksums of the built artifacts; artifacts of
  other versions are removed from `dist/<platform>/` before packing.
- Webhook deduplication on every platform: an event log table
  (`*_aneepay_event`) claims each delivery by `event_id` (falling back to
  `sha256(body)` for legacy payloads). A retry is acknowledged with HTTP 200
  and applies nothing, events are pruned after 7 days, and a claim is released
  when applying the status fails so the vendor retry is not swallowed.
- Sandbox guard: a payment from Amoy cannot complete a live order (and a live
  payment cannot complete a sandbox order). The mismatched event is recorded as
  ignored, logged at error level and acknowledged with HTTP 200.
- PrestaShop payment entry is CSRF-protected: the checkout issues a one-time,
  cart-bound token that `payment.php` verifies with `hash_equals` before
  creating an order, so a bare GET can no longer spam orders into a buyer's
  session. Invalid entries answer 403, and at most 5 payment attempts per
  10 minutes are accepted.
- Shopper-facing payment errors are translated strings instead of API text:
  technical details (HTTP code, `problem+json` body, transport errors) go to
  the log only. The messages live in language files — WooCommerce `.pot`
  (`languages/`), OpenCart `error_*` keys, PrestaShop `translations/en.php`;
  each plugin maps the failure to `config`, `network`, `auth`, `rate_limit`,
  `request`, `server` or `invalid`.
- One payment mapping per order: `order_id` (OpenCart) and `id_order`
  (PrestaShop) are unique keys now. Duplicates left by earlier releases are
  merged on the first write — the newest row survives and inherits
  `stock_restored` and a `success` status from its siblings.
- Abandoned `pending` orders are expired by the poller: an order older than the
  configurable window (default 120 minutes, `0` disables) is cancelled — or
  failed when no cancelled state is configured — but only after the API
  confirms the payment did not go through. A transport error, `5xx` or `429`
  never expires an order. Every platform gained the matching `Expiry` setting.
- The status poller reports counters (`checked`, `updated`, `expired`,
  `failed`) instead of a bare `ok`.
- OpenCart gets working order-status defaults: `pending`/`paid`/`failed`/
  `cancelled` fall back to the stock statuses (1/2/10/7) on install and on the
  first settings page load, and saving rejects an empty or duplicated status, so
  a failed or cancelled payment always leaves an order history entry.
- Currencies without an ECB reference rate (RUB, UAH and others) get an explicit
  fallback: the Frankfurter request is skipped instead of retried on every
  conversion, the settings page warns that the store or manual rate is used, and
  a `warning` is logged once an hour. The stored `rate_source` records which
  rate was applied, and the conversion error now names the currency.

### Changed

- The token amount and rate are captured when the payment is created and shown
  as the amount to pay on the waiting and result screens (WooCommerce result
  page, OpenCart result page, PrestaShop pending screen). The checkout
  conversion card is labelled as an estimate: the rate is only fixed when the
  payment starts.
- Request tracing is opt-in on every platform: the `debug` option now gates
  only `info` entries (one row per API call). Errors and warnings are always
  logged, so production incidents stay diagnosable, and log rows older than
  14 days are pruned on every cron run plus probabilistically on write.
  PrestaShop gained the missing `Debug log` setting.
- The `Origin` header can no longer follow the request: OpenCart and PrestaShop
  fell back to the incoming `Host` header, which a client controls. The
  configured merchant domain is now normalised to a bare host and validated on
  save, and the fallback is the store URL from the platform configuration.
  WooCommerce already used the site domain and now normalises it too.
- The order mapping is written with an upsert instead of `DELETE` + `INSERT`:
  `stock_restored` is never reset (no double stock return) and there is no
  window where the mapping row is missing while a webhook resolves an order.
- The status poller handles one order at a time: a failing payment no longer
  aborts the batch, each run is bounded (oldest 50 orders in WooCommerce, 100 in
  OpenCart/PrestaShop, 7 days max), and `get_payment()` returns `null` instead
  of throwing while exposing the HTTP status.
- CI: a required `verify` job (tag must equal `VERSION`, version sources, lint)
  now gates both `pack` and `release`; the release publishes `SHA256SUMS`.
- Webhook `timestamp` is validated for presence and type only — the 24-hour
  freshness window was removed, since authorisation rests on the signature and
  replays are stopped by the event log.

### Fixed

- OpenCart, PrestaShop: `Abandoned Payment Expiry` of `0` now really disables the
  expiry, as the field has always promised and as WooCommerce already did. Both
  crons used to coerce `0` back to 120 minutes, so a shop that asked to keep
  abandoned orders open still had them cancelled and their stock released on the
  next run.
- The result page resolves the payment status on the server (OpenCart,
  PrestaShop): the `success`/`fail` page asks `GET /payments/{id}` before it
  renders, so a declined or abandoned payment is visible even with JavaScript
  disabled — a failed payment never produces a webhook, only a lookup sees it.
  An already terminal status is not re-checked and the lookup is cached for
  5 seconds per order, so reloading the page cannot flood the API. The page
  shows *paid*, *failed* or *pending* (instead of a generic waiting screen) and
  only offers "Try again" for a failed payment, so a buyer who actually paid is
  never sent back to the checkout.
- The result-page cookie lives 24 hours and is renewed on every view (at most
  once a minute) instead of expiring after one hour with no renewal: a buyer who
  pays from an exchange, or comes back after closing the tab, still gets the real
  result page instead of a 403 (WooCommerce, PrestaShop). PrestaShop keeps the
  expiry inside the signed value, because its `Cookie::__set()` has no per-key
  lifetime, and leaves the cookie jar's own 20-day expiry alone — `setExpire()`
  there would shorten the shopper's whole session to our TTL.
- Status polling backs off instead of hammering the API and then going quiet
  (WooCommerce, OpenCart, PrestaShop): the first check runs after 5 seconds, the
  next after 15, then every 30 seconds, for at most 30 minutes. When the budget
  runs out the page stops polling and shows an explicit "Check again" button, so
  a slow confirmation is not mistaken for a dead page. OpenCart's result page now
  polls at all — it used to rely on the buyer reloading it by hand.
- PrestaShop: the note a webhook adds to an order is actually stored. The webhook
  built the text but `OrderHistory` has no note column, so it was silently
  dropped. PrestaShop 1.7/8 keeps its "internal note" as a single field on the
  order, so the note is appended there on its own line, keeping whatever the
  merchant already wrote. WooCommerce and OpenCart already recorded it.

## [1.2.0] - 2026-10-22

Security and stability batch produced by the plugin audit.

### Security

- OpenCart/PrestaShop: TLS peer and host verification enabled in the cURL
  clients (was silently disabled).
- All plugins: status rollback, unknown statuses and stale webhook replay are
  rejected instead of applied.
- All plugins: IDOR/CSRF closed in the result, `check` and `cron` endpoints —
  order data is only readable with a signed/authorised reference.
- OpenCart: the stored webhook secret survives re-saving the settings form with
  an empty secret field.

### Fixed

- OpenCart/PrestaShop: stock is restored exactly once on `failed`/`cancelled`,
  including repeated webhooks and manual status changes.
- PrestaShop: the order is created before the payment request and compensated if
  the gateway call fails, instead of losing the cart contents.
- PrestaShop: the `Origin` header falls back to the canonical shop host instead
  of trusting the incoming `Host`.
- OpenCart/PrestaShop: the FX rate cache expires after 1 hour, so a stale rate
  can no longer be used indefinitely.
- Stock, cron and PrestaShop host regressions introduced by the first fixes.

### Changed

- WooCommerce: plugin version synced with `VERSION`; PrestaShop `config.xml` is
  generated at build time instead of being committed.
- All plugins: uninstalling keeps tables and settings by default; deleting data
  is an explicit opt-in.

## [1.1.0] - 2026-09-21

### Added

- PrestaShop: admin extras — connection test, conversion preview, request log
  and a sandbox/live badge.
- PrestaShop: pending-payment screen, fiat-to-token conversion shown on
  checkout, and a confirmation step before leaving to the AneePay checkout.

### Fixed

- PrestaShop: module installation and the docker stand work end to end
  (hooks, tables, front controllers).
- OpenCart: catalog model class renamed to match the OpenCart loader name.

## [1.0.0] - 2026-09-06

First release of the plugins.

### Added

- WooCommerce gateway: hosted checkout redirect, HMAC-SHA256 webhook
  verification, fiat → USD → token conversion (ECB/Frankfurter, store currency
  rate, manual rate) with ceil rounding in the merchant's favour.
- WooCommerce: idempotent payment creation and duplicate-payment prevention,
  configurable status sync with cron overlap protection, result pages with order
  context, spinner and "I already paid" action.
- WooCommerce admin: grouped settings with account UUID validation, quick setup,
  connection test and request log.
- OpenCart 3.x extension and PrestaShop 1.7/8 module with the same payment flow,
  status mapping and admin settings.
- Monorepo split into per-platform folders with `scripts/pack.sh` artifacts and
  a docker test shop per platform.
- English READMEs and the `TECHDEBT.md` plan for a shared platform-agnostic core.

### Fixed

- Stock restored on failed/cancelled orders.
- WooCommerce gateway class loaded on `plugins_loaded`; activation sync fixed.
