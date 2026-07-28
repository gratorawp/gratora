<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donations\Donation;
use Dono\Donations\DonationRepository;
use Dono\Donations\DonationService;
use Dono\Foundation\Plugin;
use Dono\Foundation\Time\Clock;
use Dono\Gateways\GatewayManager;
use Dono\Gateways\Razorpay\RazorpayAccount;
use Dono\Gateways\Razorpay\RazorpayApi;
use Dono\Gateways\Razorpay\RazorpayGateway;
use Dono\Gateways\Razorpay\RazorpayPlans;
use Dono\Gateways\Razorpay\RazorpaySignature;
use Dono\Recurring\RecurringPlanRepository;
use WP_REST_Request;

/**
 * Razorpay recurring: a Plan and a Subscription are provisioned server-side,
 * the browser only reports the signature Checkout gave it, and the first
 * subscription.charged belongs to the signup donation rather than being banked
 * as a second gift.
 */
final class RazorpaySubscriptionTest extends IntegrationTestCase
{
    private const KEY_SECRET     = 'test_secret_abcd';
    private const WEBHOOK_SECRET = 'whsec_razor';

    /** @var array<int,array{method:string,url:string,body:array<string,mixed>}> */
    private array $calls = [];

    /**
     * Subscription ids must be distinct per creation: donations carry a unique
     * index on gateway_intent_id, so reusing one id would be rejected by the
     * database rather than by anything under test.
     */
    private int $subSeq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        update_option('dono_gateway_config', ['test_mode' => true]);
        update_option('dono_currency_locale', [
            'default_currency'     => 'INR',
            'supported_currencies' => ['INR'],
        ]);
        delete_option('dono_razorpay_plans');

        $c = Plugin::instance()->container;
        $account = $c->get(RazorpayAccount::class);
        $account->forget();
        $account->saveKeys(true, 'rzp_test_abc123', self::KEY_SECRET);
        $account->saveWebhookSecret(true, self::WEBHOOK_SECRET);

        $this->mockRazorpay();

