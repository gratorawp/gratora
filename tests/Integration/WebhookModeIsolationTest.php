<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donations\Donation;
use Dono\Donations\DonationRepository;
use Dono\Donors\DonorService;
use Dono\Foundation\Plugin;
use Dono\Gateways\GatewayManager;
use Dono\Gateways\Stripe\StripeAccount;
use WP_REST_Request;

/**
 * What a delivery signed with the test secret may do to live money.
 *
 * A test-mode secret is the softer credential: it lives in staging env files,
 * in CI, and with contractors. The guards refuse it the money decisions, but a
 * refusal is only worth what it covers, and anything written before the guard
 * runs is written on that credential's authority.
 */
final class WebhookModeIsolationTest extends IntegrationTestCase
{
    private string $testSecret = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->testSecret = 'whsec_test_' . bin2hex(random_bytes(8));
        update_option('dono_gateway_config', [
            'stripe' => [
                'webhook_secret_test' => $this->testSecret,
                'webhook_secret_live' => 'whsec_live_' . bin2hex(random_bytes(8)),
            ],
        ]);

        $c       = Plugin::instance()->container;
        $account = $c->get(StripeAccount::class);
        $account->saveKeys(true, 'sk_test_connected', 'pk_test_seed');
        $account->saveKeys(false, 'sk_live_connected', 'pk_live_seed');
        $account->refresh(['id' => 'acct_live_org', 'charges_enabled' => true]);

        $manager = $c->get(GatewayManager::class);
        if (! $manager->get('stripe')) {
            $manager->register(new \Dono\Gateways\Stripe\StripeGateway(
                $c->get(\Dono\Gateways\Stripe\StripeApi::class),
                $c->get(DonationRepository::class),
                $c->get(\Dono\Donations\DonationService::class),
                $account,
                $c->get(\Dono\Donors\DonorRepository::class),
                $c->get(DonorService::class),
                $c->get(\Dono\Foundation\Time\Clock::class),
                $c->get(\Dono\Recurring\RecurringPlanRepository::class),
            ));
        }
    }

    private function liveDonation(string $email): Donation
    {
        $now   = gmdate('Y-m-d H:i:s');
        $donor = Plugin::instance()->container->get(DonorService::class)
            ->findOrCreate($email, ['first_name' => 'Live', 'last_name' => 'Money']);

        $d = Donation::make();
        $d->reference         = 'LIVE-' . strtoupper(bin2hex(random_bytes(4)));
        $d->donor_id          = (int) $donor->id;
        $d->amount_cents      = 250000;
        $d->net_cents         = 250000;
        $d->base_amount_cents = 250000;
        $d->base_currency     = 'USD';
        $d->currency          = 'USD';
        $d->gateway           = 'stripe';
        $d->status            = 'pending';
        $d->is_test           = false;
        $d->created_at        = $now;
        $d->updated_at        = $now;
        $d->save();

        return $d;
    }

    /** @param array<string,mixed> $object */
    private function postSignedWithTestSecret(string $type, array $object): int
    {
        $event   = ['id' => 'evt_' . bin2hex(random_bytes(6)), 'type' => $type, 'data' => ['object' => $object]];
        $payload = (string) wp_json_encode($event);
        $ts      = (string) time();
        $sig     = hash_hmac('sha256', "{$ts}.{$payload}", $this->testSecret);

        $req = new WP_REST_Request('POST', '/dono/v1/webhooks/stripe');
        $req->set_header('content-type', 'application/json');
        $req->set_header('stripe_signature', "t={$ts},v1={$sig}");
        $req->set_body($payload);

        return rest_do_request($req)->get_status();
    }

    /**
     * The reference lives in the intent's metadata so a lost intent id can be
     * healed from it. It is not a secret: it is on the donor's own receipt and
     * in every export. So a test-signed event can name a live donation, and
     * writing the id it carries before the mode is checked stamps an id of the
     * sender's choosing onto live money. The guard then refuses the confirm,
     * which reads as the system working, while the poison stays on the row,
     * survives the genuine live event, and is what a later refund is sent
     * against with the live key.
     */
    public function test_a_test_signed_event_cannot_write_an_intent_id_onto_a_live_donation(): void
    {
        $donation = $this->liveDonation('poison@example.test');

        $this->postSignedWithTestSecret('payment_intent.succeeded', [
            'id'                   => 'pi_chosen_by_the_sender',
            'status'               => 'succeeded',
            'amount'               => 250000,
            'amount_received'      => 250000,
            'currency'             => 'usd',
            'metadata'             => ['dono_reference' => $donation->reference],
            'payment_method_types' => ['card'],
            'livemode'             => false,
        ]);

        $fresh = Donation::query()->find('id', (int) $donation->id);
        $this->assertNull(
            $fresh->gateway_intent_id,
            'a test-mode secret wrote a gateway id onto a live donation'
        );
        $this->assertSame('pending', (string) $fresh->status, 'and it did not bank it either');
    }

    /** The heal itself still works when the delivery is entitled to it. */
    public function test_a_matching_mode_still_heals_a_lost_intent_id(): void
    {
        $now   = gmdate('Y-m-d H:i:s');
        $donor = Plugin::instance()->container->get(DonorService::class)
            ->findOrCreate('heal@example.test', ['first_name' => 'He', 'last_name' => 'Al']);

        $d = Donation::make();
        $d->reference         = 'TEST-' . strtoupper(bin2hex(random_bytes(4)));
        $d->donor_id          = (int) $donor->id;
        $d->amount_cents      = 2500;
        $d->net_cents         = 2500;
        $d->base_amount_cents = 2500;
        $d->base_currency     = 'USD';
        $d->currency          = 'USD';
        $d->gateway           = 'stripe';
        $d->status            = 'pending';
        $d->is_test           = true;
        $d->created_at        = $now;
        $d->updated_at        = $now;
        $d->save();

        $this->postSignedWithTestSecret('payment_intent.succeeded', [
            'id'                   => 'pi_the_real_one',
            'status'               => 'succeeded',
            'amount'               => 2500,
            'amount_received'      => 2500,
            'currency'             => 'usd',
            'metadata'             => ['dono_reference' => $d->reference],
            'payment_method_types' => ['card'],
            'livemode'             => false,
        ]);

        $fresh = Donation::query()->find('id', (int) $d->id);
        $this->assertSame('pi_the_real_one', (string) $fresh->gateway_intent_id, 'the heal still happens');
    }
}
