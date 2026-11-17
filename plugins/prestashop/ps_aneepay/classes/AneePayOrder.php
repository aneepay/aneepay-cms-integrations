<?php
/**
 * AneePay order mapping (ps_aneepay_order table).
 */
class AneePayOrder {
	const TABLE = 'aneepay_order';

	/**
	 * Store the payment + conversion context for an order.
	 *
	 * @param int   $id_order Order id.
	 * @param array $data     Payment and conversion context.
	 * @return bool
	 */
	public function saveMapping($id_order, $data) {
		$db = Db::getInstance();

		self::ensureSchema();

		$table = _DB_PREFIX_ . self::TABLE;

		$sql = 'INSERT INTO `' . $table . '` SET
			`id_order` = ' . (int) $id_order . ',
			`id_cart` = ' . (isset($data['id_cart']) ? (int) $data['id_cart'] : 0) . ',
			`payment_id` = \'' . pSQL(isset($data['payment_id']) ? $data['payment_id'] : '') . '\',
			`operation_id` = \'' . pSQL(isset($data['operation_id']) ? $data['operation_id'] : '') . '\',
			`checkout_url` = \'' . pSQL(isset($data['checkout_url']) ? $data['checkout_url'] : '') . '\',
			`token` = \'' . pSQL(isset($data['token']) ? $data['token'] : '') . '\',
			`network` = \'' . pSQL(isset($data['network']) ? $data['network'] : '') . '\',
			`sandbox` = ' . (isset($data['sandbox']) ? (int) $data['sandbox'] : 0) . ',
			`payment_status` = \'' . pSQL(isset($data['payment_status']) ? $data['payment_status'] : 'pending') . '\',
			`token_amount` = \'' . pSQL(isset($data['token_amount']) ? $data['token_amount'] : '') . '\',
			`order_currency` = \'' . pSQL(isset($data['order_currency']) ? $data['order_currency'] : '') . '\',
			`order_total` = \'' . pSQL(isset($data['order_total']) ? $data['order_total'] : '') . '\',
			`usd_amount` = \'' . pSQL(isset($data['usd_amount']) ? $data['usd_amount'] : '') . '\',
			`fiat_per_usd` = \'' . pSQL(isset($data['fiat_per_usd']) ? $data['fiat_per_usd'] : '') . '\',
			`rate_source` = \'' . pSQL(isset($data['rate_source']) ? $data['rate_source'] : '') . '\',
			`date_added` = \'' . pSQL(date('Y-m-d H:i:s')) . '\'
			ON DUPLICATE KEY UPDATE
			`id_cart` = VALUES(`id_cart`),
			`payment_id` = VALUES(`payment_id`),
			`operation_id` = VALUES(`operation_id`),
			`checkout_url` = VALUES(`checkout_url`),
			`token` = VALUES(`token`),
			`network` = VALUES(`network`),
			`sandbox` = VALUES(`sandbox`),
			`payment_status` = VALUES(`payment_status`),
			`token_amount` = VALUES(`token_amount`),
			`order_currency` = VALUES(`order_currency`),
			`order_total` = VALUES(`order_total`),
			`usd_amount` = VALUES(`usd_amount`),
			`fiat_per_usd` = VALUES(`fiat_per_usd`),
			`rate_source` = VALUES(`rate_source`)';

		return (bool) $db->execute($sql);
	}

