<?php
declare(strict_types=1);

namespace AneePay\Tests\Support;

use Exception;
use PHPUnit\Framework\TestCase;

/**
 * Shared behaviour of the three fiat -> USD -> token converters.
 *
 * The platforms resolve the rate with the same priority (automatic Frankfurter
 * rate, then the platform-native/store rate, then the merchant's manual rate)
 * and round the token amount up. These tests pin that contract once and run it
 * against every implementation.
 *
 * The network call is never made: each platform double overrides the protected
 * fetch method and returns a configured value. Warning emission is recorded by
 * overriding `warn_fallback()`, so the tests assert *when* it is called without
 * touching the WP transients, the OpenCart cache table or the filesystem.
 */
abstract class UsdConverterContractTestCase extends TestCase
{
    /**
     * Build a converter double.
     *
     * Supported keys:
     *  - rate_source   (string) 'auto' | 'manual', default 'auto'
     *  - exchange_rate (float)  manual rate, default 0
     *  - store_rate    (float)  store-native rate, default 0
     *  - auto_rate     (float|null) what the automatic rate resolves to, default null
     *  - cached_rate   (float|null) value found in the cache by peek(), default null
     *  - store_currency(string) default 'EUR'
     *
     * @param array<string, mixed> $settings Converter settings.
     * @return object
     */
    abstract protected function makeConverter(array $settings);

    /**
     * Whether the ECB publishes a rate for the currency.
     *
     * @param string $currency Currency code.
     * @return bool
     */
    abstract protected function isEcbCurrency(string $currency): bool;

    /**
     * Warnings recorded by the double, as [currency, source, rate] tuples.
     *
     * @param object $converter Converter double.
     * @return array<int, array{0:string, 1:string, 2:float}>
     */
    abstract protected function recordedWarnings($converter): array;

    /**
     * WooCommerce has no store-native rate: the automatic rate falls straight
     * back to the manual one.
     *
     * @return bool
     */
    protected function supportsStoreRate(): bool
    {
        return true;
    }

    /**
     * @dataProvider currencyProvider
     */
    public function testEcbCurrencyListIsNormalised(string $currency, bool $expected): void
    {
        self::assertSame($expected, $this->isEcbCurrency($currency));
    }

    /**
     * @return array<string, array{0:string, 1:bool}>
     */
    public function currencyProvider(): array
    {
        return [
            'EUR' => ['EUR', true],
            'USD' => ['USD', true],
            'lower case' => ['eur', true],
            'padded' => [' Chf ', true],
            'RUB is not published by the ECB' => ['RUB', false],
            'UAH is not published by the ECB' => ['UAH', false],
            'unknown code' => ['XXX', false],
            'empty' => ['', false],
        ];
    }

    public function testUsdIsAlwaysOneWithoutConsultingAnySource(): void
    {
        $converter = $this->makeConverter([
            'auto_rate' => 0.5,
            'store_rate' => 2.0,
            'exchange_rate' => 3.0,
        ]);

        $source = 'unset';
        $rate = $converter->get_fiat_per_usd('USD', $source);

        self::assertSame(1.0, $rate);
        self::assertSame('auto', $source);
        self::assertSame([], $this->recordedWarnings($converter));
    }

    public function testUsdIsNormalisedBeforeTheShortcut(): void
    {
        $converter = $this->makeConverter(['auto_rate' => 0.5]);

        $source = 'unset';
        $rate = $converter->get_fiat_per_usd(' usd ', $source);

        self::assertSame(1.0, $rate);
        self::assertSame('auto', $source);
    }

    public function testAutoRateWinsOverStoreAndManualRates(): void
    {
        $converter = $this->makeConverter([
            'auto_rate' => 0.9,
            'store_rate' => 0.95,
            'exchange_rate' => 0.8,
        ]);

        $source = 'unset';
        $rate = $converter->get_fiat_per_usd('EUR', $source);

        self::assertSame(0.9, $rate);
        self::assertSame('auto', $source);
    }

    public function testStoreRateIsUsedWhenTheAutoRateFails(): void
    {
        if (!$this->supportsStoreRate()) {
            self::markTestSkipped('Platform has no store-native rate.');
        }

        $converter = $this->makeConverter([
            'auto_rate' => null,
            'store_rate' => 0.95,
            'exchange_rate' => 0.8,
        ]);

        $source = 'unset';
        $rate = $converter->get_fiat_per_usd('EUR', $source);

        self::assertSame(0.95, $rate);
        self::assertSame('store', $source);
    }

    public function testManualRateIsUsedWhenNothingElseIsAvailable(): void
    {
        $converter = $this->makeConverter([
            'auto_rate' => null,
            'store_rate' => 0.0,
            'exchange_rate' => 0.8,
        ]);

        $source = 'unset';
        $rate = $converter->get_fiat_per_usd('EUR', $source);

        self::assertSame(0.8, $rate);
        self::assertSame('manual', $source);
    }

    public function testManualModeDisablesTheAutoRateOnly(): void
    {
        if (!$this->supportsStoreRate()) {
            self::markTestSkipped('Platform has no store-native rate.');
        }

        $converter = $this->makeConverter([
            'rate_source' => 'manual',
            'auto_rate' => 0.9,
            'store_rate' => 0.95,
            'exchange_rate' => 0.8,
        ]);

        $source = 'unset';
        $rate = $converter->get_fiat_per_usd('EUR', $source);

        self::assertSame(0.95, $rate);
        self::assertSame('store', $source);
    }

