<?php
/**
 * Shared behaviour of the payment status applier on all three platforms.
 *
 * Each platform maps a payment status (pending|success|failed|cancelled) to its
 * own order status and restores reserved stock on failure/cancellation. The
 * decision layer is the same everywhere; only the platform status differs.
 *
 * @package AneePay_Crypto_Gateway
 */

declare(strict_types=1);

namespace AneePay\Tests\Support;

use PHPUnit\Framework\TestCase;

/**
 * Contract for mapping a payment status onto a platform order.
 *
 * Stock restoration and merchant notes are deliberately not part of this
 * contract: each platform reserves stock at a different moment (WooCommerce at
 * checkout, OpenCart and PrestaShop when the order enters a processing state)
 * and records notes differently, so both are pinned down per platform.
 */
abstract class StatusMappingContractTestCase extends TestCase {
	/**
	 * Rebuild the order fixture with the given stored payment status.
	 *
	 * @param string $currentStatus Payment status already stored on the order.
	 * @return void
	 */
	abstract protected function resetOrder(string $currentStatus): void;

	/**
	 * Apply a payment status to the current fixture.
	 *
	 * @param string $status Payment status to apply.
	 * @param string $note   Optional order note passed to the applier.
	 * @return bool Whether the status was applied.
	 */
	abstract protected function applyStatus(string $status, string $note = ''): bool;

	/**
	 * Payment status stored on the order after the last call.
	 *
	 * @return string
	 */
	abstract protected function storedStatus(): string;

	/**
	 * Platform status transitions recorded so far.
	 *
	 * @return array<int, mixed>
	 */
	abstract protected function transitions(): array;

	/**
	 * Order notes recorded so far.
	 *
	 * @return array<int, string>
	 */
	abstract protected function notes(): array;

	/**
	 * How many times stock was put back on the shelf.
	 *
	 * @return int
	 */
	abstract protected function restockCount(): int;

	/**
	 * Known statuses are mapped and stored.
	 *
	 * @return void
	 */
	public function test_applies_every_known_status(): void {
		foreach ( array( 'pending', 'success', 'failed', 'cancelled' ) as $status ) {
			$this->resetOrder( '' );

			$this->assertTrue( $this->applyStatus( $status ), $status . ' should be applied' );
			$this->assertSame( $status, $this->storedStatus() );
			$this->assertCount( 1, $this->transitions(), $status . ' should move the order once' );
		}
	}

	/**
	 * Anything the API did not promise is ignored.
	 *
	 * @return void
	 */
	public function test_ignores_unknown_statuses(): void {
		foreach ( array( 'refunded', 'processing', 'paid', 'done', '' ) as $status ) {
			$this->resetOrder( 'pending' );

			$this->assertFalse( $this->applyStatus( $status ), '"' . $status . '" must not be applied' );
			$this->assertSame( 'pending', $this->storedStatus() );
			$this->assertCount( 0, $this->transitions() );
		}
	}

	/**
	 * A repeat delivery changes nothing.
	 *
	 * @return void
	 */
	public function test_is_idempotent_for_the_same_status(): void {
		foreach ( array( 'pending', 'success', 'failed', 'cancelled' ) as $status ) {
			$this->resetOrder( $status );

			$this->assertFalse( $this->applyStatus( $status, 'duplicate' ) );
			$this->assertSame( $status, $this->storedStatus() );
			$this->assertCount( 0, $this->transitions() );
			$this->assertCount( 0, $this->notes() );
		}
	}

	/**
	 * A paid order is never downgraded.
	 *
	 * @return void
	 */
	public function test_success_is_terminal(): void {
		foreach ( array( 'pending', 'failed', 'cancelled' ) as $status ) {
			$this->resetOrder( 'success' );

			$this->assertFalse( $this->applyStatus( $status ), 'success must not become ' . $status );
			$this->assertSame( 'success', $this->storedStatus() );
			$this->assertCount( 0, $this->transitions() );
		}
	}

	/**
	 * A payment can succeed after an earlier failure.
	 *
	 * @return void
	 */
	public function test_success_after_failure_is_applied(): void {
		$this->resetOrder( 'failed' );

		$this->assertTrue( $this->applyStatus( 'success' ) );
		$this->assertSame( 'success', $this->storedStatus() );
		$this->assertCount( 1, $this->transitions() );
	}

}
