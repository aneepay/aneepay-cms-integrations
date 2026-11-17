<?php
/**
 * AneePay fiat -> USD -> token converter for PrestaShop.
 *
 * Stablecoins are pegged to USD, so charging a token amount is equivalent to
 * charging a USD amount. Converts the store total (in the store currency) into
 * the token amount requested from the AneePay API.
 *
 * Rate priority: Frankfurter/ECB (cached) -> store Currency -> manual rate.
 * If none are available an exception is thrown — the amount is never guessed.
 *
 * Currencies without an ECB reference rate (RUB, for example) skip the network
 * call on purpose and use the store rate; the settings page warns the merchant.
 */
class AneePayUsdConverter {
	/**
	 * Currencies with an ECB reference rate (the set served by Frankfurter).
	 *
	 * Update the list if the ECB changes what it publishes.
	 */
	const ECB_CURRENCIES = array(
		'AUD', 'BGN', 'BRL', 'CAD', 'CHF', 'CNY', 'CZK', 'DKK', 'EUR', 'GBP',
		'HKD', 'HUF', 'IDR', 'ILS', 'INR', 'ISK', 'JPY', 'KRW', 'MXN', 'MYR',
		'NOK', 'NZD', 'PHP', 'PLN', 'RON', 'SEK', 'SGD', 'THB', 'TRY', 'USD',
		'ZAR',
	);

	/**
	 * Whether the ECB publishes a reference rate for the currency.
	 *
	 * @param string $currency Currency code.
	 * @return bool
	 */
	public static function isEcbCurrency($currency) {
		return in_array(strtoupper(trim((string) $currency)), self::ECB_CURRENCIES, true);
	}

	/** @var AneePayApi */
	protected $client;

	/**
	 * @param AneePayApi $client API client (holds rate_source/exchange_rate).
	 */
	public function __construct($client) {
		$this->client = $client;
	}

	public function is_auto_enabled() {
		return 'manual' !== (string) $this->client->get('rate_source', 'auto');
	}

	protected function get_manual_rate() {
		$rate = (float) $this->client->get('exchange_rate', 0);

		return $rate > 0 ? $rate : null;
	}

	/**
	 * Resolve store-currency units per 1 USD.
	 *
	 * @param string $currency Currency code.
	 * @param string $source   (out) auto|store|manual.
	 * @return float|null
	 */
	public function get_fiat_per_usd($currency, &$source) {
		$currency = strtoupper(trim((string) $currency));

		if ('USD' === $currency) {
			$source = 'auto';
			return 1.0;
		}

		if ($this->is_auto_enabled() && self::isEcbCurrency($currency)) {
			$rate = $this->fetch_auto_rate($currency);

			if (null !== $rate) {
				$source = 'auto';
				return $rate;
			}
		}

		$store = (float) $this->client->get('store_rate', 0);

		if ($store > 0) {
			$source = 'store';
			return $store;
		}

		$manual = $this->get_manual_rate();

		if (null !== $manual && $manual > 0) {
			$source = 'manual';
			return $manual;
		}

		return null;
	}

	/**
	 * Resolve a rate WITHOUT making a network request (used for the admin
	 * preview). Checks the cached automatic rate, then the store rate, then
	 * the manual fallback.
	 *
	 * @param string $currency Currency code.
	 * @param string $source   (out) auto|store|manual.
	 * @return float|null
	 */
	public function peek_fiat_per_usd($currency, &$source) {
		$currency = strtoupper(trim((string) $currency));

		if ('USD' === $currency) {
			$source = 'auto';
			return 1.0;
		}

		if ($this->is_auto_enabled() && self::isEcbCurrency($currency)) {
			$rate = $this->read_cached_rate($currency);

			if (null !== $rate) {
				$source = 'auto';
				return $rate;
			}
		}

		$store = (float) $this->client->get('store_rate', 0);

		if ($store > 0) {
			$source = 'store';
			return $store;
		}

		$manual = $this->get_manual_rate();

		if (null !== $manual && $manual > 0) {
			$source = 'manual';
			return $manual;
		}

		return null;
	}

