<?php
declare(strict_types=1);

namespace AneePay\Tests\Unit\WooCommerce;

use AneePay\Tests\Support\UsdConverterContractTestCase;

final class UsdConverterTest extends UsdConverterContractTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        aneepay_test_reset_transients();
        aneepay_test_set_currency('EUR');
    }

    protected function makeConverter(array $settings)
    {
        aneepay_test_set_currency((string) ($settings['store_currency'] ?? 'EUR'));

        $gateway = new FakeGateway(
            [
                'rate_source' => $settings['rate_source'] ?? 'auto',
                'exchange_rate' => $settings['exchange_rate'] ?? 0,
            ]
        );

        return new UsdConverterDouble($gateway, $settings['auto_rate'] ?? null);
    }

    protected function isEcbCurrency(string $currency): bool
    {
        return \AneePay_USD_Converter::is_ecb_currency($currency);
    }

    protected function recordedWarnings($converter): array
    {
        return $converter->warned;
    }

    protected function supportsStoreRate(): bool
    {
        return false;
    }

    public function testConvertDefaultsToTheStoreCurrency(): void
    {
        $converter = $this->makeConverter(['auto_rate' => 0.8, 'store_currency' => 'GBP']);

        $result = $converter->convert(8.0);

        self::assertSame('GBP', $result['currency']);
        self::assertEqualsWithDelta(10.0, $result['token_amount'], 1e-9);
    }

    public function testTheAutoRateIsNotFetchedForNonEcbCurrencies(): void
    {
        $converter = $this->makeConverter(['auto_rate' => 90.0, 'exchange_rate' => 80.0]);

        $converter->convert(80.0, 'RUB');

        self::assertSame(0, $converter->autoCalls);
    }

    public function testPeekReturnsTheCachedRate(): void
    {
        aneepay_test_set_transient('aneepay_fiat_rate_eur', ['rate' => 0.9, 'time' => time()]);
        $converter = $this->makeConverter(['auto_rate' => null, 'exchange_rate' => 5.0]);

        $source = 'unset';
        $rate = $converter->peek_fiat_per_usd('eur', $source);

        self::assertSame(0.9, $rate);
        self::assertSame('auto', $source);
    }

    public function testPeekFallsBackToTheManualRateWithoutACache(): void
    {
        $converter = $this->makeConverter(['auto_rate' => null, 'exchange_rate' => 0.8]);

        $source = 'unset';
        $rate = $converter->peek_fiat_per_usd('EUR', $source);

        self::assertSame(0.8, $rate);
        self::assertSame('manual', $source);
    }

    public function testPeekIgnoresTheCacheInManualMode(): void
    {
        aneepay_test_set_transient('aneepay_fiat_rate_eur', ['rate' => 0.9, 'time' => time()]);
        $converter = $this->makeConverter([
            'rate_source' => 'manual',
            'auto_rate' => null,
            'exchange_rate' => 0.8,
        ]);

        $source = 'unset';
        $rate = $converter->peek_fiat_per_usd('EUR', $source);

        self::assertSame(0.8, $rate);
        self::assertSame('manual', $source);
    }

    public function testPeekIgnoresTheCacheForNonEcbCurrencies(): void
    {
        aneepay_test_set_transient('aneepay_fiat_rate_rub', ['rate' => 90.0, 'time' => time()]);
        $converter = $this->makeConverter(['auto_rate' => null, 'exchange_rate' => 80.0]);

        $source = 'unset';
        $rate = $converter->peek_fiat_per_usd('RUB', $source);

        self::assertSame(80.0, $rate);
        self::assertSame('manual', $source);
    }

    public function testPeekRejectsANonPositiveCachedRate(): void
    {
        aneepay_test_set_transient('aneepay_fiat_rate_eur', ['rate' => 0, 'time' => time()]);
        $converter = $this->makeConverter(['auto_rate' => null, 'exchange_rate' => 0.8]);

        $source = 'unset';
        $rate = $converter->peek_fiat_per_usd('EUR', $source);

        self::assertSame(0.8, $rate);
        self::assertSame('manual', $source);
    }

    public function testPeekIsOneForUsd(): void
    {
        $converter = $this->makeConverter(['exchange_rate' => 5.0]);

        $source = 'unset';
        $rate = $converter->peek_fiat_per_usd('USD', $source);

        self::assertSame(1.0, $rate);
        self::assertSame('auto', $source);
    }
}

/**
 * Gateway settings double: `get_option()` is the only method the converter uses.
 */
final class FakeGateway
{
    /**
     * @var array<string, mixed>
     */
    private $options;

    /**
     * @param array<string, mixed> $options Settings.
     */
    public function __construct(array $options)
    {
        $this->options = $options;
    }

    /**
     * @param string $key     Option name.
     * @param mixed  $default Default value.
     * @return mixed
     */
    public function get_option($key, $default = null)
    {
        return array_key_exists($key, $this->options) ? $this->options[$key] : $default;
    }
}

/**
 * Converter with the network call replaced by a fixed rate.
 */
final class UsdConverterDouble extends \AneePay_USD_Converter
{
    /**
     * @var float|null
     */
    public $autoRate;

    /**
     * @var int
     */
    public $autoCalls = 0;

    /**
     * @var array<int, array{0:string, 1:string, 2:float}>
     */
    public $warned = [];

    /**
     * @param FakeGateway $gateway  Gateway double.
     * @param float|null  $autoRate Value the automatic source resolves to.
     */
    public function __construct($gateway, $autoRate)
    {
        parent::__construct($gateway);

        $this->autoRate = $autoRate;
    }

    protected function fetch_auto_rate($currency)
    {
        ++$this->autoCalls;

        return $this->autoRate;
    }

    protected function warn_fallback($currency, $source, $rate)
    {
        $this->warned[] = [(string) $currency, (string) $source, (float) $rate];
    }
}
