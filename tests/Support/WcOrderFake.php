<?php
/**
 * Minimal WooCommerce order double for the status applier.
 *
 * `aneepay_apply_payment_status()` is duck-typed: it only calls a handful of
 * methods, so a plain object with the same surface is enough.
 *
 * @package AneePay_Crypto_Gateway
 */

declare(strict_types=1);

namespace AneePay\Tests\Support;

/**
 * Records everything the applier does to an order.
 */
class WcOrderFake {
	/**
	 * Order meta, keyed by name.
	 *
	 * @var array<string, mixed>
	 */
	public $meta = array();

	/**
	 * Statuses passed to update_status().
	 *
	 * @var array<int, string>
	 */
	public $statuses = array();

	/**
	 * Order notes added through either API.
	 *
	 * @var array<int, string>
	 */
	public $notes = array();

	/**
	 * Whether payment_complete() was called.
	 *
	 * @var bool
	 */
	public $completed = false;

	/**
	 * Whether the order counts as paid.
	 *
	 * @var bool
	 */
	public $paid = false;

	/**
	 * Number of save() calls.
	 *
	 * @var int
	 */
	public $saves = 0;

	/**
	 * Read one meta value.
	 *
	 * @param string $key     Meta key.
	 * @param bool   $single  Unused, mirrors the WordPress signature.
	 * @return mixed
	 */
	public function get_meta( $key, $single = true ) {
		return isset( $this->meta[ $key ] ) ? $this->meta[ $key ] : '';
	}

	/**
	 * Write one meta value.
	 *
	 * @param string $key   Meta key.
	 * @param mixed  $value Value.
	 * @return void
	 */
	public function update_meta_data( $key, $value ) {
		$this->meta[ $key ] = $value;
	}

	/**
	 * Change the order status.
	 *
	 * @param string $status New status.
	 * @param string $note   Status note.
	 * @return void
	 */
	public function update_status( $status, $note = '' ) {
		$this->statuses[] = (string) $status;

		if ( '' !== (string) $note ) {
			$this->notes[] = (string) $note;
		}
	}

	/**
	 * Complete the payment.
	 *
	 * @return void
	 */
	public function payment_complete() {
		$this->completed = true;
		$this->paid      = true;
	}

	/**
	 * Whether the order is paid.
	 *
	 * @return bool
	 */
	public function is_paid() {
		return $this->paid;
	}

	/**
	 * Persist the order.
	 *
	 * @return void
	 */
	public function save() {
		++$this->saves;
	}

	/**
	 * Order id.
	 *
	 * @return int
	 */
	public function get_id() {
		return 101;
	}

	/**
	 * Add an order note.
	 *
	 * @param string $note Note text.
	 * @return void
	 */
	public function add_order_note( $note ) {
		$this->notes[] = (string) $note;
	}
}
