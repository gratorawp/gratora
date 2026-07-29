<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donations\Donation;
use Dono\Donors\DonorService;
use Dono\Foundation\Crypto\Crypto;
use Dono\Foundation\Plugin;
use Dono\Gateways\Stripe\StripeAccount;
use WP_REST_Request;

/**
 * SEPA and ACH through Stripe.
 *
 * A card PaymentIntent goes straight to `succeeded`. A bank debit goes to
 * `processing` first and only succeeds days later, and can fail in between.
 * Until this event was handled, those donations sat in `pending` alongside
 * abandoned checkouts, so an admin could not tell expected income from a donor
 * who closed the tab, and the donor was told for a week that we were still
 * waiting on them.
 */
final class StripeProcessingWebhookTest extends IntegrationTestCase
{
    private string $secret;

    protected function setUp(): void
    {
        parent::setUp();
        $this->secret = 'whsec_test_' . bin2hex(random_bytes(8));
        update_option('dono_gateway_config', [
            'stripe' => ['webhook_secret_live' => $this->secret, 'test_mode' => true],
        ]);

        $account = new StripeAccount(new Crypto());
        $account->saveKeys(true, 'sk_test_connected', 'pk_test_seed');
        $account->saveKeys(false, 'sk_live_connected', 'pk_live_seed');
        $account->refresh(['id' => 'acct_test_123', 'charges_enabled' => true]);

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

    public function test_a_bank_debit_moves_out_of_pending_when_stripe_starts_processing(): void
    {
        $donation = $this->pendingStripeDonation();

        $this->postWebhook('payment_intent.processing', [
            'id'       => (string) $donation->gateway_intent_id,
            'status'   => 'processing',
            'currency' => 'usd',
            'amount'   => 5000,
        ]);

        $this->assertSame('processing', $this->reload($donation)->status);
    }

    /** The money has not arrived. Whatever else changes, it is not income yet. */
    public function test_processing_does_not_record_the_money_as_received(): void
    {
        $donation = $this->pendingStripeDonation();

        $this->postWebhook('payment_intent.processing', [
            'id'       => (string) $donation->gateway_intent_id,
            'status'   => 'processing',
            'currency' => 'usd',
            'amount'   => 5000,
        ]);

        $this->assertNull($this->reload($donation)->paid_at);
    }

    /** Settlement, days later, is what makes it paid. */
    public function test_the_later_success_still_settles_the_donation(): void
    {
        $donation = $this->pendingStripeDonation();

        $this->postWebhook('payment_intent.processing', [
            'id'       => (string) $donation->gateway_intent_id,
            'status'   => 'processing',
            'currency' => 'usd',
            'amount'   => 5000,
        ]);
        $this->postWebhook('payment_intent.succeeded', [
            'id'              => (string) $donation->gateway_intent_id,
            'status'          => 'succeeded',
            'currency'        => 'usd',
            'amount_received' => 5000,
        ]);

        $row = $this->reload($donation);
        $this->assertSame('paid', $row->status);
        $this->assertNotNull($row->paid_at);
    }

    /** A bank debit can bounce after it was submitted. */
    public function test_a_debit_that_bounces_afterwards_fails_the_donation(): void
    {
        $donation = $this->pendingStripeDonation();

        $this->postWebhook('payment_intent.processing', [
            'id'       => (string) $donation->gateway_intent_id,
            'status'   => 'processing',
            'currency' => 'usd',
            'amount'   => 5000,
        ]);
        $this->postWebhook('payment_intent.payment_failed', [
            'id'                 => (string) $donation->gateway_intent_id,
            'last_payment_error' => ['message' => 'Insufficient funds.'],
        ]);

        $this->assertSame('failed', $this->reload($donation)->status);
    }

    /**
     * A verified signature proves Stripe sent the event, not that it is about
     * this donation for this amount. The same guard the other handlers use.
     */
    public function test_an_event_for_a_different_amount_is_refused(): void
    {
        $donation = $this->pendingStripeDonation();

        $this->postWebhook('payment_intent.processing', [
            'id'       => (string) $donation->gateway_intent_id,
            'status'   => 'processing',
            'currency' => 'usd',
            'amount'   => 100000,
        ]);

        $this->assertSame('pending', $this->reload($donation)->status);
    }

    /** Money that already settled must not be walked back by a late event. */
    public function test_a_late_processing_event_does_not_unsettle_a_paid_donation(): void
    {
        $donation = $this->pendingStripeDonation();

        $this->postWebhook('payment_intent.succeeded', [
            'id'              => (string) $donation->gateway_intent_id,
            'status'          => 'succeeded',
            'currency'        => 'usd',
            'amount_received' => 5000,
        ]);
        $this->postWebhook('payment_intent.processing', [
            'id'       => (string) $donation->gateway_intent_id,
            'status'   => 'processing',
            'currency' => 'usd',
            'amount'   => 5000,
        ]);

        $this->assertSame('paid', $this->reload($donation)->status);
    }

    private function reload(Donation $donation): Donation
    {
        return Donation::query()->where('id', $donation->id)->get();
    }

    private function pendingStripeDonation(): Donation
    {
        $donor = Plugin::instance()->container
            ->get(DonorService::class)
            ->findOrCreate('sepa-' . uniqid() . '@example.test', ['first_name' => 'Sepa', 'last_name' => 'Donor']);

        $now = gmdate('Y-m-d H:i:s');
        $d = Donation::make();
        $d->reference         = 'SEPA-' . strtoupper(bin2hex(random_bytes(4)));
        $d->donor_id          = (int) $donor->id;
        $d->amount_cents      = 5000;
        $d->net_cents         = 5000;
        $d->currency          = 'USD';
        $d->status            = 'pending';
        $d->gateway           = 'stripe';
        $d->payment_method    = 'sepa_debit';
        $d->gateway_intent_id = 'pi_test_' . bin2hex(random_bytes(6));
        $d->is_test           = false;
        $d->created_at        = $now;
        $d->updated_at        = $now;
        $d->save();

        return $d;
    }

    /** @param array<string,mixed> $object */
    private function postWebhook(string $type, array $object): void
    {
        $payload   = (string) wp_json_encode([
            'id'   => 'evt_' . bin2hex(random_bytes(6)),
            'type' => $type,
            'data' => ['object' => $object],
        ]);
        $timestamp = (string) time();
        $sig       = hash_hmac('sha256', "{$timestamp}.{$payload}", $this->secret);

        $req = new WP_REST_Request('POST', '/dono/v1/webhooks/stripe');
        $req->set_header('content-type', 'application/json');
        $req->set_header('stripe_signature', "t={$timestamp},v1={$sig}");
        $req->set_body($payload);
        rest_do_request($req);
    }
}
