# Plugins — AneePay implementations for CMSs

The monorepo has one folder per platform. The code is fully isolated: each
folder can be packaged into its own artifact and released independently.

| Folder | Platform | Package (`scripts/pack.sh`) | Artifact | Status |
| ------------------------------ | --------- | -------------------------- | -------- | ------ |
| `plugins/woocommerce/aneepay/` | WooCommerce | `./scripts/pack.sh wc`  | `dist/wc/woocommerce-aneepay-<ver>.zip` | beta |
| `plugins/opencart/aneepay/`    | OpenCart 3.x | `./scripts/pack.sh oc` | `dist/oc/opencart-aneepay-<ver>.ocmod.zip` | beta |
| `plugins/prestashop/ps_aneepay/` | PrestaShop 1.7/8 | `./scripts/pack.sh ps` | `dist/ps/prestashop-aneepay-<ver>.zip` | beta |

Shared materials:

- `VERSION` — the shared release version; the single source of truth for the build.
- `scripts/pack.sh` — builds each platform artifact.
- `docker/` — local test shops per platform.
