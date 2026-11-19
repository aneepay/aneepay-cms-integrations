<?php
/**
 * PrestaShop: mapping a payment status onto an order.
 *
 * `AneePayWebhook` talks to PrestaShop through Order/OrderHistory/Configuration,
 * which are replaced by the doubles in tests/stubs/prestashop.php.
 *
 * @package AneePay_Crypto_Gateway
 */

declare(strict_types=1);

namespace AneePay\Tests\Unit\PrestaShop;

use AneePay\Tests\Support\StatusMappingContractTestCase;

/**
 * Mapping table double.
 */
final class PsOrderModelFake {
	/**
	 * Mapping row, or null when the order has none.
	 *
	 * @var array<string, mixed>|null
	 */
	public $row = null;

	/**
	 * Statuses written to the mapping.
	 *
	 * @var array<int, string>
	 */
	public $updates = array();

	/**
	 * Whether stock is already back.
	 *
	 * @var bool
	 */
	public $stock_restored = false;

	/**
	 * Read the mapping for an order.
	 *
	 * @param int $id_order Order id.
	 * @return array<string, mixed>|null
	 */
	public function getByOrder( $id_order ) {
		return $this->row;
	}

	/**
	 * Store the payment status.
	 *
	 * @param int    $id_order Order id.
	 * @param string $status   Payment status.
	 * @return bool
	 */
	public function updateStatus( $id_order, $status ) {
		$this->updates[] = (string) $status;

		if ( null !== $this->row ) {
			$this->row['payment_status'] = (string) $status;
		}

		return true;
	}

	/**
	 * Whether stock was restored.
	 *
	 * @param int $id_order Order id.
	 * @return bool
	 */
	public function isStockRestored( $id_order ) {
		return $this->stock_restored;
	}

	/**
	 * Remember that stock was restored.
	 *
	 * @param int $id_order Order id.
	 * @return void
	 */
	public function markStockRestored( $id_order ) {
		$this->stock_restored = true;
	}
}

/**
 * Contract tests for the PrestaShop status applier.
 */
final class StatusMappingTest extends StatusMappingContractTestCase {
	/**
	 * Payment status => PrestaShop order state id.
	 *
	 * @var array<string, int>
	 */
	private const STATE_MAP = array(
		'pending'   => 1,
		'success'   => 2,
		'failed'    => 8,
		'cancelled' => 6,
	);

	/**
	 * Order id used everywhere.
	 *
	 * @var int
	 */
	private const ORDER_ID = 77;

	/**
	 * Mapping table double.
	 *
	 * @var PsOrderModelFake
	 */
	private $model;

	/**
	 * Webhook under test.
	 *
	 * @var \AneePayWebhook
	 */
	private $webhook;

	/**
	 * Fresh doubles before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->resetOrder( '' );
	}

	/**
	 * Rebuild the doubles for an order with the given payment status.
	 *
	 * @param string $currentStatus Stored payment status.
	 * @return void
	 */
	protected function resetOrder( string $currentStatus ): void {
		$this->model = new PsOrderModelFake();
		$this->model->row = array(
			'id_order'       => self::ORDER_ID,
			'payment_status' => $currentStatus,
		);

		if ( in_array( $currentStatus, array( 'failed', 'cancelled' ), true ) ) {
			$this->model->stock_restored = true;
		}

		\Order::$states             = array( self::ORDER_ID => 1 );
		\Order::$existing_note      = '';
		\Order::$notes              = array();
		\Order::$last_note          = '';
		\OrderHistory::$entries     = array();
		\Context::reset();

		$this->webhook = new \AneePayWebhook( null, $this->model, self::STATE_MAP );

		$GLOBALS['aneepay_test_restock_count'] = 0;
	}

	/**
	 * Apply a status through the webhook.
	 *
	 * @param string $status Payment status.
	 * @param string $note   Order note.
	 * @return bool
	 */
	protected function applyStatus( string $status, string $note = '' ): bool {
		return (bool) $this->webhook->apply( self::ORDER_ID, $status, $note );
	}

	/**
	 * Stored payment status.
	 *
	 * @return string
	 */
	protected function storedStatus(): string {
		return isset( $this->model->row['payment_status'] ) ? (string) $this->model->row['payment_status'] : '';
	}

	/**
	 * Order history states written so far.
	 *
	 * @return array<int, int>
	 */
	protected function transitions(): array {
		return array_map(
			static function ( $entry ) {
				return $entry[1];
			},
			\OrderHistory::$entries
		);
	}

	/**
	 * Order notes written to the order, one per applied status.
	 *
	 * PrestaShop has no note column in the order history, so the note lands in
	 * the order's internal note field; the double records the lines it saw.
	 *
	 * @return array<int, string>
	 */
	protected function notes(): array {
		return \Order::$notes;
	}

