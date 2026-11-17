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
  merchant's favour.
- Order metadata is stored in the `aneepay_order` table; the webhook resolves the
  order by `payment_id` / `operation_id` (128-bit int, kept exact) or `Order #N`.
- Webhook deliveries are deduplicated in the `aneepay_event` table by `event_id`
  (or `sha256(body)` for legacy payloads): a retry gets HTTP 200 and changes
  nothing, rows are pruned after 7 days, and a sandbox payment never completes a
  live order.
- One mapping per order: `order_id` is unique. Duplicates from older releases are
  merged on the first write (newest row wins, keeps `stock_restored`), and the
  row is upserted, so `stock_restored` is never reset.
- Cron is not built into OpenCart: add the cron URL above to your server crontab.
- Uninstall keeps the `aneepay_order` / `aneepay_log` tables by default; set
  "On uninstall" to "Delete data" in the settings to drop them.
- The admin page upgrades an existing `aneepay_order` table in place (adds
  `stock_restored`), so no manual SQL is needed after updating.
