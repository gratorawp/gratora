<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Donations\DonationRepository;
use Gratora\Foundation\Plugin;
use Gratora\Gateways\GatewayManager;
use Gratora\Gateways\PayPal\PayPalAccount;
use Gratora\Gateways\PayPal\PayPalApi;
use Gratora\Gateways\PayPal\PayPalGateway;
use Gratora\Gateways\PayPal\PayPalPlans;
use Gratora\Recurring\RecurringPlan;
use WP_REST_Request;

/**
 * PayPal reports one decline under two event types, and only the sale-shaped
 * one names the sale. A site could not tell them apart, so a plan's second and
 * every later decline was swallowed as a redelivery of the first.
 *
 * Separately, a webhook this site could not verify because PayPal was
 * unreachable was recorded as one PayPal refused.
 */
final class GatewayEdgeTruthTest extends IntegrationTestCase
{
    private bool $verifyTransportFails = false;

    private bool $verifyRefuses = false;

    private bool $tokenIsDown = false;

    private bool $verifyIsDown = false;

    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        $c = Plugin::instance()->container;
        $account = $c->get(PayPalAccount::class);
        $account->forget();
        $account->saveKeys(false, 'client-live', 'secret-live');
        $account->saveWebhookId(false, '5ML12345AB678901C');

        add_filter('pre_http_request', function ($pre, $args, $url) {
            if (! is_string($url) || ! str_contains($url, 'paypal.com')) return $pre;

            if (str_contains($url, '/v1/oauth2/token')) {
                if ($this->tokenIsDown) {
                    return $this->reply(['error_description' => 'Internal Server Error'], 500);
                }
                return $this->reply(['access_token' => 'A21AAF_test', 'expires_in' => 32400]);
            }
            if (str_contains($url, '/v1/catalogs/products')) {
                return $this->reply(['id' => 'PROD-1']);
            }
            if (str_contains($url, '/v1/billing/plans')) {
                return $this->reply(['id' => 'P-MINE']);
            }
            if (str_contains($url, '/verify-webhook-signature')) {
                if ($this->verifyTransportFails) {
                    return new \WP_Error('http_request_failed', 'Operation timed out after 10000 milliseconds');
                }
                if ($this->verifyIsDown) {
                    return $this->reply(['message' => 'Internal Server Error'], 500);
                }
                return $this->reply([
                    'verification_status' => $this->verifyRefuses ? 'FAILURE' : 'SUCCESS',
                ]);
            }

            return $this->reply([]);
        }, 10, 3);

