#!/usr/bin/env bash
#
# pack.sh — собирает (упаковывает) плагин AneePay под конкретную CMS.
#
# Использование:
#   ./scripts/pack.sh <platform>
#
#   platform  wc | oc | ps         (или "all" для всех трёх)
#             check                (только проверки, без упаковки)
#
# Версия берётся ТОЛЬКО из ./VERSION (единый источник истины). Второй
# аргумент убран намеренно: передавать версию мимо VERSION нельзя.
#
# После сборки контрольные суммы артефактов пишутся в dist/SHA256SUMS.
#
# Примеры:
#   ./scripts/pack.sh wc                       # → dist/wc/woocommerce-aneepay-<ver>.zip
#   ./scripts/pack.sh oc                       # → dist/oc/opencart-aneepay-<ver>.ocmod.zip
#   ./scripts/pack.sh ps                       # → dist/ps/prestashop-aneepay-<ver>.zip
#   ./scripts/pack.sh check                    # версия + линт, без архивов
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

DIST="$ROOT/dist"

fail() { echo "ERROR: $*" >&2; exit 1; }
warn() { echo "WARN: $*" >&2; }

PLATFORM="${1:-}"
[ "$#" -le 1 ] || fail "версия задаётся только в ./VERSION, аргумент версии убран"

[ -f VERSION ] || fail "нет файла VERSION"
VERSION="$(tr -d '[:space:]' < VERSION)"
[ -n "$VERSION" ] || fail "файл VERSION пустой"

SRC_WC="plugins/woocommerce/aneepay"
SRC_OC="plugins/opencart/aneepay"
SRC_PS="plugins/prestashop/ps_aneepay"

# ---------------------------------------------------------------------------
# version — единый источник истины
#
# VERSION — единственное место, где версия задаётся руками. Остальные
# носители версии (WC header + ANEEPAY_VERSION, OC install.xml, PS
# $this->version) обязаны совпадать с ним: расхождение = ошибка сборки.
# В артефакт версия всё равно штампуется из VERSION (см. stamp_*), чтобы
# архив не зависел от того, обновили ли поля в исходниках.
# ---------------------------------------------------------------------------

wc_header_version() {
  sed -nE 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*([^[:space:]]+)[[:space:]]*$/\1/p' \
    "${1:-$SRC_WC}/aneepay.php" | head -n 1
}

wc_const_version() {
  sed -nE "s@^[[:space:]]*define\( 'ANEEPAY_VERSION', '([^']+)' \);[[:space:]]*\$@\1@p" \
    "${1:-$SRC_WC}/aneepay.php" | head -n 1
}

oc_manifest_version() {
  sed -nE 's@.*<version>([^<]+)</version>.*@\1@p' "${1:-$SRC_OC}/install.xml" | head -n 1
}

ps_module_version() {
  sed -nE "s@^[[:space:]]*\\\$this->version[[:space:]]*=[[:space:]]*'([^']+)';.*@\1@p" \
    "${1:-$SRC_PS}/ps_aneepay.php" | head -n 1
}

# Список «носителей версии» в исходниках (проверяется на расхождение с VERSION).
version_sources() {
  printf '%s\t%s\n' \
    "plugins/woocommerce/aneepay/aneepay.php: * Version:" "$(wc_header_version)" \
    "plugins/woocommerce/aneepay/aneepay.php: ANEEPAY_VERSION" "$(wc_const_version)" \
    "plugins/opencart/aneepay/install.xml: <version>" "$(oc_manifest_version)" \
    "plugins/prestashop/ps_aneepay/ps_aneepay.php: \$this->version" "$(ps_module_version)"
}

verify_versions() {
  local bad=0 name value
  echo "-- проверка единого источника версии (VERSION=$VERSION)"
  while IFS=$'\t' read -r name value; do
    if [ -z "$value" ]; then
      echo "   [x] $name — не найдено" >&2
      bad=1
    elif [ "$value" != "$VERSION" ]; then
      echo "   [x] $name = $value, ожидалось $VERSION" >&2
      bad=1
    else
      echo "   [ok] $name = $value"
    fi
  done < <(version_sources)
  [ "$bad" -eq 0 ] || fail "версия расходится с VERSION (VERSION=$VERSION): правь VERSION и источники вместе"
}

# In-place sed, переносимый между GNU и BSD/macOS.
sed_i() {
  local file="$1" expr="$2" tmp="$1.tmp.$$"
  sed -E "$expr" "$file" > "$tmp" && mv "$tmp" "$file"
}

stamp_version_wc() {
  local base="$1"
  sed_i "$base/aneepay.php" \
    "s@^[[:space:]]*\\*[[:space:]]*Version:.*@ * Version:     $VERSION@"
  sed_i "$base/aneepay.php" \
    "s@^([[:space:]]*define\\( 'ANEEPAY_VERSION', ')[^']+(' \\);)\$@\\1$VERSION\\2@"
}

