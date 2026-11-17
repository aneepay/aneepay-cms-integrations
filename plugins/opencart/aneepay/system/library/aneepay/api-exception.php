<?php
/**
 * Structured failure raised by the AneePay API client.
 *
 * The message is the technical reason (logged, never shown to a shopper);
 * `get_kind()` tells the storefront which customer-facing sentence to
 * render from the language file.
 */
class AneePay_Api_Exception extends Exception {
	const KIND_CONFIG     = 'config';
	const KIND_NETWORK    = 'network';
	const KIND_AUTH       = 'auth';
	const KIND_RATE_LIMIT = 'rate_limit';
	const KIND_REQUEST    = 'request';
	const KIND_SERVER     = 'server';
	const KIND_INVALID    = 'invalid';

	/**
	 * @var string
	 */
	protected $kind;

	/**
	 * @var int
	 */
	protected $http_code;

	/**
	 * @param string          $message   Technical reason.
	 * @param string          $kind      One of the KIND_* constants.
	 * @param int             $http_code HTTP status, 0 when there was no response.
	 * @param Throwable|null  $previous  Previous exception.
	 */
	public function __construct($message, $kind = self::KIND_REQUEST, $http_code = 0, $previous = null) {
		parent::__construct((string) $message, 0, $previous);

		$this->kind      = (string) $kind;
		$this->http_code = (int) $http_code;
	}

	/**
	 * @return string
	 */
	public function get_kind() {
		return $this->kind;
	}

	/**
	 * @return int
	 */
	public function get_http_code() {
		return $this->http_code;
	}

	/**
	 * Map an HTTP status to a failure kind.
	 *
	 * @param int $code HTTP status code.
	 * @return string
	 */
	public static function kind_from_http($code) {
		$code = (int) $code;

		if (401 === $code || 403 === $code) {
			return self::KIND_AUTH;
		}

		if (429 === $code) {
			return self::KIND_RATE_LIMIT;
		}

		if ($code >= 500 || $code <= 0) {
			return self::KIND_SERVER;
		}

		if ($code >= 400) {
			return self::KIND_REQUEST;
		}

		return self::KIND_SERVER;
	}
}
