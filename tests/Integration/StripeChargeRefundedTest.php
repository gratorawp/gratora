<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donations\Donation;
use Dono\Donations\Refund;
use Dono\Donors\DonorService;
use Dono\Foundation\Crypto\Crypto;
use Dono\Foundation\Plugin;
use Dono\Gateways\Stripe\StripeAccount;
use WP_REST_Request;

/**
 * A refund issued from the Stripe dashboard arrives as a `charge.refunded`
 * webhook and must sync back locally: mark the donation refunded and email the
 * donor. This branch was previously untested (only admin-initiated refunds were
 * covered by RefundFlowTest).
 */
final class StripeChargeRefundedTest extends IntegrationTestCase
{
    private string $secret;

    protected function setUp(): void
    {
        parent::setUp();
        $this->secret = 'whsec_test_' . bin2hex(random_bytes(8));
        update_option('dono_gateway_config', [
            'stripe' => ['webhook_secret_live' => $this->secret, 'test_mode' => true],
        ]);

        $stripeAcct = (new StripeAccount(new Crypto()));
        $stripeAcct->saveKeys(true, 'sk_test_connected', 'pk_test_seed');
        $stripeAcct->saveKeys(false, 'sk_live_connected', 'pk_live_seed');
        $stripeAcct->refresh(['id' => 'acct_test_123', 'charges_enabled' => true]);

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

    public function test_charge_refunded_webhook_marks_refunded_and_emails_donor(): void
    {
        $donation = $this->paidStripeDonation();
        $mails    = $this->captureMails();

        $this->postWebhook('charge.refunded', [
            'id'             => 'ch_' . bin2hex(random_bytes(4)),
            'payment_intent' => (string) $donation->gateway_intent_id,
            'refunds'        => ['data' => [[
                'id'     => 're_' . bin2hex(random_bytes(4)),
                'amount' => (int) $donation->amount_cents,
                'reason' => 'requested_by_customer',
            ]]],
        ]);

        $reloaded = Donation::query()->where('id', $donation->id)->get();
        $this->assertSame('refunded', $reloaded->status, 'a dashboard refund syncs the donation to refunded');

        $refund = Refund::query()->where('donation_id', $donation->id)->get();
        $this->assertNotNull($refund);
        $this->assertSame((int) $donation->amount_cents, (int) $refund->amount_cents);

        $refundMails = array_filter(
            iterator_to_array($mails),
            static fn ($m) => stripos((string) ($m['subject'] ?? ''), 'refund') !== false,
        );
        $this->assertCount(1, $refundMails, 'the donor is emailed about the refund');
    }

    public function test_charge_refunded_is_idempotent_on_redelivery(): void
    {
        $donation = $this->paidStripeDonation();
        $refundId = 're_' . bin2hex(random_bytes(4));

        $object = [
            'id'             => 'ch_' . bin2hex(random_bytes(4)),
            'payment_intent' => (string) $donation->gateway_intent_id,
            'refunds'        => ['data' => [[
                'id'     => $refundId,
                'amount' => (int) $donation->amount_cents,
                'reason' => 'requested_by_customer',
            ]]],
        ];

        $this->postWebhook('charge.refunded', $object);
        $this->postWebhook('charge.refunded', $object); // redelivery

        $count = (int) Refund::query()->where('donation_id', $donation->id)->count();
        $this->assertSame(1, $count, 'a redelivered charge.refunded does not double-record the refund');
    }

    public function test_dispute_funds_withdrawn_marks_donation_refunded(): void
    {
        $donation = $this->paidStripeDonation();

        $this->postWebhook('charge.dispute.funds_withdrawn', [
            'id'             => 'dp_' . bin2hex(random_bytes(4)),
            'payment_intent' => (string) $donation->gateway_intent_id,
            'amount'         => (int) $donation->amount_cents,
            'reason'         => 'fraudulent',
        ]);

        $reloaded = Donation::query()->where('id', $donation->id)->get();
        $this->assertSame('refunded', $reloaded->status, 'a chargeback withdraws the funds and reverses the donation');

        $refund = Refund::query()->where('donation_id', $donation->id)->get();
        $this->assertNotNull($refund, 'the withdrawn funds are recorded as a refund');
    }

    private function paidStripeDonation(): Donation
    {
        $donor = Plugin::instance()->container
            ->get(DonorService::class)
            ->findOrCreate('stripe-refund-' . uniqid() . '@example.test', ['first_name' => 'Stripe', 'last_name' => 'Refund']);

        $now = gmdate('Y-m-d H:i:s');
        $d = Donation::make();
        $d->reference         = 'STRIPE-REF-' . strtoupper(bin2hex(random_bytes(4)));
        $d->donor_id          = (int) $donor->id;
        $d->amount_cents      = 5000;
        $d->net_cents         = 5000;
        $d->currency          = 'USD';
        $d->status            = 'paid';
        $d->gateway           = 'stripe';
        $d->gateway_intent_id = 'pi_test_' . bin2hex(random_bytes(6));
        $d->is_test           = false;
        $d->paid_at           = $now;
        $d->created_at        = $now;
        $d->updated_at        = $now;
        $d->save();

        return $d;
    }

    private function postWebhook(string $type, array $object): void
    {
        $event = [
            'id'   => 'evt_' . bin2hex(random_bytes(6)),
            'type' => $type,
            'data' => ['object' => $object],
        ];
        $payload   = (string) wp_json_encode($event);
        $timestamp = (string) time();
        $sig       = hash_hmac('sha256', "{$timestamp}.{$payload}", $this->secret);

        $req = new WP_REST_Request('POST', '/dono/v1/webhooks/stripe');
        $req->set_header('content-type', 'application/json');
        $req->set_header('stripe_signature', "t={$timestamp},v1={$sig}");
        $req->set_body($payload);
        rest_do_request($req);
    }
}
