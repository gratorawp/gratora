<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Foundation\Crypto\Crypto;
use Dono\Gateways\Stripe\StripeApi;
use Dono\Gateways\Stripe\StripeAccount;

/**
 * StripeApi is "configured" when the org has stored a secret key for the
 * active mode; the webhook secret comes from dono_gateway_config. Both are
 * DB-backed, so this is an integration test.
 */
final class StripeApiTest extends IntegrationTestCase
{
    private string $secret;

    protected function setUp(): void
    {
        parent::setUp();
        $this->secret = 'whsec_test_' . bin2hex(random_bytes(8));
        update_option('dono_gateway_config', [
            'stripe' => ['webhook_secret_test' => $this->secret, 'test_mode' => true],
        ]);
    }

    private function api(): StripeApi
    {
        return new StripeApi(new StripeAccount(new Crypto()));
    }

    private function connectAccount(): void
    {
        $stripeAcct = (new StripeAccount(new Crypto()));
        $stripeAcct->saveKeys(true, 'sk_test_connected', 'pk_test_seed');
        $stripeAcct->saveKeys(false, 'sk_live_connected', 'pk_live_seed');
        $stripeAcct->refresh(['id' => 'acct_test_123', 'charges_enabled' => true]);
    }

    public function test_is_configured_false_without_keys(): void
    {
        $this->assertFalse($this->api()->isConfigured());
    }

    public function test_is_configured_true_once_keys_are_saved(): void
    {
        $this->connectAccount();
        $this->assertTrue($this->api()->isConfigured());
    }

    public function test_valid_signature_passes_within_tolerance(): void
    {
        $payload = '{"id":"evt_test","type":"payment_intent.succeeded"}';
        $timestamp = (string) time();
        $sig = hash_hmac('sha256', "{$timestamp}.{$payload}", $this->secret);

        $this->assertNotNull($this->api()->verifiedWebhookMode($payload, "t={$timestamp},v1={$sig}"));
    }

    public function test_per_mode_secrets_each_verify_their_own_delivery(): void
    {
        // Test and live endpoints have distinct secrets; a delivery signed by
        // either must verify (the other mode's deliveries are not rejected).
        update_option('dono_gateway_config', [
            'stripe' => [
                'webhook_secret_test' => 'whsec_mode_test',
                'webhook_secret_live' => 'whsec_mode_live',
            ],
        ]);
        $payload   = '{"id":"evt_test","type":"payment_intent.succeeded","livemode":false}';
        $timestamp = (string) time();

        $testSig = hash_hmac('sha256', "{$timestamp}.{$payload}", 'whsec_mode_test');
        $liveSig = hash_hmac('sha256', "{$timestamp}.{$payload}", 'whsec_mode_live');

        $this->assertNotNull($this->api()->verifiedWebhookMode($payload, "t={$timestamp},v1={$testSig}"));
        $this->assertNotNull($this->api()->verifiedWebhookMode($payload, "t={$timestamp},v1={$liveSig}"));

        $bogus = hash_hmac('sha256', "{$timestamp}.{$payload}", 'whsec_neither');
        $this->assertNull($this->api()->verifiedWebhookMode($payload, "t={$timestamp},v1={$bogus}"));
    }

    public function test_tampered_payload_is_rejected(): void
    {
        $payload = '{"id":"evt_test","type":"payment_intent.succeeded"}';
        $timestamp = (string) time();
        $sig = hash_hmac('sha256', "{$timestamp}.{$payload}", $this->secret);

        $tampered = str_replace('succeeded', 'failed', $payload);
        $this->assertNull($this->api()->verifiedWebhookMode($tampered, "t={$timestamp},v1={$sig}"));
    }

    public function test_wrong_secret_is_rejected(): void
    {
        $payload = '{"id":"evt"}';
        $timestamp = (string) time();
        $sig = hash_hmac('sha256', "{$timestamp}.{$payload}", 'wrong-secret');

        $this->assertNull($this->api()->verifiedWebhookMode($payload, "t={$timestamp},v1={$sig}"));
    }

    public function test_old_timestamp_outside_tolerance_is_rejected(): void
    {
        $payload = '{"id":"evt"}';
        $timestamp = (string) (time() - 600);
        $sig = hash_hmac('sha256', "{$timestamp}.{$payload}", $this->secret);

        $this->assertNull($this->api()->verifiedWebhookMode($payload, "t={$timestamp},v1={$sig}"));
    }

    public function test_future_timestamp_outside_tolerance_is_rejected(): void
    {
        $payload = '{"id":"evt"}';
        $timestamp = (string) (time() + 600);
        $sig = hash_hmac('sha256', "{$timestamp}.{$payload}", $this->secret);

        $this->assertNull($this->api()->verifiedWebhookMode($payload, "t={$timestamp},v1={$sig}"));
    }

    public function test_malformed_header_is_rejected(): void
    {
        $api = $this->api();
        $this->assertNull($api->verifiedWebhookMode('{}', ''));
        $this->assertNull($api->verifiedWebhookMode('{}', 'garbage'));
        $this->assertNull($api->verifiedWebhookMode('{}', 'v1=onlyone'));
        $this->assertNull($api->verifiedWebhookMode('{}', 't=123'));
    }

    public function test_no_webhook_secret_configured_rejects_all(): void
    {
        update_option('dono_gateway_config', ['stripe' => ['test_mode' => true]]);

        $payload = '{}';
        $timestamp = (string) time();
        $sig = hash_hmac('sha256', "{$timestamp}.{$payload}", 'whatever');
        $this->assertNull($this->api()->verifiedWebhookMode($payload, "t={$timestamp},v1={$sig}"));
    }
}