stamp_version_oc() {
  local base="$1"
  sed_i "$base/install.xml" "s@<version>[^<]*</version>@<version>$VERSION</version>@"
}

stamp_version_ps() {
  local base="$1"
  sed_i "$base/ps_aneepay.php" \
    "s@^([[:space:]]*\\\$this->version[[:space:]]*=[[:space:]]*')[^']+(';)\$@\\1$VERSION\\2@"
}

# Страховка: артефакт уходит в архив только с версией из VERSION.
assert_version_in_staging() {
  local platform="$1" base="$2" got=""
  case "$platform" in
    wc) got="$(wc_header_version "$base")" ;;
    oc) got="$(oc_manifest_version "$base")" ;;
    ps) got="$(ps_module_version "$base")" ;;
  esac
  [ "$got" = "$VERSION" ] || fail "после штамповки $platform: версия '$got', ожидалось '$VERSION'"
}

# ---------------------------------------------------------------------------
# helpers
# ---------------------------------------------------------------------------

php_lint() {
  local base="$1"
  command -v php >/dev/null 2>&1 || { warn "php не найден — линт пропущен"; return 0; }
  local found=0 errors=0 f
  while IFS= read -r -d '' f; do
    found=1
    if ! php -l "$f" >/dev/null 2>&1; then
      php -l "$f" || true
      errors=$((errors + 1))
    fi
  done < <(find "$base" -type f -name '*.php' -print0)
  [ "$found" = 1 ] || warn "нет .php файлов в $base"
  [ "$errors" -eq 0 ] || fail "php -l: $errors ошибок в $base"
}

check_platform() {
  local p="$1"
  case "$p" in
    wc)
      [ -f "$SRC_WC/aneepay.php" ] || fail "нет $SRC_WC/aneepay.php"
      grep -qE '^ \* Version:' "$SRC_WC/aneepay.php" || warn "нет WP-header 'Version:' в aneepay.php"
      ;;
    oc)
      [ -d "$SRC_OC/upload" ] || [ -d "$SRC_OC/admin" ] || fail "нет исходников расширения OC"
      [ -f "$SRC_OC/install.xml" ] \
        || warn "нет install.xml (для ocmod/загрузки расширения OC 3.x оба формата валидны)"
      ;;
    ps)
      local dir
      while IFS= read -r -d '' dir; do
        [ -f "$dir/index.php" ] || fail "нет index.php (требование PrestaShop): $dir"
      done < <(find "$SRC_PS" -type d -print0)
      [ -f "$SRC_PS/logo.png" ] || warn "нет logo.png в $SRC_PS"
      ;;
  esac
}

# Zip the *contents* of a staging directory (so the caller controls the root path).
zip_stage() {
  local stage="$1" out="$2"
  ( cd "$stage" && zip -qr "$out" . )
  rm -rf "$stage"
}

src_of() {
  case "$1" in
    wc) echo "$SRC_WC" ;;
    oc) echo "$SRC_OC" ;;
    ps) echo "$SRC_PS" ;;
    *) fail "неизвестная платформа: $1" ;;
  esac
}

# Artifact name of a platform at the current VERSION.
artifact_of() {
  case "$1" in
    wc) echo "$DIST/wc/woocommerce-aneepay-${VERSION}.zip" ;;
    oc) echo "$DIST/oc/opencart-aneepay-${VERSION}.ocmod.zip" ;;
    ps) echo "$DIST/ps/prestashop-aneepay-${VERSION}.zip" ;;
  esac
}

# Drop artifacts of other versions so dist/ never holds a stale archive next to
# the fresh one (and SHA256SUMS stays unambiguous).
clean_platform_dist() {
  case "$1" in
    wc) rm -f "$DIST/wc/woocommerce-aneepay-"*.zip "$DIST/wc/aneepay.zip" ;;
    oc) rm -f "$DIST/oc/opencart-aneepay-"*.ocmod.zip "$DIST/oc/aneepay-"*.ocmod.zip ;;
    ps) rm -f "$DIST/ps/prestashop-aneepay-"*.zip "$DIST/ps/ps_aneepay.zip" ;;
  esac
}

sha256_of() {
  if command -v sha256sum >/dev/null 2>&1; then
    sha256sum "$1"
  else
    shasum -a 256 "$1"   # BSD/macOS
  fi
}

# Write dist/SHA256SUMS for every artifact that exists in dist/. Paths are
# relative to dist/ so `cd dist && sha256sum -c SHA256SUMS` works as-is.
write_checksums() {
  local out="$DIST/SHA256SUMS" tmp="$DIST/SHA256SUMS.tmp.$$" p f rel
  : > "$tmp"
  for p in wc oc ps; do
    f="$(artifact_of "$p")"
    [ -f "$f" ] || continue
    rel="${f#"$DIST"/}"
    ( cd "$DIST" && sha256_of "$rel" ) >> "$tmp"
  done
  if [ -s "$tmp" ]; then
    mv "$tmp" "$out"
    echo "   -> $out"
  else
    rm -f "$tmp"
    warn "нет артефактов — SHA256SUMS не создан"
  fi
}

