<?php
declare(strict_types=1);

namespace AneePay\Tests\Unit\OpenCart;

use AneePay\Tests\Support\WebhookSignatureContractTestCase;

final class WebhookSignatureTest extends WebhookSignatureContractTestCase
{
    protected function verifier(array $settings): callable
    {
        $client = new \AneePay_Client($settings, null);

        return static function (string $body, string $signature) use ($client): bool {
            return $client->verify_signature($body, $signature);
        };
    }
}
