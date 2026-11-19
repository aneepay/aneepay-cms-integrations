<?php
/**
 * Poll pending AneePay orders (cron entry point).
 */
class Ps_AneepayCronModuleFrontController extends ModuleFrontController {
	public function init() {
		$module = Module::getInstanceByName('ps_aneepay');

		if (!Validate::isLoadedObject($module)) {
			$this->respond(500, array('success' => false, 'message' => 'module not loaded'));
		}

		// The poller is a public front controller, so it must be gated by a
		// secret: otherwise any visitor could drive status transitions for
		// every pending order. The secret is generated once and persisted.
		$provided = (string) Tools::getValue('secret');

		if ('' === $provided || !hash_equals($module->cronSecret(), $provided)) {
			$this->respond(403, array('success' => false, 'message' => 'forbidden'));
		}

		// No-overlap guard. A lock older than 10 minutes is considered stale
		// (a previous run crashed) and is taken over.
		$lock = _PS_CACHE_DIR_ . 'aneepay_cron_lock';

		if (file_exists($lock) && (time() - (int) file_get_contents($lock)) < 600) {
			$this->respond(200, array('success' => false, 'message' => 'locked'));
		}

		file_put_contents($lock, (string) time());

		$payload = array('success' => true, 'checked' => 0, 'updated' => 0, 'expired' => 0, 'failed' => 0);

		try {
			$api       = $module->getApi();
			$orders    = new AneePayOrder();
			$webhook   = new AneePayWebhook($api, $orders, $module->getStateMap());

			// Retention: drop log rows older than the window on every run.
			$api->pruneLog();

			// 0 disables expiry: the cron only reports statuses then, it never
			// cancels an abandoned order and never releases its stock.
			$expiry_minutes = max(0, (int) Configuration::get('ANEEPAY_EXPIRY_MINUTES'));

			foreach ($orders->getPendingOrders(100, 7) as $row) {
				$id_order = (int) $row['id_order'];
				$payload['checked']++;

				// One broken payment must never abort the batch.
				try {
					$status = '';

					if ('' !== (string) $row['payment_id']) {
						$data = $api->get_payment((string) $row['payment_id']);

						if (is_array($data) && !empty($data['status'])) {
							$status = (string) $data['status'];
						}
					}

					if ('' === $status) {
						// Nothing to apply: decide whether the pending order has
						// simply been abandoned. Only expire on a definite answer
						// from the API, never on a transport error or 5xx.
						$http    = '' !== (string) $row['payment_id'] ? $api->lastHttpCode() : 404;
						$unknown = (0 === $http || $http >= 500 || 429 === $http);

						if (!$unknown && $this->isExpired((string) $row['date_added'], $expiry_minutes)) {
							$state_map = $module->getStateMap();
							$terminal  = (!empty($state_map['cancelled']) && (int) $state_map['cancelled'] > 0) ? 'cancelled' : 'failed';

							if ($webhook->apply($id_order, $terminal)) {
								$payload['expired']++;
							}

							$api->write_log('cron', sprintf('Order #%d: no payment after %d minutes, marked %s.', $id_order, $expiry_minutes, $terminal), 'warning');
						}

						continue;
					}

					if ($webhook->apply($id_order, $status)) {
						$payload['updated']++;
					}
				} catch (Exception $e) {
					$payload['failed']++;
					$api->write_log('cron', sprintf('Order #%d: %s', $id_order, $e->getMessage()), 'error');
				}
			}
		} catch (Exception $e) {
			$payload['success'] = false;
			$payload['message'] = $e->getMessage();
			$payload['failed']++;
		}

		// Release the lock before responding: respond() exits the script, so a
		// finally block would never run.
		if (file_exists($lock)) {
			unlink($lock);
		}

		$this->respond(200, $payload);
	}

	/**
	 * Whether a pending order is older than the expiry window.
	 *
	 * @param string $date_added Mapping creation time (Y-m-d H:i:s).
	 * @param int    $minutes    Expiry window in minutes.
	 * @return bool
	 */
	protected function isExpired($date_added, $minutes) {
		$created = strtotime((string) $date_added);

		if (false === $created || $minutes <= 0) {
			return false;
		}

		return (time() - $created) > ($minutes * 60);
	}

	protected function respond($code, $data) {
		http_response_code((int) $code);
		header('Content-Type: application/json');
		echo json_encode($data);

		exit;
	}
}
