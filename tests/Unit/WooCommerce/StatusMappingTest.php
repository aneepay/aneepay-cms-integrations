<?php
/**
 * WooCommerce: mapping a payment status onto an order.
 *
 * @package AneePay_Crypto_Gateway
 */

declare(strict_types=1);

namespace AneePay\Tests\Unit\WooCommerce;

use AneePay\Tests\Support\StatusMappingContractTestCase;
use AneePay\Tests\Support\WcOrderFake;

/**
 * `aneepay_apply_payment_status()` is procedural, so the fixture is a double of
 * the WC_Order methods it calls.
 */
final class StatusMappingTest extends StatusMappingContractTestCase {
	/**
	 * Order handed to the applier.
	 *
	 * @var WcOrderFake
	 */
	private $order;

	/**
	 * Fresh order before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->resetOrder( '' );
	}

	/**
	 * Build an order that already carries the given payment status.
	 *
	 * A failed or cancelled order has already had its stock put back, so the
	 * fixture sets the same marker the applier sets.
	 *
	 * @param string $currentStatus Stored payment status.
	 * @return void
	 */
	protected function resetOrder( string $currentStatus ): void {
		$this->order = new WcOrderFake();

		if ( '' !== $currentStatus ) {
			$this->order->meta['_aneepay_payment_status'] = $currentStatus;
		}

		if ( in_array( $currentStatus, array( 'failed', 'cancelled' ), true ) ) {
			$this->order->meta['_aneepay_stock_restored'] = 1;
		}

		$GLOBALS['aneepay_test_restock_count'] = 0;
	}

	/**
	 * Apply a status; WooCommerce signals success by saving the order.
	 *
	 * @param string $status Payment status.
	 * @param string $note   Unused: the applier writes its own notes.
	 * @return bool
	 */
	protected function applyStatus( string $status, string $note = '' ): bool {
		$saves_before = $this->order->saves;

		aneepay_apply_payment_status( $this->order, $status );

		return $this->order->saves > $saves_before;
	}

	/**
	 * Stored payment status.
	 *
	 * @return string
	 */
	protected function storedStatus(): string {
		return (string) $this->order->get_meta( '_aneepay_payment_status', true );
	}

	/**
	 * Status moves plus the payment_complete() marker.
	 *
	 * @return array<int, string>
	 */
	protected function transitions(): array {
		$transitions = $this->order->statuses;

		if ( $this->order->completed ) {
			$transitions[] = 'payment_complete';
		}

		return $transitions;
	}

	/**
	 * Order notes.
	 *
	 * @return array<int, string>
	 */
	protected function notes(): array {
		return $this->order->notes;
	}

	/**
	 * Stock restorations.
	 *
	 * @return int
	 */
	protected function restockCount(): int {
		return isset( $GLOBALS['aneepay_test_restock_count'] ) ? (int) $GLOBALS['aneepay_test_restock_count'] : 0;
	}

	/**
	 * Every applied status leaves an order note for the merchant.
	 *
	 * @return void
	 */
	public function test_notes_the_transition(): void {
		foreach ( array( 'success', 'failed', 'cancelled', 'pending' ) as $status ) {
			$this->resetOrder( '' );

			$this->applyStatus( $status, 'AneePay: test note.' );

			$this->assertCount( 1, $this->notes(), $status . ' should be logged for the merchant' );
		}
	}

	/**
	 * Failure returns the stock reserved at checkout.
	 *
	 * @return void
	 */
	public function test_failure_restores_stock_once(): void {
		$this->resetOrder( 'pending' );

		$this->assertTrue( $this->applyStatus( 'failed' ) );

		$this->assertSame( 1, $this->restockCount() );
		$this->assertSame( 1, (int) $this->order->get_meta( '_aneepay_stock_restored', true ) );
	}

	/**
	 * A cancelled order that already failed is not restocked again.
	 *
	 * @return void
	 */
	public function test_cancelling_after_failure_does_not_restock_again(): void {
		$this->resetOrder( 'failed' );

		$this->assertTrue( $this->applyStatus( 'cancelled' ) );

		$this->assertSame( 'cancelled', $this->storedStatus() );
		$this->assertSame( 0, $this->restockCount() );
	}

	/**
	 * A pending payment never touches the stock.
	 *
	 * @return void
	 */
	public function test_pending_does_not_restock(): void {
		$this->resetOrder( '' );

		$this->applyStatus( 'pending' );

		$this->assertSame( 0, $this->restockCount() );
	}

	/**
	 * A paid payment completes the order.
	 *
	 * @return void
	 */
	public function test_success_completes_the_payment(): void {
		$this->resetOrder( 'pending' );
		aneepay_apply_payment_status( $this->order, 'success', array( 'operation_id' => '2095860140616323946138232506829736363' ) );

		$this->assertTrue( $this->order->completed );
		$this->assertTrue( $this->order->paid );
		$this->assertSame( array( 'payment_complete' ), $this->transitions() );
		$this->assertStringContainsString( 'Operation ID: 2095860140616323946138232506829736363', $this->notes()[0] );
	}

	/**
	 * An order already marked paid is not completed twice.
	 *
	 * @return void
	 */
	public function test_success_does_not_complete_a_paid_order_again(): void {
		$this->resetOrder( 'failed' );
		$this->order->paid = true;

		$this->assertTrue( $this->applyStatus( 'success' ) );

		$this->assertSame( 'success', $this->storedStatus() );
		$this->assertFalse( $this->order->completed );
		$this->assertCount( 0, $this->notes() );
	}

	/**
	 * Failure moves the order to failed and restores stock.
	 *
	 * @return void
	 */
	public function test_failure_uses_the_failed_status(): void {
		$this->resetOrder( 'pending' );
		aneepay_apply_payment_status( $this->order, 'failed', array( 'error' => 'Transaction reverted' ) );

		$this->assertSame( array( 'failed' ), $this->order->statuses );
		$this->assertStringContainsString( 'Reason: Transaction reverted', $this->notes()[0] );
		$this->assertSame( 1, (int) $this->order->get_meta( '_aneepay_stock_restored', true ) );
	}

	/**
	 * Cancellation is a distinct status, not a failure.
	 *
	 * @return void
	 */
	public function test_cancellation_uses_the_cancelled_status(): void {
		$this->resetOrder( 'pending' );
		aneepay_apply_payment_status( $this->order, 'cancelled' );

		$this->assertSame( array( 'cancelled' ), $this->order->statuses );
		$this->assertSame( 1, (int) $this->order->get_meta( '_aneepay_stock_restored', true ) );
	}

	/**
	 * A failed order stays unpaid.
	 *
	 * @return void
	 */
	public function test_failure_does_not_pay_the_order(): void {
		$this->resetOrder( 'pending' );
		aneepay_apply_payment_status( $this->order, 'failed' );

		$this->assertFalse( $this->order->paid );
		$this->assertSame( 'AneePay: payment failed.', $this->notes()[0] );
	}
}
