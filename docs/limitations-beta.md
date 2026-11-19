# Beta limitations

The plugins in this repository are in **beta**: the payment flow is complete and
has been exercised end to end against each test shop in `docker/`, but the admin
tooling and the documentation are still settling. Read this list before taking
live payments.

The long version, with the reasoning behind each item, lives in the docs site:
<https://docs.aneepay.com/cms/limitations-beta/>.

## Gaps you can hit

- **No refunds through the API.** Refund from your wallet and change the order
  state by hand.
- **Only a successful payment is pushed to the shop.** A failed or abandoned
  payment produces no webhook; the plugin learns about it from the result page
  and from the cron job.
- **No TTL on the AneePay side.** The plugin decides when a payment is
  abandoned: after _Abandoned Payment Expiry_ minutes (default 120, `0`
  disables it). WooCommerce closes it as **failed**; OpenCart and PrestaShop as
  **cancelled** (or failed when no cancelled status is configured).
- **No `Idempotency-Key`.** A `creating` mapping row locks the order, and a lost
  API answer is adopted from the account payment list by amount and order
  reference.
- **A webhook is delivered once** and cannot be replayed. Use _Reconcile
  payments_ on the settings page when a delivery is lost for good.
- **Sandbox always pays in USDC** on Polygon Amoy, whatever token is configured.
- **WooCommerce blocks checkout is not supported** — the gateway renders in the
  classic checkout only.
- **OpenCart 3.0.x only** (not 4.x), and it pins PHP 7.4 because OpenCart 3 does
  not run on PHP 8.
- **English only.** All shopper-facing strings ship in `en`; WooCommerce and
  PrestaShop can be translated through their own mechanisms.
- **Automatic rates are ECB-only.** Other currencies fall back to the store
  rate, then to the manual rate; the settings page warns about it.
- **No fee rule.** AneePay charges 0.5% per transaction and the plugins model
  nothing else. The OpenCart select that once claimed otherwise was removed
  because no code ever read it.

## Platform parity

| Behaviour                   | WooCommerce           | OpenCart                 | PrestaShop                                  |
| --------------------------- | --------------------- | ------------------------ | ------------------------------------------- |
| Abandoned payment closes as | `failed`              | `cancelled`, else failed | `cancelled`, else failed                    |
| Status mapping configurable | no (uses WC statuses) | yes (defaults 1/2/10/7)  | yes (`PS_OS_*` states)                      |
| Scheduler                   | WP-Cron               | your crontab             | your crontab                                |
| Test mode default           | off                   | off                      | **on**                                      |
| Order note on status change | yes                   | yes                      | yes (appended to the order's internal note) |

## Before a release

`docs/WEBHOOK-SMOKE.md` is the manual checklist for the one part unit tests
cannot cover: the signed request itself.
