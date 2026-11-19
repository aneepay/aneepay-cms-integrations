<?php
/**
 * WordPress surface needed to load the plugin files under test.
 *
 * The plugins themselves are procedural and call WordPress only at run time;
 * at include time they need `ABSPATH`, `add_action()` and the base gateway
 * class. Anything richer belongs in a WordPress test installation, not here.
 *
 * @package AneePay_Crypto_Gateway
 */

defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/' );

if ( ! function_exists( 'add_action' ) ) {
	/**
	 * Record-free stub: the tests never trigger hooks.
	 *
	 * @param string   $hook_name     Hook name.
	 * @param callable $callback      Callback.
	 * @param int      $priority      Priority.
	 * @param int      $accepted_args Accepted args.
	 * @return void
	 */
	function add_action( $hook_name, $callback, $priority = 10, $accepted_args = 1 ) {
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	/**
	 * Record-free stub: the tests never trigger hooks.
	 *
	 * @param string   $hook_name     Hook name.
	 * @param callable $callback      Callback.
	 * @param int      $priority      Priority.
	 * @param int      $accepted_args Accepted args.
	 * @return void
	 */
	function add_filter( $hook_name, $callback, $priority = 10, $accepted_args = 1 ) {
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	/**
	 * Pass the value through untouched.
	 *
	 * @param string $hook_name Hook name.
	 * @param mixed  $value     Value.
	 * @return mixed
	 */
	function apply_filters( $hook_name, $value ) {
		return $value;
	}
}

if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
	/**
	 * Base class only: the gateway is never instantiated in unit tests.
	 */
	class WC_Payment_Gateway {
	}
}

defined( 'HOUR_IN_SECONDS' ) || define( 'HOUR_IN_SECONDS', 3600 );

if ( ! function_exists( '__' ) ) {
	/**
	 * Translation stub: the source string is the message.
	 *
	 * @param string $text   Text.
	 * @param string $domain Text domain.
	 * @return string
	 */
	function __( $text, $domain = 'default' ) {
		return $text;
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	/**
	 * Lowercase and keep the characters WordPress allows in a key.
	 *
	 * @param string $key Raw key.
	 * @return string
	 */
	function sanitize_key( $key ) {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	/**
	 * Strip tags and line breaks, mirroring WordPress closely enough for tests.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	function sanitize_text_field( $value ) {
		return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $value ) ) );
	}
}

if ( ! function_exists( 'wc_increase_stock_levels' ) ) {
	/**
	 * Count stock restorations instead of touching a database.
	 *
	 * @param int $order_id Order id.
	 * @return void
	 */
	function wc_increase_stock_levels( $order_id ) {
		$GLOBALS['aneepay_test_restock_count'] = isset( $GLOBALS['aneepay_test_restock_count'] )
			? (int) $GLOBALS['aneepay_test_restock_count'] + 1
			: 1;
	}
}

if ( ! function_exists( 'get_woocommerce_currency' ) ) {
	/**
	 * Store currency for the converter, controlled per test.
	 *
	 * @return string
	 */
	function get_woocommerce_currency() {
		return isset( $GLOBALS['aneepay_test_currency'] ) ? $GLOBALS['aneepay_test_currency'] : 'USD';
	}
}

if ( ! function_exists( 'get_transient' ) ) {
	/**
	 * In-memory transient store.
	 *
	 * @param string $key Key.
	 * @return mixed
	 */
	function get_transient( $key ) {
		return isset( $GLOBALS['aneepay_test_transients'][ $key ] ) ? $GLOBALS['aneepay_test_transients'][ $key ] : false;
	}
}

if ( ! function_exists( 'set_transient' ) ) {
	/**
	 * In-memory transient store: the expiry is ignored on purpose.
	 *
	 * @param string $key        Key.
	 * @param mixed  $value      Value.
	 * @param int    $expiration Lifetime in seconds.
	 * @return bool
	 */
	function set_transient( $key, $value, $expiration = 0 ) {
		$GLOBALS['aneepay_test_transients'][ $key ] = $value;

		return true;
	}
}

if ( ! function_exists( 'delete_transient' ) ) {
	/**
	 * In-memory transient store.
	 *
	 * @param string $key Key.
	 * @return bool
	 */
	function delete_transient( $key ) {
		unset( $GLOBALS['aneepay_test_transients'][ $key ] );

		return true;
	}
}

/**
 * Set the store currency used by the converter.
 *
 * @param string $currency Currency code.
 * @return void
 */
function aneepay_test_set_currency( $currency ) {
	$GLOBALS['aneepay_test_currency'] = (string) $currency;
}

/**
 * Empty the in-memory transient store.
 *
 * @return void
 */
function aneepay_test_reset_transients() {
	$GLOBALS['aneepay_test_transients'] = array();
}

/**
 * Seed one transient.
 *
 * @param string $key   Key.
 * @param mixed  $value Value.
 * @return void
 */
function aneepay_test_set_transient( $key, $value ) {
	$GLOBALS['aneepay_test_transients'][ $key ] = $value;
}
