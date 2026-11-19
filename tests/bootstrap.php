<?php
/**
 * PHPUnit bootstrap.
 *
 * Loads the composer autoloader and the platform plugin files under test. The
 * plugins target three different platforms, so the files are included directly
 * instead of being autoloaded: there is no shared namespace and the platform
 * classes do not exist here.
 *
 * @package AneePay_Crypto_Gateway
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/stubs/wordpress.php';

$plugin_root = dirname( __DIR__ ) . '/plugins';

// WooCommerce: the webhook signature and operation_id helpers live in the
// gateway file, next to the class that uses them.
require_once $plugin_root . '/woocommerce/aneepay/includes/class-wc-gateway-crypto.php';
require_once $plugin_root . '/woocommerce/aneepay/includes/class-usd-converter.php';

// OpenCart: the API client carries signature verification and operation_id.
require_once $plugin_root . '/opencart/aneepay/system/library/aneepay/aneepay.php';
require_once $plugin_root . '/opencart/aneepay/system/library/aneepay/usd-converter.php';

// PrestaShop: same responsibilities on the AneePayApi class.
require_once $plugin_root . '/prestashop/ps_aneepay/classes/AneePayApiException.php';
require_once $plugin_root . '/prestashop/ps_aneepay/classes/AneePayApi.php';
require_once $plugin_root . '/prestashop/ps_aneepay/classes/AneePayUsdConverter.php';
