<?php
declare(strict_types=1);

namespace AneePay\Tests\Unit\PrestaShop;

use AneePay\Tests\Support\WebhookSignatureContractTestCase;

final class WebhookSignatureTest extends WebhookSignatureContractTestCase
{
    protected function verifier(array $settings): callable
    {
        $api = new \AneePayApi($settings);

        return static function (string $body, string $signature) use ($api): bool {
            return $api->verify_signature($body, $signature);
        };
    }
}
