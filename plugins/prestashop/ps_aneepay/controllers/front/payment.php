<?php
/**
 * Payment entry point: validates the cart into an order, creates the AneePay
 * payment and shows the pending screen with the hosted-checkout link.
 */
class Ps_AneepayPaymentModuleFrontController extends ModuleFrontController {
	public $ssl = true;

	public function initContent() {
		parent::initContent();

		$cart     = $this->context->cart;
		$customer = $this->context->customer;

		$home = $this->context->link->getPageLink('index');

		if (!$this->module->active || !$cart->id || $cart->id_customer != (int) $customer->id) {
			Tools::redirect($home);
		}

		$order_model = new AneePayOrder();

		// Idempotency gate: reuse an existing pending hosted payment for this cart.
		$existing = $order_model->getByCart((int) $cart->id);
		if ($existing && !empty($existing['checkout_url']) && in_array((string) $existing['payment_status'], array('', 'pending'), true)) {
			$this->context->cookie->__set('aneepay_order_id', $this->module->orderToken((int) $existing['id_order']), time() + 3600);

			$this->renderPending($existing);

			return;
		}

		// Idempotency gate 2: the cookie may point at a pending order started
		// before the cart was consumed (e.g. retry from the fail page). Reuse
		// its hosted payment instead of creating a duplicate order + payment.
		$cookie_order = $this->module->resultOrderId();

		if ($cookie_order > 0) {
			$by_cookie = $order_model->getByOrder($cookie_order);

			if ($by_cookie && !empty($by_cookie['checkout_url']) && in_array((string) $by_cookie['payment_status'], array('', 'pending'), true)) {
				$this->renderPending($by_cookie);

				return;
			}
		}

		$total    = (float) $cart->getOrderTotal(true, Cart::BOTH);
		$currency = new Currency((int) $cart->id_currency);

		if ($total <= 0) {
			Tools::redirect($home);
		}

		// From here on the controller creates an order and a payment: that must
		// only happen for a request started on this shop's checkout page.
		$api = $this->module->getApi();

		if ($this->module->paymentAttemptsExceeded()) {
			$api->write_log('payment', 'Payment entry rate limit hit for cart #' . (int) $cart->id, 'warning');
			$this->redirectWithNotice($home, 'error', $this->module->l('Too many payment attempts. Please wait a few minutes and try again.'));

			return;
		}

		if (!$this->module->consumePaymentToken(Tools::getValue('token', ''), (int) $cart->id)) {
			$api->write_log('payment', 'Rejected payment entry without a valid token (cart #' . (int) $cart->id . ')', 'warning');
			$this->denyRequest();

			return;
		}

		$this->module->recordPaymentAttempt();

		// Create the order FIRST so the payment references the real order id
		// and a failed validateOrder() cannot leave an orphan payment behind.
		$pendingState = (int) Configuration::get('ANEEPAY_PENDING_STATE');

		$this->module->validateOrder(
			(int) $cart->id,
			$pendingState,
			$total,
			$this->module->displayName,
			null,
			array(),
			null,
			false,
			$cart->secure_key
		);

		$id_order = (int) $this->module->currentOrder;

		if ($id_order <= 0) {
			$this->redirectWithNotice($home, 'error', $this->module->l('Unable to create the order. Please try again.'));

			return;
		}

		// One payment at a time per order: a request that got this far anyway
		// (duplicate tab, retry with a fresh token) must not create a second
		// AneePay payment for the same order.
		if (!$order_model->claimOrder($id_order)) {
			$api->write_log('payment', 'A payment for order #' . $id_order . ' is already being created', 'warning');

			$this->redirectWithNotice($home, 'error', $this->module->l('A payment for this order is already being created. Please wait a moment and refresh the page.'));

			return;
		}

		$api->set_config('store_rate', $this->module->getStoreRate((string) $currency->iso_code));

		$converted = null;

		try {
			$converter = new AneePayUsdConverter($api);
			$converted = $converter->convert($total, (string) $currency->iso_code);
			$created   = $api->create_payment($converted['token_amount'], 'Order #' . $id_order);
		} catch (Throwable $e) {
			$kind = $e instanceof AneePayApiException ? $e->getKind() : AneePayApiException::KIND_REQUEST;

			$api->write_log('payment', 'Payment creation failed for order #' . $id_order . ': ' . $e->getMessage(), 'error');

			// The request may have reached AneePay before the connection died:
			// adopt the payment it created instead of leaving an orphan behind.
			if (is_array($converted) && in_array($kind, array(AneePayApiException::KIND_NETWORK, AneePayApiException::KIND_INVALID), true)) {
				$found = $this->findCreatedPayment($api, 'Order #' . $id_order, (string) $converted['token_amount']);

				if (null !== $found) {
					$mapping = $this->storeMapping($order_model, $id_order, $cart, $api, $converted, $found, $total, $currency);

					$this->context->cookie->__set('aneepay_order_id', $this->module->orderToken($id_order), time() + 3600);

					$this->renderPending(array_merge(array('id_order' => $id_order), $mapping));

					return;
				}

				$api->write_log('payment', 'Unknown outcome for order #' . $id_order . ' and no matching payment in the account list; the shopper may retry.', 'warning');
			}

			$order_model->releaseClaim($id_order);

			// Compensate: the order exists but no payment does — cancel it and
			// release the stock validateOrder() just reserved.
			$cancelledState = (int) Configuration::get('ANEEPAY_CANCELLED_STATE');
			$cancel_order   = new Order($id_order);
			$previous_state = Validate::isLoadedObject($cancel_order) ? (int) $cancel_order->getCurrentState() : 0;

			if ($cancelledState > 0 && Validate::isLoadedObject($cancel_order)) {
				$history           = new OrderHistory();
				$history->id_order = $id_order;

				$history->changeIdOrderState($cancelledState, $cancel_order, true);
				$history->add();
			}

			AneePayWebhook::restoreOrderStockIfNeeded($id_order, $cancelledState, $previous_state);
			$order_model->markStockRestored($id_order);

			// The shopper gets one short translated sentence; the technical
			// reason stays in the request log.
			$this->redirectWithNotice($home, 'error', $this->module->paymentErrorMessage($kind));

			return;
		}

		$mapping = $this->storeMapping($order_model, $id_order, $cart, $api, $converted, $created, $total, $currency);

		$this->context->cookie->__set('aneepay_order_id', $this->module->orderToken($id_order), time() + 3600);

		$this->renderPending(array_merge(array('id_order' => $id_order), $mapping));
	}