# ---------------------------------------------------------------------------
# per-platform assembly
# ---------------------------------------------------------------------------

build_wc() {
  local stage dest
  stage="$(mktemp -d)"
  dest="$DIST/wc"; mkdir -p "$dest"
  mkdir -p "$stage/aneepay"
  cp -a "$SRC_WC/." "$stage/aneepay/"
  stamp_version_wc "$stage/aneepay"
  assert_version_in_staging wc "$stage/aneepay"
  zip_stage "$stage" "$dest/woocommerce-aneepay-${VERSION}.zip"
  echo "   -> $dest/woocommerce-aneepay-${VERSION}.zip"
}

build_oc() {
  local stage dest
  stage="$(mktemp -d)"
  dest="$DIST/oc"; mkdir -p "$dest"
  mkdir -p "$stage/upload/admin" "$stage/upload/catalog" "$stage/upload/system"
  cp -a "$SRC_OC/admin/." "$stage/upload/admin/"
  cp -a "$SRC_OC/catalog/." "$stage/upload/catalog/"
  cp -a "$SRC_OC/system/." "$stage/upload/system/"
  [ -f "$SRC_OC/install.xml" ] && cp "$SRC_OC/install.xml" "$stage/install.xml"
  stamp_version_oc "$stage"
  assert_version_in_staging oc "$stage"
  zip_stage "$stage" "$dest/opencart-aneepay-${VERSION}.ocmod.zip"
  echo "   -> $dest/opencart-aneepay-${VERSION}.ocmod.zip"
}

build_ps() {
  local stage dest
  stage="$(mktemp -d)"
  dest="$DIST/ps"; mkdir -p "$dest"
  mkdir -p "$stage/ps_aneepay"
  cp -a "$SRC_PS/." "$stage/ps_aneepay/"
  # config.xml is gitignored (**/config.xml): generate it from the canonical
  # VERSION so a fresh checkout always ships a matching module manifest. The
  # write happens after the copy so it wins over any stale local copy.
  # $this->version is stamped for the same reason.
  stamp_version_ps "$stage/ps_aneepay"
  assert_version_in_staging ps "$stage/ps_aneepay"
  write_ps_config "$stage/ps_aneepay/config.xml"
  zip_stage "$stage" "$dest/prestashop-aneepay-${VERSION}.zip"
  echo "   -> $dest/prestashop-aneepay-${VERSION}.zip"
}

# Generate the PrestaShop module manifest (config.xml) at the build version.
write_ps_config() {
  local out="$1"
  cat > "$out" <<EOF
<?xml version="1.0" encoding="UTF-8" ?>
<module>
    <name>ps_aneepay</name>
    <displayName><![CDATA[AneePay Crypto Gateway]]></displayName>
    <version><![CDATA[${VERSION}]]></version>
    <description><![CDATA[Accept stablecoin payments (USDT, USDC, DAI) on Polygon (live) or Amoy (testnet). Non-custodial, 0.5% fee, no KYC.]]></description>
    <author><![CDATA[AneePay]]></author>
    <tab><![CDATA[payments_gateways]]></tab>
    <confirmUninstall><![CDATA[Are you sure you want to uninstall AneePay?]]></confirmUninstall>
    <is_configurable>1</is_configurable>
    <need_instance>0</need_instance>
</module>
EOF
}

# ---------------------------------------------------------------------------
# main
# ---------------------------------------------------------------------------

[ -n "$PLATFORM" ] || fail "укажи платформу: wc | oc | ps | all (или check)"

# check: те же проверки, что перед сборкой, но без упаковки. Используется в CI
# (job verify) и локально перед коммитом.
if [ "$PLATFORM" = "check" ]; then
  verify_versions
  for p in wc oc ps; do
    src="$(src_of "$p")"
    [ -d "$src" ] || fail "нет каталога $src"
    echo "==> Проверка: $p"
    php_lint "$src"
    check_platform "$p"
  done
  echo "Проверки пройдены (версия и линт), артефакты не собирались."
  exit 0
fi

platforms=("$PLATFORM")
[ "$PLATFORM" = "all" ] && platforms=(wc oc ps)

verify_versions

mkdir -p "$DIST"

for p in "${platforms[@]}"; do
  src="$(src_of "$p")"

  [ -d "$src" ] || fail "нет каталога $src"

  echo "==> Упаковка: $p (v$VERSION)"
  php_lint "$src"
  check_platform "$p"
  clean_platform_dist "$p"

  case "$p" in
    wc) build_wc ;;
    oc) build_oc ;;
    ps) build_ps ;;
  esac
done

write_checksums

echo "Готово. Артефакты в $DIST"
