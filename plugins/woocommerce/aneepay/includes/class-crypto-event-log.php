<?php
/**
 * Webhook event log for the AneePay gateway (WooCommerce).
 *
 * AneePay delivers webhooks at-least-once: the same event is retried up to
 * eight times with an identical body and a stable `event_id`. The event log
 * stores every accepted event under its `event_id` as the primary key, so a
 * retry is recognised and acknowledged with HTTP 200 without applying the
 * payment status a second time.
 *
 * Payloads from the legacy dApp confirm endpoint carry no `event_id`; those
 * fall back to `sha256(raw_body)`, which is just as stable across retries.
 *
 * @package AneePay_Crypto_Gateway
 */

defined( 'ABSPATH' ) || exit;

/**
 * Deduplication log for incoming webhook events.
 */
final class AneePay_Crypto_Event_Log {

	/**
	 * Schema version stored in an option to decide whether to (re)install.
	 */
	const SCHEMA_VERSION = '1';

	/**
	 * Option holding the installed schema version.
	 */
	const SCHEMA_OPTION = 'aneepay_events_schema';

	/**
	 * How long events are kept: far beyond the ~25 min retry window of the
	 * vendor, so a late redelivery is still recognised, while the table does
	 * not grow without bound.
	 */
	const RETENTION_DAYS = 7;

	/**
	 * One in N accepted events prunes expired rows (no cron dependency).
	 */
	const PRUNE_PROBABILITY = 20;

	/**
	 * Fully qualified event table name.
	 *
	 * @return string
	 */
	public static function table_name() {
		global $wpdb;

		return $wpdb->prefix . 'aneepay_event';
	}

	/**
	 * Create or upgrade the event table.
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;

		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		$table   = self::table_name();
		$collate = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$table} (
				event_key varchar(80) NOT NULL,
				event_id varchar(64) NOT NULL DEFAULT '',
				payment_id varchar(64) NOT NULL DEFAULT '',
				order_ref varchar(64) NOT NULL DEFAULT '',
				status varchar(16) NOT NULL DEFAULT '',
				is_sandbox tinyint(1) NOT NULL DEFAULT 0,
				outcome varchar(32) NOT NULL DEFAULT '',
				date_added datetime NOT NULL,
				PRIMARY KEY  (event_key),
				KEY date_added (date_added)
			) {$collate};"
		);

		update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION, false );
	}

	/**
	 * Install the table once per schema version.
	 *
	 * Webhooks run without the admin bootstrap, so a store that upgraded the
	 * plugin without reactivating it still gets the table on the first event.
	 *
	 * @return void
	 */
	public static function maybe_install() {
		if ( self::SCHEMA_VERSION === (string) get_option( self::SCHEMA_OPTION, '' ) ) {
			return;
		}

		self::install();
	}

	/**
	 * Deduplication key for a webhook body.
	 *
	 * @param array  $params   Decoded webhook payload.
	 * @param string $raw_body Raw request body.
	 * @return string
	 */
	public static function dedup_key( $params, $raw_body ) {
		if ( ! empty( $params['event_id'] ) && is_scalar( $params['event_id'] ) ) {
			return 'event:' . substr( sanitize_text_field( (string) $params['event_id'] ), 0, 64 );
		}

		return 'body:' . hash( 'sha256', (string) $raw_body );
	}

	/**
	 * Claim an event for processing.
	 *
	 * Returns true exactly once per event: the caller may then apply the
	 * status. Every later call with the same key returns false and the caller
	 * must acknowledge the webhook without side effects.
	 *
	 * @param string $key       Dedup key from ::dedup_key().
	 * @param array  $params    Decoded webhook payload.
	 * @param string $order_ref Resolved order reference (audit only).
	 * @param string $outcome   What the handler did with the event.
	 * @return bool Whether this call claimed the event.
	 */
	public static function claim( $key, $params, $order_ref = '', $outcome = 'applied' ) {
		global $wpdb;

		self::maybe_install();

		$table = self::table_name();

		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$table}
					(event_key, event_id, payment_id, order_ref, status, is_sandbox, outcome, date_added)
				VALUES (%s, %s, %s, %s, %s, %d, %s, %s)",
				substr( $key, 0, 80 ),
				isset( $params['event_id'] ) && is_scalar( $params['event_id'] ) ? substr( sanitize_text_field( (string) $params['event_id'] ), 0, 64 ) : '',
				isset( $params['payment_id'] ) && is_scalar( $params['payment_id'] ) ? substr( sanitize_text_field( (string) $params['payment_id'] ), 0, 64 ) : '',
				substr( (string) $order_ref, 0, 64 ),
				isset( $params['status'] ) && is_scalar( $params['status'] ) ? substr( sanitize_key( (string) $params['status'] ), 0, 16 ) : '',
				self::payload_is_sandbox( $params ) ? 1 : 0,
				substr( (string) $outcome, 0, 32 ),
				gmdate( 'Y-m-d H:i:s' )
			)
		);

		// A duplicate is reported as 0 affected rows by INSERT IGNORE.
		$claimed = is_int( $inserted ) && $inserted > 0;

		if ( $claimed && wp_rand( 1, self::PRUNE_PROBABILITY ) === 1 ) {
			self::prune();
		}

		return $claimed;
	}

	/**
	 * Drop a claimed event so a retry can be processed again.
	 *
	 * Used when applying the status fails hard (exception, API outage): the
	 * vendor retries, and the retry must not be swallowed as a duplicate.
	 *
	 * @param string $key Dedup key from ::dedup_key().
	 * @return void
	 */
	public static function release( $key ) {
		global $wpdb;

		$table = self::table_name();

		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE event_key = %s", substr( $key, 0, 80 ) ) );
	}

	/**
	 * Delete events older than the retention window.
	 *
	 * @return void
	 */
	public static function prune() {
		global $wpdb;

		$table = self::table_name();
		$since = gmdate( 'Y-m-d H:i:s', time() - ( self::RETENTION_DAYS * DAY_IN_SECONDS ) );

		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE date_added < %s", $since ) );
	}

	/**
	 * Drop the event table (opt-in data deletion on uninstall).
	 *
	 * @return void
	 */
	public static function drop() {
		global $wpdb;

		$table = self::table_name();

		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		delete_option( self::SCHEMA_OPTION );
	}

	/**
	 * Whether a webhook payload was produced in the sandbox.
	 *
	 * The v1 contract carries an explicit `is_sandbox` flag; older payloads
	 * expose only the network, so the chain is used as a fallback.
	 *
	 * @param array $params Decoded webhook payload.
	 * @return bool|null Null when the payload does not say.
	 */
	public static function payload_is_sandbox( $params ) {
		if ( array_key_exists( 'is_sandbox', $params ) ) {
			return (bool) filter_var( $params['is_sandbox'], FILTER_VALIDATE_BOOLEAN );
		}

		if ( isset( $params['network'] ) && is_scalar( $params['network'] ) ) {
			$network = strtolower( trim( (string) $params['network'] ) );

			if ( 'amoy' === $network ) {
				return true;
			}

			if ( 'polygon' === $network ) {
				return false;
			}
		}

		if ( isset( $params['chain_id'] ) && is_numeric( $params['chain_id'] ) ) {
			$chain_id = (int) $params['chain_id'];

			if ( 80002 === $chain_id ) {
				return true;
			}

			if ( 137 === $chain_id ) {
				return false;
			}
		}

		return null;
	}
}
