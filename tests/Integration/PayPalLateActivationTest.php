<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Analytics\Event;
use Dono\Campaigns\Campaign;
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
 * BILLING.SUBSCRIPTION.ACTIVATED arriving after the plan is closed.
 *
 * PayPal does not promise event order and redelivers anything it did not get a
 * 2xx for, for up to three days, so an activation can land behind a
 * cancellation the donor made minutes after signing up. A cancellation is
 * terminal at PayPal, so such an activation is never a fact about the
 * subscription: acting on it counts a dead subscription toward MRR and toward
 * the archive dialog's active-recurring figure, and lets the next cancellation
 * win the transition a second time and email the donor twice.
 */
final class PayPalLateActivationTest extends IntegrationTestCase
{
    private string $currentReference = '';

    private string $subscriptionPlanId = 'P-PLAN-1';

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

        $c       = Plugin::instance()->container;
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
                $c->get(\Dono\Gateways\PayPal\PayPalPlanRecorder::class),
            ));
        }
    }

    /** When set, only a delivery naming this webhook id verifies. */
    private ?string $onlyWebhookIdVerifies = null;

    private function mockPayPal(): void
    {
        add_filter('pre_http_request', function ($pre, $args, $url) {
            if (! is_string($url) || ! str_contains($url, 'paypal.com')) return $pre;

            $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');
            $sent = (array) json_decode((string) ($args['body'] ?? ''), true);

            return [
                'headers'  => [],
                'body'     => (string) wp_json_encode($this->cannedResponse($path, $sent)),
                'response' => ['code' => 200, 'message' => 'OK'],
                'cookies'  => [], 'filename' => null,
            ];
        }, 10, 3);
    }

    /**
     * @param  array<string,mixed> $sent the request body PayPal was called with
     * @return array<string,mixed>
     */
    private function cannedResponse(string $path, array $sent = []): array
    {
        if (str_contains($path, '/v1/oauth2/token'))          return ['access_token' => 'A21AAF_test', 'expires_in' => 32400];
        if (str_contains($path, '/verify-webhook-signature')) {
            // A real signature verifies against one endpoint's id, so a
            // sandbox-signed delivery fails the live check and passes the test
            // one. Unconditional SUCCESS makes live always win, which is the
            // one state the mixed-mode question cannot be asked in.
            $ok = $this->onlyWebhookIdVerifies === null
                || (string) ($sent['webhook_id'] ?? '') === $this->onlyWebhookIdVerifies;

            return ['verification_status' => $ok ? 'SUCCESS' : 'FAILURE'];
        }
        if (str_contains($path, '/v1/catalogs/products'))     return ['id' => 'PROD-1'];
        if (str_contains($path, '/v1/billing/plans'))         return ['id' => 'P-PLAN-1'];
        if (str_contains($path, '/v1/billing/subscriptions/')) {
            $asked = rawurldecode(basename($path));
            return [
                'id'           => $asked !== '' ? $asked : 'I-SUB-ORD',
                'status'       => 'ACTIVE',
                'custom_id'    => $this->currentReference,
                'plan_id'      => $this->subscriptionPlanId,
                'subscriber'   => ['payer_id' => 'PAYER-1'],
                'billing_info' => ['next_billing_time' => gmdate('Y-m-d H:i:s', time() + 2592000)],
            ];
        }

        return ['id' => 'OBJ-1'];
    }

    private function createRecurringDonation(int $amount = 2500): string
    {
        $req = new WP_REST_Request('POST', '/dono/v1/donations');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode([
            'email'        => 'late' . bin2hex(random_bytes(3)) . '@example.test',
            'amount_cents' => $amount,
            'currency'     => 'USD',
            'gateway'      => 'paypal',
            'frequency'    => 'monthly',
            'profile'      => ['first_name' => 'Late', 'last_name' => 'Event'],
        ]));
        $data = rest_do_request($req)->get_data();
        if (! isset($data['reference'])) {
            $this->fail('Recurring donation creation failed: ' . wp_json_encode($data));
        }
        $this->currentReference = $data['reference'];

        return $data['reference'];
    }

    private function recordSubscription(string $reference, string $subId): void
    {
        $req = new WP_REST_Request('POST', '/dono/v1/gateways/paypal/subscription');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode([
            'reference'       => $reference,
            'subscription_id' => $subId,
            'status_token'    => $this->stampStatusToken($reference),
        ]));
        $res = rest_do_request($req);
        $this->assertSame(200, $res->get_status(), (string) wp_json_encode($res->get_data()));
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

    private function plan(string $subId): RecurringPlan
    {
        $plan = Plugin::instance()->container
            ->get(RecurringPlanRepository::class)
            ->findBySubscriptionId('paypal', $subId);

        $this->assertNotNull($plan, "no plan for {$subId}");

        return $plan;
    }

    /** @param array<string,mixed> $extra */
    private function activation(string $subId, array $extra = []): array
    {
        return [
            'id'         => $subId,
            'status'     => 'ACTIVE',
            'custom_id'  => $this->currentReference,
            'plan_id'    => $this->subscriptionPlanId,
            'subscriber' => ['payer_id' => 'PAYER-1'],
        ] + $extra;
    }

    public function test_a_late_activation_does_not_reopen_a_cancelled_plan(): void
    {
        $reference = $this->createRecurringDonation();
        $this->recordSubscription($reference, 'I-SUB-ORD');

        $this->postWebhook('BILLING.SUBSCRIPTION.CANCELLED', ['id' => 'I-SUB-ORD']);
        $cancelled = $this->plan('I-SUB-ORD');
        $this->assertSame('cancelled', $cancelled->status);
        $this->assertNotNull($cancelled->cancelled_at);

        $res = $this->postWebhook('BILLING.SUBSCRIPTION.ACTIVATED', $this->activation('I-SUB-ORD'));
        $this->assertSame(200, $res->get_status(), (string) wp_json_encode($res->get_data()));

        $after = $this->plan('I-SUB-ORD');
        $this->assertSame(
            'cancelled',
            $after->status,
            'cancelled is terminal at PayPal; a redelivered activation is not a fact'
        );
        $this->assertSame($cancelled->cancelled_at, $after->cancelled_at);
    }

    /**
     * The money-visible half: activeForCampaign feeds the dashboard's MRR and
     * the archive dialog, and both read status alone.
     */
    public function test_a_reopened_plan_does_not_come_back_into_the_active_recurring_figures(): void
    {
        // Those figures count live plans only, so this one case runs live end
        // to end: live keys, a live webhook id for the signature to verify
        // against, and the global test switch off.
        $account = Plugin::instance()->container->get(PayPalAccount::class);
        $account->saveKeys(false, 'AeA1QIZ_live', 'EO422dn3_live');
        $account->saveWebhookId(false, 'WH-LIVE-1');
        update_option('dono_gateway_config', ['test_mode' => false]);

        $reference = $this->createRecurringDonation(2500);
        $this->recordSubscription($reference, 'I-SUB-MRR');

        $now      = gmdate('Y-m-d H:i:s');
        $campaign = Campaign::make();
        $campaign->title      = 'Late activation';
        $campaign->slug       = 'late-activation-' . uniqid();
        $campaign->status     = 'published';
        $campaign->currency   = 'USD';
        $campaign->created_at = $now;
        $campaign->updated_at = $now;
        $campaign->save();

        $plan = $this->plan('I-SUB-MRR');
        $this->assertFalse((bool) $plan->is_test, 'the plan is live money');
        RecurringPlan::query()
            ->where('id', (int) $plan->id)
            ->update(['campaign_id' => (int) $campaign->id]);

        $repo   = Plugin::instance()->container->get(RecurringPlanRepository::class);
        $active = $repo->activeForCampaign((int) $campaign->id);
        $this->assertSame(1, $active['count'], 'the live plan is counted while it renews');
        $this->assertSame(2500, $active['mrr_cents'], 'and its monthly value is the figure shown');

        $this->postWebhook('BILLING.SUBSCRIPTION.CANCELLED', ['id' => 'I-SUB-MRR']);
        $this->assertSame(0, $repo->activeForCampaign((int) $campaign->id)['count']);

        $this->postWebhook('BILLING.SUBSCRIPTION.ACTIVATED', $this->activation('I-SUB-MRR'));

        $after = $repo->activeForCampaign((int) $campaign->id);
        $this->assertSame(
            0,
            $after['count'],
            'a subscription PayPal has ended must not be counted as active again'
        );
        $this->assertSame(0, $after['mrr_cents'], 'nor reported as monthly income');
    }

    public function test_the_donor_is_not_told_twice_about_one_cancellation(): void
    {
        $reference = $this->createRecurringDonation();
        $this->recordSubscription($reference, 'I-SUB-TWICE');

        $announced = 0;
        add_action('dono.recurring.cancelled', static function () use (&$announced): void { $announced++; });

        $this->postWebhook('BILLING.SUBSCRIPTION.CANCELLED', ['id' => 'I-SUB-TWICE']);
        $this->assertSame(1, $announced, 'the cancellation is announced once');

        $this->postWebhook('BILLING.SUBSCRIPTION.ACTIVATED', $this->activation('I-SUB-TWICE'));
        $this->postWebhook('BILLING.SUBSCRIPTION.CANCELLED', ['id' => 'I-SUB-TWICE']);

        $this->assertSame(1, $announced, 'and a redelivered pair does not announce it a second time');
    }

    /**
     * An activation is still an activation on a plan PayPal has only suspended:
     * the donor fixed their card and the money resumes.
     */
    public function test_an_activation_still_revives_a_past_due_plan(): void
    {
        $reference = $this->createRecurringDonation();
        $this->recordSubscription($reference, 'I-SUB-FIXED');

        $this->postWebhook('BILLING.SUBSCRIPTION.SUSPENDED', ['id' => 'I-SUB-FIXED']);
        $this->assertSame('past_due', $this->plan('I-SUB-FIXED')->status);

        $res = $this->postWebhook('BILLING.SUBSCRIPTION.ACTIVATED', $this->activation('I-SUB-FIXED'));
        $this->assertSame(200, $res->get_status());

        $this->assertSame('active', $this->plan('I-SUB-FIXED')->status, 'a fixed card resumes the plan');
    }

    /**
     * A subscription the recorder refuses gets no plan row, and every cancel
     * path in the product reads gateway_subscription_id off such a row. PayPal
     * keeps billing the donor, so the refusal has to reach the screen someone
     * opens when a recurring donation did not behave.
     */
    public function test_a_refused_recovery_is_reported_where_the_site_owner_looks(): void
    {
        $reference = $this->createRecurringDonation();
        $this->recordSubscription($reference, 'I-SUB-FIRST');

        // A second subscription naming the same donation: binding it would
        // leave the first one billing unrecorded, so the recorder refuses.
        $res = $this->postWebhook('BILLING.SUBSCRIPTION.ACTIVATED', $this->activation('I-SUB-SECOND'));
        $this->assertSame(200, $res->get_status(), (string) wp_json_encode($res->get_data()));

        $repo = Plugin::instance()->container->get(RecurringPlanRepository::class);
        $this->assertNull(
            $repo->findBySubscriptionId('paypal', 'I-SUB-SECOND'),
            'the refusal stands: no plan row is written'
        );

        $errors = Event::query()->where('type', 'error.recurring.paypal')->getAll();
        $this->assertCount(1, $errors, 'and the refusal is reported rather than swallowed');
        $this->assertStringContainsString(
            'I-SUB-SECOND',
            (string) ($errors[0]->payload['message'] ?? ''),
            'naming the subscription that is still billing the donor'
        );
    }

    /**
     * The mode of the secret that verified the delivery, not the mode the event
     * claims. A test-mode webhook may not move a live plan in either direction.
     */
    public function test_a_test_mode_secret_cannot_move_a_live_plan(): void
    {
        $reference = $this->createRecurringDonation();
        $this->recordSubscription($reference, 'I-SUB-LIVE');

        $plan = $this->plan('I-SUB-LIVE');
        RecurringPlan::query()
            ->where('id', (int) $plan->id)
            ->update(['is_test' => 0, 'status' => 'past_due']);

        $activated = $this->postWebhook('BILLING.SUBSCRIPTION.ACTIVATED', $this->activation('I-SUB-LIVE'));
        $this->assertSame(200, $activated->get_status(), 'a retry cannot make it acceptable');
        $this->assertSame(
            'past_due',
            $this->plan('I-SUB-LIVE')->status,
            'a test-mode secret does not activate live money'
        );

        $announced = 0;
        add_action('dono.recurring.cancelled', static function () use (&$announced): void { $announced++; });

        $this->postWebhook('BILLING.SUBSCRIPTION.CANCELLED', ['id' => 'I-SUB-LIVE']);

        $this->assertSame(
            'past_due',
            $this->plan('I-SUB-LIVE')->status,
            'nor cancels a live plan whose money is still flowing'
        );
        $this->assertSame(0, $announced, 'and the donor is not emailed about it');
    }

    /**
     * Recovery is the one plan path with no row to ask about yet, so a guard
     * placed after it refuses a delivery that has already written and activated
     * the plan. The donation the resource names carries the same two facts and
     * exists before the write, so it is what the question has to be put to.
     */
    public function test_a_test_mode_secret_cannot_recover_a_plan_for_a_live_donation(): void
    {
        // A live org: live keys, the global switch off, and a live webhook id,
        // without which the gateway declines to offer recurring at all. The
        // sandbox endpoint stays configured alongside it, as it does on any
        // site that ever rehearsed, and only its id verifies this delivery.
        $account = Plugin::instance()->container->get(PayPalAccount::class);
        $account->saveKeys(false, 'AeA1QIZ_live', 'EO422dn3_live');
        $account->saveWebhookId(false, 'WH-LIVE-1');
        update_option('dono_gateway_config', ['test_mode' => false]);

        $reference = $this->createRecurringDonation();
        $donation  = Plugin::instance()->container->get(DonationRepository::class)->findByReference($reference);
        $this->assertFalse((bool) $donation->is_test, 'the donation is live money');

        $this->onlyWebhookIdVerifies = 'WH-TEST-1';

        // No recordSubscription: the browser POST is what never landed, which
        // is the only reason recovery runs at all.
        $res = $this->postWebhook('BILLING.SUBSCRIPTION.ACTIVATED', $this->activation('I-SUB-RECOVER'));

        $this->assertSame(200, $res->get_status(), (string) wp_json_encode($res->get_data()));
        $this->assertNull(
            Plugin::instance()->container
                ->get(RecurringPlanRepository::class)
                ->findBySubscriptionId('paypal', 'I-SUB-RECOVER'),
            'no live plan is written on the authority of a sandbox credential'
        );
    }
}
