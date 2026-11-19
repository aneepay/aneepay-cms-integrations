<?php
declare(strict_types=1);

namespace AneePay\Tests\Support;

use PHPUnit\Framework\TestCase;

/**
 * Contract for decoding an AneePay `operation_id` into the payment UUID.
 *
 * `operation_id` is the payment UUID read as a 128-bit unsigned integer, so the
 * mapping is bijective: every UUID has exactly one decimal form and the plugin
 * must reproduce it digit for digit — a float cast or a 64-bit `intval()`
 * silently loses the high bits and resolves the wrong order.
 */
abstract class OperationIdContractTestCase extends TestCase
{
    /**
     * Decode a decimal operation id the way the platform does.
     *
     * @param mixed $operation_id Decimal operation id.
     * @return string|null
     */
    abstract protected function toUuid($operation_id);

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function validMappings(): array
    {
        return [
            'one'          => ['1', '00000000-0000-0000-0000-000000000001'],
            'zero'         => ['0', '00000000-0000-0000-0000-000000000000'],
            'leading zero' => ['000123', '00000000-0000-0000-0000-00000000007b'],
            'random uuid'  => ['2095860140616323946138232506829736363', '0193a5e2-0b3c-7c4d-9e8f-0123456789ab'],
            'max 128-bit'  => ['340282366920938463463374607431768211455', 'ffffffff-ffff-ffff-ffff-ffffffffffff'],
        ];
    }

    /**
     * @dataProvider validMappings
     */
    public function testDecodesTheOperationIdToTheSameUuid(string $operation_id, string $expected): void
    {
        $this->assertSame($expected, $this->toUuid($operation_id));
    }

    public function testReturnsALowercaseUuidWithThirtySixCharacters(): void
    {
        $uuid = $this->toUuid('2095860140616323946138232506829736363');

        $this->assertIsString($uuid);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $uuid
        );
    }

    public function testTrimsSurroundingWhitespace(): void
    {
        $this->assertSame('00000000-0000-0000-0000-00000000007b', $this->toUuid("  123\n"));
    }

    public function testAcceptsAnIntegerAsWellAsAString(): void
    {
        $this->assertSame('00000000-0000-0000-0000-00000000007b', $this->toUuid(123));
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function invalidValues(): array
    {
        return [
            'empty string'  => [''],
            'blank string'  => ['   '],
            'letters'       => ['abc'],
            'trailing junk' => ['123abc'],
            'hex literal'   => ['0x7b'],
            'negative'      => ['-1'],
            'decimal point' => ['1.5'],
            'exponent'      => ['1e5'],
            'null'          => [null],
        ];
    }

    /**
     * @dataProvider invalidValues
     *
     * @param mixed $value
     */
    public function testReturnsNullForAnUndecodableValue($value): void
    {
        $this->assertNull($this->toUuid($value));
    }
}
