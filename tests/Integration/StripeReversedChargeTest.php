<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donations\Donation;
use Dono\Donations\DonationRepository;
use Dono\Donations\Refund;
use Dono\Donors\DonorService;
use Dono\Foundation\Plugin;
use Dono\Gateways\GatewayManager;
use Dono\Gateways\Stripe\StripeAccount;
use WP_REST_Request;

/**
 * Money that has gone back to the donor, or is still on its way back, must
 * never be banked as a completed donation.
 */
final class StripeReversedChargeTest extends IntegrationTestCase
{
    private string $secret = '';

    /** @var array<string,array<string,mixed>> Charge id => charge object the API answers with. */
    private array $charges = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->charges = [];
        $this->secret  = 'whsec_live_' . bin2hex(random_bytes(8));
        update_option('dono_gateway_config', [
            'stripe' => ['webhook_secret_live' => $this->secret],
        ]);

        $c       = Plugin::instance()->container;
        $account = $c->get(StripeAccount::class);
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

        add_filter('pre_http_request', function ($pre, $args, $url) {
            if (! is_string($url) || ! str_starts_with($url, 'https://api.stripe.com/')) {
                return $pre;
            }

            $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');
            $body = ['id' => 'unknown'];
            foreach ($this->charges as $id => $charge) {
                if ($path === '/v1/charges/' . $id) {
                    $body = $charge;
                }
            }

            return [
                'headers'  => [],
                'body'     => (string) wp_json_encode($body),
                'response' => ['code' => 200, 'message' => 'OK'],
                'cookies'  => [],
                'filename' => null,
            ];
        }, 10, 3);
    }

    public function test_a_refund_that_landed_first_stops_the_confirm_banking_the_money(): void
    {
        $donation = $this->stripeDonation('pending');
        $chargeId = 'ch_' . bin2hex(random_bytes(4));

        // Arrives while the row is still pending, so it is dropped: nothing
        // local can be refunded yet.
        $this->postWebhook('charge.refunded', [
            'id'              => $chargeId,
            'payment_intent'  => (string) $donation->gateway_intent_id,
            'amount'          => 5000,
            'amount_refunded' => 5000,
            'refunds'         => ['data' => [[
                'id'     => 're_' . bin2hex(random_bytes(4)),
                'amount' => 5000,
                'status' => 'succeeded',
            ]]],
        ]);
        $this->assertSame('pending', $this->reload($donation)->status, 'precondition: the refund found nothing to reduce');

        $this->charges[$chargeId] = [
            'id'              => $chargeId,
            'amount'          => 5000,
            'amount_refunded' => 5000,
            'refunded'        => true,
        ];

        $this->postWebhook('payment_intent.succeeded', $this->succeededIntent($donation, $chargeId));

        $fresh = $this->reload($donation);
        $this->assertNotSame('paid', $fresh->status, 'a refunded charge was banked as a completed donation');
        $this->assertNull($fresh->paid_at, 'and dated as money the org holds');
    }

    public function test_a_lost_dispute_that_landed_first_stops_the_confirm_banking_the_money(): void
    {
        $donation = $this->stripeDonation('pending');
        $chargeId = 'ch_' . bin2hex(random_bytes(4));

        $this->postWebhook('charge.dispute.funds_withdrawn', [
            'id'             => 'dp_' . bin2hex(random_bytes(4)),
            'payment_intent' => (string) $donation->gateway_intent_id,
            'amount'         => 5000,
            'reason'         => 'fraudulent',
        ]);
        $this->assertSame('pending', $this->reload($donation)->status, 'precondition: the dispute found nothing to reduce');

        $this->charges[$chargeId] = [
            'id'       => $chargeId,
            'amount'   => 5000,
            'disputed' => true,
            'dispute'  => ['id' => 'dp_x', 'amount' => 5000, 'status' => 'lost'],
        ];

        $this->postWebhook('payment_intent.succeeded', $this->succeededIntent($donation, $chargeId));

        $this->assertNotSame('paid', $this->reload($donation)->status, 'a charged-back donation was banked as completed');
    }

    public function test_an_untouched_charge_still_confirms(): void
    {
        $donation = $this->stripeDonation('pending');
        $chargeId = 'ch_' . bin2hex(random_bytes(4));

        $this->charges[$chargeId] = [
            'id'              => $chargeId,
            'amount'          => 5000,
            'amount_refunded' => 0,
            'refunded'        => false,
        ];

        $this->postWebhook('payment_intent.succeeded', $this->succeededIntent($donation, $chargeId));

        $this->assertSame('paid', $this->reload($donation)->status, 'an ordinary donation must still be banked');
    }

    public function test_a_dispute_that_was_won_does_not_block_the_confirm(): void
    {
        $donation = $this->stripeDonation('pending');
        $chargeId = 'ch_' . bin2hex(random_bytes(4));

        $this->charges[$chargeId] = [
            'id'       => $chargeId,
            'amount'   => 5000,
            'disputed' => true,
            'dispute'  => ['id' => 'dp_won', 'amount' => 5000, 'status' => 'won'],
        ];

        $this->postWebhook('payment_intent.succeeded', $this->succeededIntent($donation, $chargeId));

        $this->assertSame('paid', $this->reload($donation)->status, 'the money is back with the org, so it counts');
    }

    public function test_a_pending_refund_is_not_recorded_as_money_returned(): void
    {
        $donation = $this->stripeDonation('paid');

        $this->postWebhook('charge.refunded', [
            'id'              => 'ch_' . bin2hex(random_bytes(4)),
            'payment_intent'  => (string) $donation->gateway_intent_id,
            'amount'          => 5000,
            'amount_refunded' => 5000,
            'refunds'         => ['data' => [[
                'id'     => 're_pending_' . bin2hex(random_bytes(3)),
                'amount' => 5000,
                'status' => 'pending',
            ]]],
        ]);

        $fresh = $this->reload($donation);
        $this->assertSame('paid', $fresh->status, 'a bank refund Stripe has only submitted is not a refund yet');
        $this->assertSame(0, (int) $fresh->refunded_cents);
        $this->assertSame(0, (int) Refund::query()->where('donation_id', $donation->id)->count());
    }

    public function test_a_refund_that_reaches_succeeded_later_is_recorded(): void
    {
        $donation = $this->stripeDonation('paid');
        $refundId = 're_settled_' . bin2hex(random_bytes(3));

        $this->postWebhook('charge.refund.updated', [
            'id'             => $refundId,
            'payment_intent' => (string) $donation->gateway_intent_id,
            'charge'         => 'ch_' . bin2hex(random_bytes(4)),
            'amount'         => 5000,
            'status'         => 'succeeded',
            'reason'         => 'requested_by_customer',
        ]);

        $fresh = $this->reload($donation);
        $this->assertSame('refunded', $fresh->status, 'the settled refund has to reach the donation');
        $this->assertSame(5000, (int) $fresh->refunded_cents);

        $refund = Refund::query()->where('gateway_refund_id', $refundId)->get();
        $this->assertNotNull($refund);
        $this->assertSame('succeeded', (string) $refund->status);
    }

    public function test_a_refund_the_bank_rejected_is_put_back(): void
    {
        $donation = $this->stripeDonation('paid');
        $refundId = 're_failed_' . bin2hex(random_bytes(3));

        $this->postWebhook('charge.refunded', [
            'id'              => 'ch_' . bin2hex(random_bytes(4)),
            'payment_intent'  => (string) $donation->gateway_intent_id,
            'amount'          => 5000,
            'amount_refunded' => 5000,
            'refunds'         => ['data' => [[
                'id'     => $refundId,
                'amount' => 5000,
                'status' => 'succeeded',
            ]]],
        ]);
        $this->assertSame('refunded', $this->reload($donation)->status, 'precondition: the refund was recorded');

        $this->postWebhook('charge.refund.updated', [
            'id'             => $refundId,
            'payment_intent' => (string) $donation->gateway_intent_id,
            'amount'         => 5000,
            'status'         => 'failed',
            'failure_reason' => 'expired_or_canceled_card',
        ]);

        $fresh = $this->reload($donation);
        $this->assertSame('paid', $fresh->status, 'the org still holds the money, so the donation still counts');
        $this->assertSame(0, (int) $fresh->refunded_cents);
        $this->assertSame(
            'reversed',
            (string) Refund::query()->where('gateway_refund_id', $refundId)->get()->status
        );
    }

    /** @return array<string,mixed> */
    private function succeededIntent(Donation $donation, string $chargeId): array
    {
        return [
            'id'                   => (string) $donation->gateway_intent_id,
            'status'               => 'succeeded',
            'amount'               => 5000,
            'amount_received'      => 5000,
            'currency'             => 'usd',
            'latest_charge'        => $chargeId,
            'payment_method'       => 'pm_card',
            'payment_method_types' => ['card'],
            'livemode'             => true,
        ];
    }

    private function reload(Donation $donation): Donation
    {
        return Donation::query()->find('id', (int) $donation->id);
    }

    private function stripeDonation(string $status): Donation
    {
        $donor = Plugin::instance()->container
            ->get(DonorService::class)
            ->findOrCreate('reversal-' . uniqid() . '@example.test', ['first_name' => 'Rev', 'last_name' => 'Ersal']);

        $now = gmdate('Y-m-d H:i:s');
        $d   = Donation::make();
        $d->reference         = 'STRIPE-REV-' . strtoupper(bin2hex(random_bytes(4)));
        $d->status_token_hash = '';
        $d->donor_id          = (int) $donor->id;
        $d->amount_cents      = 5000;
        $d->net_cents         = 5000;
        $d->base_amount_cents = 5000;
        $d->base_currency     = 'USD';
        $d->fx_rate           = sprintf('%.8F', 1);
        $d->currency          = 'USD';
        $d->status            = $status;
        $d->frequency         = 'one_time';
        $d->gateway           = 'stripe';
        $d->gateway_intent_id = 'pi_rev_' . bin2hex(random_bytes(6));
        $d->is_test           = false;
        $d->paid_at           = $status === 'paid' ? $now : null;
        $d->created_at        = $now;
        $d->updated_at        = $now;
        $d->save();

        return $d;
    }

    /** @param array<string,mixed> $object */
    private function postWebhook(string $type, array $object): \WP_REST_Response
    {
        $event = [
            'id'       => 'evt_' . bin2hex(random_bytes(6)),
            'type'     => $type,
            'livemode' => true,
            'data'     => ['object' => $object],
        ];
        $payload   = (string) wp_json_encode($event);
        $timestamp = (string) time();
        $sig       = hash_hmac('sha256', "{$timestamp}.{$payload}", $this->secret);

        $req = new WP_REST_Request('POST', '/dono/v1/webhooks/stripe');
        $req->set_header('content-type', 'application/json');
        $req->set_header('stripe_signature', "t={$timestamp},v1={$sig}");
        $req->set_body($payload);

        return rest_do_request($req);
    }
}
