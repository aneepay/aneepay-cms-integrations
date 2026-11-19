<?php
declare(strict_types=1);

namespace AneePay\Tests\Support;

use PHPUnit\Framework\TestCase;

/**
 * Contract every platform's webhook signature verifier must satisfy.
 *
 * The verifier is the only thing standing between a public `status_url` and a
 * forged "this payment succeeded" webhook, so the tests cover the malformed
 * inputs as carefully as the happy path.
 */
abstract class WebhookSignatureContractTestCase extends TestCase
{
    /**
     * Build a verifier for the given plugin settings.
     *
     * @param array<string, mixed> $settings Plugin settings (webhook_secret).
     * @return callable(string $body, string $signature): bool
     */
    abstract protected function verifier(array $settings): callable;

    public function testAcceptsTheSignatureOfTheExactBody(): void
    {
        $body      = '{"event_id":"evt_1","status":"success"}';
        $signature = hash_hmac('sha256', $body, 'top-secret');

        $verify = $this->verifier(['webhook_secret' => 'top-secret']);

        $this->assertTrue($verify($body, $signature));
    }

    public function testAcceptsABodyWithMultibyteCharacters(): void
    {
        $body      = '{"order":"Оплата №42 💸","status":"success"}';
        $signature = hash_hmac('sha256', $body, 'top-secret');

        $verify = $this->verifier(['webhook_secret' => 'top-secret']);

        $this->assertTrue($verify($body, $signature));
    }

    public function testRejectsATamperedBody(): void
    {
        $signature = hash_hmac('sha256', '{"status":"failed"}', 'top-secret');

        $verify = $this->verifier(['webhook_secret' => 'top-secret']);

        $this->assertFalse($verify('{"status":"success"}', $signature));
    }

    public function testRejectsASignatureMadeWithAnotherSecret(): void
    {
        $body      = '{"status":"success"}';
        $signature = hash_hmac('sha256', $body, 'other-secret');

        $verify = $this->verifier(['webhook_secret' => 'top-secret']);

        $this->assertFalse($verify($body, $signature));
    }

    public function testRejectsATamperedSignature(): void
    {
        $body      = '{"status":"success"}';
        $signature = hash_hmac('sha256', $body, 'top-secret');
        $tampered  = substr($signature, 0, -1) . ('0' === substr($signature, -1) ? '1' : '0');

        $verify = $this->verifier(['webhook_secret' => 'top-secret']);

        $this->assertFalse($verify($body, $tampered));
    }

    public function testRejectsAnEmptySecretEvenWhenTheSignatureMatchesIt(): void
    {
        $body      = '{"status":"success"}';
        $signature = hash_hmac('sha256', $body, '');

        $verify = $this->verifier(['webhook_secret' => '']);

        $this->assertFalse($verify($body, $signature));
    }

    public function testRejectsAMissingSecretSetting(): void
    {
        $body      = '{"status":"success"}';
        $signature = hash_hmac('sha256', $body, '');

        $verify = $this->verifier([]);

        $this->assertFalse($verify($body, $signature));
    }

    public function testRejectsAnEmptySignature(): void
    {
        $verify = $this->verifier(['webhook_secret' => 'top-secret']);

        $this->assertFalse($verify('{"status":"success"}', ''));
    }

    /**
     * `hash_equals()` compares the hex strings byte by byte, so the header must
     * be sent the way the API sends it: lowercase hex, without whitespace.
     */
    public function testRejectsAnUppercaseSignature(): void
    {
        $body      = '{"status":"success"}';
        $signature = strtoupper(hash_hmac('sha256', $body, 'top-secret'));

        $verify = $this->verifier(['webhook_secret' => 'top-secret']);

        $this->assertFalse($verify($body, $signature));
    }

    public function testRejectsASignatureWithTrailingWhitespace(): void
    {
        $body      = '{"status":"success"}';
        $signature = hash_hmac('sha256', $body, 'top-secret') . "\n";

        $verify = $this->verifier(['webhook_secret' => 'top-secret']);

        $this->assertFalse($verify($body, $signature));
    }
}
