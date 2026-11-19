<?php
declare(strict_types=1);

namespace AneePay\Tests\Unit\OpenCart;

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

        return new UsdConverterDouble($client, $settings['auto_rate'] ?? null);
    }

    protected function isEcbCurrency(string $currency): bool
    {
        return \AneePay_Token_Converter::is_ecb_currency($currency);
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
     * @return null
     */
    public function db()
    {
        return null;
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
 * Converter with the network call and the cache replaced by fixed values.
 */
final class UsdConverterDouble extends \AneePay_Token_Converter
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
     * @param FakeClient $client   Client double.
     * @param float|null $autoRate Value the automatic source resolves to.
     */
    public function __construct($client, $autoRate)
    {
        parent::__construct($client);

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
