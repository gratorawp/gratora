<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donations\Donation;
use Dono\Donations\DonationRepository;
use Dono\Foundation\Plugin;
use Dono\Gateways\GatewayManager;
use Dono\Gateways\PayPal\PayPalAccount;
use Dono\Gateways\PayPal\PayPalApi;
use Dono\Gateways\PayPal\PayPalGateway;
use Dono\Gateways\PayPal\PayPalPlans;
use Dono\Recurring\RecurringPlan;
use Dono\Recurring\RecurringPlanRepository;
use WP_REST_Request;

/**
 * PayPal recurring: a Plan is provisioned per amount plus interval, the browser
 * opens a Subscription against it, and the server records it only after PayPal
 * confirms the subscription really belongs to that donation.
 *
 * The subtle one is the opening payment. PayPal bills the moment the donor
 * approves, so the first PAYMENT.SALE.COMPLETED must confirm the signup
 * donation already on file rather than banking a second, duplicate donation.
 */
final class PayPalSubscriptionTest extends IntegrationTestCase
{
    /** @var array<int,array{method:string,url:string,body:array<string,mixed>}> */
    private array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();

        update_option('dono_gateway_config', ['test_mode' => true]);
        update_option('dono_currency_locale', [
            'default_currency'     => 'USD',
            'supported_currencies' => ['USD'],
        ]);
        delete_option('dono_paypal_product');
        delete_option('dono_paypal_plans');

        $c = Plugin::instance()->container;
        $account = $c->get(PayPalAccount::class);
        $account->forget();
        $account->saveKeys(true, 'AeA1QIZ_client', 'EO422dn3_secret');
        $account->saveWebhookId(true, 'WH-TEST-1');

        $this->mockPayPal();

