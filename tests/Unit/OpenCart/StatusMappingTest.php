<?php
/**
 * OpenCart: mapping a payment status onto an order.
 *
 * `AneePay_Webhook::apply()` reaches the platform through the registry, so the
 * registry is replaced with small doubles that record what was asked of it.
 *
 * @package AneePay_Crypto_Gateway
 */

declare(strict_types=1);

namespace AneePay\Tests\Unit\OpenCart;

use AneePay\Tests\Support\StatusMappingContractTestCase;

/**
 * Service locator with a fixed set of doubles.
 */
final class OcRegistryFake {
	/**
	 * Registered services.
	 *
	 * @var array<string, object>
	 */
	private $services = array();

	/**
	 * Register one service.
	 *
	 * @param string $name    Registry key.
	 * @param object $service Service.
	 * @return void
	 */
	public function set( $name, $service ) {
		$this->services[ $name ] = $service;
	}

	/**
	 * Resolve a service.
	 *
	 * @param string $name Registry key.
	 * @return object
	 */
	public function get( $name ) {
		if ( ! isset( $this->services[ $name ] ) ) {
			throw new \RuntimeException( 'Unknown service: ' . $name );
		}

		return $this->services[ $name ];
	}
}

/**
 * Settings store.
 */
final class OcConfigFake {
	/**
	 * Settings.
	 *
	 * @var array<string, mixed>
	 */
	public $values = array(
		'config_processing_status' => array( 2 ),
		'config_complete_status'   => array( 5 ),
	);

	/**
	 * Read one setting.
	 *
	 * @param string $key Setting key.
	 * @return mixed
	 */
	public function get( $key ) {
		return isset( $this->values[ $key ] ) ? $this->values[ $key ] : null;
	}
}

/**
 * AneePay mapping table.
 */
final class OcOrderModelFake {
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
	 * @param int $order_id Order id.
	 * @return array<string, mixed>|null
	 */
	public function getByOrderId( $order_id ) {
		return $this->row;
	}

	/**
	 * Store the payment status.
	 *
	 * @param int    $order_id Order id.
	 * @param string $status   Payment status.
	 * @return bool
	 */
	public function updatePaymentStatus( $order_id, $status ) {
		$this->updates[] = (string) $status;

		if ( null !== $this->row ) {
			$this->row['payment_status'] = (string) $status;
		}

		return true;
	}

	/**
	 * Whether stock was restored.
	 *
	 * @param int $order_id Order id.
	 * @return bool
	 */
	public function isStockRestored( $order_id ) {
		return $this->stock_restored;
	}

	/**
	 * Remember that stock was restored.
	 *
	 * @param int $order_id Order id.
	 * @return void
	 */
	public function markStockRestored( $order_id ) {
		$this->stock_restored = true;
	}
}

/**
 * Order history writer.
 */
final class OcCheckoutOrderFake {
	/**
	 * Current order status id.
	 *
	 * @var int
	 */
	public $status_id = 1;

	/**
	 * History entries: [order_id, status_id, note, notify].
	 *
	 * @var array<int, array<int, mixed>>
	 */
	public $history = array();

	/**
	 * Read the order.
	 *
	 * @param int $order_id Order id.
	 * @return array<string, mixed>
	 */
	public function getOrder( $order_id ) {
		return array(
			'order_id'        => $order_id,
			'order_status_id' => $this->status_id,
		);
	}

	/**
	 * Add an order history entry.
	 *
	 * @param int    $order_id  Order id.
	 * @param int    $status_id Status id.
	 * @param string $note      Note.
	 * @param bool   $notify    Notify the customer.
	 * @return void
	 */
	public function addOrderHistory( $order_id, $status_id, $note = '', $notify = false ) {
		$this->history[] = array( $order_id, $status_id, (string) $note, $notify );
		$this->status_id = (int) $status_id;
	}
}

/**
 * Product lookup used by the manual stock restore.
 */
final class OcSaleOrderFake {
	/**
	 * Read the order products; counts one manual restore per call.
	 *
	 * @param int $order_id Order id.
	 * @return array<int, array<string, mixed>>
	 */
	public function getOrderProducts( $order_id ) {
		$GLOBALS['aneepay_test_restock_count'] = isset( $GLOBALS['aneepay_test_restock_count'] )
			? (int) $GLOBALS['aneepay_test_restock_count'] + 1
			: 1;

		return array();
	}

	/**
	 * Read the options of an order product.
	 *
	 * @param int $order_id         Order id.
	 * @param int $order_product_id Order product id.
	 * @return array<int, array<string, mixed>>
	 */
	public function getOrderOptions( $order_id, $order_product_id ) {
		return array();
	}
}

/**
 * Database double.
 */
final class OcDbFake {
	/**
	 * Queries issued.
	 *
	 * @var array<int, string>
	 */
	public $queries = array();

	/**
	 * Run a query.
	 *
	 * @param string $sql SQL.
	 * @return void
	 */
	public function query( $sql ) {
		$this->queries[] = (string) $sql;
	}
}

/**
 * Loader double.
 */
final class OcLoadFake {
	/**
	 * Pretend to load a model.
	 *
	 * @param string $route Model route.
	 * @return void
	 */
	public function model( $route ) {
	}
}

/**
 * Contract tests for the OpenCart status applier.
 */
final class StatusMappingTest extends StatusMappingContractTestCase {
	/**
	 * Status map used by the webhook.
	 *
	 * @var array<string, int>
	 */
	private const STATUS_MAP = array(
		'pending'   => 1,
		'success'   => 2,
		'failed'    => 10,
		'cancelled' => 7,
	);

