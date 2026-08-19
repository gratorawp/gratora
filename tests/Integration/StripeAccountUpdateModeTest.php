<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Analytics\Event;
use Dono\Foundation\Crypto\Crypto;
use Dono\Foundation\Plugin;
use Dono\Gateways\GatewayManager;
use Dono\Gateways\Stripe\StripeAccount;
use WP_REST_Request;

/**
 * A test-signed account update may not stop a live connection charging.
 *
 * charges_enabled is one flag shared by both modes, and GatewayManager::isOn()
 * is what the donor form resolves its gateway list against, so clearing it
 * takes Stripe off every form in both modes. The account id is the same string
 * in test and live and the webhook route is public and unauthenticated, so
 * without a mode rule a leaked test signing secret produces the same site-wide
 * payments outage as a forged deauthorization, silently and without costing the
 * attacker the API keys.
 */
final class StripeAccountUpdateModeTest extends IntegrationTestCase
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

        $c       = Plugin::instance()->container;
        $manager = $c->get(GatewayManager::class);
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

    public function test_a_test_signed_update_cannot_stop_a_live_connection_charging(): void
    {
        $this->connect(live: true);

        $status = $this->postAccountUpdated($this->testSecret, false, ['charges_enabled' => false]);

        $this->assertSame(200, $status);
        $this->assertTrue(
            (new StripeAccount(new Crypto()))->canCharge(),
            'a test signing secret must not disable charging for a live connection',
        );
        $this->assertTrue(
            Plugin::instance()->container->get(GatewayManager::class)->isOn('stripe'),
            'the donor form resolves against isOn(), so Stripe has to stay offered',
        );
    }

    public function test_the_refusal_is_visible_to_the_operator(): void
    {
        $this->connect(live: true);

        $this->postAccountUpdated($this->testSecret, false, ['charges_enabled' => false]);

        $row = Event::query()
            ->where('type', 'webhook.stripe')
            ->orderBy('id', 'DESC')
            ->get();

        $this->assertNotNull($row, 'every delivery is logged');
        $this->assertStringContainsString(
            'test-signed account update',
            (string) ($row->payload['error'] ?? ''),
            'the readiness check points at Stripe, so the refusal is the only thing naming the cause',
        );
    }

    public function test_a_live_signed_update_that_stops_charging_is_honoured(): void
    {
        $this->connect(live: true);

        $status = $this->postAccountUpdated($this->liveSecret, true, ['charges_enabled' => false]);

        $this->assertSame(200, $status);
        $this->assertFalse(
            (new StripeAccount(new Crypto()))->canCharge(),
            'the mode that owns the connection is believed',
        );
    }

    public function test_a_test_only_connection_still_takes_its_own_updates(): void
    {
        $this->connect(live: false);

        $this->postAccountUpdated($this->testSecret, false, ['charges_enabled' => false]);

        $this->assertFalse(
            (new StripeAccount(new Crypto()))->canCharge(),
            'a connection with no live keys has only test-signed updates to go on',
        );
    }

    public function test_a_test_signed_update_still_merges_the_display_flags(): void
    {
        $this->connect(live: true);

        $this->postAccountUpdated($this->testSecret, false, [
            'charges_enabled'  => true,
            'email'            => 'finance@example.test',
            'business_profile' => ['name' => 'Sea Turtle Rescue'],
        ]);

        $acct = (new StripeAccount(new Crypto()))->get();
        $this->assertSame('finance@example.test', (string) $acct['email']);
        $this->assertSame('Sea Turtle Rescue', (string) $acct['business_name']);
    }

    /**
     * The upgrade direction. One charges_enabled is stored for both modes, so a
     * test-signed event saying charging works speaks for the live connection
     * too: a live account Stripe has restricted goes back in front of donors,
     * and every donation then fails at the gateway with nothing on screen
     * saying why.
     */
    public function test_a_test_signed_update_cannot_start_a_restricted_live_connection_charging(): void
    {
        $this->connect(live: true);

        // Stripe restricted the live account, said so with the live secret.
        $this->postAccountUpdated($this->liveSecret, true, ['charges_enabled' => false]);
        $this->assertFalse((new StripeAccount(new Crypto()))->canCharge(), 'precondition: restricted');

        $this->postAccountUpdated($this->testSecret, false, ['charges_enabled' => true]);

        $this->assertFalse(
            (new StripeAccount(new Crypto()))->canCharge(),
            'the sandbox does not get to say the live account is in good standing'
        );
    }

    public function test_a_null_capability_flag_is_not_a_downgrade(): void
    {
        $this->connect(live: true);

        $this->postAccountUpdated($this->testSecret, false, [
            'charges_enabled'  => null,
            'business_profile' => ['name' => 'Sea Turtle Rescue'],
        ]);

        $acct = (new StripeAccount(new Crypto()))->get();
        $this->assertTrue((bool) $acct['charges_enabled'], 'a null flag keeps the stored value');
        $this->assertSame(
            'Sea Turtle Rescue',
            (string) $acct['business_name'],
            'the refusal has to agree with refresh() about what a downgrade is, or it drops the whole merge',
        );
    }

    private function connect(bool $live): void
    {
        $acct = new StripeAccount(new Crypto());
        $acct->saveKeys(true, 'sk_test_connected', 'pk_test_seed');
        if ($live) {
            $acct->saveKeys(false, 'sk_live_connected', 'pk_live_seed');
        }
        $acct->refresh(['id' => 'acct_test_123', 'charges_enabled' => true]);
    }

    /** @param array<string,mixed> $account */
    private function postAccountUpdated(string $secret, bool $livemode, array $account): int
    {
        $payload = (string) wp_json_encode([
            'id'       => 'evt_' . bin2hex(random_bytes(6)),
            'type'     => 'account.updated',
            'livemode' => $livemode,
            'data'     => ['object' => ['id' => 'acct_test_123'] + $account],
        ]);

        $timestamp = (string) time();
        $sig       = hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);

        $req = new WP_REST_Request('POST', '/dono/v1/webhooks/stripe');
        $req->set_header('content-type', 'application/json');
        $req->set_header('stripe_signature', "t={$timestamp},v1={$sig}");
        $req->set_body($payload);

        return rest_do_request($req)->get_status();
    }
}
