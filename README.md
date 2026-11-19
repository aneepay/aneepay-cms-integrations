# AneePay CMS Integrations

AneePay plugins (crypto payments for USDT/USDC/DAI on Polygon + Amoy) for three
platforms in a single repository. Each plugin lives in its own folder and is
packaged into a separate artifact.

- **AneePay** — a non-custodial crypto payment system with a 0.5% fee, no KYC,
  hosted checkout, HMAC-SHA256-signed webhooks, and a sandbox (Amoy).
- API documentation and the payment model are not part of this repository:
  see <https://docs.aneepay.com>.

## Plugins

| Platform         | Folder                                                           | Build                  | Artifact                          |
| ---------------- | ---------------------------------------------------------------- | ---------------------- | --------------------------------- |
| WooCommerce      | [`plugins/woocommerce/aneepay`](plugins/woocommerce/aneepay)     | `./scripts/pack.sh wc` | `dist/wc/woocommerce-aneepay-<ver>.zip`        |
| OpenCart 3.x     | [`plugins/opencart/aneepay`](plugins/opencart/aneepay)           | `./scripts/pack.sh oc` | `dist/oc/opencart-aneepay-<ver>.ocmod.zip`     |
| PrestaShop 1.7/8 | [`plugins/prestashop/ps_aneepay`](plugins/prestashop/ps_aneepay) | `./scripts/pack.sh ps` | `dist/ps/prestashop-aneepay-<ver>.zip`         |

Status: all three plugins work end to end but are in **beta** — known gaps are
listed in [`docs/limitations-beta.md`](docs/limitations-beta.md). Installation
and setup for each platform are documented at
<https://docs.aneepay.com/cms/>; these READMEs are the development reference.

## Build

```bash
./scripts/pack.sh wc     # or oc, ps, all
```

The script lints PHP (`php -l`), validates the platform structure/manifest and
writes the artifact to `dist/<platform>/`. `VERSION` is the only place the
release version is set: the build fails if the WooCommerce header/`ANEEPAY_VERSION`,
OpenCart `install.xml` or PrestaShop `$version` diverge from it, and the version
is stamped into the staged copy before zipping. Each platform is packaged with
the correct root path.

`./scripts/pack.sh check` runs the same verification (version sources + `php -l`)
without packaging anything; CI requires it before any release, and it is the
fast local pre-commit check.

Every build writes `dist/SHA256SUMS` for the artifacts it produced, and stale
artifacts of other versions are removed from `dist/<platform>/` first:

```bash
cd dist && sha256sum -c SHA256SUMS
```

## Tests

The unit tests run against all three plugins from one PHPUnit run: the platform
APIs are stubbed in `tests/stubs/`, so no shop is needed. PHP is not required on
the host — use the same container images as CI:

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 composer install
docker run --rm -v "$PWD":/app -w /app php:8.2-cli php vendor/bin/phpunit
```

Swap `php:8.2-cli` for `php:7.4-cli` to check the oldest supported PHP. CI runs
the suite on PHP 7.4, 8.0 and 8.3, and each matrix entry also runs
`./scripts/pack.sh check`, so a syntax error that only parses on 8.x cannot
reach a release. `vendor/` and `composer.lock` are not committed.

The webhook endpoint itself is outside what unit tests can prove — the header
name, the raw body and the deduplication only exist behind a real request.
[`docs/WEBHOOK-SMOKE.md`](docs/WEBHOOK-SMOKE.md) is the manual checklist to run
before a release.

## Local development

### WooCommerce

```bash
cd docker/woocommerce
docker compose up --watch
# WooCommerce: http://localhost:8081  (admin / admin)
```

### OpenCart

```bash
cd docker/opencart
docker compose up --build
# OpenCart: http://localhost:8082  (admin / admin)
```

Each container bind-mounts (WooCommerce) or installs (OpenCart, PrestaShop) the
matching plugin, so changes are picked up with a rebuild.

### PrestaShop

```bash
cd docker/prestashop
docker compose up --build
# PrestaShop: http://localhost:8084  (admin / admin)
```

## Structure

```
.
├── VERSION                            # shared release version
├── plugins/
│   ├── woocommerce/aneepay/           # WC plugin (PHP)
│   ├── opencart/aneepay/              # OC extension (PHP)
│   └── prestashop/ps_aneepay/         # PS module (PHP)
├── scripts/pack.sh                    # build / package artifacts
├── docker/                            # local test shops (woocommerce/, opencart/, prestashop/)
└── .github/workflows/release.yml      # CI: lint + package + release
```

> See [`TECHDEBT.md`](TECHDEBT.md) for the planned extraction of a shared
> platform-agnostic core (currently the pure logic is duplicated per plugin).
