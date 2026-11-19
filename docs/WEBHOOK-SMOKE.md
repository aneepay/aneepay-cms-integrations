# Webhook smoke test

The unit tests cover the signature check, the operation id decoding, the
converter and the status mapping in isolation. What they cannot cover is the
endpoint itself: the header name, the JSON body, the deduplication and the
sandbox gate only exist once a real web request reaches the shop.

Run this checklist before tagging a release, on a staging shop or on the local
stand. It takes about ten minutes per platform.

> **Never run it against a live shop with real orders.** Every case below sends
> a forged event; some of them mark an order as paid.

## Prerequisites

1. The plugin is installed and enabled, and the AneePay **Webhook secret** is
   filled in on the settings page. An empty secret rejects every webhook — that
   is deliberate (fail-closed).
2. **Test mode** is on, so a sandbox event is expected and the sandbox gate does
   not reject the event for the wrong reason.
3. You have the STATUS_URL of the platform:

   | Platform    | STATUS_URL                                                     |
   | ----------- | -------------------------------------------------------------- |
   | WooCommerce | `http://localhost:8081/wp-json/aneepay/v1/webhook`              |
   | OpenCart    | `http://localhost:8082/index.php?route=extension/payment/aneepay/callback` |
   | PrestaShop  | `http://localhost:8084/index.php?fc=module&module=ps_aneepay&controller=webhook` |

   On a staging shop replace the host and keep the path exactly as shown.

## Step 1 — get an order to point the event at

The endpoint resolves an order by `order_id`, `payment_id`, `operation_id`
(both spellings) or a `#N` in `description`. The simplest input is `order_id`,
but the order must belong to the gateway:

- **WooCommerce** — the order's payment method is `aneepay_crypto`.
- **OpenCart / PrestaShop** — the order has a row in `aneepay_order` /
  `ps_aneepay_order`.

The reliable way to get one is a sandbox checkout stopped on the payment page:
choose AneePay, let the plugin create the payment, then close the tab without
paying. The order stays `pending` and the mapping row carries the payment id.

Note the numeric order id — it goes into the payload below.

## Step 2 — build the body

The signature is computed over the **exact bytes** that are posted, so write the
body to a file once and reuse it. Compacting the JSON first avoids whitespace
differences between what you sign and what you send.

```bash
cd /tmp
cat > body.json <<'JSON'
{"version":1,"event_id":"9f1d3c2a-0000-4000-8000-000000000001","order_id":42,"status":"success","timestamp":1755262800.0,"is_sandbox":true}
JSON
```

`event_id` must be unique per case; it is the deduplication key. Without it the
plugin falls back to `sha256(body)`, which is fine too but makes deliberate
replays of *different* bodies impossible.

## Step 3 — sign and send

```bash
STATUS_URL='http://localhost:8084/index.php?fc=module&module=ps_aneepay&controller=webhook'
SECRET='<the webhook secret from the settings page>'

sig=$(openssl dgst -sha256 -hmac "$SECRET" -r body.json | cut -d' ' -f1)

curl -sS -o reply.json -w '%{http_code}\n' \
  -X POST "$STATUS_URL" \
  -H 'Content-Type: application/json' \
  -H "X-AneePay-Signature: $sig" \
  --data-binary @body.json

cat reply.json; echo
```

`--data-binary` is required: `-d` strips newlines and the signature would no
longer match. The same applies to the body you signed — if you edit
`body.json` afterwards, recompute `sig`.

## Step 4 — the cases

Send them in this order; each one builds on the state left by the previous.

| # | Case                                        | Expected | What to check                                                        |
|---|---------------------------------------------|----------|----------------------------------------------------------------------|
| 1 | Valid `success`, fresh `event_id`           | `200`    | Order is paid, history has one entry, stock restored once            |
| 2 | Replay of #1, identical body                | `200`, `"duplicate":true` | No second history entry, no second stock restore      |
| 3 | Same body, `X-AneePay-Signature` tampered   | `401`    | Nothing changes; the attempt is in the request log                   |
| 4 | Valid signature, `"status":"refunded"`      | `400`    | Nothing changes                                                      |
| 5 | Valid signature, body is not JSON (`{`)     | `400`    | Nothing changes                                                      |
| 6 | Valid signature, `order_id` of another shop order | `404` | Nothing changes                                                 |
| 7 | `failed` with a fresh `event_id`, then `success` | `200`, `200` | Order ends up paid, not left failed                     |
| 8 | Sandbox flag flipped (`"is_sandbox":false`) | `200`, `"ignored":"sandbox_mismatch"` | Status not applied, logged as an error       |
| 9 | Secret emptied on the settings page, then #1 | `401`    | Nothing changes; restore the secret afterwards                       |

Case 3 is the one that catches the most real mistakes: a signature computed over
a pretty-printed body, or a header name that the shop silently drops.

## Step 5 — verify in the shop

- **Order page** — status matches the last accepted event, and the history has
  one entry per applied event, not one per delivery.
- **Note / comment** — `AneePay: payment confirmed.` is attached to the entry
  (WooCommerce and OpenCart write it per history entry, PrestaShop appends it to
  the order's internal note).
- **Event table** — `aneepay_event` (WooCommerce, OpenCart) or
  `ps_aneepay_event` (PrestaShop) has one row per accepted event, with the
  outcome of the sandbox mismatch recorded.
- **Request log** — the plugin settings page lists the rejected attempts
  (401/400) and the sandbox mismatch.

Both the order history and the event table are idempotent by design: if case 2
adds a row, the deduplication is broken and the release must be stopped.

## Step 6 — clean up

- Delete the test order, or set it back to cancelled, so it does not pollute the
  shop's reports.
- Restore the webhook secret if case 9 was run.
- Clear the request log from the settings page.
