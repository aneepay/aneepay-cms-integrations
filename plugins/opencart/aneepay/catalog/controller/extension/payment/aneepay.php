<?php
/**
 * AneePay payment method (catalog): index / confirm / callback / success / fail / cron.
 */
class ControllerExtensionPaymentAneePay extends Controller {
	/**
	 * Load the AneePay library classes (OC's library loader expects the class
	 * name to match the file basename, so these are included directly).
	 *
	 * @return void
	 */
	protected function load_lib() {
		$base = DIR_SYSTEM . 'library/aneepay/';

		if (!class_exists('AneePay_Client')) {
			require_once $base . 'aneepay.php';
		}

		if (!class_exists('AneePay_Usd_Converter')) {
			require_once $base . 'usd-converter.php';
		}

		if (!class_exists('AneePay_Webhook')) {
			require_once $base . 'webhook.php';
		}

		if (!class_exists('AneePay_Event_Log')) {
			require_once $base . 'event-log.php';
		}
	}

	/**
	 * Build the API client from the current store settings.
	 *
	 * @return AneePay_Client
	 */
	protected function client() {
		$this->load_lib();

		$site_domain = $this->normalize_site_domain((string) $this->config->get('payment_aneepay_site_domain'));

		$settings = array(
			'account_id'     => (string) $this->config->get('payment_aneepay_account_id'),
			'webhook_secret' => (string) $this->config->get('payment_aneepay_webhook_secret'),
			'site_domain'    => $site_domain,
			'host'           => '' !== $site_domain ? $site_domain : $this->canonical_host(),
			'token'          => (string) $this->config->get('payment_aneepay_token', 'usdt'),
			'network'        => (string) $this->config->get('payment_aneepay_network', 'polygon'),
			'test_mode'      => $this->setting_bool('payment_aneepay_test_mode'),
			'rate_source'    => (string) $this->config->get('payment_aneepay_rate_source', 'auto'),
			'exchange_rate'  => (string) $this->config->get('payment_aneepay_exchange_rate', ''),
			'debug'          => $this->setting_bool('payment_aneepay_debug'),
		);

		return new AneePay_Client($settings, $this->db);
	}

	/**
	 * Is a setting truthy (OC stores checkbox as '1'/'0' or ''/'on')?
	 *
	 * @param string $key Setting key.
	 * @return bool
	 */
	protected function setting_bool($key) {
		$value = $this->config->get($key);

		return in_array($value, array('1', 'on', 'yes', true), true);
	}

	/**
	 * Canonical storefront host, never taken from the current request.
	 *
	 * The Origin header identifies the merchant account at AneePay, so it must
	 * not depend on a client-supplied `Host` header (SEC-10). The store URL
	 * from the OpenCart configuration is the only fallback.
	 *
	 * @return string Bare host, or '' when the store URL is unusable.
	 */
	protected function canonical_host() {
		$candidates = array(
			(string) $this->config->get('config_ssl'),
			(string) $this->config->get('config_url'),
		);

		foreach ($candidates as $candidate) {
			$host = parse_url($candidate, PHP_URL_HOST);

			if (is_string($host) && '' !== $host) {
				return strtolower($host);
			}
		}

		return '';
	}

	/**
	 * Reduce a configured site domain to a bare host.
	 *
	 * Accepts `shop.example.com`, `https://shop.example.com/`,
	 * `shop.example.com:8443` — the API expects a host, not a URL.
	 *
	 * @param string $value Raw setting value.
	 * @return string
	 */
	protected function normalize_site_domain($value) {
		$value = strtolower(trim((string) $value));
		$value = preg_replace('#^[a-z][a-z0-9+.\-]*://#', '', $value);
		$value = preg_split('#[/?\#]#', $value);
		$value = rtrim((string) $value[0], '.');
		$value = preg_replace('#:[0-9]+$#', '', $value);

		return (string) $value;
	}

	/**
	 * Render the payment method on the checkout confirmation page.
	 */
	public function index() {
		$this->load->language('extension/payment/aneepay');

		$data['button_confirm'] = $this->language->get('button_confirm');
		$data['text_instruction'] = $this->language->get('text_instruction');
		$data['action'] = $this->url->link('extension/payment/aneepay/confirm', '', true);
		$data['breakdown'] = $this->breakdown_html();

		return $this->load->view('extension/payment/aneepay', $data);
	}

