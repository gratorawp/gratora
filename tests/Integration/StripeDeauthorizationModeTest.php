<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Foundation\Crypto\Crypto;
use Dono\Foundation\Plugin;
use Dono\Gateways\Stripe\StripeAccount;
use WP_REST_Request;

/**
 * A deauthorization may only drop the keys of the mode that signed it.
 *
 * The webhook route is public and unauthenticated by design, and the Stripe
 * account id is the same string in test and live, so the id check alone lets a
 * leaked test signing secret erase the live secret key: Stripe disappears from
 * every donation form and in-flight payments can no longer be confirmed or
 * refunded. Every other record-touching handler in the gateway refuses a
 * mode it was not signed for; this one erases the most.
 */
final class StripeDeauthorizationModeTest extends IntegrationTestCase
{
    private string $testSecret;
    private string $liveSecret;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testSecret = 'whsec_test_' . bin2hex(random_bytes(8));
        $this->liveSecret = 'whsec_live_' . bin2hex(random_bytes(8));
        update_option('dono_gateway_config', [
            'stripe' => [
                'webhook_secret_test' => $this->testSecret,
                'webhook_secret_live' => $this->liveSecret,
            ],
        ]);

        $acct = new StripeAccount(new Crypto());
        $acct->saveKeys(true, 'sk_test_connected', 'pk_test_seed');
        $acct->saveKeys(false, 'sk_live_connected', 'pk_live_seed');
        $acct->refresh(['id' => 'acct_test_123', 'charges_enabled' => true]);

        $c       = Plugin::instance()->container;
        $manager = $c->get(\Dono\Gateways\GatewayManager::class);
        if (! $manager->get('stripe')) {
            $manager->register(new \Dono\Gateways\Stripe\StripeGateway(
                $c->get(\Dono\Gateways\Stripe\StripeApi::class),
                $c->get(\Dono\Donations\DonationRepository::class),
                $c->get(\Dono\Donations\DonationService::class),
                $c->get(\Dono\Gateways\Stripe\StripeAccount::class),
                $c->get(\Dono\Donors\DonorRepository::class),
                $c->get(\Dono\Donors\DonorService::class),
                $c->get(\Dono\Foundation\Time\Clock::class),
                $c->get(\Dono\Recurring\RecurringPlanRepository::class),
            ));
        }
    }

    public function test_a_test_signed_deauthorization_leaves_the_live_keys_alone(): void
    {
        $this->postDeauthorization($this->testSecret, false);

        $acct = new StripeAccount(new Crypto());
        $this->assertTrue(
            $acct->hasKeysFor(false),
            'a test signing secret must not erase the live secret key',
        );
        $this->assertFalse($acct->hasKeysFor(true), 'the mode it was signed for is dropped');
    }

    public function test_a_live_signed_deauthorization_leaves_the_test_keys_alone(): void
    {
        $this->postDeauthorization($this->liveSecret, true);

        $acct = new StripeAccount(new Crypto());
        $this->assertFalse($acct->hasKeysFor(false), 'the mode it was signed for is dropped');
        $this->assertTrue($acct->hasKeysFor(true), 'the other mode is a separate connection');
    }

    public function test_deauthorizing_the_last_connected_mode_drops_the_record(): void
    {
        $this->postDeauthorization($this->testSecret, false);
        $this->postDeauthorization($this->liveSecret, true);

        $this->assertNull(
            (new StripeAccount(new Crypto()))->accountId(),
            'nothing is left to keep once both modes are gone',
        );
    }

    public function test_a_deauthorization_for_another_account_touches_nothing(): void
    {
        $this->postDeauthorization($this->testSecret, false, 'acct_someone_else');

        $acct = new StripeAccount(new Crypto());
        $this->assertTrue($acct->hasKeysFor(true));
        $this->assertTrue($acct->hasKeysFor(false));
    }

    private function postDeauthorization(string $secret, bool $livemode, string $account = 'acct_test_123'): void
    {
        $payload = (string) wp_json_encode([
            'id'       => 'evt_' . bin2hex(random_bytes(6)),
            'type'     => 'account.application.deauthorized',
            'livemode' => $livemode,
            'account'  => $account,
            'data'     => ['object' => []],
        ]);

        $timestamp = (string) time();
        $sig       = hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);

        $req = new WP_REST_Request('POST', '/dono/v1/webhooks/stripe');
        $req->set_header('content-type', 'application/json');
        $req->set_header('stripe_signature', "t={$timestamp},v1={$sig}");
        $req->set_body($payload);
        rest_do_request($req);
    }
}
