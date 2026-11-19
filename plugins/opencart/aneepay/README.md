# AneePay Crypto Gateway — OpenCart 3.x

Status: **working implementation** (mirrors the WooCommerce plugin).
Reference implementation — `../woocommerce/aneepay`.

## Files

```
aneepay/
  admin/controller/extension/payment/aneepay.php    # settings + AJAX + install
  admin/model/extension/payment/aneepay.php          # tables + request log
  admin/language/en-gb/extension/payment/aneepay.php
  admin/view/template/extension/payment/aneepay.twig
  catalog/controller/extension/payment/aneepay.php   # index/confirm/callback/success/fail/cron/check
  catalog/model/extension/payment/aneepay.php          # aneepay_order mapping
  catalog/language/en-gb/extension/payment/aneepay.php
  catalog/view/theme/default/template/extension/payment/aneepay.twig
  catalog/view/theme/default/template/extension/payment/aneepay_result.twig
  system/library/aneepay/aneepay.php                   # API client + webhook signature + log
  system/library/aneepay/usd-converter.php             # fiat -> USD -> token
  system/library/aneepay/webhook.php                   # resolve order + apply status
  system/library/aneepay/event-log.php                 # webhook dedup by event_id + is_sandbox guard
  system/library/aneepay/api-exception.php             # typed API failures -> shopper-safe messages
install.xml                                            # ocmod manifest (add-on files)
```

## Endpoints

- `status_url` (webhook) — `index.php?route=extension/payment/aneepay/callback`
- `success_url` — `index.php?route=extension/payment/aneepay/success`
- `fail_url` — `index.php?route=extension/payment/aneepay/fail`
- polling — `index.php?route=extension/payment/aneepay/cron&secret=<cron_secret>` (schedule it in cron; the secret is shown on the settings page)

## Notes

- Setup: Extensions → Payments → AneePay → Edit. Enter the Account ID + Webhook
  Secret (UUID + HMAC-SHA256 signature verification, fail-closed). Optionally
  toggle Test mode (Amoy/USDC).
- Amount semantics: token amount = store_total converted via Frankfurter/ECB
  (auto) + store rate fallback + manual rate; rounded **up** (ceil) in the
  merchant's favour. The rate and token amount are fixed when the payment is
  created; the checkout breakdown is an estimate and says so, while the result
  page shows the fixed amount that was charged.
- Only ECB currencies convert automatically: for others (RUB, UAH and so on) the
  Frankfurter request is skipped and the store rate, then the manual rate, is
  used. The settings page warns about it, a `warning` is logged once an hour, and
  `rate_source` in the mapping records which rate was applied.
- Order metadata is stored in the `aneepay_order` table; the webhook resolves the
  order by `payment_id` / `operation_id` (128-bit int, kept exact) or `Order #N`.
- The success/fail pages resolve the status on the server before rendering
  (`GET /payments/{id}`, throttled to one call per order per 5 seconds), so the
  real outcome is visible with JavaScript disabled — a failed payment is only
  ever visible through the API, never through a webhook. The page shows paid,
  failed or pending, and "Try again" only for a failed payment, so a buyer who
  actually paid is never sent back to the checkout. While the payment is pending
  the page polls the status (after 5 s, then 15 s, then every 30 s for at most
  30 minutes) and offers an explicit "Check again" button once that budget is
  spent.
- Webhook deliveries are deduplicated in the `aneepay_event` table by `event_id`
  (or `sha256(body)` for legacy payloads): a retry gets HTTP 200 and changes
  nothing, rows are pruned after 7 days, and a sandbox payment never completes a
  live order.
- One mapping per order: `order_id` is unique. Duplicates from older releases are
  merged on the first write (newest row wins, keeps `stock_restored`), and the
  row is upserted, so `stock_restored` is never reset.
- The `Origin` header is the configured merchant domain (normalised to a bare
  host and validated on save); when it is empty the store URL from the OpenCart
  configuration is used, never the request `Host` header.
- The cron job returns JSON counters (`checked`, `updated`, `expired`, `failed`),
  polls the oldest 100 pending orders (7 days max) and expires abandoned ones: an
  order older than `Abandoned Payment Expiry` (default 120 minutes, `0` disables)
  is cancelled — or failed when no cancelled status is configured — once the API
  confirms the payment did not go through.
- Order statuses: `pending`/`paid`/`failed`/`cancelled` default to the stock
  OpenCart statuses (1/2/10/7); saving rejects an empty or duplicated status, so
  a failed or cancelled payment always leaves an order history entry.
- Cron is not built into OpenCart: add the cron URL above to your server crontab.
- **Sync a Payment** on the settings page takes an AneePay payment ID (order ID
  optional), fetches that payment from the API and applies its status right away —
  the manual fix for "the buyer paid but the order is still pending", since
  webhooks only ever deliver `success` and there is no replay endpoint.
- One payment per order: a mapping row in the `creating` state locks the order
  while the payment is created, so a duplicate tab or a double click cannot
  start a second AneePay payment. If the API answer is lost (timeout, unreadable
  body), the plugin adopts the payment found in the account list by amount and
  order reference instead of retrying blind — there is no `Idempotency-Key`.
  If saving the mapping still fails after the payment exists, the order is not
  cancelled: the failure is logged with the payment ID and checkout URL, the
  order history says it needs attention, and the buyer is sent to the checkout.
- **Reconcile Payments** reads the account payment list for the last 1–30 days,
  applies the status of every payment it can match to an order, and reports
  `checked` / `matched` / `updated` / `unmatched`. Use it when a webhook was lost
  for good — the vendor never re-sends one on request.
- Uninstall keeps the `aneepay_order` / `aneepay_log` tables by default; set
  "On uninstall" to "Delete data" in the settings to drop them.
- The admin page upgrades an existing `aneepay_order` table in place (adds
  `stock_restored`), so no manual SQL is needed after updating.
