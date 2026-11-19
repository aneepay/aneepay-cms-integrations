# AneePay Crypto Gateway — PrestaShop 1.7 / 8.x

Status: **beta** — the payment flow works end to end. Known gaps are listed in
[`docs/limitations-beta.md`](../../../docs/limitations-beta.md); installation and
setup are documented at <https://docs.aneepay.com/cms/prestashop/>.
(Development reference — this README describes the files, not the user guide.)

## Files

```
ps_aneepay/
  ps_aneepay.php                      (PaymentModule: install, hookPaymentOptions, getContent)
  classes/
    AneePayApi.php                    (create/get_payment, test_connection, signature, log)
    AneePayUsdConverter.php           (fiat -> USD -> token)
    AneePayOrder.php                  (ps_aneepay_order mapping)
    AneePayWebhook.php                (resolve order + apply OrderHistory)
    AneePayEventLog.php               (webhook dedup by event_id + is_sandbox guard)
    AneePayApiException.php           (typed API failures -> shopper-safe messages)
  controllers/front/
    payment.php    (CSRF token gate + validateOrder + redirect to checkout_url)
    webhook.php    (STATUS_URL — HMAC verify, fail-closed)
    success.php / fail.php            (result pages)
    cron.php       (poll pending orders)
    check.php      (AJAX "I already paid" / polling)
  views/templates/front/result.tpl
  views/templates/front/error.tpl     (expired/invalid payment session)
  translations/en.php                 (shopper-facing messages; template for other locales)
  logo.png
  index.php                           (in every folder — PS requirement)
```

## Endpoints (set them in the AneePay account panel)

- `status_url` — `index.php?fc=module&module=ps_aneepay&controller=webhook`
- `success_url` — `index.php?fc=module&module=ps_aneepay&controller=success`
- `fail_url` — `index.php?fc=module&module=ps_aneepay&controller=fail`
- polling — `index.php?fc=module&module=ps_aneepay&controller=cron&secret=<cron_secret>` (the secret is shown on the settings page)

## Notes

- Setup: Modules → Module manager → AneePay → Configure. Enter the Account ID +
  Webhook Secret. Save. Order states are configurable (defaults: pending =
  "Awaiting payment", paid = "Payment accepted", failed/cancelled accordingly).
- `payment.php` creates an order and a payment, so it is not reachable by a bare
  GET: the checkout renders a one-time, cart-bound token into the payment
  action, the controller verifies it with `hash_equals` and spends it. Invalid
  or expired entries answer 403 without touching the cart, and more than 5
  attempts in 10 minutes are refused.
- The result/check controllers identify the order through a signed cookie
  (`<id_order>.<expiry>.<hmac>`). The expiry lives inside the value because
  PrestaShop's `Cookie::__set()` has no per-key lifetime; it is 24 hours and is
  renewed on every view, at most once a minute. The cookie jar's own expiry is
  left alone: PrestaShop already makes it persistent for 20 days, and calling
  `Cookie::setExpire()` would shorten the whole shopper session to our TTL.
- The settings page adds a SANDBOX/LIVE badge, a "Test connection" button
  (`GET /accounts/{id}/payments`), an offline conversion preview, a request log
  panel (`ps_aneepay_log`) and the copyable STATUS/SUCCESS/FAIL/cron URLs.
- Amount semantics: token amount = store total converted via Frankfurter/ECB
  (auto) + store `Currency` rate fallback + manual rate; rounded **up** (ceil).
  The rate and token amount are fixed when the payment is created; the checkout
  breakdown is an estimate and says so, while the pending and result screens
  show the fixed amount that was charged.
- Only ECB currencies convert automatically: for others (RUB, UAH and so on) the
  Frankfurter request is skipped and the store `Currency` rate, then the manual
  rate, is used. The settings page warns about it, a `warning` is logged once an
  hour, and `rate_source` in the mapping records which rate was applied.
- Order metadata is stored in the `ps_aneepay_order` table; the webhook resolves
  the order by `payment_id` / `operation_id` (128-bit int, kept exact) or `#N`.
- The success/fail pages resolve the status on the server before rendering
  (`GET /payments/{id}`, throttled to one call per order per 5 seconds), so the
  real outcome is visible with JavaScript disabled — a failed payment is only
  ever visible through the API, never through a webhook. The page shows paid,
  failed or pending, and "Try again" only for a failed payment, so a buyer who
  actually paid is never sent back to the checkout. While the payment is pending,
  the waiting and result screens poll the status (after 5 s, then 15 s, then
  every 30 s for at most 30 minutes) and offer an explicit "Check again" button
  once that budget is spent.
- Webhook deliveries are deduplicated in the `ps_aneepay_event` table by
  `event_id` (or `sha256(body)` for legacy payloads): a retry gets HTTP 200 and
  changes nothing, rows are pruned after 7 days, and a sandbox payment never
  completes a live order.
- A webhook that changes the order state also appends a line to the order's
  internal note, the one the admin order page shows. PrestaShop has no note per
  history entry, only a single field on the order, so the plugin appends instead
  of overwriting and keeps the merchant's own notes.
- One mapping per order: `id_order` is unique. Duplicates from older releases
  are merged on the first write (newest row wins, keeps `stock_restored`), and
  the row is upserted, so `stock_restored` is never reset.
- The `Origin` header is the configured merchant domain (normalised to a bare
  host and validated on save); when it is empty the shop domain from the
  PrestaShop configuration is used, never the request `Host` header.
- The cron job returns JSON counters (`checked`, `updated`, `expired`, `failed`),
  polls the oldest 100 pending orders (7 days max) and expires abandoned ones: an
  order older than `Abandoned Payment Expiry` (default 120 minutes, `0` disables)
  is cancelled — or failed when no cancelled state is configured — once the API
  confirms the payment did not go through.
- PrestaShop has no built-in cron: add the cron URL above to your server crontab.
- **Sync a payment** on the configuration page takes an AneePay payment ID (order
  ID optional), fetches that payment from the API and applies its status right
  away — the manual fix for "the buyer paid but the order is still pending",
  since webhooks only ever deliver `success` and there is no replay endpoint.
- One payment per order: a mapping row in the `creating` state locks the order
  while the payment is created, so a duplicate tab or a double click cannot
  start a second AneePay payment. If the API answer is lost (timeout, unreadable
  body), the plugin adopts the payment found in the account list by amount and
  order reference instead of retrying blind — there is no `Idempotency-Key`.
  If saving the mapping still fails after the payment exists, the order is not
  cancelled: the failure is logged with the payment ID and checkout URL, a
  private order note says it needs attention, and the buyer is sent to the
  checkout.
- **Reconcile payments** reads the account payment list for the last 1–30 days,
  applies the status of every payment it can match to an order, and reports
  `checked` / `matched` / `updated` / `unmatched`. Use it when a webhook was lost
  for good — the vendor never re-sends one on request.
- Uninstall keeps the `ps_aneepay_order` / `ps_aneepay_log` tables by default;
  disable "Keep data on uninstall" in the settings to drop them.
- Upgrading from 1.1.x runs `upgrade/upgrade-1.2.0.php`, which adds the
  `stock_restored` column automatically.
