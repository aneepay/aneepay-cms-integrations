<?php
declare(strict_types=1);

namespace AneePay\Tests\Unit\WooCommerce;

use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * The gateway's procedural helpers (AJAX handlers, status sync, webhook
 * callback, cron) are free functions in the same file and read the handler
 * from the outside, so the property cannot be protected.
 */
final class GatewayApiHandlerVisibilityTest extends TestCase
{
    public function test_the_gateway_exposes_its_api_handler(): void
    {
        $property = new ReflectionProperty(\WC_Gateway_AneePay_Crypto::class, 'api_handler');

        self::assertTrue($property->isPublic());
    }
}