	/**
	 * Order id used everywhere.
	 *
	 * @var int
	 */
	private const ORDER_ID = 55;

	/**
	 * Registry handed to the webhook.
	 *
	 * @var OcRegistryFake
	 */
	private $registry;

	/**
	 * Mapping table double.
	 *
	 * @var OcOrderModelFake
	 */
	private $model;

	/**
	 * Order history double.
	 *
	 * @var OcCheckoutOrderFake
	 */
	private $checkout;

	/**
	 * Settings double.
	 *
	 * @var OcConfigFake
	 */
	private $config;

	/**
	 * Webhook under test.
	 *
	 * @var \AneePay_Webhook
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
	 * Rebuild the registry for an order with the given payment status.
	 *
	 * @param string $currentStatus Stored payment status.
	 * @return void
	 */
	protected function resetOrder( string $currentStatus ): void {
		$this->model = new OcOrderModelFake();
		$this->model->row = array(
			'order_id'       => self::ORDER_ID,
			'payment_status' => $currentStatus,
		);

		if ( in_array( $currentStatus, array( 'failed', 'cancelled' ), true ) ) {
			$this->model->stock_restored = true;
		}

		$this->checkout = new OcCheckoutOrderFake();
		$this->config   = new OcConfigFake();

		$this->registry = new OcRegistryFake();
		$this->registry->set( 'model_extension_payment_aneepay', $this->model );
		$this->registry->set( 'model_checkout_order', $this->checkout );
		$this->registry->set( 'model_sale_order', new OcSaleOrderFake() );
		$this->registry->set( 'config', $this->config );
		$this->registry->set( 'db', new OcDbFake() );
		$this->registry->set( 'load', new OcLoadFake() );

		$this->webhook = new \AneePay_Webhook( $this->registry, null, self::STATUS_MAP );

		$GLOBALS['aneepay_test_restock_count'] = 0;
	}

	/**
	 * Apply a status through the webhook.
	 *
	 * @param string $status Payment status.
	 * @param string $note   Order history note.
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
	 * History entries, reduced to their status id.
	 *
	 * @return array<int, int>
	 */
	protected function transitions(): array {
		return array_map(
			static function ( $entry ) {
				return $entry[1];
			},
			$this->checkout->history
		);
	}

	/**
	 * History notes that are not empty.
	 *
	 * @return array<int, string>
	 */
	protected function notes(): array {
		return array_values(
			array_filter(
				array_map(
					static function ( $entry ) {
						return $entry[2];
					},
					$this->checkout->history
				),
				static function ( $note ) {
					return '' !== $note;
				}
			)
		);
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
	 * Every applied status leaves an order history note.
	 *
	 * @return void
	 */
	public function test_notes_the_transition(): void {
		foreach ( array( 'success', 'failed', 'cancelled', 'pending' ) as $status ) {
			$this->resetOrder( '' );

			$this->applyStatus( $status, 'AneePay: test note.' );

			$this->assertSame( array( 'AneePay: test note.' ), $this->notes(), $status . ' should be logged' );
		}
	}

	/**
	 * The order history entry carries the configured status and notifies.
	 *
	 * @return void
	 */
	public function test_writes_the_configured_status_id(): void {
		$this->resetOrder( 'pending' );

		$this->applyStatus( 'success', 'AneePay: payment confirmed.' );

		$this->assertSame( array( 2 ), $this->transitions() );
		$this->assertTrue( $this->checkout->history[0][3] );
		$this->assertSame( 'AneePay: payment confirmed.', $this->checkout->history[0][2] );
	}

	/**
	 * An unmapped status id still updates the mapping.
	 *
	 * @return void
	 */
	public function test_an_empty_status_id_keeps_the_mapping_in_sync(): void {
		$this->resetOrder( 'pending' );

		$this->webhook = new \AneePay_Webhook(
			$this->registry,
			null,
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
	 * OpenCart restocks natively when the target status is not reserving.
	 *
	 * @return void
	 */
	public function test_native_restock_is_not_repeated(): void {
		$this->resetOrder( 'pending' );
		$this->checkout->status_id = 2; // Processing: stock is reserved.

		$this->applyStatus( 'failed' );

		$this->assertSame( 0, $this->restockCount() );
		$this->assertTrue( $this->model->stock_restored );
	}

	/**
	 * A status change OpenCart does not restock is compensated manually.
	 *
	 * That happens when the target status counts as reserving too: OpenCart then
	 * keeps the reservation, so the plugin puts the quantities back itself.
	 *
	 * @return void
	 */
	public function test_manual_restock_runs_when_the_platform_does_not(): void {
		$this->resetOrder( 'pending' );
		$this->checkout->status_id = 2;
		$this->config->values['config_processing_status'] = array( 2, 10 );

		$this->applyStatus( 'failed' );

		$this->assertSame( 1, $this->restockCount() );
		$this->assertTrue( $this->model->stock_restored );
	}

	/**
	 * Nothing was reserved, so nothing comes back.
	 *
	 * @return void
	 */
	public function test_no_restock_when_nothing_was_reserved(): void {
		$this->resetOrder( 'pending' );
		$this->checkout->status_id = 1;

		$this->applyStatus( 'failed' );

		$this->assertSame( 0, $this->restockCount() );
		$this->assertTrue( $this->model->stock_restored );
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
}
