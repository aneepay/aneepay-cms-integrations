<?php
/**
 * AneePay payment settings (admin).
 */
class ControllerExtensionPaymentAneePay extends Controller {
	private $error = array();

	/**
	 * Permission token for AJAX routes.
	 *
	 * @return bool
	 */
	protected function authorised() {
		if (!$this->user->hasPermission('modify', 'extension/payment/aneepay')) {
			return false;
		}

		$token = isset($this->request->get['user_token']) ? (string) $this->request->get['user_token'] : '';

		return $token == (string) $this->session->data['user_token'];
	}

	/**
	 * Render the settings page and handle the save.
	 */
	public function index() {
		$this->load->language('extension/payment/aneepay');

		$this->document->setTitle($this->language->get('heading_title'));

		$this->load->model('setting/setting');
		$this->load->model('localisation/order_status');
		$this->load->model('extension/payment/aneepay');
		$this->load_lib();

		$cron_secret = (string) $this->config->get('payment_aneepay_cron_secret');

		if ('' === $cron_secret) {
			$cron_secret = bin2hex(random_bytes(16));
			$this->model_extension_payment_aneepay->setSetting('payment_aneepay_cron_secret', $cron_secret);
		}

		$this->model_extension_payment_aneepay->ensureSchema();

		if ($this->request->server['REQUEST_METHOD'] == 'POST' && $this->validate()) {
			$this->request->post['payment_aneepay_cron_secret'] = $cron_secret;

			$this->model_setting_setting->editSetting('payment_aneepay', $this->request->post);

			$this->session->data['success'] = $this->language->get('text_success');

			$this->response->redirect($this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment', true));
		}

		if (isset($this->error['warning'])) {
			$data['error_warning'] = $this->error['warning'];
		} else {
			$data['error_warning'] = '';
		}

		foreach (array('pending_status_id', 'paid_status_id', 'failed_status_id', 'cancelled_status_id') as $field) {
			$data['error_' . $field] = isset($this->error[$field]) ? $this->error[$field] : '';
		}

		$fields = array(
			'status', 'title', 'description', 'account_id', 'webhook_secret', 'site_domain',
			'test_mode', 'token', 'network', 'rate_source', 'exchange_rate',
			'pending_status_id', 'paid_status_id', 'failed_status_id', 'cancelled_status_id',
			'debug', 'cron_interval', 'expiry_minutes', 'fee_rule', 'keep_data',
		);

		foreach ($fields as $field) {
			if (isset($this->request->post['payment_aneepay_' . $field])) {
				$data['payment_aneepay_' . $field] = $this->request->post['payment_aneepay_' . $field];
			} else {
				$data['payment_aneepay_' . $field] = $this->config->get('payment_aneepay_' . $field);
			}
		}

		// Defaults for booleans/selects when not yet configured.
		if ('' === $data['payment_aneepay_token']) {
			$data['payment_aneepay_token'] = 'usdt';
		}

		if ('' === $data['payment_aneepay_network']) {
			$data['payment_aneepay_network'] = 'polygon';
		}

		if ('' === $data['payment_aneepay_rate_source']) {
			$data['payment_aneepay_rate_source'] = 'auto';
		}

		if ('' === $data['payment_aneepay_cron_interval']) {
			$data['payment_aneepay_cron_interval'] = 'every_five_minutes';
		}

		if ('' === $data['payment_aneepay_expiry_minutes']) {
			$data['payment_aneepay_expiry_minutes'] = '120';
		}

		if ('' === $data['payment_aneepay_fee_rule']) {
			$data['payment_aneepay_fee_rule'] = 'merchant';
		}

		if ('' === (string) $data['payment_aneepay_keep_data']) {
			$data['payment_aneepay_keep_data'] = '1';
		}

		if ('' == $data['payment_aneepay_pending_status_id']) {
			$data['payment_aneepay_pending_status_id'] = 1;
		}

		if ('' == $data['payment_aneepay_paid_status_id']) {
			$data['payment_aneepay_paid_status_id'] = 2;
		}

		if ('' == $data['payment_aneepay_failed_status_id']) {
			$data['payment_aneepay_failed_status_id'] = 10;
		}

		if ('' == $data['payment_aneepay_cancelled_status_id']) {
			$data['payment_aneepay_cancelled_status_id'] = 7;
		}

		$data['order_statuses'] = $this->model_localisation_order_status->getOrderStatuses();

		$data['token_options'] = array(
			'usdt' => 'USDT (Tether)',
			'usdc' => 'USDC (USD Coin)',
			'dai'  => 'DAI',
		);

		$data['network_options'] = array(
			'polygon' => 'Polygon (Mainnet)',
			'amoy'    => 'Amoy (Testnet)',
		);

		$data['rate_source_options'] = array(
			'auto'   => $this->language->get('text_auto'),
			'manual' => $this->language->get('text_manual'),
		);

		$data['rate_warning'] = $this->rate_warning(
			(string) $data['payment_aneepay_rate_source'],
			(string) $this->config->get('config_currency'),
			(string) $data['payment_aneepay_exchange_rate']
		);

		$data['cron_options'] = array(
			'every_five_minutes'    => 'Every 5 minutes',
			'every_ten_minutes'     => 'Every 10 minutes',
			'every_fifteen_minutes' => 'Every 15 minutes',
			'every_thirty_minutes'  => 'Every 30 minutes',
		);

		$data['fee_options'] = array(
			'merchant' => $this->language->get('text_fee_merchant'),
			'customer' => $this->language->get('text_fee_customer'),
		);

		// Helper blocks.
		$data['sandbox']          = $this->setting_bool('payment_aneepay_test_mode');
		$data['account_id']       = (string) $data['payment_aneepay_account_id'];
		$data['webhook_secret']   = (string) $data['payment_aneepay_webhook_secret'];
		$data['quick_setup']      = ('' === $data['account_id'] || '' === $data['webhook_secret']);

		$catalog_root = defined('HTTPS_CATALOG') ? HTTPS_CATALOG : HTTP_CATALOG;

		$data['status_url']     = $catalog_root . 'index.php?route=extension/payment/aneepay/callback';
		$data['success_url']    = $catalog_root . 'index.php?route=extension/payment/aneepay/success';
		$data['fail_url']       = $catalog_root . 'index.php?route=extension/payment/aneepay/fail';
		$data['cron_url']       = $catalog_root . 'index.php?route=extension/payment/aneepay/cron&secret=' . rawurlencode($cron_secret);

		$data['mode_label'] = $data['sandbox'] ? $this->language->get('text_sandbox') : $this->language->get('text_live');
		$data['mode_class'] = $data['sandbox'] ? 'label-warning' : 'label-success';

		$data['conversion_example'] = $this->conversion_example_html();

		$data['user_token'] = $this->session->data['user_token'];

		$data['breadcrumbs'] = array();
		$data['breadcrumbs'][] = array(
			'text' => $this->language->get('text_home'),
			'href' => $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token'], true),
		);
		$data['breadcrumbs'][] = array(
			'text' => $this->language->get('text_extension'),
			'href' => $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment', true),
		);
		$data['breadcrumbs'][] = array(
			'text' => $this->language->get('heading_title'),
			'href' => $this->url->link('extension/payment/aneepay', 'user_token=' . $this->session->data['user_token'], true),
		);

		$data['action']     = $this->url->link('extension/payment/aneepay', 'user_token=' . $this->session->data['user_token'], true);
		$data['cancel']     = $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment', true);
		$data['test_url']   = $this->url->link('extension/payment/aneepay/test_connection', 'user_token=' . $this->session->data['user_token'], true);
		$data['sync_url']   = $this->url->link('extension/payment/aneepay/sync_payment', 'user_token=' . $this->session->data['user_token'], true);
		$data['logs_url']   = $this->url->link('extension/payment/aneepay/get_logs', 'user_token=' . $this->session->data['user_token'], true);
		$data['clear_url']  = $this->url->link('extension/payment/aneepay/clear_logs', 'user_token=' . $this->session->data['user_token'], true);

		$data['header']     = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer']     = $this->load->controller('common/footer');

		$this->response->setOutput($this->load->view('extension/payment/aneepay', $data));
	}

	/**
	 * Validate the posted settings.
	 *
	 * @return bool
	 */
	protected function validate() {
		if (!$this->user->hasPermission('modify', 'extension/payment/aneepay')) {
			$this->error['warning'] = $this->language->get('error_permission');
		}

		$account_id = (string) ($this->request->post['payment_aneepay_account_id'] ?? '');

		if ('' === $account_id) {
			$this->error['account_id'] = $this->language->get('error_account_id');
		} elseif (!$this->is_uuid($account_id)) {
			$this->error['account_id'] = $this->language->get('error_account_id');
		}

		$posted_secret    = (string) ($this->request->post['payment_aneepay_webhook_secret'] ?? '');
		$effective_secret = '' !== $posted_secret ? $posted_secret : (string) $this->config->get('payment_aneepay_webhook_secret');

		if ('' === $effective_secret) {
			$this->error['webhook_secret'] = $this->language->get('error_webhook_secret');
		} else {
			// Persist the effective secret: editSetting() saves the whole POST,
			// so an empty password field would otherwise wipe the stored value.
			$this->request->post['payment_aneepay_webhook_secret'] = $effective_secret;
		}

		$rate = (string) ($this->request->post['payment_aneepay_exchange_rate'] ?? '');

		if ('' !== $rate && !((float) $rate > 0)) {
			$this->error['exchange_rate'] = $this->language->get('error_exchange_rate');
		}

		// The Origin header identifies the merchant at AneePay: keep it a bare
		// host, never a URL, and never derive it from the current request.
		$site_domain = $this->normalize_site_domain((string) ($this->request->post['payment_aneepay_site_domain'] ?? ''));

		if ('' !== $site_domain && !$this->is_valid_site_domain($site_domain)) {
			$this->error['site_domain'] = $this->language->get('error_site_domain');
		}

		$this->request->post['payment_aneepay_site_domain'] = $site_domain;

		// Every configured status must be a real, distinct order status: a
		// status of 0 records the payment mapping but adds no order history
		// entry, so the merchant never learns that a payment failed.
		$seen_statuses = array();

		foreach (array('pending_status_id', 'paid_status_id', 'failed_status_id', 'cancelled_status_id') as $field) {
			$status_id = (int) ($this->request->post['payment_aneepay_' . $field] ?? 0);

			if ($status_id <= 0) {
				$this->error[$field] = $this->language->get('error_' . $field);
			} elseif (in_array($status_id, $seen_statuses, true)) {
				$this->error[$field] = $this->language->get('error_status_duplicate');
			}

			if ($status_id > 0) {
				$seen_statuses[] = $status_id;
			}

			$this->request->post['payment_aneepay_' . $field] = $status_id;
		}

		if ($this->error) {
			$this->error['warning'] = $this->language->get('error_warning');
			return false;
		}

		return true;
	}

	/**
	 * Whether a string is a valid versionless UUID.
	 *
	 * @param string $id Value.
	 * @return bool
	 */
	protected function is_uuid($id) {
		return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', trim((string) $id));
	}

	/**
	 * Warning shown next to the rate settings when the store currency has no
	 * ECB reference rate.
	 *
	 * The automatic source only covers the currencies the ECB publishes; for
	 * anything else the plugin deliberately skips the request and uses the
	 * store rate, so the merchant should know which rate is in play.
	 *
	 * @param string $rate_source   Configured rate source (auto|manual).
	 * @param string $currency_code Store currency code.
	 * @param string $manual_rate   Configured manual rate.
	 * @return string Empty when there is nothing to warn about.
	 */
	protected function rate_warning($rate_source, $currency_code, $manual_rate) {
		$currency_code = strtoupper(trim((string) $currency_code));

		if (!class_exists('AneePay_Usd_Converter')) {
			require_once DIR_SYSTEM . 'library/aneepay/usd-converter.php';
		}

		if ('manual' === $rate_source || AneePay_Usd_Converter::is_ecb_currency($currency_code)) {
			return '';
		}

		$key = (float) $manual_rate > 0 ? 'help_rate_fallback_manual' : 'help_rate_fallback_store';

		return sprintf((string) $this->language->get($key), $currency_code);
	}

	/**
	 * Reduce a configured Origin domain to a bare host.
	 *
	 * Accepts `shop.example.com`, `https://shop.example.com/` and
	 * `shop.example.com:8443`; the API expects a host, not a URL.
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
	 * Whether the value looks like a hostname or an IP address.
	 *
	 * @param string $host Normalised host.
	 * @return bool
	 */
	protected function is_valid_site_domain($host) {
		if (false !== filter_var($host, FILTER_VALIDATE_IP)) {
			return true;
		}

		return (bool) preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$/', $host);
	}

	/**
	 * Load the AneePay library classes.
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
	}

	/**
	 * Is a stored setting truthy?
	 *
	 * @param string $key Setting key.
	 * @return bool
	 */
	protected function setting_bool($key) {
		return in_array($this->config->get($key), array('1', 'on', 'yes', true), true);
	}

	/**
	 * Build the API client from stored settings for admin AJAX.
	 *
	 * @return AneePay_Client
	 */
	protected function admin_client() {
		$this->load_lib();

		$host = (string) parse_url(HTTP_CATALOG, PHP_URL_HOST);

		$settings = array(
			'account_id'     => (string) $this->config->get('payment_aneepay_account_id'),
			'webhook_secret' => (string) $this->config->get('payment_aneepay_webhook_secret'),
			'site_domain'    => (string) $this->config->get('payment_aneepay_site_domain'),
			'host'           => $host,
			'token'          => (string) $this->config->get('payment_aneepay_token', 'usdt'),
			'network'        => (string) $this->config->get('payment_aneepay_network', 'polygon'),
			'test_mode'      => $this->setting_bool('payment_aneepay_test_mode'),
			'rate_source'    => (string) $this->config->get('payment_aneepay_rate_source', 'auto'),
			'exchange_rate'  => (string) $this->config->get('payment_aneepay_exchange_rate', ''),
		);

		return new AneePay_Client($settings, $this->db);
	}

	/**
	 * AJAX: test the connection.
	 */
	public function test_connection() {
		$this->load->language('extension/payment/aneepay');

		$this->response->addHeader('Content-Type: application/json');

		if (!$this->authorised()) {
			$this->response->setOutput(json_encode(array('ok' => false, 'message' => $this->language->get('error_permission'))));
			return;
		}

		$result = $this->admin_client()->test_connection();

		$this->response->setOutput(json_encode($result));
	}

	/**
	 * AJAX: fetch one payment from the API and apply its status now.
	 *
	 * Webhooks only ever deliver `success` and there is no replay endpoint, so
	 * this is the manual path for "the buyer paid, the order is still pending".
	 */
	public function sync_payment() {
		$this->load->language('extension/payment/aneepay');
		$this->load->model('extension/payment/aneepay');
		$this->load_lib();

		$this->response->addHeader('Content-Type: application/json');

		if (!$this->authorised()) {
			$this->response->setOutput(json_encode(array('ok' => false, 'message' => $this->language->get('error_permission'))));
			return;
		}

		$payment_id = isset($this->request->post['payment_id']) ? trim((string) $this->request->post['payment_id']) : '';
		$order_id   = isset($this->request->post['order_id']) ? (int) $this->request->post['order_id'] : 0;

		if ('' === $payment_id) {
			$this->response->setOutput(json_encode(array('ok' => false, 'message' => $this->language->get('error_sync_payment_id'))));
			return;
		}

		$client = $this->admin_client();
		$data   = $client->get_payment($payment_id);

		if (!is_array($data) || empty($data['status'])) {
			$this->response->setOutput(json_encode(array(
				'ok'      => false,
				'message' => sprintf($this->language->get('error_sync_not_found'), $payment_id, $client->last_http_code()),
			)));
			return;
		}

		if ($order_id <= 0) {
			$mapping = $this->model_extension_payment_aneepay->getByPaymentId($payment_id);

			if ($mapping) {
				$order_id = (int) $mapping['order_id'];
			}
		}

		if ($order_id <= 0) {
			$this->response->setOutput(json_encode(array(
				'ok'      => false,
				'message' => sprintf($this->language->get('error_sync_no_order'), $payment_id),
			)));
			return;
		}

		$status_map = array(
			'pending'   => (int) $this->config->get('payment_aneepay_pending_status_id'),
			'success'   => (int) $this->config->get('payment_aneepay_paid_status_id'),
			'failed'    => (int) $this->config->get('payment_aneepay_failed_status_id'),
			'cancelled' => (int) $this->config->get('payment_aneepay_cancelled_status_id'),
		);

		$webhook = new AneePay_Webhook($this->registry, $client, $status_map);
		$webhook->apply($order_id, (string) $data['status']);

		$this->response->setOutput(json_encode(array(
			'ok'      => true,
			'status'  => (string) $data['status'],
			'message' => sprintf($this->language->get('text_sync_ok'), $order_id, (string) $data['status']),
		)));
	}

	/**
	 * AJAX: fetch the request log.
	 */
	public function get_logs() {
		$this->load->language('extension/payment/aneepay');
		$this->load->model('extension/payment/aneepay');

		$this->response->addHeader('Content-Type: application/json');

		if (!$this->authorised()) {
			$this->response->setOutput(json_encode(array('ok' => false, 'logs' => array())));
			return;
		}

		$this->response->setOutput(json_encode(array('ok' => true, 'logs' => $this->model_extension_payment_aneepay->getLogs())));
	}

	/**
	 * AJAX: clear the request log.
	 */
	public function clear_logs() {
		$this->load->model('extension/payment/aneepay');

		$this->response->addHeader('Content-Type: application/json');

		if (!$this->authorised()) {
			$this->response->setOutput(json_encode(array('ok' => false)));
			return;
		}

		$this->model_extension_payment_aneepay->clearLogs();

		$this->response->setOutput(json_encode(array('ok' => true)));
	}

	/**
	 * Install: create tables.
	 */
	public function install() {
		$this->load->model('extension/payment/aneepay');

		$this->model_extension_payment_aneepay->install();
	}

	/**
	 * Uninstall: drop tables.
	 */
	public function uninstall() {
		$this->load->model('extension/payment/aneepay');

		$this->model_extension_payment_aneepay->uninstall();
	}

	/**
	 * Build the conversion example block (no network call).
	 *
	 * @return string
	 */
	protected function conversion_example_html() {
		$this->load_lib();

		$client = $this->admin_client();
		$converter = new AneePay_Usd_Converter($client);

		$sample   = 150.0;
		$currency = (string) $this->config->get('config_currency');
		$currency = $currency ? $currency : 'USD';
		$token    = strtoupper($client->get_token());

		try {
			$converted = $converter->convert($sample, $currency);
		} catch (Exception $e) {
			return 'No rate is available yet. Set a manual exchange rate or save with auto rate to see a preview.';
		}

		$source_label = ('manual' === $converted['source']) ? 'shop rate' : 'ECB / Frankfurter';

		return sprintf(
			'Order: %01.2f %s &rarr; %01.2f USD (%s) &rarr; %01.2f %s',
			$sample,
			$currency,
			(float) $converted['usd_amount'],
			$source_label,
			(float) $converted['token_amount'],
			$token
		);
	}
}