	/**
	 * Manual stock restorations.
	 *
	 * @return int
	 */
	protected function restockCount(): int {
		return isset( $GLOBALS['aneepay_test_restock_count'] ) ? (int) $GLOBALS['aneepay_test_restock_count'] : 0;
	}

	/**
	 * The configured state id is written to the order history.
	 *
	 * @return void
	 */
	public function test_writes_the_configured_state(): void {
		$this->resetOrder( 'pending' );

		$this->applyStatus( 'success' );

		$this->assertSame( array( 2 ), $this->transitions() );
	}

	/**
	 * PrestaShop restocks natively when the target state is an error state.
	 *
	 * @return void
	 */
	public function test_native_restock_is_not_repeated(): void {
		$this->resetOrder( 'pending' );

		$this->applyStatus( 'failed' );

		$this->assertSame( array( 8 ), $this->transitions() );
		$this->assertSame( 0, $this->restockCount() );
		$this->assertTrue( $this->model->stock_restored );
	}

	/**
	 * A custom failure state is not an error state, so the plugin restocks.
	 *
	 * @return void
	 */
	public function test_manual_restock_runs_for_a_custom_failure_state(): void {
		$this->resetOrder( 'pending' );
		$this->webhook = new \AneePayWebhook(
			null,
			$this->model,
			array(
				'pending'   => 1,
				'success'   => 2,
				'failed'    => 12,
				'cancelled' => 13,
			)
		);

		$this->applyStatus( 'failed' );

		$this->assertSame( array( 12 ), $this->transitions() );
		$this->assertSame( 1, $this->restockCount() );
	}

	/**
	 * An order that was already failed never reserved stock again.
	 *
	 * @return void
	 */
	public function test_no_restock_when_the_order_was_already_failed(): void {
		$this->resetOrder( 'pending' );
		\Order::$states[ self::ORDER_ID ] = 8;

		$this->webhook = new \AneePayWebhook(
			null,
			$this->model,
			array(
				'pending'   => 1,
				'success'   => 2,
				'failed'    => 12,
				'cancelled' => 13,
			)
		);

		$this->applyStatus( 'cancelled' );

		$this->assertSame( 0, $this->restockCount() );
		$this->assertTrue( $this->model->stock_restored );
	}

	/**
	 * An unmapped state id still updates the mapping.
	 *
	 * @return void
	 */
	public function test_an_empty_state_id_keeps_the_mapping_in_sync(): void {
		$this->resetOrder( 'pending' );
		$this->webhook = new \AneePayWebhook(
			null,
			$this->model,
			array(
				'pending'   => 1,
				'success'   => 2,
				'failed'    => 0,
				'cancelled' => 0,
			)
		);

		$this->assertTrue( $this->applyStatus( 'failed' ) );

		$this->assertSame( 'failed', $this->storedStatus() );
		$this->assertCount( 0, $this->transitions() );
	}

	/**
	 * A status the map does not know is rejected before any write.
	 *
	 * @return void
	 */
	public function test_unknown_status_does_not_touch_the_mapping(): void {
		$this->resetOrder( 'pending' );

		$this->assertFalse( $this->applyStatus( 'refunded' ) );
		$this->assertSame( array(), $this->model->updates );
	}

	/**
	 * The webhook note lands in the order's internal note field.
	 *
	 * @return void
	 */
	public function test_records_the_note_on_the_order(): void {
		$this->resetOrder( 'pending' );

		$this->applyStatus( 'failed', 'AneePay: payment failed.' );

		$this->assertSame( array( 'AneePay: payment failed.' ), \Order::$notes );
		$this->assertSame( 'AneePay: payment failed.', \Order::$last_note );
	}

	/**
	 * A note the merchant already wrote is kept, not replaced.
	 *
	 * @return void
	 */
	public function test_appends_to_the_note_the_merchant_wrote(): void {
		$this->resetOrder( 'pending' );
		\Order::$existing_note = 'Called the buyer about the delivery.';

		$this->applyStatus( 'cancelled', 'AneePay: payment cancelled by the customer.' );

		$this->assertSame(
			"Called the buyer about the delivery.\nAneePay: payment cancelled by the customer.",
			\Order::$last_note
		);
	}

	/**
	 * A delivery without a note writes nothing.
	 *
	 * @return void
	 */
	public function test_an_empty_note_does_not_touch_the_order(): void {
		$this->resetOrder( 'pending' );

		$this->applyStatus( 'success' );

		$this->assertSame( '', \Order::$last_note );
		$this->assertSame( array(), \Order::$notes );
	}
}