	/**
	 * Confirm the order, create the AneePay payment and redirect the buyer.
	 */
	public function confirm() {
		$this->load_lib();
		$this->load->language('extension/payment/aneepay');
		$this->load->model('checkout/order');
		$this->load->model('extension/payment/aneepay');

		if (!$this->cart->hasProducts()) {
			$this->response->redirect($this->url->link('checkout/cart', '', true));
			return;
		}

		$order_id = isset($this->session->data['order_id']) ? (int) $this->session->data['order_id'] : 0;

		if (!$order_id) {
			$this->response->redirect($this->url->link('checkout/cart', '', true));
			return;
		}

		$order_info = $this->model_checkout_order->getOrder($order_id);

		if (!$order_info) {
			$this->response->redirect($this->url->link('checkout/cart', '', true));
			return;
		}

		$mapping = $this->model_extension_payment_aneepay->getByOrderId($order_id);

		// Idempotency gate: reuse an existing pending hosted payment.
		if ($mapping && '' !== (string) $mapping['checkout_url'] && in_array((string) $mapping['payment_status'], array('', 'pending'), true)) {
			$this->session->data['aneepay_order'] = $order_id;

			$this->response->redirect((string) $mapping['checkout_url']);
			return;
		}

		$client = $this->client();

		try {
			$converter = new AneePay_Usd_Converter($client);
			$client->set_config('store_rate', $this->store_rate((string) $order_info['currency_code']));

			$converted = $converter->convert((float) $order_info['total'], (string) $order_info['currency_code']);

			$created = $client->create_payment($converted['token_amount'], 'Order #' . $order_id);

			$this->model_extension_payment_aneepay->addMapping($order_id, array(
				'payment_id'     => $created['payment_id'],
				'operation_id'   => $created['operation_id'],
				'checkout_url'   => $created['checkout_url'],
				'token'          => $client->get_token(),
				'network'        => $client->get_network(),
				'sandbox'        => $client->is_sandbox() ? 1 : 0,
				'payment_status' => 'pending',
				'token_amount'   => number_format((float) $converted['token_amount'], 2, '.', ''),
				'order_currency' => (string) $order_info['currency_code'],
				'order_total'    => (string) $order_info['total'],
				'usd_amount'     => number_format((float) $converted['usd_amount'], 2, '.', ''),
				'fiat_per_usd'   => (string) $converted['fiat_per_usd'],
				'rate_source'    => (string) $converted['source'],
			));

			$pending_status = (int) $this->config->get('payment_aneepay_pending_status_id');
			$pending_status = $pending_status ? $pending_status : 1;

			$this->model_checkout_order->addOrderHistory($order_id, $pending_status, $this->language->get('text_pending'), true);

			$this->session->data['aneepay_order'] = $order_id;

			$this->response->redirect((string) $created['checkout_url']);
		} catch (Exception $e) {
			// The order is left untouched. The shopper sees one short translated
			// sentence; the technical reason stays in the request log.
			$client->write_log('confirm', 'Payment creation failed: ' . $e->getMessage(), 'error');

			$kind = $e instanceof AneePay_Api_Exception ? $e->get_kind() : AneePay_Api_Exception::KIND_REQUEST;
			$key  = 'error_' . $kind;
			$text = $this->language->get($key);

			$this->session->data['error_warning'] = ($text === $key) ? $this->language->get('error_request') : $text;

			$this->response->redirect($this->url->link('checkout/confirm', '', true));
		}
	}

	/**
	 * Scale for the store currency -> USD conversion from the OC currency table.
	 *
	 * @param string $currency_code Store currency code.
	 * @return float
	 */
	protected function store_rate($currency_code) {
		$currency_code = strtoupper((string) $currency_code);

		if ('USD' === $currency_code) {
			return 1.0;
		}

		try {
			$usd_per_fiat = (float) $this->currency->convert(1, $currency_code, 'USD');

			return $usd_per_fiat > 0 ? 1 / $usd_per_fiat : 0;
		} catch (Exception $e) {
			return 0;
		}
	}

