<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Foundation\Plugin;
use Dono\Gateways\Stripe\StripeApi;

/**
 * The signing secret that matters is the one for the mode the site charges in.
 *
 * The shape of the failure this guards: an org rehearses in test, saves the test
 * signing secret, then goes live. Provisioning the live endpoint fails, quietly,
 * and its error goes to the log. Every live event now fails its signature. If
 * the readiness screen asks only whether SOME secret exists it reports that
 * webhooks are signed, so nothing on the site says otherwise while cards are
 * charged, donors are thanked, and the donations sit unpaid.
 */
final class StripeWebhookSecretModeTest extends IntegrationTestCase
{
    private StripeApi $api;

    protected function setUp(): void
    {
        parent::setUp();
        $this->api = Plugin::instance()->container->get(StripeApi::class);
    }

    /** @param array<string,string> $stripe */
    private function config(bool $testMode, array $stripe): void
    {
        update_option('dono_gateway_config', ['test_mode' => $testMode, 'stripe' => $stripe]);
    }

    public function test_a_live_site_is_not_satisfied_by_the_test_secret(): void
    {
        $this->config(false, ['webhook_secret_test' => 'whsec_test_only']);

        $this->assertFalse($this->api->hasWebhookSecret());
    }

    public function test_a_live_site_is_satisfied_by_the_live_secret(): void
    {
        $this->config(false, ['webhook_secret_live' => 'whsec_live']);

        $this->assertTrue($this->api->hasWebhookSecret());
    }

    public function test_a_test_site_is_not_satisfied_by_the_live_secret(): void
    {
        $this->config(true, ['webhook_secret_live' => 'whsec_live_only']);

        $this->assertFalse($this->api->hasWebhookSecret());
    }

    public function test_a_test_site_is_satisfied_by_the_test_secret(): void
    {
        $this->config(true, ['webhook_secret_test' => 'whsec_test']);

        $this->assertTrue($this->api->hasWebhookSecret());
    }

    public function test_neither_secret_satisfies_nothing(): void
    {
        $this->config(false, []);

        $this->assertFalse($this->api->hasWebhookSecret());
    }

    public function test_both_on_file_satisfies_either_mode(): void
    {
        $both = ['webhook_secret_test' => 'whsec_test', 'webhook_secret_live' => 'whsec_live'];

        $this->config(false, $both);
        $this->assertTrue($this->api->hasWebhookSecret());

        $this->config(true, $both);
        $this->assertTrue($this->api->hasWebhookSecret());
    }

    public function test_the_secrets_stay_keyed_by_mode(): void
    {
        $this->config(false, ['webhook_secret_test' => 'whsec_test']);

        // Which mode a secret belongs to is what stops a test secret verifying
        // live money, so a flattened list would be a different bug wearing the
        // same fix.
        $this->assertSame(['test' => 'whsec_test'], $this->api->webhookSecrets());
    }
}