	/**
	 * Convert a store-total into the token amount.
	 *
	 * @param float|string $store_total Order total in the store currency.
	 * @param string       $currency    Currency code.
	 * @return array{token_amount:float, usd_amount:float, fiat_per_usd:float, currency:string, source:string}
	 * @throws Exception When no rate can be resolved.
	 */
	public function convert($store_total, $currency) {
		$store_total = (float) $store_total;

		if ($store_total <= 0) {
			throw new Exception('Order total must be greater than zero.');
		}

		$source = 'auto';
		$rate   = $this->get_fiat_per_usd($currency, $source);

		if (null === $rate || $rate <= 0) {
			throw new Exception('Unable to resolve a USD exchange rate for ' . $currency . '. Set a manual rate in the AneePay settings.');
		}

		if ('auto' !== $source && $this->is_auto_enabled() && !self::isEcbCurrency($currency)) {
			$this->warnFallback($currency, $source, $rate);
		}

		$usd_amount  = $store_total / $rate;
		$token_amount = ceil($usd_amount * 100) / 100;

		return array(
			'token_amount' => $token_amount,
			'usd_amount'   => $usd_amount,
			'fiat_per_usd' => $rate,
			'currency'     => $currency,
			'source'       => $source,
		);
	}

	/**
	 * Log that the automatic source is unusable for this currency, once an hour.
	 *
	 * The settings page carries the standing warning; this is traceability for
	 * the orders that were converted with a fallback rate.
	 *
	 * @param string $currency Currency code.
	 * @param string $source   Resolved source: store|manual.
	 * @param float  $rate     Resolved units of store currency per 1 USD.
	 * @return void
	 */
	protected function warnFallback($currency, $source, $rate) {
		$marker = _PS_CACHE_DIR_ . 'aneepay_ratewarn_' . strtolower((string) $currency) . '.json';

		if (is_file($marker) && (time() - (int) filemtime($marker)) < 3600) {
			return;
		}

		@file_put_contents($marker, json_encode(array('time' => time())));

		if (method_exists($this->client, 'write_log')) {
			$this->client->write_log(
				'rate',
				sprintf(
					'%s has no ECB reference rate; using the %s rate (1 USD = %s %s).',
					$currency,
					$source,
					$rate,
					$currency
				),
				'warning'
			);
		}
	}

	/**
	 * Fetch and cache the Frankfurter/ECB rate (PrestaShop file cache).
	 *
	 * @param string $currency Currency code.
	 * @return float|null
	 */
	protected function fetch_auto_rate($currency) {
		$cached = $this->read_cached_rate($currency);

		if (null !== $cached) {
			return $cached;
		}

		$url = 'https://api.frankfurter.app/latest?from=USD&to=' . rawurlencode($currency);

		$ch = curl_init();

		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
		curl_setopt($ch, CURLOPT_TIMEOUT, 10);

		$body = curl_exec($ch);
		$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

		curl_close($ch);

		if (200 !== $code) {
			return null;
		}

		$data = json_decode((string) $body, true);
		$rate = isset($data['rates'][$currency]) ? (float) $data['rates'][$currency] : 0.0;

		if ($rate <= 0) {
			return null;
		}

		file_put_contents($this->cache_file($currency), json_encode(array('rate' => $rate, 'time' => time())));

		return $rate;
	}

	/**
	 * Read the cached Frankfurter/ECB rate without touching the network.
	 *
	 * @param string $currency Currency code.
	 * @return float|null
	 */
	protected function read_cached_rate($currency) {
		$cache_file = $this->cache_file($currency);

		if (!is_file($cache_file)) {
			return null;
		}

		$cached = json_decode((string) file_get_contents($cache_file), true);

		if (is_array($cached)
			&& isset($cached['rate'], $cached['time'])
			&& (float) $cached['rate'] > 0
			&& (time() - (int) $cached['time']) < 3600
		) {
			return (float) $cached['rate'];
		}

		return null;
	}

	/**
	 * Cache file path for a currency rate.
	 *
	 * @param string $currency Currency code.
	 * @return string
	 */
	protected function cache_file($currency) {
		return _PS_CACHE_DIR_ . 'aneepay_rate_' . strtolower((string) $currency) . '.json';
	}
}
