# Security Policy

This repository ships the AneePay payment plugins for WooCommerce, OpenCart 3.x
and PrestaShop 1.7/8. All three plugins are built from the same `VERSION` and
are released together, so a fix is published for every platform at once.

## Supported versions

| Version           | Supported |
| ----------------- | --------- |
| `1.3.x` (beta)    | Yes       |
| `1.2.x` and older | No        |

Only the latest release receives security fixes. Upgrade to the newest artifact
before reporting an issue that may already be fixed.

## Reporting a vulnerability

**Do not open a public issue, pull request or discussion for a suspected
vulnerability.**

Send a report to **security@aneepay.com** (encrypted mail / PGP key on request).

Please include:

- plugin and platform version (WooCommerce + WordPress, OpenCart 3.0.3.8,
  PrestaShop 8.1, …) and the PHP version;
- the endpoint or admin screen involved (`webhook`, `success`/`fail`, `check`,
  `cron`, checkout, settings page);
- steps to reproduce, the expected and the actual result, and the impact you
  believe it has (order status, stock, funds, data exposure);
- relevant log lines with **secrets and signatures removed**.

Do not include webhook secrets, account IDs paired with secrets, API keys,
wallet private keys, seed phrases, or transaction hashes tied to real funds.

When testing, use your own AneePay account in sandbox mode (Amoy testnet) and
your own test shops. Never test against a live merchant store or move other
people's funds.

## What to expect

- acknowledgement within **3 business days**;
- an initial assessment (confirmed / not a vulnerability, severity, plan)
  within **10 business days**;
- a fix shipped as a patch release, with credit in `CHANGELOG.md` unless you ask
  to stay anonymous;
- coordinated disclosure: please give us **90 days** from the acknowledgement
  before publishing details.

## Out of scope

The following are known, documented limitations rather than vulnerabilities:

- **No IP allowlist on the webhook endpoint.** AneePay does not publish the
  source ranges; authenticity rests on the HMAC-SHA256 signature over the raw
  request body, verified in constant time and fail-closed.
- **Secrets are stored in the platform's own settings storage** (e.g.
  `wp_options`, `oc_setting`, `ps_configuration`) in plaintext, because the
  signature has to be computed locally. Protect database and backup access; the
  plugins never print secrets into logs or the admin UI.
- **No refund API.** Refunds are settled off-chain by the merchant; the plugins
  do not move funds back.
- **No `Idempotency-Key` support in the API.** Duplicate-payment protection is
  local (order lock + reconciliation via `GET /accounts/{id}/payments`).
- Reports that require an already-compromised admin account, database access or
  server filesystem access.
