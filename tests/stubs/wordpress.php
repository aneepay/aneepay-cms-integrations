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