        $manager = $c->get(GatewayManager::class);
        if (! $manager->get('paypal')) {
            $manager->register(new PayPalGateway(
                $c->get(PayPalApi::class),
                $account,
                $c->get(DonationRepository::class),
                $c->get(\Dono\Donations\DonationService::class),
                $c->get(PayPalPlans::class),
                $c->get(RecurringPlanRepository::class),
                $c->get(\Dono\Foundation\Time\Clock::class),
            ));
        }
    }

    private function mockPayPal(): void
    {
        add_filter('pre_http_request', function ($pre, $args, $url) {
            if (! is_string($url) || ! str_contains($url, 'paypal.com')) return $pre;

            $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');
            $body = [];
            if (! empty($args['body']) && is_string($args['body'])) {
                $decoded = json_decode($args['body'], true);
                $body = is_array($decoded) ? $decoded : [];
            }
            $this->calls[] = ['method' => (string) ($args['method'] ?? 'POST'), 'url' => $url, 'body' => $body];

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
        if (str_contains($path, '/v1/oauth2/token'))          return ['access_token' => 'A21AAF_test', 'expires_in' => 32400];
        if (str_contains($path, '/verify-webhook-signature')) return ['verification_status' => 'SUCCESS'];
        if (str_contains($path, '/v1/catalogs/products'))     return ['id' => 'PROD-1'];
        if (str_contains($path, '/v1/billing/plans'))         return ['id' => 'P-PLAN-1'];
        if (str_contains($path, '/v1/billing/subscriptions/')) {
            return [
                'id'         => 'I-SUB-1',
                'status'     => 'ACTIVE',
                'custom_id'  => $this->currentReference,
                // A real subscription always names the plan it bills on, which
                // is what fixes the amount.
                'plan_id'    => $this->subscriptionPlanId,
                'subscriber' => ['payer_id' => 'PAYER-1'],
                'billing_info' => ['next_billing_time' => gmdate('Y-m-d H:i:s', time() + 2592000)],
            ];
        }
        return ['id' => 'OBJ-1'];
    }

    private string $currentReference = '';

    /** What PayPal reports the subscription bills on; the browser picks this. */
    private string $subscriptionPlanId = 'P-PLAN-1';

    public function test_a_subscription_on_another_plan_is_refused(): void
    {
        $reference = $this->createRecurringDonation(100000);

        // custom_id is the donor's own reference, so it passes the ownership
        // check. The subscription bills on a plan we never quoted for it: the
        // SDK runs in their browser, so choosing a cheaper plan costs nothing.
        $this->subscriptionPlanId = 'P-PLAN-CHEAP';

        $req = new WP_REST_Request('POST', '/dono/v1/gateways/paypal/subscription');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode([
            'reference'       => $reference,
            'subscription_id' => 'I-SUB-1',
        ]));

        $res = rest_get_server()->dispatch($req);

        $this->assertSame(403, $res->get_status(), 'the amount is not taken on trust');

        $donation = (new DonationRepository())->findByReference($reference);
        $this->assertNull($donation->recurring_plan_id, 'and no plan is recorded for money nobody is charging');
    }

    private function createRecurringDonation(int $amount = 2500, string $frequency = 'monthly'): string
    {
        $req = new WP_REST_Request('POST', '/dono/v1/donations');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode([
            'email'        => 'sub' . bin2hex(random_bytes(3)) . '@example.test',
            'amount_cents' => $amount,
            'currency'     => 'USD',
            'gateway'      => 'paypal',
            'frequency'    => $frequency,
            'profile'      => ['first_name' => 'Sub', 'last_name' => 'Scriber'],
        ]));
        $res  = rest_do_request($req);
        $data = $res->get_data();
        if (! isset($data['reference'])) {
            $this->fail('Recurring donation creation failed: ' . wp_json_encode($data));
        }
        $this->currentReference = $data['reference'];
        return $data['reference'];
    }

    private function recordSubscription(string $reference, string $subId = 'I-SUB-1'): \WP_REST_Response
    {
        $req = new WP_REST_Request('POST', '/dono/v1/gateways/paypal/subscription');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode([
            'reference' => $reference, 'subscription_id' => $subId,
        ]));
        return rest_do_request($req);
    }

    /** @param array<string,mixed> $resource */
    private function postWebhook(string $type, array $resource): \WP_REST_Response
    {
        $req = new WP_REST_Request('POST', '/dono/v1/webhooks/paypal');
        $req->set_header('content-type', 'application/json');
        foreach ([
            'paypal_transmission_id'   => 'tx-' . bin2hex(random_bytes(3)),
            'paypal_transmission_time' => gmdate('c'),
            'paypal_transmission_sig'  => 'sig',
            'paypal_cert_url'          => 'https://api.paypal.com/cert',
            'paypal_auth_algo'         => 'SHA256withRSA',
        ] as $k => $v) {
            $req->set_header($k, $v);
        }
        $req->set_body((string) wp_json_encode([
            'id'         => 'WH-EVT-' . bin2hex(random_bytes(4)),
            'event_type' => $type,
            'resource'   => $resource,
        ]));
        return rest_do_request($req);
    }

    private function findCall(string $needle): ?array
    {
        foreach ($this->calls as $c) {
            if (str_contains($c['url'], $needle)) return $c;
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

    public function test_recurring_intent_provisions_a_product_and_plan(): void
    {
        $this->createRecurringDonation();

        $this->assertNotNull($this->findCall('/v1/catalogs/products'), 'a product is provisioned');
        $plan = $this->findCall('/v1/billing/plans');
        $this->assertNotNull($plan, 'a plan is provisioned');

        $this->assertSame('PROD-1', $plan['body']['product_id'] ?? null);
        $this->assertSame('MONTH', $plan['body']['billing_cycles'][0]['frequency']['interval_unit'] ?? null);
        $this->assertSame('25.00', $plan['body']['billing_cycles'][0]['pricing_scheme']['fixed_price']['value'] ?? null);
        // 0 = bill forever, which is what an open-ended donation wants.
        $this->assertSame(0, $plan['body']['billing_cycles'][0]['total_cycles'] ?? null);

        // No order is opened for a subscription: PayPal bills from the plan.
        $this->assertNull($this->findCall('/v2/checkout/orders'));
    }

    /**
     * "Every 2 weeks" is a frequency donors can pick on the form. A local
     * interval table with a monthly default silently billed them once a month
     * instead, and stamped the wrong interval on the plan row as well.
     */
    public function test_biweekly_bills_every_two_weeks_not_monthly(): void
    {
        $reference = $this->createRecurringDonation(2500, 'biweekly');

        $plan = $this->findCall('/v1/billing/plans');
        $this->assertSame('WEEK', $plan['body']['billing_cycles'][0]['frequency']['interval_unit'] ?? null);
        $this->assertSame(2, $plan['body']['billing_cycles'][0]['frequency']['interval_count'] ?? null);

        $this->recordSubscription($reference);
        $local = $this->plans()->findBySubscriptionId('paypal', 'I-SUB-1');
        $this->assertSame('week', $local->interval_unit);
        $this->assertSame(2, (int) $local->interval_count);
    }

    /** A repeat of the same amount and interval must reuse the plan. */
    public function test_plans_are_reused_across_donors(): void
    {
        $this->createRecurringDonation(2500);
        $firstCount = count(array_filter($this->calls, fn ($c) => str_contains($c['url'], '/v1/billing/plans')));

        $this->createRecurringDonation(2500);
        $totalCount = count(array_filter($this->calls, fn ($c) => str_contains($c['url'], '/v1/billing/plans')));

        $this->assertSame(1, $firstCount, 'the first donation creates the plan');
        $this->assertSame(1, $totalCount, 'the second reuses it rather than creating a duplicate');
    }

    public function test_recording_a_subscription_creates_the_local_plan(): void
    {
        $reference = $this->createRecurringDonation();
        $res = $this->recordSubscription($reference);

        $this->assertSame(200, $res->get_status(), wp_json_encode($res->get_data()));

        $plan = $this->plans()->findBySubscriptionId('paypal', 'I-SUB-1');
        $this->assertNotNull($plan);
        $this->assertSame('active', $plan->status);
        $this->assertSame(2500, (int) $plan->amount_cents);
        $this->assertSame('month', $plan->interval_unit);
        $this->assertTrue((bool) $plan->is_test);
        // Counters stay at zero until money actually lands via the sale webhook.
        $this->assertSame(0, (int) $plan->payments_count);

        $donation = $this->donations()->findByReference($reference);
        $this->assertSame((int) $plan->id, (int) $donation->recurring_plan_id);
    }

    /**
     * The browser supplies the subscription id, so the server must prove it
     * belongs to this donation before recording anything against it.
     */
    public function test_a_subscription_belonging_to_another_donation_is_refused(): void
    {
        $reference = $this->createRecurringDonation();
        // Point the canned subscription at a different donation's reference.
        $this->currentReference = 'DONO-SOMEONE-ELSE';

        $res = $this->recordSubscription($reference);

        $this->assertSame(403, $res->get_status());
        $this->assertSame('dono_paypal_subscription_mismatch', $res->get_data()['code'] ?? null);
        $this->assertNull(
            $this->plans()->findBySubscriptionId('paypal', 'I-SUB-1'),
            'no plan is created for a subscription that is not ours'
        );
    }

    /**
     * The heart of it: PayPal charges on approval, so the opening sale belongs
     * to the signup donation. Treating it as a renewal would record the same
     * money twice.
     */
    public function test_the_opening_sale_confirms_the_signup_donation_instead_of_duplicating_it(): void
    {
        $reference = $this->createRecurringDonation();
        $this->recordSubscription($reference);

        $before = Donation::query()->where('gateway', 'paypal')->getAll();

        $res = $this->postWebhook('PAYMENT.SALE.COMPLETED', [
            'id'                   => 'SALE-1',
            'billing_agreement_id' => 'I-SUB-1',
            'amount'               => ['total' => '25.00', 'currency' => 'USD'],
        ]);
        $this->assertSame(200, $res->get_status(), wp_json_encode($res->get_data()));

        $after = Donation::query()->where('gateway', 'paypal')->getAll();
        $this->assertCount(
            count($before),
            $after,
            'the opening sale must not create a second donation'
        );

        $signup = $this->donations()->findByReference($reference);
        $this->assertSame('paid', $signup->status, 'the signup donation is the one that got paid');
        $this->assertSame('SALE-1', $signup->gateway_txn_id);

        $plan = $this->plans()->findBySubscriptionId('paypal', 'I-SUB-1');
        $this->assertSame(1, (int) $plan->payments_count, 'the first payment is counted once');
        $this->assertSame(2500, (int) $plan->total_paid_cents);
    }

    public function test_a_sale_arriving_before_the_plan_exists_is_redelivered(): void
    {
        // The donor approved and PayPal charged, but the browser POST that
        // writes the plan has not landed: a closed tab, a dropped connection,
        // or simply the webhook winning the race.
        $reference = $this->createRecurringDonation();

        $res = $this->postWebhook('PAYMENT.SALE.COMPLETED', [
            'id'                   => 'SALE-EARLY',
            'billing_agreement_id' => 'I-SUB-1',
            'amount'               => ['total' => '25.00', 'currency' => 'USD'],
        ]);

        $this->assertGreaterThanOrEqual(
            500,
            $res->get_status(),
            'a 200 tells PayPal the payment was accepted and it never comes back'
        );

        $signup = $this->donations()->findByReference($reference);
        $this->assertNotSame('paid', $signup->status, 'nothing was booked from an event we could not match');
    }

    public function test_the_redelivered_sale_lands_once_the_plan_exists(): void
    {
        $reference = $this->createRecurringDonation();

        // First delivery, too early.
        $this->postWebhook('PAYMENT.SALE.COMPLETED', [
            'id'                   => 'SALE-EARLY',
            'billing_agreement_id' => 'I-SUB-1',
            'amount'               => ['total' => '25.00', 'currency' => 'USD'],
        ]);

        // The browser catches up.
        $this->recordSubscription($reference);

        // PayPal redelivers, which is the whole point of the 5xx.
        $res = $this->postWebhook('PAYMENT.SALE.COMPLETED', [
            'id'                   => 'SALE-EARLY',
            'billing_agreement_id' => 'I-SUB-1',
            'amount'               => ['total' => '25.00', 'currency' => 'USD'],
        ]);

        $this->assertSame(200, $res->get_status(), wp_json_encode($res->get_data()));
        $this->assertSame('paid', $this->donations()->findByReference($reference)->status);

        $plan = $this->plans()->findBySubscriptionId('paypal', 'I-SUB-1');
        $this->assertSame(1, (int) $plan->payments_count, 'and it is still counted exactly once');
    }

    /** A later billing cycle is a genuine new donation. */
    public function test_a_later_sale_creates_a_renewal_donation(): void
    {
        $reference = $this->createRecurringDonation();
        $this->recordSubscription($reference);

        // Opening sale consumes the signup row.
        $this->postWebhook('PAYMENT.SALE.COMPLETED', [
            'id' => 'SALE-1', 'billing_agreement_id' => 'I-SUB-1',
            'amount' => ['total' => '25.00', 'currency' => 'USD'],
        ]);
        $afterFirst = count(Donation::query()->where('gateway', 'paypal')->getAll());

        // Next month.
        $this->postWebhook('PAYMENT.SALE.COMPLETED', [
            'id' => 'SALE-2', 'billing_agreement_id' => 'I-SUB-1',
            'amount' => ['total' => '25.00', 'currency' => 'USD'],
        ]);

        $afterSecond = count(Donation::query()->where('gateway', 'paypal')->getAll());
        $this->assertSame($afterFirst + 1, $afterSecond, 'the second cycle adds a renewal donation');

        $plan = $this->plans()->findBySubscriptionId('paypal', 'I-SUB-1');
        $this->assertSame(2, (int) $plan->payments_count);
        $this->assertSame(5000, (int) $plan->total_paid_cents);
    }

    /** PayPal redelivers events; a repeated renewal must not double-count. */
    public function test_a_redelivered_renewal_is_ignored(): void
    {
        $reference = $this->createRecurringDonation();
        $this->recordSubscription($reference);
        $this->postWebhook('PAYMENT.SALE.COMPLETED', [
            'id' => 'SALE-1', 'billing_agreement_id' => 'I-SUB-1',
            'amount' => ['total' => '25.00', 'currency' => 'USD'],
        ]);
        $this->postWebhook('PAYMENT.SALE.COMPLETED', [
            'id' => 'SALE-2', 'billing_agreement_id' => 'I-SUB-1',
            'amount' => ['total' => '25.00', 'currency' => 'USD'],
        ]);
        $countBefore = count(Donation::query()->where('gateway', 'paypal')->getAll());

        // Same sale id arrives again.
        $this->postWebhook('PAYMENT.SALE.COMPLETED', [
            'id' => 'SALE-2', 'billing_agreement_id' => 'I-SUB-1',
            'amount' => ['total' => '25.00', 'currency' => 'USD'],
        ]);

        $this->assertCount($countBefore, Donation::query()->where('gateway', 'paypal')->getAll());
        $plan = $this->plans()->findBySubscriptionId('paypal', 'I-SUB-1');
        $this->assertSame(2, (int) $plan->payments_count, 'a redelivery must not inflate the counter');
    }

    public function test_cancelling_at_paypal_marks_the_plan_cancelled(): void
    {
        $reference = $this->createRecurringDonation();
        $this->recordSubscription($reference);

        $res = $this->postWebhook('BILLING.SUBSCRIPTION.CANCELLED', ['id' => 'I-SUB-1']);

        $this->assertSame(200, $res->get_status());
        $plan = $this->plans()->findBySubscriptionId('paypal', 'I-SUB-1');
        $this->assertSame('cancelled', $plan->status);
        $this->assertNotNull($plan->cancelled_at);
    }

    /** Cancelling from Dono is idempotent: PayPal errors on an ended sub. */
    public function test_cancel_is_idempotent(): void
    {
        $reference = $this->createRecurringDonation();
        $this->recordSubscription($reference);
        $plan = $this->plans()->findBySubscriptionId('paypal', 'I-SUB-1');

        $gateway = Plugin::instance()->container->get(GatewayManager::class)->require('paypal');

        add_filter('pre_http_request', static function ($pre, $args, $url) {
            if (is_string($url) && str_contains($url, '/cancel')) {
                return [
                    'headers'  => [],
                    'body'     => (string) wp_json_encode(['name' => 'SUBSCRIPTION_STATUS_INVALID']),
                    'response' => ['code' => 422, 'message' => 'Unprocessable'],
                    'cookies'  => [], 'filename' => null,
                ];
            }
            return $pre;
        }, 5, 3);

        $gateway->cancelSubscription($plan, 'donor asked');
        $this->assertTrue(true, 'cancelling an already-ended subscription does not throw');
    }
}