        $manager = $c->get(GatewayManager::class);
        if (! $manager->get('razorpay')) {
            $manager->register(new RazorpayGateway(
                $c->get(RazorpayApi::class),
                $account,
                $c->get(DonationRepository::class),
                $c->get(DonationService::class),
                $c->get(RazorpayPlans::class),
                $c->get(RecurringPlanRepository::class),
                $c->get(Clock::class),
            ));
        }
    }

    private function mockRazorpay(): void
    {
        add_filter('pre_http_request', function ($pre, $args, $url) {
            if (! is_string($url) || ! str_contains($url, 'api.razorpay.com')) return $pre;

            $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');
            $body = [];
            if (! empty($args['body']) && is_string($args['body'])) {
                $decoded = json_decode($args['body'], true);
                $body = is_array($decoded) ? $decoded : [];
            }

            $this->calls[] = [
                'method' => (string) ($args['method'] ?? 'POST'),
                'url'    => $url,
                'body'   => $body,
            ];

            return [
                'headers'  => [],
                'body'     => (string) wp_json_encode($this->cannedResponse($path)),
                'response' => ['code' => 200, 'message' => 'OK'],
                'cookies'  => [], 'filename' => null,
            ];
        }, 10, 3);
    }

    /** @return array<string,mixed> */
    private function cannedResponse(string $path): array
    {
        if (str_contains($path, '/v1/plans')) {
            return ['id' => 'plan_1', 'entity' => 'plan'];
        }
        if (str_contains($path, '/v1/subscriptions')) {
            // A read of an existing subscription keeps its id; a create mints
            // the next one.
            preg_match('#/v1/subscriptions/(sub_[0-9]+)#', $path, $m);
            $id = $m[1] ?? 'sub_' . ++$this->subSeq;

            return [
                'id'          => $id,
                'entity'      => 'subscription',
                'status'      => 'active',
                'plan_id'     => 'plan_1',
                'customer_id' => 'cust_1',
                'charge_at'   => 1800000000,
            ];
        }
        return ['id' => 'obj_1'];
    }

    private function createDonation(string $frequency = 'monthly', int $amount = 100000): string
    {
        $req = new WP_REST_Request('POST', '/dono/v1/donations');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode([
            'email'        => 'sub@example.test',
            'amount_cents' => $amount,
            'currency'     => 'INR',
            'gateway'      => 'razorpay',
            'frequency'    => $frequency,
            'profile'      => ['first_name' => 'Sub', 'last_name' => 'Scriber'],
        ]));
        $res  = rest_do_request($req);
        $data = $res->get_data();
        if (! isset($data['reference'])) {
            $this->fail('Donation creation failed: ' . wp_json_encode($data));
        }
        return $data['reference'];
    }

    private function recordSubscription(string $reference, ?string $signature = null): \WP_REST_Response
    {
        $signature ??= RazorpaySignature::forSubscription('pay_first', 'sub_1', self::KEY_SECRET);

        $req = new WP_REST_Request('POST', '/dono/v1/gateways/razorpay/subscription');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode([
            'reference'  => $reference,
            'payment_id' => 'pay_first',
            'signature'  => $signature,
        ]));
        return rest_do_request($req);
    }

    /** @param array<string,mixed> $event */
    private function webhook(array $event): \WP_REST_Response
    {
        $raw = (string) wp_json_encode($event);

        $req = new WP_REST_Request('POST', '/dono/v1/webhooks/razorpay');
        $req->set_header('content-type', 'application/json');
        $req->set_header('x-razorpay-signature', RazorpaySignature::forWebhook($raw, self::WEBHOOK_SECRET));
        $req->set_header('x-razorpay-event-id', 'evt_' . md5($raw));
        $req->set_body($raw);
        return rest_do_request($req);
    }

    /** @param array<string,mixed> $payment */
    private function chargedEvent(array $payment): array
    {
        return [
            'event'   => 'subscription.charged',
            'payload' => [
                'subscription' => ['entity' => ['id' => 'sub_1', 'status' => 'active']],
                'payment'      => ['entity' => $payment],
            ],
        ];
    }

    /** @return array<string,mixed>|null */
    private function findCall(string $needle): ?array
    {
        foreach ($this->calls as $call) {
            if (str_contains($call['url'], $needle)) return $call;
        }
        return null;
    }

    private function donations(): DonationRepository
    {
        return Plugin::instance()->container->get(DonationRepository::class);
    }

    private function plans(): RecurringPlanRepository
    {
        return Plugin::instance()->container->get(RecurringPlanRepository::class);
    }

    public function test_create_intent_provisions_a_plan_and_a_subscription(): void
    {
        $reference = $this->createDonation();

        $plan = $this->findCall('/v1/plans');
        $this->assertNotNull($plan, 'a plan was created');
        $this->assertSame('monthly', $plan['body']['period'] ?? null);
        $this->assertSame(1, $plan['body']['interval'] ?? null);
        $this->assertSame(100000, $plan['body']['item']['amount'] ?? null);

        $sub = $this->findCall('/v1/subscriptions');
        $this->assertNotNull($sub, 'a subscription was created');
        $this->assertSame('plan_1', $sub['body']['plan_id'] ?? null);
        $this->assertSame($reference, $sub['body']['notes']['dono_reference'] ?? null);
        // Razorpay will not take an open-ended subscription, so ten years of
        // monthly cycles is what gets asked for.
        $this->assertSame(120, $sub['body']['total_count'] ?? null);

        $donation = $this->donations()->findByReference($reference);
        $this->assertSame('sub_1', $donation->gateway_intent_id);
    }

    /** Quarterly is monthly with an interval of 3, not its own period. */
    public function test_quarterly_maps_to_a_three_month_interval(): void
    {
        $this->createDonation('quarterly');

        $plan = $this->findCall('/v1/plans');
        $this->assertSame('monthly', $plan['body']['period'] ?? null);
        $this->assertSame(3, $plan['body']['interval'] ?? null);

        $sub = $this->findCall('/v1/subscriptions');
        $this->assertSame(40, $sub['body']['total_count'] ?? null, 'ten years of quarters');
    }

    /**
     * Biweekly is a real frequency on the form. Mapping it to monthly would
     * silently bill donors half as often as they chose.
     */
    public function test_biweekly_maps_to_a_two_week_interval(): void
    {
        $this->createDonation('biweekly');

        $plan = $this->findCall('/v1/plans');
        $this->assertSame('weekly', $plan['body']['period'] ?? null);
        $this->assertSame(2, $plan['body']['interval'] ?? null);
    }

    /** A repeat of the same amount and interval must reuse the plan. */
    public function test_plans_are_reused_across_donors(): void
    {
        $this->createDonation();
        $this->calls = [];
        $this->createDonation();

        $this->assertNull($this->findCall('/v1/plans'), 'no second plan was created');
        $this->assertNotNull($this->findCall('/v1/subscriptions'), 'but a second subscription was');
    }

    public function test_recording_a_subscription_creates_the_recurring_plan(): void
    {
        $reference = $this->createDonation();

        $res = $this->recordSubscription($reference);

        $this->assertSame(200, $res->get_status(), wp_json_encode($res->get_data()));

        $plan = $this->plans()->findBySubscriptionId('razorpay', 'sub_1');
        $this->assertNotNull($plan);
        $this->assertSame('active', $plan->status);
        $this->assertSame(100000, (int) $plan->amount_cents);
        $this->assertSame('month', $plan->interval_unit);
        $this->assertSame(1, (int) $plan->interval_count);
        // Counters stay at zero until money actually lands via the webhook.
        $this->assertSame(0, (int) $plan->payments_count);

        $donation = $this->donations()->findByReference($reference);
        $this->assertSame((int) $plan->id, (int) $donation->recurring_plan_id);
        $this->assertSame('pending', $donation->status, 'the charge webhook confirms it, not this route');
    }

    /** The route is public, so an unverifiable payload must record nothing. */
    public function test_a_forged_subscription_signature_is_refused(): void
    {
        $reference = $this->createDonation();

        $res = $this->recordSubscription($reference, 'deadbeef');

        $this->assertSame(403, $res->get_status());
        $this->assertNull($this->plans()->findBySubscriptionId('razorpay', 'sub_1'));
    }

    /**
     * Signing the ids in order form (parent first) is the natural mistake, and
     * it must not pass.
     */
    public function test_a_subscription_signature_in_order_form_is_refused(): void
    {
        $reference = $this->createDonation();

        $wrong = RazorpaySignature::forOrder('sub_1', 'pay_first', self::KEY_SECRET);
        $res   = $this->recordSubscription($reference, $wrong);

        $this->assertSame(403, $res->get_status());
    }

    public function test_recording_twice_is_a_no_op(): void
    {
        $reference = $this->createDonation();

        $this->recordSubscription($reference);
        $res = $this->recordSubscription($reference);

        $this->assertSame(200, $res->get_status());
        $this->assertCount(
            1,
            \Dono\Recurring\RecurringPlan::query()->where('gateway_subscription_id', 'sub_1')->getAll()
        );
    }

    /**
     * Razorpay charges the first instalment itself and reports it as
     * subscription.charged. Treating that as a renewal would bank the signup
     * money twice.
     */
    public function test_the_first_charge_confirms_the_signup_donation(): void
    {
        $reference = $this->createDonation();
        $this->recordSubscription($reference);

        $this->webhook($this->chargedEvent([
            'id'       => 'pay_first',
            'amount'   => 100000,
            'currency' => 'INR',
            'status'   => 'captured',
            'method'   => 'upi',
        ]));

        $all = Donation::query()->where('gateway', 'razorpay')->getAll();
        $this->assertCount(1, $all, 'no second donation was created for the signup charge');
        $this->assertSame('paid', $all[0]->status);
        $this->assertSame($reference, $all[0]->reference);

        $plan = $this->plans()->findBySubscriptionId('razorpay', 'sub_1');
        $this->assertSame(1, (int) $plan->payments_count);
        $this->assertSame(100000, (int) $plan->total_paid_cents);
    }

    public function test_a_later_charge_creates_a_renewal(): void
    {
        $reference = $this->createDonation();
        $this->recordSubscription($reference);

        // First charge closes out the signup.
        $this->webhook($this->chargedEvent([
            'id' => 'pay_first', 'amount' => 100000, 'currency' => 'INR',
            'status' => 'captured', 'method' => 'upi',
        ]));
        // Next month.
        $this->webhook($this->chargedEvent([
            'id' => 'pay_second', 'amount' => 100000, 'currency' => 'INR',
            'status' => 'captured', 'method' => 'upi',
        ]));

        $all = Donation::query()->where('gateway', 'razorpay')->getAll();
        $this->assertCount(2, $all, 'the renewal is its own donation');

        $plan = $this->plans()->findBySubscriptionId('razorpay', 'sub_1');
        $this->assertSame(2, (int) $plan->payments_count);
        $this->assertSame(200000, (int) $plan->total_paid_cents);
    }

    /** Razorpay redelivers events; a replay must not inflate the counters. */
    public function test_a_redelivered_renewal_does_not_double_count(): void
    {
        $reference = $this->createDonation();
        $this->recordSubscription($reference);

        $this->webhook($this->chargedEvent([
            'id' => 'pay_first', 'amount' => 100000, 'currency' => 'INR',
            'status' => 'captured', 'method' => 'upi',
        ]));

        $renewal = $this->chargedEvent([
            'id' => 'pay_second', 'amount' => 100000, 'currency' => 'INR',
            'status' => 'captured', 'method' => 'upi',
        ]);
        $this->webhook($renewal);
        $this->webhook($renewal);

        $this->assertCount(2, Donation::query()->where('gateway', 'razorpay')->getAll());

        $plan = $this->plans()->findBySubscriptionId('razorpay', 'sub_1');
        $this->assertSame(2, (int) $plan->payments_count, 'the replay did not bump the counter');
        $this->assertSame(200000, (int) $plan->total_paid_cents);
    }

    public function test_a_cancellation_webhook_ends_the_plan(): void
    {
        $reference = $this->createDonation();
        $this->recordSubscription($reference);

        $this->webhook([
            'event'   => 'subscription.cancelled',
            'payload' => ['subscription' => ['entity' => ['id' => 'sub_1', 'status' => 'cancelled']]],
        ]);

        $plan = $this->plans()->findBySubscriptionId('razorpay', 'sub_1');
        $this->assertSame('cancelled', $plan->status);
    }

    /** Halted means Razorpay gave up after repeated failures; it ends too. */
    public function test_a_halted_subscription_ends_the_plan(): void
    {
        $reference = $this->createDonation();
        $this->recordSubscription($reference);

        $this->webhook([
            'event'   => 'subscription.halted',
            'payload' => ['subscription' => ['entity' => ['id' => 'sub_1', 'status' => 'halted']]],
        ]);

        $plan = $this->plans()->findBySubscriptionId('razorpay', 'sub_1');
        $this->assertSame('cancelled', $plan->status);
    }

    public function test_cancelling_from_dono_calls_razorpay(): void
    {
        $reference = $this->createDonation();
        $this->recordSubscription($reference);
        $this->calls = [];

        $plan    = $this->plans()->findBySubscriptionId('razorpay', 'sub_1');
        $gateway = Plugin::instance()->container->get(GatewayManager::class)->require('razorpay');
        $gateway->cancelSubscription($plan, 'Donor asked');

        $this->assertNotNull($this->findCall('/v1/subscriptions/sub_1/cancel'));
    }
}
