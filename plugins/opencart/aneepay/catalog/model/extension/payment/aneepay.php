<?php
/**
 * AneePay order mapping model (catalog).
 *
 * Stores payment context per order in the aneepay_order table: payment_id,
 * operation_id, checkout_url, token, network, sandbox, token_amount and the
 * conversion context, plus the resolved payment_status.
 */
class ModelExtensionPaymentAneePay extends Model {
	/**
	 * Store the payment + conversion context for an order.
	 *
	 * @param int   $order_id Order id.
	 * @param array $data     Payment and conversion data.
	 * @return void
	 */
	public function addMapping($order_id, $data) {
		$this->ensureOrderIndex();

		$this->db->query("INSERT INTO `" . DB_PREFIX . "aneepay_order` SET
			`order_id` = '" . (int) $order_id . "',
			`store_id` = '" . (int) $this->config->get('config_store_id') . "',
			`payment_id` = '" . $this->db->escape(isset($data['payment_id']) ? $data['payment_id'] : '') . "',
			`operation_id` = '" . $this->db->escape(isset($data['operation_id']) ? $data['operation_id'] : '') . "',
			`checkout_url` = '" . $this->db->escape(isset($data['checkout_url']) ? $data['checkout_url'] : '') . "',
			`token` = '" . $this->db->escape(isset($data['token']) ? $data['token'] : '') . "',
			`network` = '" . $this->db->escape(isset($data['network']) ? $data['network'] : '') . "',
			`sandbox` = '" . (isset($data['sandbox']) ? (int) $data['sandbox'] : 0) . "',
			`payment_status` = '" . $this->db->escape(isset($data['payment_status']) ? $data['payment_status'] : 'pending') . "',
			`token_amount` = '" . $this->db->escape(isset($data['token_amount']) ? $data['token_amount'] : '') . "',
			`order_currency` = '" . $this->db->escape(isset($data['order_currency']) ? $data['order_currency'] : '') . "',
			`order_total` = '" . $this->db->escape(isset($data['order_total']) ? $data['order_total'] : '') . "',
			`usd_amount` = '" . $this->db->escape(isset($data['usd_amount']) ? $data['usd_amount'] : '') . "',
			`fiat_per_usd` = '" . $this->db->escape(isset($data['fiat_per_usd']) ? $data['fiat_per_usd'] : '') . "',
			`rate_source` = '" . $this->db->escape(isset($data['rate_source']) ? $data['rate_source'] : '') . "',
			`date_added` = NOW()
			ON DUPLICATE KEY UPDATE
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
			`rate_source` = VALUES(`rate_source`)");
	}

	/**
	 * Claim the right to create a payment for an order.
	 *
	 * The AneePay API has no Idempotency-Key, so two concurrent confirms for the
	 * same order would create two payable invoices. `order_id` is unique, so an
	 * INSERT IGNORE of a row in the "creating" state is an atomic claim: exactly
	 * one request inserts it. The row is turned into the real mapping by
	 * addMapping() and removed again by releaseClaim() when nothing was created.
	 *
	 * @param int $order_id Order id.
	 * @param int $minutes  Age after which a "creating" row is considered stale.
	 * @return bool True when this request owns the claim.
	 */
	public function claimOrder($order_id, $minutes = 10) {
		$order_id = (int) $order_id;

		if ($order_id <= 0) {
			return false;
		}

		$this->ensureOrderIndex();

		$table = DB_PREFIX . 'aneepay_order';

		// A request that died between the claim and the mapping must not block
		// the order forever.
		$this->db->query("DELETE FROM `" . $table . "`
			WHERE `order_id` = '" . $order_id . "'
			AND `payment_status` = 'creating'
			AND `date_added` < DATE_SUB(NOW(), INTERVAL " . max(1, (int) $minutes) . " MINUTE)");

		$this->db->query("INSERT IGNORE INTO `" . $table . "` SET
			`order_id` = '" . $order_id . "',
			`store_id` = '" . (int) $this->config->get('config_store_id') . "',
			`payment_status` = 'creating',
			`date_added` = NOW()");

		return $this->db->countAffected() > 0;
	}

	/**
	 * Drop the claim taken by claimOrder() when no payment was created.
	 *
	 * @param int $order_id Order id.
	 * @return void
	 */
	public function releaseClaim($order_id) {
		$this->db->query("DELETE FROM `" . DB_PREFIX . "aneepay_order`
			WHERE `order_id` = '" . (int) $order_id . "'
			AND `payment_status` = 'creating'");
	}

	/**
	 * Guarantee one mapping row per order.
	 *
	 * Existing installations created a plain (non-unique) index, so
	 * duplicates may already exist. They are merged first — the newest row
	 * survives, inheriting `stock_restored` and a `success` status — and only
	 * then the index is tightened, which also makes the upsert in
	 * addMapping() race-free.
	 *
	 * @return void
	 */
	protected function ensureOrderIndex() {
		static $done = false;

		if ($done) {
			return;
		}

		$done = true;

		$table = DB_PREFIX . 'aneepay_order';
		$index = $this->db->query("SHOW INDEX FROM `" . $table . "` WHERE `Key_name` = 'order_id'");

		if ($index->num_rows && 0 === (int) $index->row['Non_unique']) {
			return;
		}

		$this->db->query("UPDATE `" . $table . "` o
			JOIN (
				SELECT `order_id`, MAX(`id`) AS keep_id, MAX(`stock_restored`) AS stock_restored, MAX(`payment_status` = 'success') AS has_success
				FROM `" . $table . "` GROUP BY `order_id` HAVING COUNT(*) > 1
			) d ON o.`id` = d.keep_id
			SET o.`stock_restored` = GREATEST(o.`stock_restored`, d.stock_restored),
				o.`payment_status` = IF(d.has_success = 1, 'success', o.`payment_status`)");

		$this->db->query("DELETE o FROM `" . $table . "` o
			JOIN (
				SELECT `order_id`, MAX(`id`) AS keep_id
				FROM `" . $table . "` GROUP BY `order_id` HAVING COUNT(*) > 1
			) d ON o.`order_id` = d.order_id AND o.`id` <> d.keep_id");

		if ($index->num_rows) {
			$this->db->query("ALTER TABLE `" . $table . "` DROP INDEX `order_id`");
		}

		$this->db->query("ALTER TABLE `" . $table . "` ADD UNIQUE KEY `order_id` (`order_id`)");
	}

	/**
	 * Fetch the mapping for an order.
	 *
	 * @param int $order_id Order id.
	 * @return array|null
	 */
	public function getByOrderId($order_id) {
		$query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "aneepay_order` WHERE `order_id` = '" . (int) $order_id . "' LIMIT 1");

		return $query->num_rows ? $query->row : null;
	}

	/**
	 * Fetch a mapping by AneePay payment UUID.
	 *
	 * @param string $payment_id Payment uuid.
	 * @return array|null
	 */
	public function getByPaymentId($payment_id) {
		$query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "aneepay_order` WHERE `payment_id` = '" . $this->db->escape((string) $payment_id) . "' LIMIT 1");

		return $query->num_rows ? $query->row : null;
	}

	/**
	 * Fetch a mapping by AneePay operation_id (128-bit integer as string).
	 *
	 * @param string $operation_id Operation id.
	 * @return array|null
	 */
	public function getByOperationId($operation_id) {
		$query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "aneepay_order` WHERE `operation_id` = '" . $this->db->escape((string) $operation_id) . "' LIMIT 1");

		return $query->num_rows ? $query->row : null;
	}

	/**
	 * Update the stored payment status of an order.
	 *
	 * @param int    $order_id Order id.
	 * @param string $status   Payment status.
	 * @return void
	 */
	public function updatePaymentStatus($order_id, $status) {
		$this->db->query("UPDATE `" . DB_PREFIX . "aneepay_order` SET `payment_status` = '" . $this->db->escape((string) $status) . "' WHERE `order_id` = '" . (int) $order_id . "'");
	}

	/**
	 * Whether stock was already restored for the order (idempotency guard).
	 *
	 * @param int $order_id Order id.
	 * @return bool
	 */
	public function isStockRestored($order_id) {
		$query = $this->db->query("SELECT `stock_restored` FROM `" . DB_PREFIX . "aneepay_order` WHERE `order_id` = '" . (int) $order_id . "' LIMIT 1");

		return $query->num_rows && !empty($query->row['stock_restored']);
	}

	/**
	 * Mark stock as restored so a later failed/cancelled event cannot double-restore.
	 *
	 * @param int $order_id Order id.
	 * @return void
	 */
	public function markStockRestored($order_id) {
		$this->db->query("UPDATE `" . DB_PREFIX . "aneepay_order` SET `stock_restored` = 1 WHERE `order_id` = '" . (int) $order_id . "'");
	}

	/**
	 * Orders still awaiting a hosted payment, oldest first.
	 *
	 * Bounded by a limit and by age so the poller never walks ancient rows:
	 * payments have no TTL on the vendor side, so anything older than the
	 * aging window is handled by the expiry pass instead.
	 *
	 * @param int $limit        Maximum rows per run.
	 * @param int $max_age_days Ignore rows older than this many days.
	 * @return array<int,array{order_id:int,payment_id:string,date_added:string}>
	 */
	public function getPendingOrders($limit = 100, $max_age_days = 7) {
		$limit        = max(1, (int) $limit);
		$max_age_days = max(1, (int) $max_age_days);

		$query = $this->db->query("SELECT `order_id`, `payment_id`, `date_added` FROM `" . DB_PREFIX . "aneepay_order`
			WHERE `payment_status` IN ('', 'pending')
				AND `date_added` > DATE_SUB(NOW(), INTERVAL " . $max_age_days . " DAY)
			ORDER BY `date_added` ASC, `order_id` ASC
			LIMIT " . $limit);

		return $query->rows;
	}
}