	/**
	 * Build the conversion breakdown HTML for the payment method card.
	 *
	 * @return string
	 */
	protected function breakdown_html() {
		$total = (float) $this->cart->getTotal();

		if ($total <= 0) {
			return '';
		}

		$currency = (string) $this->config->get('config_currency');

		$client = $this->client();
		$client->set_config('store_rate', $this->store_rate($currency));
		$converter = new AneePay_Usd_Converter($client);

		try {
			$converted = $converter->convert($total, $currency);
		} catch (Exception $e) {
			return '';
		}

		$token = strtoupper($client->get_token());

		$html = '<span class="aneepay-breakdown">';
		$html .= sprintf('You will pay %01.2f %s', (float) $converted['token_amount'], $token);
		$html .= ' <small>(' . number_format((float) $converted['usd_amount'], 2, '.', '');
		$html .= ' USD &middot; ' . $this->rate_source_label((string) $converted['source']) . ')</small>';

		$note = (string) $this->language->get('text_rate_note');

		if ('' !== $note && 'text_rate_note' !== $note) {
			$html .= '<br><small class="aneepay-breakdown-note">' . $note . '</small>';
		}

		$html .= '</span>';

		return $html;
	}

	/**
	 * Human label for the resolved rate source.
	 *
	 * @param string $source Source key.
	 * @return string
	 */
	protected function rate_source_label($source) {
		if ('manual' === $source) {
			return 'shop rate';
		}

		if ('store' === $source) {
			return 'store rate';
		}

		return 'ECB / Frankfurter';
	}

