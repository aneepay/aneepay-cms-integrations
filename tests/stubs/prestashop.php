<?php
/**
 * PrestaShop surface needed by the webhook class.
 *
 * Only the members the status applier touches are implemented: it reads and
 * moves the current order state, writes an OrderHistory row, creates the
 * context currency when it is missing, and restocks through StockAvailable.
 * Behaviour that belongs to PrestaShop itself (native restock on error states)
 * is modelled by the tests, not here.
 *
 * @package AneePay_Crypto_Gateway
 */

if ( ! class_exists( 'Configuration' ) ) {
	/**
	 * Configuration store with the stock PrestaShop order-state ids.
	 */
	class Configuration {
		/**
		 * Values, by key.
		 *
		 * @var array<string, mixed>
		 */
		public static $values = array(
			'PS_OS_CHEQUE'   => 1,
			'PS_OS_PAYMENT'  => 2,
			'PS_OS_ERROR'    => 8,
			'PS_OS_CANCELED' => 6,
		);

		/**
		 * Read one value.
		 *
		 * @param string $key Key.
		 * @return mixed
		 */
		public static function get( $key ) {
			return isset( self::$values[ $key ] ) ? self::$values[ $key ] : null;
		}
	}
}

if ( ! class_exists( 'Validate' ) ) {
	/**
	 * Object validity check.
	 */
	class Validate {
		/**
		 * Whether the object loaded.
		 *
		 * @param mixed $object Object.
		 * @return bool
		 */
		public static function isLoadedObject( $object ) {
			if ( ! is_object( $object ) ) {
				return false;
			}

			if ( property_exists( $object, 'id' ) ) {
				return (int) $object->id > 0;
			}

			return true;
		}
	}
}

if ( ! class_exists( 'Currency' ) ) {
	/**
	 * Currency stub.
	 */
	class Currency {
		/**
		 * Currency id.
		 *
		 * @var int
		 */
		public $id = 0;

		/**
		 * @param int $id Currency id.
		 */
		public function __construct( $id = 0 ) {
			$this->id = (int) $id;
		}
	}
}

if ( ! class_exists( 'Context' ) ) {
	/**
	 * Context singleton.
	 */
	class Context {
		/**
		 * Currency in use.
		 *
		 * @var Currency|null
		 */
		public $currency;

		/**
		 * Singleton instance.
		 *
		 * @var Context|null
		 */
		private static $instance;

		/**
		 * Current context.
		 *
		 * @return Context
		 */
		public static function getContext() {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}

			return self::$instance;
		}

		/**
		 * Drop the singleton between tests.
		 *
		 * @return void
		 */
		public static function reset() {
			self::$instance = null;
		}
	}
}

if ( ! class_exists( 'Order' ) ) {
	/**
	 * Order stub: current state plus the product list used for restocking.
	 */
	class Order {
		/**
		 * Current state per order id.
		 *
		 * @var array<int, int>
		 */
		public static $states = array();

		/**
		 * Order id.
		 *
		 * @var int
		 */
		public $id = 0;

		/**
		 * Shop id.
		 *
		 * @var int
		 */
		public $id_shop = 1;

		/**
		 * Currency id.
		 *
		 * @var int
		 */
		public $id_currency = 1;

		/**
		 * State applied by the last changeIdOrderState() call.
		 *
		 * @var int|null
		 */
		public $pending_state;

		/**
		 * Internal note, as stored by PrestaShop.
		 *
		 * @var string
		 */
		public $note = '';

		/**
		 * Note an order is loaded with.
		 *
		 * @var string
		 */
		public static $existing_note = '';

		/**
		 * De-duplicated note lines seen by update().
		 *
		 * @var array<int, string>
		 */
		public static $notes = array();

		/**
		 * Full note value written by the last update().
		 *
		 * @var string
		 */
		public static $last_note = '';

		/**
		 * @param int $id Order id.
		 */
		public function __construct( $id = 0 ) {
			$this->id   = (int) $id;
			$this->note = self::$existing_note;
		}

		/**
		 * Persist the order (the note, in practice).
		 *
		 * @return bool
		 */
		public function update() {
			self::$last_note = (string) $this->note;

			foreach ( explode( "\n", (string) $this->note ) as $line ) {
				$line = trim( $line );

				if ( '' !== $line && ! in_array( $line, self::$notes, true ) ) {
					self::$notes[] = $line;
				}
			}

			return true;
		}

		/**
		 * Current order state.
		 *
		 * @return int
		 */
		public function getCurrentState() {
			return isset( self::$states[ $this->id ] ) ? (int) self::$states[ $this->id ] : 0;
		}

		/**
		 * Order products; counts one restock attempt per call.
		 *
		 * @return array<int, array<string, mixed>>
		 */
		public function getProducts() {
			$GLOBALS['aneepay_test_restock_count'] = isset( $GLOBALS['aneepay_test_restock_count'] )
				? (int) $GLOBALS['aneepay_test_restock_count'] + 1
				: 1;

			return array();
		}
	}
}

if ( ! class_exists( 'OrderHistory' ) ) {
	/**
	 * Order history writer.
	 */
	class OrderHistory {
		/**
		 * Entries written so far: [order id, state id].
		 *
		 * @var array<int, array<int, int>>
		 */
		public static $entries = array();

		/**
		 * Order id.
		 *
		 * @var int
		 */
		public $id_order = 0;

		/**
		 * State to write.
		 *
		 * @var int|null
		 */
		public $state;

		/**
		 * Move the order.
		 *
		 * @param int   $state        Target state id.
		 * @param Order $order        Order.
		 * @param bool  $use_existing Reuse the existing history row.
		 * @return bool
		 */
		public function changeIdOrderState( $state, $order, $use_existing = false ) {
			$this->state = (int) $state;

			return true;
		}

		/**
		 * Persist the history entry.
		 *
		 * @return bool
		 */
		public function add() {
			self::$entries[] = array( (int) $this->id_order, (int) $this->state );

			return true;
		}
	}
}

if ( ! class_exists( 'Pack' ) ) {
	/**
	 * Product pack check.
	 */
	class Pack {
		/**
		 * Never a pack in tests.
		 *
		 * @param int $id_product Product id.
		 * @return bool
		 */
		public static function isPack( $id_product ) {
			return false;
		}
	}
}

if ( ! class_exists( 'StockAvailable' ) ) {
	/**
	 * Stock writer.
	 */
	class StockAvailable {
		/**
		 * Stock is managed by PrestaShop, not by an external warehouse.
		 *
		 * @param int $id_product         Product id.
		 * @param int $id_shop           Shop id.
		 * @return bool
		 */
		public static function dependsOnStock( $id_product, $id_shop = null ) {
			return false;
		}

		/**
		 * Return units to stock.
		 *
		 * @param int      $id_product           Product id.
		 * @param int      $id_product_attribute Attribute id.
		 * @param int      $delta                Quantity.
		 * @param int|null $id_shop              Shop id.
		 * @return bool
		 */
		public static function updateQuantity( $id_product, $id_product_attribute, $delta, $id_shop = null ) {
			return true;
		}
	}
}
