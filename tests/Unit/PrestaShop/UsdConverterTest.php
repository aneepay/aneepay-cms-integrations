<?php
declare(strict_types=1);

namespace AneePay\Tests\Unit\PrestaShop;

use AneePay\Tests\Support\UsdConverterContractTestCase;

final class UsdConverterTest extends UsdConverterContractTestCase
{
    protected function makeConverter(array $settings)
    {
        $client = new FakeClient(
            [
                'rate_source' => $settings['rate_source'] ?? 'auto',
                'exchange_rate' => $settings['exchange_rate'] ?? 0,
                'store_rate' => $settings['store_rate'] ?? 0,
            ]
        );

        return new UsdConverterDouble($client, $settings['auto_rate'] ?? null, $settings['cached_rate'] ?? null);
    }

    protected function isEcbCurrency(string $currency): bool
    {
        return \AneePayUsdConverter::isEcbCurrency($currency);
    }

    protected function recordedWarnings($converter): array
    {
        return $converter->warned;
    }

    public function testTheAutoRateIsNotFetchedForNonEcbCurrencies(): void
    {
        $converter = $this->makeConverter(['auto_rate' => 90.0, 'store_rate' => 95.0]);

        $converter->convert(95.0, 'RUB');

        self::assertSame(0, $converter->autoCalls);
    }

    public function testPeekReturnsTheCachedRate(): void
    {
        $converter = $this->makeConverter([
            'auto_rate' => null,
            'cached_rate' => 0.9,
            'store_rate' => 0.95,
        ]);

        $source = 'unset';
        $rate = $converter->peek_fiat_per_usd('eur', $source);

        self::assertSame(0.9, $rate);
        self::assertSame('auto', $source);
    }

    public function testPeekPrefersTheStoreRateWithoutACache(): void
    {
        $converter = $this->makeConverter([
            'auto_rate' => null,
            'cached_rate' => null,
            'store_rate' => 0.95,
            'exchange_rate' => 0.8,
        ]);

        $source = 'unset';
        $rate = $converter->peek_fiat_per_usd('EUR', $source);

        self::assertSame(0.95, $rate);
        self::assertSame('store', $source);
    }

    public function testPeekFallsBackToTheManualRate(): void
    {
        $converter = $this->makeConverter([
            'auto_rate' => null,
            'cached_rate' => null,
            'store_rate' => 0.0,
            'exchange_rate' => 0.8,
        ]);

        $source = 'unset';
        $rate = $converter->peek_fiat_per_usd('EUR', $source);

        self::assertSame(0.8, $rate);
        self::assertSame('manual', $source);
    }

    public function testPeekIgnoresTheCacheInManualMode(): void
    {
        $converter = $this->makeConverter([
            'rate_source' => 'manual',
            'auto_rate' => null,
            'cached_rate' => 0.9,
            'exchange_rate' => 0.8,
        ]);

        $source = 'unset';
        $rate = $converter->peek_fiat_per_usd('EUR', $source);

        self::assertSame(0.8, $rate);
        self::assertSame('manual', $source);
    }

    public function testPeekIgnoresTheCacheForNonEcbCurrencies(): void
    {
        $converter = $this->makeConverter([
            'auto_rate' => null,
            'cached_rate' => 90.0,
            'store_rate' => 95.0,
        ]);

        $source = 'unset';
        $rate = $converter->peek_fiat_per_usd('RUB', $source);

        self::assertSame(95.0, $rate);
        self::assertSame('store', $source);
    }

    public function testPeekIsOneForUsd(): void
    {
        $converter = $this->makeConverter(['store_rate' => 5.0]);

        $source = 'unset';
        $rate = $converter->peek_fiat_per_usd('USD', $source);

        self::assertSame(1.0, $rate);
        self::assertSame('auto', $source);
    }
}

/**
 * API client double: the converter reads settings and never touches the DB.
 */
final class FakeClient
{
    /**
     * @var array<string, mixed>
     */
    private $settings;

    /**
     * @param array<string, mixed> $settings Settings.
     */
    public function __construct(array $settings)
    {
        $this->settings = $settings;
    }

    /**
     * @param string $key     Setting name.
     * @param mixed  $default Default value.
     * @return mixed
     */
    public function get($key, $default = null)
    {
        return array_key_exists($key, $this->settings) ? $this->settings[$key] : $default;
    }

    /**
     * @param string $context Context.
     * @param mixed  $message Message.
     * @param string $level   Level.
     * @return void
     */
    public function write_log($context, $message, $level = 'info')
    {
    }
}

/**
 * Converter with the network call and the file cache replaced by fixed values.
 */
final class UsdConverterDouble extends \AneePayUsdConverter
{
    /**
     * @var float|null
     */
    public $autoRate;

    /**
     * @var float|null
     */
    public $cachedRate;

    /**
     * @var int
     */
    public $autoCalls = 0;

    /**
     * @var array<int, array{0:string, 1:string, 2:float}>
     */
    public $warned = [];

    /**
     * @param FakeClient $client     Client double.
     * @param float|null $autoRate   Value the automatic source resolves to.
     * @param float|null $cachedRate Value found in the file cache.
     */
    public function __construct($client, $autoRate, $cachedRate)
    {
        parent::__construct($client);

        $this->autoRate = $autoRate;
        $this->cachedRate = $cachedRate;
    }

    protected function fetch_auto_rate($currency)
    {
        ++$this->autoCalls;

        return $this->autoRate;
    }

    protected function warnFallback($currency, $source, $rate)
    {
        $this->warned[] = [(string) $currency, (string) $source, (float) $rate];
    }

    protected function read_cached_rate($currency)
    {
        return $this->cachedRate;
    }
}