	/**
	 * Webhook endpoint (STATUS_URL).
	 */
	public function callback() {
		$this->load_lib();
		$client = $this->client();

		$body      = (string) file_get_contents('php://input');
		$signature = $this->header('X-AneePay-Signature');

		if (!$client->verify_signature($body, $signature)) {
			$this->response->addHeader('HTTP/1.1 401 Unauthorized');
			$this->response->setOutput('{"success":false}');
			return;
		}

		$params = json_decode($body, true, 512, JSON_BIGINT_AS_STRING);

		if (!is_array($params)) {
			$this->response->addHeader('HTTP/1.1 400 Bad Request');
			$this->response->setOutput('{"success":false}');
			return;
		}

		$status = isset($params['status']) ? (string) $params['status'] : '';

		if ('' === $status || !isset($this->status_map()[$status])) {
			$this->response->addHeader('HTTP/1.1 400 Bad Request');
			$this->response->setOutput('{"success":false}');
			return;
		}

		if (!$this->timestamp_is_valid($params)) {
			$this->response->addHeader('HTTP/1.1 400 Bad Request');
			$this->response->setOutput('{"success":false,"error":"invalid_timestamp"}');
			return;
		}

		$this->load->model('extension/payment/aneepay');
		$this->load->model('checkout/order');

		$webhook  = new AneePay_Webhook($this->registry, $client, $this->status_map());
		$order_id = $webhook->resolve_order_id($params);

		if (!$order_id) {
			// Deliberately not recorded: the vendor retries, and a retry can
			// resolve once the payment mapping has been committed.
			$this->response->addHeader('HTTP/1.1 404 Not Found');
			$this->response->setOutput('{"success":false}');
			return;
		}

		$mapping = $this->model_extension_payment_aneepay->getByOrderId($order_id);
		$events  = new AneePay_Event_Log($this->db);
		$event_key = $events->dedup_key($params, $body);

		// A sandbox payment must never complete a live order (and vice versa).
		$payload_sandbox = AneePay_Event_Log::payload_is_sandbox($params);
		$order_sandbox   = $mapping ? (bool) (int) $mapping['sandbox'] : null;

		if (null !== $payload_sandbox && null !== $order_sandbox && $payload_sandbox !== $order_sandbox) {
			$events->claim($event_key, $params, (string) $order_id, 'ignored_sandbox');
			$client->write_log('webhook', sprintf(
				'Ignored "%1$s" event for order %2$d: the payment environment (%3$s) does not match the order (%4$s).',
				$status,
				$order_id,
				$payload_sandbox ? 'sandbox' : 'live',
				$order_sandbox ? 'sandbox' : 'live'
			), 'error');

			$this->response->addHeader('Content-Type: application/json');
			$this->response->setOutput('{"success":false,"ignored":"sandbox_mismatch"}');
			return;
		}

		if (!$events->claim($event_key, $params, (string) $order_id, 'applied')) {
			// At-least-once delivery: the same event was already applied.
			$this->response->addHeader('Content-Type: application/json');
			$this->response->setOutput('{"success":true,"duplicate":true}');
			return;
		}

		try {
			$webhook->apply($order_id, $status, $this->status_note($status, $params));
		} catch (Throwable $e) {
			// The retry must not be swallowed as a duplicate.
			$events->release($event_key);
			$client->write_log('webhook', $e->getMessage(), 'error');

			$this->response->addHeader('HTTP/1.1 500 Internal Server Error');
			$this->response->setOutput('{"success":false}');
			return;
		}

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode(array('success' => true, 'status' => $status)));
	}

	/**
	 * Validate the `timestamp` field of a webhook payload.
	 *
	 * The v1 contract makes `timestamp` mandatory and numeric, but it is
	 * informational: freshness is not what authenticates a webhook (the HMAC
	 * signature is), and replays are rejected by event deduplication. There is
	 * therefore no staleness window — only a type check.
	 *
	 * @param array $params Decoded webhook payload.
	 * @return bool
	 */
	protected function timestamp_is_valid($params) {
		$is_v1 = isset($params['version']) || isset($params['event_id']);

		if (!array_key_exists('timestamp', $params)) {
			return !$is_v1;
		}

		$timestamp = $params['timestamp'];

		if (is_bool($timestamp) || !is_numeric($timestamp)) {
			return false;
		}

		return (float) $timestamp >= 0;
	}

	/**
	 * Read a request header, tolerant of OC's normalized server key.
	 *
	 * @param string $name Header name.
	 * @return string
	 */
	protected function header($name) {
		$key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));

		return isset($this->request->server[$key]) ? (string) $this->request->server[$key] : '';
	}

	/**
	 * Map payment statuses to OpenCart order status ids from settings.
	 *
	 * @return array
	 */
	protected function status_map() {
		return array(
			'pending'   => (int) $this->config->get('payment_aneepay_pending_status_id'),
			'success'   => (int) $this->config->get('payment_aneepay_paid_status_id'),
			'failed'    => (int) $this->config->get('payment_aneepay_failed_status_id'),
			'cancelled' => (int) $this->config->get('payment_aneepay_cancelled_status_id'),
		);
	}

	/**
	 * Order history note for a payment status.
	 *
	 * @param string $status Payment status.
	 * @param array  $params Webhook payload.
	 * @return string
	 */
	protected function status_note($status, $params = array()) {
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
	 * Result page after the buyer returns from AneePay (SUCCESS_URL / FAIL_URL
	 * receive a ?result=... or ?payment=...; status is polled client-side).
	 */
	public function success() {
		$this->load->language('extension/payment/aneepay');
		$this->load->model('extension/payment/aneepay');
		$this->load->model('checkout/order');

		// Only the session established at confirm() is trusted. The old
		// ?aneepay_order=N fallback let any visitor fetch another buyer's
		// order number/total/payment id by guessing the id (IDOR).
		$order_id = isset($this->session->data['aneepay_order']) ? (int) $this->session->data['aneepay_order'] : 0;

		$data = $this->result_facing($order_id, true, (string) ($this->request->get['result'] ?? ''), (string) ($this->request->get['payment'] ?? ''));

		$this->response->setOutput($this->load->view('extension/payment/aneepay_result', $data));
	}

	/**
	 * Failure / cancelled page.
	 */
	public function fail() {
		$this->load->language('extension/payment/aneepay');
		$this->load->model('extension/payment/aneepay');
		$this->load->model('checkout/order');

		// Session-only: see success() above.
		$order_id = isset($this->session->data['aneepay_order']) ? (int) $this->session->data['aneepay_order'] : 0;

		$data = $this->result_facing($order_id, false, (string) ($this->request->get['result'] ?? ''), (string) ($this->request->get['payment'] ?? ''));

		$this->response->setOutput($this->load->view('extension/payment/aneepay_result', $data));
	}

	/**
	 * Build the result-page data (shared by success/fail).
	 *
	 * @param int    $order_id Order id.
	 * @param bool   $is_success Whether it is a success page.
	 * @param string $result   Query param.
	 * @param string $payment  Query param.
	 * @return array
	 */
	protected function result_facing($order_id, $is_success, $result = '', $payment = '') {
		$facing = array(
			'is_success'      => $is_success,
			'result'          => $result,
			'payment'         => $payment,
			'order_id'        => $order_id,
			'order_number'    => '',
			'total'           => '',
			'currency'        => (string) $this->config->get('config_currency'),
			'token'           => '',
			'token_amount'    => '',
			'payment_id'      => '',
			'tx_hash'         => '',
			'checkout_url'    => '',
			'button_retry'    => $this->language->get('button_retry'),
			'button_shop'     => $this->language->get('button_shop'),
			'text_success'    => $this->language->get('text_success'),
			'text_failed'     => $this->language->get('text_failed'),
			'text_pending'    => $this->language->get('text_pending'),
			'text_awaiting'   => $this->language->get('text_awaiting'),
			'text_you_pay'    => $this->language->get('text_you_pay'),
			'button_i_paid'   => $this->language->get('button_i_paid'),
			'checking_label'  => $this->language->get('text_checking'),
			'shop_url'        => $this->url->link('common/home', '', true),
			'page'            => $is_success ? 'success' : 'fail',
		);

		if ($order_id) {
			$order_info = $this->model_checkout_order->getOrder($order_id);

			if ($order_info) {
				$facing['order_number'] = (string) ($order_info['order_id'] ?? '');
				$facing['total']        = (string) ($order_info['total'] ?? '');
				$facing['currency']     = (string) ($order_info['currency_code'] ?? '');
				$facing['order_number'] = (string) ($order_info['order_id']);
			}

			$mapping = $this->model_extension_payment_aneepay->getByOrderId($order_id);

			if ($mapping) {
				$facing['token']      = strtoupper((string) $mapping['token']);
				$facing['token_amount'] = trim((string) $mapping['token_amount']);
				$facing['payment_id'] = (string) $mapping['payment_id'];
				$facing['checkout_url'] = (string) $mapping['checkout_url'];
			}
		}

		return $facing;
	}

	/**
	 * Poll pending orders (cron entry point for the store).
	 */
	public function cron() {
		$this->load_lib();
		$this->load->model('extension/payment/aneepay');
		$this->load->model('checkout/order');

		$expected = (string) $this->config->get('payment_aneepay_cron_secret');
		$provided = isset($this->request->get['secret']) ? (string) $this->request->get['secret'] : '';

		if ('' === $expected || '' === $provided || !hash_equals($expected, $provided)) {
			$this->response->addHeader('HTTP/1.1 403 Forbidden');
			return 'forbidden';
		}

		if ($this->cache->get('aneepay_cron_lock')) {
			return 'locked';
		}

		$this->cache->set('aneepay_cron_lock', 1, 300);

		$client = null;
		$stats  = array('checked' => 0, 'updated' => 0, 'expired' => 0, 'failed' => 0);

		try {
			$client  = $this->client();
			$map     = $this->status_map();
			$webhook = new AneePay_Webhook($this->registry, $client, $map);

			$client->prune_log();

			$expiry_minutes = (int) $this->config->get('payment_aneepay_expiry_minutes');

			if ($expiry_minutes <= 0) {
				$expiry_minutes = 120;
			}

			foreach ($this->model_extension_payment_aneepay->getPendingOrders(100, 7) as $row) {
				$order_id = (int) $row['order_id'];
				$stats['checked']++;

				// One broken payment must never abort the batch.
				try {
					$status = '';

					if (!empty($row['payment_id'])) {
						$data = $client->get_payment((string) $row['payment_id']);

						if (is_array($data) && !empty($data['status'])) {
							$status = (string) $data['status'];
						}
					}

					if ('' === $status) {
						// Nothing to apply: decide whether the pending order has
						// simply been abandoned. Only expire when the API gave a
						// definite answer, never on a transport error or 5xx.
						$http    = !empty($row['payment_id']) ? $client->last_http_code() : 404;
						$unknown = (0 === $http || $http >= 500 || 429 === $http);

						if (!$unknown && $this->pending_is_expired((string) $row['date_added'], $expiry_minutes)) {
							$terminal = (isset($map['cancelled']) && (int) $map['cancelled'] > 0) ? 'cancelled' : 'failed';

							if ($webhook->apply($order_id, $terminal)) {
								$stats['expired']++;
							}

							$client->write_log('cron', sprintf('Order #%d: no payment after %d minutes, marked %s.', $order_id, $expiry_minutes, $terminal), 'warning');
						}

						continue;
					}

					if ($webhook->apply($order_id, $status)) {
						$stats['updated']++;
					}
				} catch (Throwable $e) {
					$stats['failed']++;
					$client->write_log('cron', sprintf('Order #%d: %s', $order_id, $e->getMessage()), 'error');
				}
			}
		} catch (Throwable $e) {
			if ($client instanceof AneePay_Client) {
				$client->write_log('cron', $e->getMessage(), 'error');
			} else {
				error_log('AneePay cron: ' . $e->getMessage());
			}

			$stats['failed']++;
		} finally {
			$this->cache->delete('aneepay_cron_lock');
		}

		return json_encode($stats);
	}

	/**
	 * Whether a pending order is older than the expiry window.
	 *
	 * @param string $date_added Mapping creation time (Y-m-d H:i:s).
	 * @param int    $minutes    Expiry window in minutes.
	 * @return bool
	 */
	protected function pending_is_expired($date_added, $minutes) {
		$created = strtotime((string) $date_added);

		if (false === $created || $minutes <= 0) {
			return false;
		}

		return (time() - $created) > ($minutes * 60);
	}

	/**
	 * AJAX: check the status of an order ("I already paid" button / polling).
	 */
	public function check() {
		$this->load_lib();
		$this->load->language('extension/payment/aneepay');
		$this->load->model('extension/payment/aneepay');
		$this->load->model('checkout/order');

		$this->response->addHeader('Content-Type: application/json');

		$order_id = isset($this->request->post['order_id']) ? (int) $this->request->post['order_id'] : 0;

		if (!$order_id) {
			$this->response->setOutput(json_encode(array('status' => 'invalid')));
			return;
		}

		// Ownership: the order must be the one this browser started at
		// confirm(). Without this check any visitor could poll / trigger a
		// status transition for an arbitrary order id (IDOR).
		$session_order = isset($this->session->data['aneepay_order']) ? (int) $this->session->data['aneepay_order'] : 0;

		if (!$session_order || $session_order !== $order_id) {
			$this->response->setOutput(json_encode(array('status' => 'forbidden')));
			return;
		}

		// Cheap per-session rate limit so the endpoint cannot be used to
		// hammer the AneePay API for a known order.
		$rate_key = 'aneepay_check_' . $order_id;

		if ($this->cache->get($rate_key)) {
			$mapping = $this->model_extension_payment_aneepay->getByOrderId($order_id);
			$last    = $mapping && !empty($mapping['payment_status']) ? (string) $mapping['payment_status'] : 'pending';

			$this->response->setOutput(json_encode(array('status' => $last)));
			return;
		}

		$this->cache->set($rate_key, 1, 3);

		$mapping = $this->model_extension_payment_aneepay->getByOrderId($order_id);

		if (!$mapping) {
			$this->response->setOutput(json_encode(array('status' => 'not_found')));
			return;
		}

		if (!empty($mapping['payment_status'])) {
			$status = (string) $mapping['payment_status'];

			if (!in_array($status, array('', 'pending'), true)) {
				$this->response->setOutput(json_encode(array('status' => $status)));
				return;
			}
		}

		$client = null;

		try {
			$client = $this->client();
			$data   = $client->get_payment((string) $mapping['payment_id']);

			if (null === $data || empty($data['status'])) {
				$this->response->setOutput(json_encode(array('status' => (string) $mapping['payment_status'])));
				return;
			}

			$webhook = new AneePay_Webhook($this->registry, $client, $this->status_map());
			$webhook->apply($order_id, (string) $data['status']);

			$this->response->setOutput(json_encode(array('status' => (string) $data['status'])));
		} catch (Throwable $e) {
			if ($client instanceof AneePay_Client) {
				$client->write_log('check', $e->getMessage(), 'error');
			} else {
				error_log('AneePay check: ' . $e->getMessage());
			}

			$this->response->setOutput(json_encode(array('status' => (string) $mapping['payment_status'])));
		}
	}
}