    public function testNonEcbCurrencySkipsTheAutoRate(): void
    {
        $converter = $this->makeConverter([
            'auto_rate' => 90.0,
            'store_rate' => 95.0,
            'exchange_rate' => 80.0,
        ]);

        $source = 'unset';
        $rate = $converter->get_fiat_per_usd('RUB', $source);

        self::assertSame($this->supportsStoreRate() ? 95.0 : 80.0, $rate);
        self::assertSame($this->supportsStoreRate() ? 'store' : 'manual', $source);
    }

    public function testNoRateAvailableReturnsNull(): void
    {
        $converter = $this->makeConverter([
            'auto_rate' => null,
            'store_rate' => 0.0,
            'exchange_rate' => 0.0,
        ]);

        $source = 'auto';

        self::assertNull($converter->get_fiat_per_usd('EUR', $source));
    }

    public function testConvertReturnsUsdAndTokenAmounts(): void
    {
        $converter = $this->makeConverter(['store_currency' => 'USD']);

        $result = $converter->convert(100.0, 'USD');

        self::assertEqualsWithDelta(100.0, $result['usd_amount'], 1e-9);
        self::assertEqualsWithDelta(100.0, $result['token_amount'], 1e-9);
        self::assertSame(1.0, $result['fiat_per_usd']);
        self::assertSame('USD', $result['currency']);
        self::assertSame('auto', $result['source']);
    }

    /**
     * @dataProvider roundingProvider
     */
    public function testConvertRoundsTheTokenAmountUp(
        float $total,
        float $ratePerUsd,
        float $expectedUsd,
        float $expectedToken
    ): void {
        $converter = $this->makeConverter(['auto_rate' => $ratePerUsd]);

        $result = $converter->convert($total, 'EUR');

        self::assertEqualsWithDelta($expectedUsd, $result['usd_amount'], 1e-9);
        self::assertEqualsWithDelta($expectedToken, $result['token_amount'], 1e-9, 'The token amount must round up.');
        self::assertGreaterThanOrEqual($expectedUsd, $result['token_amount']);
    }

    /**
     * @return array<string, array{0:float, 1:float, 2:float, 3:float}>
     */
    public function roundingProvider(): array
    {
        return [
            'repeating decimal' => [10.0, 0.9, 11.11111111111111, 11.12],
            'third of a token' => [1.0, 3.0, 0.3333333333333333, 0.34],
            'just above a cent' => [100.0, 1.1, 90.90909090909091, 90.91],
            'exact amount is unchanged' => [10.0, 0.5, 20.0, 20.0],
            'one cent' => [0.01, 1.0, 0.01, 0.01],
        ];
    }

    /**
     * @dataProvider nonPositiveTotalProvider
     */
    public function testConvertRejectsNonPositiveTotals(float $total): void
    {
        $converter = $this->makeConverter(['auto_rate' => 0.9]);

        $this->expectException(Exception::class);

        $converter->convert($total, 'EUR');
    }

    /**
     * @return array<string, array{0:float}>
     */
    public function nonPositiveTotalProvider(): array
    {
        return [
            'zero' => [0.0],
            'negative' => [-5.0],
            'tiny negative' => [-0.01],
        ];
    }

    public function testConvertThrowsWhenNoRateIsAvailable(): void
    {
        $converter = $this->makeConverter(['auto_rate' => null]);

        $this->expectException(Exception::class);

        $converter->convert(10.0, 'RUB');
    }

    public function testConvertReportsTheStoreSource(): void
    {
        if (!$this->supportsStoreRate()) {
            self::markTestSkipped('Platform has no store-native rate.');
        }

        $converter = $this->makeConverter([
            'auto_rate' => null,
            'store_rate' => 0.5,
        ]);

        $result = $converter->convert(10.0, 'EUR');

        self::assertSame('store', $result['source']);
        self::assertEqualsWithDelta(0.5, $result['fiat_per_usd'], 1e-9);
        self::assertEqualsWithDelta(20.0, $result['token_amount'], 1e-9);
    }

    public function testWarnsWhenANonEcbCurrencyFallsBack(): void
    {
        $converter = $this->makeConverter([
            'auto_rate' => 90.0,
            'store_rate' => 95.0,
            'exchange_rate' => 80.0,
        ]);

        $converter->convert(95.0, 'RUB');

        $expectedSource = $this->supportsStoreRate() ? 'store' : 'manual';
        $expectedRate = $this->supportsStoreRate() ? 95.0 : 80.0;

        self::assertSame([['RUB', $expectedSource, $expectedRate]], $this->recordedWarnings($converter));
    }

    public function testDoesNotWarnForTheAutomaticRate(): void
    {
        $converter = $this->makeConverter(['auto_rate' => 0.9]);

        $converter->convert(10.0, 'EUR');

        self::assertSame([], $this->recordedWarnings($converter));
    }

    public function testDoesNotWarnWhenTheAutomaticSourceIsDisabled(): void
    {
        $converter = $this->makeConverter([
            'rate_source' => 'manual',
            'exchange_rate' => 80.0,
        ]);

        $converter->convert(80.0, 'RUB');

        self::assertSame([], $this->recordedWarnings($converter));
    }

    public function testAutoSourceIsNotWarnedAboutForUsd(): void
    {
        $converter = $this->makeConverter(['auto_rate' => 0.9]);

        $converter->convert(10.0, 'USD');

        self::assertSame([], $this->recordedWarnings($converter));
    }

    /**
     * The recorded warning carries the resolved source and rate.
     */
    public function testWarningCarriesTheResolvedRate(): void
    {
        $converter = $this->makeConverter([
            'auto_rate' => null,
            'exchange_rate' => 0.8,
        ]);

        $converter->convert(8.0, 'RUB');

        self::assertSame([['RUB', 'manual', 0.8]], $this->recordedWarnings($converter));
    }
}
