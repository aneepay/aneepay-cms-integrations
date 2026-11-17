<?php
/**
 * AneePay admin model: table install/uninstall + request log access.
 */
class ModelExtensionPaymentAneePay extends Model {
	/**
	 * Create the extension tables.
	 *
	 * @return void
	 */
	public function install() {
		$this->db->query("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "aneepay_order` (
			`id` INT(11) NOT NULL AUTO_INCREMENT,
			`order_id` INT(11) NOT NULL,
			`store_id` INT(11) NOT NULL DEFAULT 0,
			`payment_id` VARCHAR(64) NOT NULL DEFAULT '',
			`operation_id` VARCHAR(64) NOT NULL DEFAULT '',
			`checkout_url` TEXT NOT NULL,
			`token` VARCHAR(16) NOT NULL DEFAULT '',
			`network` VARCHAR(16) NOT NULL DEFAULT '',
			`sandbox` TINYINT(1) NOT NULL DEFAULT 0,
			`payment_status` VARCHAR(16) NOT NULL DEFAULT 'pending',
			`token_amount` VARCHAR(32) NOT NULL DEFAULT '',
			`order_currency` VARCHAR(8) NOT NULL DEFAULT '',
			`order_total` VARCHAR(32) NOT NULL DEFAULT '',
			`usd_amount` VARCHAR(32) NOT NULL DEFAULT '',
			`fiat_per_usd` VARCHAR(32) NOT NULL DEFAULT '',
			`rate_source` VARCHAR(16) NOT NULL DEFAULT '',
			`stock_restored` TINYINT(1) NOT NULL DEFAULT 0,
			`date_added` DATETIME NOT NULL,
			PRIMARY KEY (`id`),
			UNIQUE KEY `order_id` (`order_id`),
			KEY `payment_id` (`payment_id`),
			KEY `operation_id` (`operation_id`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

		$this->db->query("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "aneepay_log` (
			`log_id` INT(11) NOT NULL AUTO_INCREMENT,
			`context` VARCHAR(64) NOT NULL DEFAULT '',
			`message` TEXT NOT NULL,
			`level` VARCHAR(16) NOT NULL DEFAULT 'info',
			`date_added` DATETIME NOT NULL,
			PRIMARY KEY (`log_id`),
			KEY `date_added` (`date_added`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

		$this->createEventTable();

		$this->ensureSchema();
	}

	/**
	 * Create the webhook event log table (deduplication of at-least-once
	 * webhook deliveries).
	 *
	 * @return void
	 */
	public function createEventTable() {
		$this->db->query("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "aneepay_event` (
			`event_key` VARCHAR(80) NOT NULL,
			`event_id` VARCHAR(64) NOT NULL DEFAULT '',
			`payment_id` VARCHAR(64) NOT NULL DEFAULT '',
			`order_ref` VARCHAR(64) NOT NULL DEFAULT '',
			`status` VARCHAR(16) NOT NULL DEFAULT '',
			`is_sandbox` TINYINT(1) NOT NULL DEFAULT 0,
			`outcome` VARCHAR(32) NOT NULL DEFAULT '',
			`date_added` DATETIME NOT NULL,
			PRIMARY KEY (`event_key`),
			KEY `date_added` (`date_added`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
	}

	/**
	 * Bring an existing installation up to the current schema.
	 *
	 * @return void
	 */
	public function ensureSchema() {
		$table = DB_PREFIX . 'aneepay_order';

		$exists = $this->db->query("SHOW TABLES LIKE '" . $this->db->escape($table) . "'");

		if (!$exists->num_rows) {
			return;
		}

		$column = $this->db->query("SHOW COLUMNS FROM `" . $table . "` LIKE 'stock_restored'");

		if (!$column->num_rows) {
			$this->db->query("ALTER TABLE `" . $table . "` ADD COLUMN `stock_restored` TINYINT(1) NOT NULL DEFAULT 0");
		}

		$this->createEventTable();

		$this->ensureOrderStatusDefaults();
	}

	/**
	 * Give the payment states a working default order status.
	 *
	 * A status of 0 records the payment mapping but adds no order history
	 * entry, so a failed or cancelled payment would be invisible to the
	 * merchant. OpenCart ships 1 = Pending, 2 = Processing, 7 = Canceled and
	 * 10 = Failed; a store that removed them keeps the field empty and the
	 * settings page refuses to save until the merchant picks one.
	 *
	 * @return void
	 */
	public function ensureOrderStatusDefaults() {
		$defaults = array(
			'pending_status_id'   => 1,
			'paid_status_id'      => 2,
			'failed_status_id'    => 10,
			'cancelled_status_id' => 7,
		);

		foreach ($defaults as $field => $status_id) {
			$key = 'payment_aneepay_' . $field;

			if ('' !== (string) $this->config->get($key)) {
				continue;
			}

			if (!$this->orderStatusExists($status_id)) {
				continue;
			}

			$this->setSetting($key, (string) $status_id);
		}
	}

	/**
	 * Whether an order status exists in this store.
	 *
	 * @param int $status_id Order status id.
	 * @return bool
	 */
	protected function orderStatusExists($status_id) {
		$query = $this->db->query("SELECT `order_status_id` FROM `" . DB_PREFIX . "order_status` WHERE `order_status_id` = '" . (int) $status_id . "' LIMIT 1");

		return (bool) $query->num_rows;
	}

	/**
	 * Persist a single payment setting without touching the others.
	 *
	 * OpenCart's editSetting() deletes every row for the code first, so it
	 * cannot be used to update one value (e.g. the generated cron secret).
	 *
	 * @param string $key   Setting key.
	 * @param string $value Setting value.
	 * @return void
	 */
	public function setSetting($key, $value) {
		$this->db->query("DELETE FROM `" . DB_PREFIX . "setting` WHERE `store_id` = '0' AND `code` = 'payment_aneepay' AND `key` = '" . $this->db->escape($key) . "'");
		$this->db->query("INSERT INTO `" . DB_PREFIX . "setting` SET `store_id` = '0', `code` = 'payment_aneepay', `key` = '" . $this->db->escape($key) . "', `value` = '" . $this->db->escape($value) . "', `serialized` = '0'");
	}

	/**
	 * Drop the extension tables.
	 *
	 * @return void
	 */
	public function uninstall() {
		// Preserve order/log data by default. The DROP only runs when the
		// merchant explicitly opts in to deletion via the keep-data setting,
		// so a reinstall or audit can still read the tables.
		if ($this->config->get('payment_aneepay_keep_data') !== '0') {
			return;
		}

		$this->db->query("DROP TABLE IF EXISTS `" . DB_PREFIX . "aneepay_order`");
		$this->db->query("DROP TABLE IF EXISTS `" . DB_PREFIX . "aneepay_log`");
		$this->db->query("DROP TABLE IF EXISTS `" . DB_PREFIX . "aneepay_event`");
	}

	/**
	 * The most recent request log entries (newest first), ring buffer.
	 *
	 * @return array
	 */
	public function getLogs() {
		$query = $this->db->query("SELECT `context`, `message`, `level`, `date_added` FROM `" . DB_PREFIX . "aneepay_log` WHERE `context` = 'request' ORDER BY `log_id` DESC LIMIT 50");

		return $query->rows;
	}

	/**
	 * Clear the request log.
	 *
	 * @return void
	 */
	public function clearLogs() {
		$this->db->query("DELETE FROM `" . DB_PREFIX . "aneepay_log` WHERE `context` = 'request'");
	}
}