	/**
	 * Guarantee one mapping row per order.
	 *
	 * Existing installations created a plain (non-unique) index and wrote
	 * with DELETE + INSERT, so duplicates may already exist. They are merged
	 * first — the newest row survives, inheriting `stock_restored` and a
	 * `success` status — and only then the index is tightened, which also
	 * makes the upsert in saveMapping() race-free.
	 *
	 * Runs once per request at most; guarded by a configuration flag.
	 *
	 * @return void
	 */
	public static function ensureSchema()
	{
		static $done = false;

		if ($done) {
			return;
		}

		$done = true;

		if ('2' === (string) Configuration::get('ANEEPAY_ORDER_SCHEMA')) {
			return;
		}

		$db    = Db::getInstance();
		$table = _DB_PREFIX_ . self::TABLE;

		$index = $db->getRow('SHOW INDEX FROM `' . $table . '` WHERE `Key_name` = \'id_order\'');

		if (!$index) {
			return;
		}

		if (0 === (int) $index['Non_unique']) {
			Configuration::updateValue('ANEEPAY_ORDER_SCHEMA', '2');

			return;
		}

		$db->execute('UPDATE `' . $table . '` o
			JOIN (
				SELECT `id_order`, MAX(`id`) AS keep_id, MAX(`stock_restored`) AS stock_restored, MAX(`payment_status` = \'success\') AS has_success
				FROM `' . $table . '` GROUP BY `id_order` HAVING COUNT(*) > 1
			) d ON o.`id` = d.keep_id
			SET o.`stock_restored` = GREATEST(o.`stock_restored`, d.stock_restored),
				o.`payment_status` = IF(d.has_success = 1, \'success\', o.`payment_status`)');

		$db->execute('DELETE o FROM `' . $table . '` o
			JOIN (
				SELECT `id_order`, MAX(`id`) AS keep_id
				FROM `' . $table . '` GROUP BY `id_order` HAVING COUNT(*) > 1
			) d ON o.`id_order` = d.id_order AND o.`id` <> d.keep_id');

		$db->execute('ALTER TABLE `' . $table . '` DROP INDEX `id_order`');
		$db->execute('ALTER TABLE `' . $table . '` ADD UNIQUE KEY `id_order` (`id_order`)');

		Configuration::updateValue('ANEEPAY_ORDER_SCHEMA', '2');
	}

	/** @return array|null */
	public function getByOrder($id_order) {
		$db  = Db::getInstance();
		$row = $db->getRow('SELECT * FROM `' . _DB_PREFIX_ . self::TABLE . '` WHERE `id_order` = ' . (int) $id_order);

		return $row ? $row : null;
	}

	/** @return array|null */
	public function getByCart($id_cart) {
		$db  = Db::getInstance();
		$row = $db->getRow('SELECT * FROM `' . _DB_PREFIX_ . self::TABLE . '` WHERE `id_cart` = ' . (int) $id_cart);

		return $row ? $row : null;
	}

	/** @return array|null */
	public function getByPaymentId($payment_id) {
		$db  = Db::getInstance();
		$row = $db->getRow('SELECT * FROM `' . _DB_PREFIX_ . self::TABLE . '` WHERE `payment_id` = \'' . pSQL((string) $payment_id) . '\'');

		return $row ? $row : null;
	}

	/** @return array|null */
	public function getByOperationId($operation_id) {
		$db  = Db::getInstance();
		$row = $db->getRow('SELECT * FROM `' . _DB_PREFIX_ . self::TABLE . '` WHERE `operation_id` = \'' . pSQL((string) $operation_id) . '\'');

		return $row ? $row : null;
	}

	/**
	 * @return bool
	 */
	public function updateStatus($id_order, $status) {
		return (bool) Db::getInstance()->update(self::TABLE, array('payment_status' => pSQL((string) $status)), 'id_order = ' . (int) $id_order);
	}

	/**
	 * Whether stock was already restored for the order (idempotency guard).
	 *
	 * @param int $id_order Order id.
	 * @return bool
	 */
	public function isStockRestored($id_order) {
		$row = Db::getInstance()->getRow(
			'SELECT `stock_restored` FROM `' . _DB_PREFIX_ . self::TABLE . '` WHERE `id_order` = ' . (int) $id_order
		);

		return $row && !empty($row['stock_restored']);
	}

	/**
	 * Mark stock as restored so a later failed/cancelled event cannot double-restore.
	 *
	 * @param int $id_order Order id.
	 * @return bool
	 */
	public function markStockRestored($id_order) {
		return (bool) Db::getInstance()->update(self::TABLE, array('stock_restored' => 1), 'id_order = ' . (int) $id_order);
	}

	/**
	 * Pending mappings worth polling, oldest first.
	 *
	 * Bounded on both axes: a hard limit keeps one cron run cheap, and the
	 * age cut-off stops an ancient abandoned order from being polled forever.
	 *
	 * @param int $limit        Maximum number of rows.
	 * @param int $max_age_days Ignore mappings older than this many days.
	 * @return array<int,array{id_order:int,payment_id:string,date_added:string}>
	 */
	public function getPendingOrders($limit = 100, $max_age_days = 7) {
		$limit        = max(1, (int) $limit);
		$max_age_days = max(1, (int) $max_age_days);

		$rows = Db::getInstance()->executeS(
			'SELECT `id_order`, `payment_id`, `date_added` FROM `' . _DB_PREFIX_ . self::TABLE . '`
			 WHERE `payment_status` IN (\'\', \'pending\')
			   AND `date_added` > DATE_SUB(NOW(), INTERVAL ' . $max_age_days . ' DAY)
			 ORDER BY `date_added` ASC, `id_order` ASC
			 LIMIT ' . $limit
		);

		if (!is_array($rows)) {
			return array();
		}

		foreach ($rows as $index => $row) {
			$rows[$index]['id_order']   = (int) $row['id_order'];
			$rows[$index]['payment_id'] = (string) $row['payment_id'];
		}

		return $rows;
	}
}