        // CoreModule registers PayPal only when credentials exist at boot.
        $manager = $c->get(GatewayManager::class);
        if (! $manager->get('paypal')) {
            $manager->register(new PayPalGateway(
                $c->get(PayPalApi::class),
                $account,
                $c->get(DonationRepository::class),
                $c->get(\Gratora\Donations\DonationService::class),
                $c->get(PayPalPlans::class),
                $c->get(\Gratora\Recurring\RecurringPlanRepository::class),
                $c->get(\Gratora\Foundation\Time\Clock::class),
                $c->get(\Gratora\Gateways\PayPal\PayPalPlanRecorder::class),
            ));
        }
    }

    /** @param array<string,mixed> $body @return array<string,mixed> */
    private function reply(array $body, int $code = 200): array
    {
        return [
            'headers'  => [],
            'body'     => (string) wp_json_encode($body),
            'response' => ['code' => $code, 'message' => 'OK'],
            'cookies'  => [], 'filename' => null,
        ];
    }

    private function plan(string $subId): RecurringPlan
    {
        $now = gmdate('Y-m-d H:i:s');

        $p = RecurringPlan::make();
        $p->donor_id                = 0;
        $p->gateway                 = 'paypal';
        $p->gateway_subscription_id = $subId;
        $p->amount_cents            = 2500;
        $p->currency                = 'EUR';
        $p->interval_unit           = 'month';
        $p->interval_count          = 1;
        $p->status                  = 'active';
        $p->is_test                 = false;
        $p->started_at              = $now;
        $p->created_at              = $now;
        $p->updated_at              = $now;
        $p->save();

        return $p;
    }

    /** @param array<string,mixed> $resource */
    private function postWebhook(string $type, array $resource): \WP_REST_Response
    {
        $req = new WP_REST_Request('POST', '/gratora/v1/webhooks/paypal');
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

    private function failedCount(int $planId): int
    {
        return (int) RecurringPlan::query()->find('id', $planId)->failed_renewals_count;
    }

    public function test_a_second_decline_on_the_same_subscription_is_counted(): void
    {
        $plan = $this->plan('I-SECONDDECLINE');

        $this->postWebhook('BILLING.SUBSCRIPTION.PAYMENT.FAILED', ['id' => 'I-SECONDDECLINE']);
        $this->assertSame(1, $this->failedCount((int) $plan->id));

        $this->postWebhook('BILLING.SUBSCRIPTION.PAYMENT.FAILED', ['id' => 'I-SECONDDECLINE']);
        $this->assertSame(2, $this->failedCount((int) $plan->id), 'a later month is a later decline');
    }

    public function test_one_decline_delivered_twice_is_still_one(): void
    {
        $plan = $this->plan('I-ONEDECLINE');

        foreach ([1, 2] as $ignored) {
            $this->postWebhook('PAYMENT.SALE.DENIED', [
                'id'                   => 'SALE-DENIED-1',
                'billing_agreement_id' => 'I-ONEDECLINE',
            ]);
        }

        $this->assertSame(1, $this->failedCount((int) $plan->id));
    }

    public function test_paypal_being_unreachable_is_not_a_refused_signature(): void
    {
        $this->plan('I-UNREACHABLE');
        $this->verifyTransportFails = true;

        $res = $this->postWebhook('BILLING.SUBSCRIPTION.PAYMENT.FAILED', ['id' => 'I-UNREACHABLE']);

        $this->assertSame(
            503,
            $res->get_status(),
            'a 5xx tells PayPal to redeliver; a 400 tells it the signature was wrong'
        );
    }

    /**
     * PayPal answering 500 to the token call is PayPal being down. Reading it
     * as a refusal told the org their keys were wrong and sent them to change
     * credentials that were never the problem, and answered the delivery 400,
     * which is PayPal's cue to stop redelivering.
     */
    public function test_paypal_failing_on_the_token_call_is_not_a_refused_key(): void
    {
        $this->plan('I-TOKENDOWN');
        $this->tokenIsDown = true;

        $res = $this->postWebhook('BILLING.SUBSCRIPTION.PAYMENT.FAILED', ['id' => 'I-TOKENDOWN']);

        $this->assertSame(503, $res->get_status());
    }

    public function test_paypal_failing_on_the_verify_call_is_not_a_refused_signature(): void
    {
        $this->plan('I-VERIFYDOWN');
        $this->verifyIsDown = true;

        $res = $this->postWebhook('BILLING.SUBSCRIPTION.PAYMENT.FAILED', ['id' => 'I-VERIFYDOWN']);

        $this->assertSame(503, $res->get_status());
    }

    /**
     * An outage lasts longer than ten deliveries. Spending the signature budget
     * on them locked the gateway out of the site for the rest of the window, so
     * the events that arrived after PayPal came back were refused too.
     */
    public function test_an_outage_does_not_lock_the_gateway_out(): void
    {
        $this->plan('I-LONGOUTAGE');
        $this->verifyIsDown = true;

        for ($i = 0; $i < 12; $i++) {
            $res = $this->postWebhook('BILLING.SUBSCRIPTION.PAYMENT.FAILED', ['id' => 'I-LONGOUTAGE']);
            $this->assertSame(503, $res->get_status(), "delivery {$i} was turned away");
        }

        $this->verifyIsDown = false;
        $this->assertNotSame(
            429,
            $this->postWebhook('BILLING.SUBSCRIPTION.PAYMENT.FAILED', ['id' => 'I-LONGOUTAGE'])->get_status(),
            'and the event that arrived once PayPal came back was still taken'
        );
    }

    public function test_an_unverifiable_flood_is_still_bounded(): void
    {
        $this->plan('I-FLOOD');
        $this->verifyIsDown = true;

        $spam = Plugin::instance()->container->get(\Gratora\Donations\AntiSpamGuard::class);
        $key  = $spam->subjectKey('gratora_wh_fail_paypal') . ':unverifiable';
        for ($i = 0; $i < 500; $i++) {
            $spam->hit($key, 900);
        }

        $res = $this->postWebhook('BILLING.SUBSCRIPTION.PAYMENT.FAILED', ['id' => 'I-FLOOD']);

        $this->assertSame(429, $res->get_status());
    }

    public function test_a_refused_signature_is_still_refused(): void
    {
        $this->plan('I-REFUSED');
        $this->verifyRefuses = true;

        $res = $this->postWebhook('BILLING.SUBSCRIPTION.PAYMENT.FAILED', ['id' => 'I-REFUSED']);

        $this->assertSame(400, $res->get_status());
    }

    /**
     * Two donors overlap inside the plan-minting round trip. The second writes
     * back the map as it stood before its own API call, so the first one's plan
     * is gone and every later donation at that amount mints it again.
     */
    public function test_a_plan_minted_during_the_round_trip_survives(): void
    {
        update_option('gratora_paypal_plans', []);

        // Stands in for the concurrent checkout: it lands while this one is
        // still waiting on PayPal.
        add_filter('pre_http_request', function ($pre, $args, $url) {
            if (is_string($url) && str_contains($url, '/v1/billing/plans')) {
                update_option('gratora_paypal_plans', ['other_donor_key' => 'P-CONCURRENT'], false);
            }
            return $pre;
        }, 1, 3);

        $planId = Plugin::instance()->container
            ->get(PayPalPlans::class)
            ->resolvePlan(false, 2500, 'EUR', 'month', 1);

        $this->assertSame('P-MINE', $planId);

        $stored = get_option('gratora_paypal_plans', []);
        $this->assertContains('P-CONCURRENT', $stored, 'the concurrent checkout keeps its plan');
        $this->assertContains('P-MINE', $stored);
    }

    public function test_a_signature_paypal_refuses_is_still_a_refusal(): void
    {
        $this->plan('I-REFUSED');
        $this->verifyRefuses = true;

        $res = $this->postWebhook('BILLING.SUBSCRIPTION.PAYMENT.FAILED', ['id' => 'I-REFUSED']);

        $this->assertSame(400, $res->get_status());
    }
}
