<?php
/**
 * Webhook endpoint (STATUS_URL). Verifies the HMAC-SHA256 signature, rejects
 * duplicates and applies the payment status to the matching order.
 */
class Ps_AneepayWebhookModuleFrontController extends ModuleFrontController {
	public function init() {
		$module = Module::getInstanceByName('ps_aneepay');

		if (!Validate::isLoadedObject($module)) {
			$this->respond(500, array('success' => false));
		}

		$api = $module->getApi();

		$body      = (string) Tools::file_get_contents('php://input');
		$signature = isset($_SERVER['HTTP_X_ANEEPAY_SIGNATURE']) ? (string) $_SERVER['HTTP_X_ANEEPAY_SIGNATURE'] : '';

		if (!$api->verify_signature($body, $signature)) {
			$this->respond(401, array('success' => false));
		}

		$params = json_decode($body, true, 512, JSON_BIGINT_AS_STRING);

		if (!is_array($params) || empty($params['status'])) {
			$this->respond(400, array('success' => false));
		}

		$status = (string) $params['status'];

		if (!isset($module->getStateMap()[$status])) {
			$this->respond(400, array('success' => false));
		}

		$orders  = new AneePayOrder();
		$webhook = new AneePayWebhook($api, $orders, $module->getStateMap());
		$id_order = $webhook->resolveOrderId($params);

		if (!$id_order) {
			// Not recorded: a retry after the order mapping is committed must
			// still be able to resolve.
			$this->respond(404, array('success' => false));
		}

		$id_order  = (int) $id_order;
		$key       = AneePayEventLog::dedupKey($params, $body);
		$order_ref = !empty($params['payment_id']) && is_scalar($params['payment_id'])
			? (string) $params['payment_id']
			: '#' . $id_order;

		// At-least-once delivery: the first claim wins, retries are no-ops.
		if (!AneePayEventLog::claim($key, $params, $order_ref)) {
			$this->respond(200, array(
				'success'   => true,
				'duplicate' => true,
				'status'    => $status,
			));
		}

		try {
			$webhook->apply($id_order, $status, $this->note($status, $params));
		} catch (Throwable $e) {
			// Give the vendor retry a chance: the event must not stay claimed.
			AneePayEventLog::release($key);
			$api->write_log('webhook', 'Failed to apply status for order #' . $id_order . ': ' . $e->getMessage(), 'error');

			$this->respond(500, array('success' => false));
		}

		$this->respond(200, array('success' => true, 'status' => $status));
	}

	/**
	 * Order note for a status.
	 *
	 * @param string $status Payment status.
	 * @param array  $params Webhook payload.
	 * @return string
	 */
	protected function note($status, $params) {
		$map = array(
			'success'   => 'AneePay: payment confirmed.',
			'failed'    => 'AneePay: payment failed.',
			'cancelled' => 'AneePay: payment cancelled by the customer.',
			'pending'   => 'AneePay: awaiting payment.',
		);

		$note = isset($map[$status]) ? $map[$status] : 'AneePay: ' . $status . '.';

		if (!empty($params['operation_id'])) {
			$note .= ' Operation ID: ' . (string) $params['operation_id'];
		}

		return $note;
	}

	/**
	 * Send a JSON response and stop.
	 *
	 * @param int   $code HTTP status code.
	 * @param array $data Payload.
	 * @return void
	 */
	protected function respond($code, $data) {
		http_response_code((int) $code);
		header('Content-Type: application/json');
		echo json_encode($data);

		exit;
	}
}
