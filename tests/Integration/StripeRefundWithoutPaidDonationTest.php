<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Analytics\ErrorLog;
use Dono\Analytics\Event;
use Dono\Donations\Donation;
use Dono\Donations\Refund;
use Dono\Donors\DonorService;
use Dono\Foundation\Crypto\Crypto;
use Dono\Foundation\Plugin;
use Dono\Gateways\Stripe\StripeAccount;
use WP_REST_Request;

/**
 * Money moved at Stripe against a donation that was never banked here is a
 * terminal answer, not a retry.
 *
 * A donation left pending, because its payment_intent.succeeded was refused on
 * an amount mismatch or never arrived, has nothing local to refund. Answering
 * with a 5xx has Stripe redeliver the same event for about three days and
 * writes an error row per attempt, and no redelivery can make the donation
 * refundable. It has to be recorded once, where the operator reads it, and
 * closed.
 */
final class StripeRefundWithoutPaidDonationTest extends IntegrationTestCase
{
    private string $secret;

    protected function setUp(): void
    {
        parent::setUp();

        $this->secret = 'whsec_live_' . bin2hex(random_bytes(8));
        update_option('dono_gateway_config', [
            'stripe' => ['webhook_secret_live' => $this->secret],
        ]);

        $acct = new StripeAccount(new Crypto());
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

    public function test_a_refund_on_a_donation_that_was_never_paid_is_answered_not_retried(): void
    {
        $donation = $this->stripeDonation('pending');

        $status = $this->postWebhook('charge.refunded', [
            'id'             => 'ch_' . bin2hex(random_bytes(4)),
            'payment_intent' => (string) $donation->gateway_intent_id,
            'refunds'        => ['data' => [[
                'id'     => 're_' . bin2hex(random_bytes(4)),
                'amount' => (int) $donation->amount_cents,
                'reason' => 'requested_by_customer',
            ]]],
        ]);

        $this->assertSame(200, $status, 'no redelivery can make a pending donation refundable');
        $this->assertNull(Refund::query()->where('donation_id', $donation->id)->get());
        $this->assertSame('pending', Donation::query()->where('id', $donation->id)->get()->status);
    }

    public function test_a_refund_on_a_donation_that_was_never_paid_is_recorded_for_the_operator(): void
    {
        $donation = $this->stripeDonation('pending');

        $this->postWebhook('charge.refunded', [
            'id'             => 'ch_' . bin2hex(random_bytes(4)),
            'payment_intent' => (string) $donation->gateway_intent_id,
            'refunds'        => ['data' => [[
                'id'     => 're_' . bin2hex(random_bytes(4)),
                'amount' => (int) $donation->amount_cents,
            ]]],
        ]);

        $row = Event::query()
            ->whereLike('type', ErrorLog::PREFIX . '%')
            ->orderBy('id', 'DESC')
            ->get();

        $this->assertNotNull($row, 'the balance moved, so the failure has to be visible');
        $this->assertSame((int) $donation->id, (int) $row->donation_id);
        $this->assertStringContainsString('not refundable locally', (string) $row->payload['message']);
    }

    public function test_a_lost_dispute_on_a_donation_that_was_never_paid_is_answered_not_retried(): void
    {
        $donation = $this->stripeDonation('failed');

        $status = $this->postWebhook('charge.dispute.funds_withdrawn', [
            'id'             => 'dp_' . bin2hex(random_bytes(4)),
            'payment_intent' => (string) $donation->gateway_intent_id,
            'amount'         => (int) $donation->amount_cents,
            'reason'         => 'fraudulent',
        ]);

        $this->assertSame(200, $status);
        $this->assertNull(Refund::query()->where('donation_id', $donation->id)->get());
    }

    public function test_a_refund_on_a_paid_donation_is_still_recorded(): void
    {
        $donation = $this->stripeDonation('paid');

        $status = $this->postWebhook('charge.refunded', [
            'id'             => 'ch_' . bin2hex(random_bytes(4)),
            'payment_intent' => (string) $donation->gateway_intent_id,
            'refunds'        => ['data' => [[
                'id'     => 're_' . bin2hex(random_bytes(4)),
                'amount' => (int) $donation->amount_cents,
            ]]],
        ]);

        $this->assertSame(200, $status);
        $this->assertNotNull(
            Refund::query()->where('donation_id', $donation->id)->get(),
            'the terminal answer must not swallow a refund that belongs on the donation',
        );
    }

    private function stripeDonation(string $status): Donation
    {
        $donor = Plugin::instance()->container
            ->get(DonorService::class)
            ->findOrCreate('stripe-unpaid-' . uniqid() . '@example.test', [
                'first_name' => 'Stripe',
                'last_name'  => 'Unpaid',
            ]);

        $now = gmdate('Y-m-d H:i:s');
        $d   = Donation::make();
        $d->reference         = 'STRIPE-UNPAID-' . strtoupper(bin2hex(random_bytes(4)));
        $d->donor_id          = (int) $donor->id;
        $d->amount_cents      = 5000;
        $d->net_cents         = 5000;
        $d->currency          = 'USD';
        $d->status            = $status;
        $d->gateway           = 'stripe';
        $d->gateway_intent_id = 'pi_test_' . bin2hex(random_bytes(6));
        $d->is_test           = false;
        $d->created_at        = $now;
        $d->updated_at        = $now;
        if ($status === 'paid') {
            $d->paid_at = $now;
        }
        $d->save();

        return $d;
    }

    /** @param array<string,mixed> $object */
    private function postWebhook(string $type, array $object): int
    {
        $payload = (string) wp_json_encode([
            'id'       => 'evt_' . bin2hex(random_bytes(6)),
            'type'     => $type,
            'livemode' => true,
            'data'     => ['object' => $object],
        ]);

        $timestamp = (string) time();
        $sig       = hash_hmac('sha256', "{$timestamp}.{$payload}", $this->secret);

        $req = new WP_REST_Request('POST', '/dono/v1/webhooks/stripe');
        $req->set_header('content-type', 'application/json');
        $req->set_header('stripe_signature', "t={$timestamp},v1={$sig}");
        $req->set_body($payload);

        return rest_do_request($req)->get_status();
    }
}