	/**
	 * Persist the payment mapping for an order.
	 *
	 * @param AneePayOrder $order_model
	 * @param int          $id_order
	 * @param Cart         $cart
	 * @param AneePayApi   $api
	 * @param array        $converted fiat -> USD -> token breakdown
	 * @param array        $created   payment_id / operation_id / checkout_url
	 * @param float        $total     order total in the store currency
	 * @param Currency     $currency
	 *
	 * @return array The stored mapping, ready for renderPending()
	 */
	protected function storeMapping($order_model, $id_order, $cart, $api, $converted, $created, $total, $currency) {
		$mapping = array(
			'id_cart'        => (int) $cart->id,
			'payment_id'     => $created['payment_id'],
			'operation_id'   => $created['operation_id'],
			'checkout_url'   => $created['checkout_url'],
			'token'          => $api->get_token(),
			'network'        => $api->get_network(),
			'sandbox'        => $api->is_sandbox() ? 1 : 0,
			'payment_status' => 'pending',
			'token_amount'   => number_format((float) $converted['token_amount'], 2, '.', ''),
			'order_currency' => (string) $currency->iso_code,
			'order_total'    => (string) $total,
			'usd_amount'     => number_format((float) $converted['usd_amount'], 2, '.', ''),
			'fiat_per_usd'   => (string) $converted['fiat_per_usd'],
			'rate_source'    => (string) $converted['source'],
		);

		$order_model->saveMapping($id_order, $mapping);

		return $mapping;
	}

	/**
	 * Find a payment that was created before the response was lost.
	 *
	 * The API has no Idempotency-Key, so a transport error or an unreadable
	 * response leaves us not knowing whether POST /payments/ went through.
	 * Looking the payment up in the account list (same amount, same order
	 * reference, created in the last hour) is the only safe way to continue;
	 * a blind retry would create a second payment.
	 *
	 * @param AneePayApi $api
	 * @param string     $reference   e.g. "Order #42"
	 * @param string     $token_amount
	 *
	 * @return array|null payment_id / operation_id / checkout_url
	 */
	protected function findCreatedPayment($api, $reference, $token_amount) {
		$payments = $api->list_payments(gmdate('Y-m-d\TH:i:s\Z', time() - 3600));

		if (!is_array($payments)) {
			return null;
		}

		$expected = round((float) $token_amount, 2);

		foreach ($payments as $row) {
			if (!is_array($row) || empty($row['id']) || empty($row['operation_id']) || empty($row['checkout_url'])) {
				continue;
			}

			if (!isset($row['amount']) || round((float) $row['amount'], 2) !== $expected) {
				continue;
			}

			if (false === strpos((string) (isset($row['description']) ? $row['description'] : ''), $reference)) {
				continue;
			}

			$api->write_log('payment', 'Adopted AneePay payment ' . $row['id'] . ' for ' . $reference . ' after an unknown create_payment() outcome.', 'warning');

			return array(
				'payment_id'   => (string) $row['id'],
				'operation_id' => (string) $row['operation_id'],
				'checkout_url' => (string) $row['checkout_url'],
			);
		}

		return null;
	}

	protected function renderPending($row) {
		$this->context->smarty->assign(array(
			'order_id'     => (int) $row['id_order'],
			'payment_id'   => (string) $row['payment_id'],
			'token'        => strtoupper((string) $row['token']),
			'token_amount' => (string) $row['token_amount'],
			'pay_now'      => trim((string) $row['token_amount']) . ' ' . strtoupper((string) $row['token']),
			'checkout_url' => (string) $row['checkout_url'],
			'network'      => (string) $row['network'],
			'sandbox'      => (bool) $row['sandbox'],
			'check_url'    => $this->module->moduleLink('check'),
			'success_url'  => $this->module->moduleLink('success'),
			'fail_url'     => $this->module->moduleLink('fail'),
			'shop_url'     => $this->context->link->getPageLink('index'),
		));

		$this->setTemplate('module:ps_aneepay/views/templates/front/payment_pending.tpl');
	}

	protected function redirectWithNotice($url, $type, $message) {
		$this->context->cookie->__set('aneepay_error', $message, time() + 600);

		Tools::redirect($url);
	}

	/**
	 * Refuse a payment request that did not start on this shop's checkout.
	 *
	 * Answers 403 and renders a friendly page instead of an order: the buyer
	 * only gets here with a stale tab or a bookmarked/expired link.
	 *
	 * @return void
	 */
	protected function denyRequest() {
		if (!headers_sent()) {
			header('HTTP/1.1 403 Forbidden');
		}

		$this->context->smarty->assign(array(
			'error_title'   => $this->module->l('Payment session expired'),
			'error_message' => $this->module->l('This payment page is no longer valid. Please return to your cart and start the payment again — nothing has been charged.'),
			'cart_url'      => $this->context->link->getPageLink('order', true),
			'shop_url'      => $this->context->link->getPageLink('index'),
		));

		$this->setTemplate('module:ps_aneepay/views/templates/front/error.tpl');
	}
}
