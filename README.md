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
| WooCommerce      | [`plugins/woocommerce/aneepay`](plugins/woocommerce/aneepay)     | `./scripts/pack.sh wc` | `dist/wc/aneepay.zip`             |
| OpenCart 3.x     | [`plugins/opencart/aneepay`](plugins/opencart/aneepay)           | `./scripts/pack.sh oc` | `dist/oc/aneepay-<ver>.ocmod.zip` |
| PrestaShop 1.7/8 | [`plugins/prestashop/ps_aneepay`](plugins/prestashop/ps_aneepay) | `./scripts/pack.sh ps` | `dist/ps/ps_aneepay.zip`          |

Status: all three plugins are working implementations.

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
