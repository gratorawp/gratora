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
 * What a reversal is allowed to do to a donation. Money that came back in full
 * stops the banking, but it is not a decline: the admin re-poll must not write
 * 'failed' over a donor who was charged. A slice coming back is not a reason to
 * lose the rest, and a dispute inquiry is not money leaving at all.
 */
final class StripeReversedConfirmSeamTest extends IntegrationTestCase
{
    private string $secret = '';

    /** @var array<string,array<string,mixed>> Charge id => charge object the API answers with. */
    private array $charges = [];

    /** @var array<string,array<string,mixed>> Intent id => intent object the API answers with. */
    private array $intents = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->charges = [];
        $this->intents = [];
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
            foreach ($this->intents as $id => $intent) {
                if ($path === '/v1/payment_intents/' . $id) {
                    $body = $intent;
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

    public function test_the_admin_re_poll_never_fails_a_donation_the_donor_was_charged_for(): void
    {
        $donation = $this->stripeDonation('pending');
        $chargeId = $this->charge($donation, ['amount_refunded' => 5000, 'refunded' => true]);
        $this->intents[(string) $donation->gateway_intent_id] = $this->succeededIntent($donation, $chargeId);

        $res = $this->adminConfirm($donation);

        $fresh = $this->reload($donation);
        $this->assertNotSame('failed', $fresh->status, 'a charged donor was told the payment was declined');
        $this->assertNull($fresh->failure_reason, 'and the decline was written on the row');
        $this->assertNotContains(
            'donation.failed',
            $this->eventTypesFor($donation),
            'and dono.donation.failed fired on a payment that was taken'
        );
        $this->assertNotSame(200, $res->get_status(), 'reversed money must not be banked either');
    }

    public function test_a_slice_coming_back_still_banks_the_rest(): void
    {
        $donation = $this->stripeDonation('pending');
        $chargeId = $this->charge($donation, ['amount_refunded' => 500]);

        $this->postWebhook('payment_intent.succeeded', $this->succeededIntent($donation, $chargeId));

        $fresh = $this->reload($donation);
        $this->assertSame('paid', $fresh->status, 'a partial refund lost the whole donation');
        $this->assertNotNull($fresh->paid_at);
    }

    public function test_a_dispute_inquiry_is_not_money_leaving(): void
    {
        $donation = $this->stripeDonation('pending');
        $chargeId = $this->charge($donation, [
            'disputed' => true,
            'dispute'  => ['id' => 'dp_inq', 'amount' => 5000, 'status' => 'warning_needs_response'],
        ]);

        $this->postWebhook('payment_intent.succeeded', $this->succeededIntent($donation, $chargeId));

        $this->assertSame(
            'paid',
            $this->reload($donation)->status,
            'an inquiry Stripe has taken no money for blocked the banking'
        );
    }

    public function test_a_dispute_under_review_is_not_money_leaving(): void
    {
        $donation = $this->stripeDonation('pending');
        $chargeId = $this->charge($donation, [
            'disputed' => true,
            'dispute'  => ['id' => 'dp_rev', 'amount' => 5000, 'status' => 'warning_under_review'],
        ]);

        $this->postWebhook('payment_intent.succeeded', $this->succeededIntent($donation, $chargeId));

        $this->assertSame('paid', $this->reload($donation)->status);
    }

    public function test_a_full_reversal_still_stops_the_banking(): void
    {
        $donation = $this->stripeDonation('pending');
        $chargeId = $this->charge($donation, ['amount_refunded' => 5000, 'refunded' => true]);

        $this->postWebhook('payment_intent.succeeded', $this->succeededIntent($donation, $chargeId));

        $fresh = $this->reload($donation);
        $this->assertNotSame('paid', $fresh->status, 'money already back with the donor was banked as raised');
        $this->assertNull($fresh->paid_at);
    }

    public function test_a_lost_dispute_still_stops_the_banking(): void
    {
        $donation = $this->stripeDonation('pending');
        $chargeId = $this->charge($donation, [
            'disputed' => true,
            'dispute'  => ['id' => 'dp_lost', 'amount' => 5000, 'status' => 'lost'],
        ]);

        $this->postWebhook('payment_intent.succeeded', $this->succeededIntent($donation, $chargeId));

        $this->assertNotSame('paid', $this->reload($donation)->status);
    }

    /** @param array<string,mixed> $extra */
    /**
     * With automatic_payment_methods on, payment_method_types is every type
     * eligible for the intent in Stripe's own order, not the one used. Reading
     * its first entry stamps card on every SEPA, iDEAL and Bacs donation, and
     * the admin list, the CSV export and any method breakdown then report card
     * for the whole non-card set, which settles on a different timeline and can
     * still bounce.
     */
    public function test_the_method_recorded_is_the_one_the_donor_paid_with(): void
    {
        $donation = $this->stripeDonation('pending');
        $chargeId = $this->charge($donation, [
            'payment_method_details' => [
                'type'       => 'sepa_debit',
                'sepa_debit' => ['last4' => '3000'],
            ],
        ]);

        $intent = $this->succeededIntent($donation, $chargeId);
        // Stripe lists card first for this intent; the donor paid by SEPA.
        $intent['payment_method_types'] = ['card', 'sepa_debit', 'link'];
        $this->postWebhook('payment_intent.succeeded', $intent);

        $fresh = $this->reload($donation);
        $this->assertSame('paid', $fresh->status);
        $this->assertSame('sepa_debit', (string) $fresh->payment_method);
        $this->assertSame('3000', (string) $fresh->payment_method_last4);
    }

    /** A card donation still reports its brand and last4 from the same place. */
    public function test_a_card_donation_carries_its_brand_and_last_four(): void
    {
        $donation = $this->stripeDonation('pending');
        $chargeId = $this->charge($donation, [
            'payment_method_details' => [
                'type' => 'card',
                'card' => ['brand' => 'visa', 'last4' => '4242'],
            ],
        ]);

        $this->postWebhook('payment_intent.succeeded', $this->succeededIntent($donation, $chargeId));

        $fresh = $this->reload($donation);
        $this->assertSame('card', (string) $fresh->payment_method);
        $this->assertSame('visa', (string) $fresh->payment_method_brand);
        $this->assertSame('4242', (string) $fresh->payment_method_last4);
    }

    private function charge(Donation $donation, array $extra): string
    {
        $chargeId = 'ch_' . bin2hex(random_bytes(4));

        $this->charges[$chargeId] = array_merge([
            'id'              => $chargeId,
            'amount'          => 5000,
            'amount_refunded' => 0,
        ], $extra);

        return $chargeId;
    }

    /** @return array<int,string> */
    private function eventTypesFor(Donation $donation): array
    {
        return array_column(
            (array) self::$wpdb->get_results(self::$wpdb->prepare(
                'SELECT type FROM ' . self::$prefix . 'dono_events WHERE donation_id = %d ORDER BY id',
                (int) $donation->id
            )),
            'type'
        );
    }

    private function adminConfirm(Donation $donation): \WP_REST_Response
    {
        $req = new WP_REST_Request('POST', "/dono/v1/donations/{$donation->reference}/confirm");
        $req->set_header('content-type', 'application/json');
        $req->set_body('{}');

        return rest_do_request($req);
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
            ->findOrCreate('seam-' . uniqid() . '@example.test', ['first_name' => 'Seam', 'last_name' => 'Probe']);

        $now = gmdate('Y-m-d H:i:s');
        $d   = Donation::make();
        $d->reference         = 'STRIPE-SEAM-' . strtoupper(bin2hex(random_bytes(4)));
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
        $d->gateway_intent_id = 'pi_seam_' . bin2hex(random_bytes(6));
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
