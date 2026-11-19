<?php
declare(strict_types=1);

namespace AneePay\Tests\Unit\WooCommerce;

use AneePay\Tests\Support\WebhookSignatureContractTestCase;

final class WebhookSignatureTest extends WebhookSignatureContractTestCase
{
    protected function verifier(array $settings): callable
    {
        $secret = isset($settings['webhook_secret']) ? (string) $settings['webhook_secret'] : '';

        return static function (string $body, string $signature) use ($secret): bool {
            return aneepay_verify_webhook_signature($signature, $body, $secret);
        };
    }
}
