<?php
/**
 * Webhook event log for AneePay (PrestaShop).
 *
 * AneePay delivers webhooks at-least-once: the same event is retried up to
 * eight times with an identical body and a stable `event_id`. Every accepted
 * event is stored under its `event_id` as the primary key, so a retry is
 * recognised and acknowledged with HTTP 200 without applying the payment
 * status a second time.
 *
 * Legacy dApp confirm payloads carry no `event_id`; those fall back to
 * `sha256(raw_body)`, which is just as stable across retries.
 */
class AneePayEventLog
{
	/** Table basename (prefixed at runtime). */
	const TABLE = 'aneepay_event';

	/** Option holding the installed schema version. */
	const SCHEMA_OPTION = 'ANEEPAY_EVENT_SCHEMA';

	/** Current schema version. */
	const SCHEMA_VERSION = '1';

	/**
	 * How long events are kept: far beyond the ~25 min retry window of the
	 * vendor, so a late redelivery is still recognised, while the table does
	 * not grow without bound.
	 */
	const RETENTION_DAYS = 7;

	/** One in N accepted events prunes expired rows (no cron dependency). */
	const PRUNE_PROBABILITY = 20;

	/**
	 * Event table name.
	 *
	 * @return string
	 */
	public static function tableName()
	{
		return _DB_PREFIX_ . self::TABLE;
	}

	/**
	 * CREATE TABLE statement for the event log.
	 *
	 * @return string
	 */
	public static function schemaSql()
	{
		return 'CREATE TABLE IF NOT EXISTS `' . self::tableName() . '` (
			`event_key` VARCHAR(80) NOT NULL,
			`event_id` VARCHAR(64) NOT NULL DEFAULT \'\',
			`payment_id` VARCHAR(64) NOT NULL DEFAULT \'\',
			`order_ref` VARCHAR(64) NOT NULL DEFAULT \'\',
			`status` VARCHAR(16) NOT NULL DEFAULT \'\',
			`is_sandbox` TINYINT(1) NOT NULL DEFAULT 0,
			`outcome` VARCHAR(32) NOT NULL DEFAULT \'\',
			`date_added` DATETIME NOT NULL,
			PRIMARY KEY (`event_key`),
			KEY `date_added` (`date_added`)
		) ENGINE=' . (defined('_MYSQL_ENGINE_') ? _MYSQL_ENGINE_ : 'InnoDB') . ' DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci';
	}

	/**
	 * Create the event table.
	 *
	 * @return bool
	 */
	public static function install()
	{
		$ok = (bool) Db::getInstance()->execute(self::schemaSql());

		if ($ok) {
			Configuration::updateValue(self::SCHEMA_OPTION, self::SCHEMA_VERSION);
		}

		return $ok;
	}

	/**
	 * Create the table once per schema version.
	 *
	 * Covers upgrades where Module::install()/upgrade did not run (e.g. files
	 * copied over an existing installation).
	 *
	 * @return void
	 */
	public static function ensure()
	{
		if (self::SCHEMA_VERSION === (string) Configuration::get(self::SCHEMA_OPTION)) {
			return;
		}

		self::install();
	}

	/**
	 * Deduplication key for a webhook body.
	 *
	 * @param array  $params   Decoded webhook payload.
	 * @param string $raw_body Raw request body.
	 *
	 * @return string
	 */
	public static function dedupKey($params, $raw_body)
	{
		if (!empty($params['event_id']) && is_scalar($params['event_id'])) {
			return 'event:' . substr((string) $params['event_id'], 0, 64);
		}

		return 'body:' . hash('sha256', (string) $raw_body);
	}

	/**
	 * Claim an event for processing.
	 *
	 * Returns true exactly once per event: the caller may then apply the
	 * status. Every later call with the same key returns false and the caller
	 * must acknowledge the webhook without side effects.
	 *
	 * @param string $key       Dedup key from ::dedupKey().
	 * @param array  $params    Decoded webhook payload.
	 * @param string $order_ref Resolved order reference (audit only).
	 * @param string $outcome   What the handler did with the event.
	 *
	 * @return bool Whether this call claimed the event.
	 */
	public static function claim($key, $params, $order_ref = '', $outcome = 'applied')
	{
		self::ensure();

		$db = Db::getInstance();

		$db->execute(
			'INSERT IGNORE INTO `' . self::tableName() . '` SET
				`event_key` = \'' . pSQL(substr($key, 0, 80)) . '\',
				`event_id` = \'' . pSQL(isset($params['event_id']) && is_scalar($params['event_id']) ? substr((string) $params['event_id'], 0, 64) : '') . '\',
				`payment_id` = \'' . pSQL(isset($params['payment_id']) && is_scalar($params['payment_id']) ? substr((string) $params['payment_id'], 0, 64) : '') . '\',
				`order_ref` = \'' . pSQL(substr((string) $order_ref, 0, 64)) . '\',
				`status` = \'' . pSQL(substr((string) (isset($params['status']) && is_scalar($params['status']) ? $params['status'] : ''), 0, 16)) . '\',
				`is_sandbox` = \'' . (self::payloadIsSandbox($params) ? 1 : 0) . '\',
				`outcome` = \'' . pSQL(substr((string) $outcome, 0, 32)) . '\',
				`date_added` = NOW()'
		);

		$claimed = (int) $db->Affected_Rows() > 0;

		if ($claimed && mt_rand(1, self::PRUNE_PROBABILITY) === 1) {
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
	 * @param string $key Dedup key from ::dedupKey().
	 *
	 * @return void
	 */
	public static function release($key)
	{
		Db::getInstance()->execute(
			'DELETE FROM `' . self::tableName() . '` WHERE `event_key` = \'' . pSQL(substr($key, 0, 80)) . '\''
		);
	}

	/**
	 * Delete events older than the retention window.
	 *
	 * @return void
	 */
	public static function prune()
	{
		Db::getInstance()->execute(
			'DELETE FROM `' . self::tableName() . '` WHERE `date_added` < DATE_SUB(NOW(), INTERVAL ' . (int) self::RETENTION_DAYS . ' DAY)'
		);
	}

	/**
	 * Whether a webhook payload was produced in the sandbox.
	 *
	 * The v1 contract carries an explicit `is_sandbox` flag; older payloads
	 * expose only the network, so the chain is used as a fallback.
	 *
	 * @param array $params Decoded webhook payload.
	 *
	 * @return bool|null Null when the payload does not say.
	 */
	public static function payloadIsSandbox($params)
	{
		if (array_key_exists('is_sandbox', $params)) {
			return (bool) filter_var($params['is_sandbox'], FILTER_VALIDATE_BOOLEAN);
		}

		if (isset($params['network']) && is_scalar($params['network'])) {
			$network = strtolower(trim((string) $params['network']));

			if ('amoy' === $network) {
				return true;
			}

			if ('polygon' === $network) {
				return false;
			}
		}

		if (isset($params['chain_id']) && is_numeric($params['chain_id'])) {
			$chain_id = (int) $params['chain_id'];

			if (80002 === $chain_id) {
				return true;
			}

			if (137 === $chain_id) {
				return false;
			}
		}

		return null;
	}
}
