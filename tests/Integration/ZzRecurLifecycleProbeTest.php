<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donations\DonationRepository;
use Dono\Foundation\Plugin;
use Dono\Gateways\GatewayManager;
use Dono\Gateways\PayPal\PayPalAccount;
use Dono\Gateways\PayPal\PayPalApi;
use Dono\Gateways\PayPal\PayPalGateway;
use Dono\Gateways\PayPal\PayPalPlans;
use Dono\Recurring\RecurringPlan;
use Dono\Recurring\RecurringPlanActions;
use Dono\Recurring\RecurringPlanChange;
use Dono\Recurring\RecurringPlanRepository;
use WP_REST_Request;

/** THROWAWAY PROBE - delete before finishing. */
final class ZzRecurLifecycleProbeTest extends IntegrationTestCase
{
    /** @var array<int,array{method:string,url:string,body:array<string,mixed>}> */
    private array $calls = [];

    private string $subscriptionStatus = 'ACTIVE';
    private string $currentReference = '';
    private string $subscriptionPlanId = 'P-PLAN-1';
    private string $nextBillingTime = '';

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

        // What PayPal's own spec says this field is: RFC 3339, minLength 20.
        $this->nextBillingTime = gmdate('Y-m-d\TH:i:s\Z', time() + 2592000);

        $c = Plugin::instance()->container;
        $account = $c->get(PayPalAccount::class);
        $account->forget();
        $account->saveKeys(true, 'AeA1QIZ_client', 'EO422dn3_secret');
        $account->saveWebhookId(true, 'WH-TEST-1');

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
                'body'     => (string) wp_json_encode($this->canned($path)),
                'response' => ['code' => 200, 'message' => 'OK'],
                'cookies'  => [], 'filename' => null,
            ];
        }, 10, 3);

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

    /** @return array<string,mixed> */
    private function canned(string $path): array
    {
        if (str_contains($path, '/v1/oauth2/token'))          return ['access_token' => 'A21AAF_test', 'expires_in' => 32400];
        if (str_contains($path, '/verify-webhook-signature')) return ['verification_status' => 'SUCCESS'];
        if (str_contains($path, '/v1/catalogs/products'))     return ['id' => 'PROD-1'];
        if (str_contains($path, '/v1/billing/plans'))         return ['id' => 'P-PLAN-1'];
        if (str_contains($path, '/v1/billing/subscriptions/')) {
            $asked = rawurldecode(basename($path));
            return [
                'id'           => $asked !== '' ? $asked : 'I-SUB-1',
                'status'       => $this->subscriptionStatus,
                'custom_id'    => $this->currentReference,
                'plan_id'      => $this->subscriptionPlanId,
                'subscriber'   => ['payer_id' => 'PAYER-1'],
                'billing_info' => ['next_billing_time' => $this->nextBillingTime],
            ];
        }
        return ['id' => 'OBJ-1'];
    }

    private function createRecurringDonation(int $amount = 2500, string $frequency = 'monthly'): string
    {
        $req = new WP_REST_Request('POST', '/dono/v1/donations');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode([
            'email'        => 'probe' . bin2hex(random_bytes(3)) . '@example.test',
            'amount_cents' => $amount,
            'currency'     => 'USD',
            'gateway'      => 'paypal',
            'frequency'    => $frequency,
            'profile'      => ['first_name' => 'Probe', 'last_name' => 'Donor'],
        ]));
        $res  = rest_do_request($req);
        $data = $res->get_data();
        if (! isset($data['reference'])) {
            $this->fail('donation creation failed: ' . wp_json_encode($data));
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
            'status_token' => $this->stampStatusToken($reference),
        ]));
        return rest_do_request($req);
    }

    /** @param array<string,mixed> $resource */
    private function postWebhook(string $type, array $resource, ?string $eventId = null): \WP_REST_Response
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
            'id'         => $eventId ?? 'WH-EVT-' . bin2hex(random_bytes(4)),
            'event_type' => $type,
            'resource'   => $resource,
        ]));
        return rest_do_request($req);
    }

    private function plans(): RecurringPlanRepository
    {
        return Plugin::instance()->container->get(RecurringPlanRepository::class);
    }

    private function actions(): RecurringPlanActions
    {
        return Plugin::instance()->container->get(RecurringPlanActions::class);
    }

    // ---------------------------------------------------------------- probes

    /** PROBE 1: what lands in next_payment_at when PayPal answers as its spec says. */
    public function test_probe_next_billing_time_rfc3339(): void
    {
        $reference = $this->createRecurringDonation();
        $res = $this->recordSubscription($reference, 'I-PROBE-RFC');
        fwrite(STDERR, "\n[P1] record status=" . $res->get_status() . ' body=' . wp_json_encode($res->get_data()) . "\n");

        $plan = $this->plans()->findBySubscriptionId('paypal', 'I-PROBE-RFC');
        fwrite(STDERR, '[P1] paypal sent: ' . $this->nextBillingTime . "\n");
        fwrite(STDERR, '[P1] next_payment_at in DB: ' . var_export($plan?->next_payment_at, true) . "\n");
        fwrite(STDERR, '[P1] status: ' . var_export($plan?->status, true) . "\n");
        $this->assertTrue(true);
    }

    /** PROBE 2: donor pauses; PayPal then reports the suspension it was asked for. */
    public function test_probe_pause_then_suspended_webhook(): void
    {
        $reference = $this->createRecurringDonation();
        $this->recordSubscription($reference, 'I-PROBE-PAUSE');
        $plan = $this->plans()->findBySubscriptionId('paypal', 'I-PROBE-PAUSE');

        $this->actions()->pause($plan, RecurringPlanActions::monthsFromNow(3), RecurringPlanChange::byDonor('pause'));
        $after = RecurringPlan::query()->find('id', (int) $plan->id);
        fwrite(STDERR, "\n[P2] after portal pause: status=" . $after->status . ' resume_at=' . var_export($after->resume_at, true) . "\n");

        $this->postWebhook('BILLING.SUBSCRIPTION.SUSPENDED', ['id' => 'I-PROBE-PAUSE']);
        $after2 = RecurringPlan::query()->find('id', (int) $plan->id);
        fwrite(STDERR, '[P2] after PayPal SUSPENDED webhook: status=' . $after2->status . "\n");
        $this->assertTrue(true);
    }

    /** PROBE 3: donor skips one payment; same webhook arrives. */
    public function test_probe_skip_then_suspended_webhook(): void
    {
        $reference = $this->createRecurringDonation();
        $this->recordSubscription($reference, 'I-PROBE-SKIP');
        $plan = $this->plans()->findBySubscriptionId('paypal', 'I-PROBE-SKIP');
        // give it a next payment to skip
        RecurringPlan::query()->where('id', (int) $plan->id)->update(['next_payment_at' => gmdate('Y-m-d H:i:s', time() + 86400)]);
        $plan = RecurringPlan::query()->find('id', (int) $plan->id);

        $this->actions()->skipNext($plan, RecurringPlanChange::byDonor('skip_next'));
        $after = RecurringPlan::query()->find('id', (int) $plan->id);
        fwrite(STDERR, "\n[P3] after portal skip_next: status=" . $after->status . ' resume_at=' . var_export($after->resume_at, true) . "\n");

        $this->postWebhook('BILLING.SUBSCRIPTION.SUSPENDED', ['id' => 'I-PROBE-SKIP']);
        $after2 = RecurringPlan::query()->find('id', (int) $plan->id);
        fwrite(STDERR, '[P3] after PayPal SUSPENDED webhook: status=' . $after2->status . "\n");

        $stats = $this->plans()->recurringStats(gmdate('Y-m-d'), true);
        fwrite(STDERR, '[P3] recurringStats: ' . wp_json_encode($stats) . "\n");
        $this->assertTrue(true);
    }

    /** PROBE 4: out-of-order redelivery of an older decline. */
    public function test_probe_out_of_order_failure_redelivery(): void
    {
        $reference = $this->createRecurringDonation();
        $this->recordSubscription($reference, 'I-PROBE-OOO');

        $notices = 0;
        add_action('dono.recurring.renewal_failed', static function () use (&$notices): void { $notices++; }, 10, 2);

        $this->postWebhook('BILLING.SUBSCRIPTION.PAYMENT.FAILED', ['id' => 'I-PROBE-OOO'], 'WH-E1');
        $this->postWebhook('BILLING.SUBSCRIPTION.PAYMENT.FAILED', ['id' => 'I-PROBE-OOO'], 'WH-E2');
        // E1 redelivered after E2 landed (PayPal retries for 3 days).
        $this->postWebhook('BILLING.SUBSCRIPTION.PAYMENT.FAILED', ['id' => 'I-PROBE-OOO'], 'WH-E1');

        $plan = $this->plans()->findBySubscriptionId('paypal', 'I-PROBE-OOO');
        fwrite(STDERR, "\n[P4] two real declines + one redelivery of the first => failed_renewals_count=" . $plan->failed_renewals_count . " notices={$notices}\n");
        $this->assertTrue(true);
    }

    /** PROBE 5: both failure event types for one decline. */
    public function test_probe_both_failure_event_types(): void
    {
        $reference = $this->createRecurringDonation();
        $this->recordSubscription($reference, 'I-PROBE-BOTH');

        $notices = 0;
        add_action('dono.recurring.renewal_failed', static function () use (&$notices): void { $notices++; }, 10, 2);

        $this->postWebhook('BILLING.SUBSCRIPTION.PAYMENT.FAILED', ['id' => 'I-PROBE-BOTH'], 'WH-SUBFAIL');
        $this->postWebhook('PAYMENT.SALE.DENIED', ['billing_agreement_id' => 'I-PROBE-BOTH', 'id' => 'SALE-X'], 'WH-SALEDENIED');

        $plan = $this->plans()->findBySubscriptionId('paypal', 'I-PROBE-BOTH');
        fwrite(STDERR, "\n[P5] one decline, two event types => failed_renewals_count=" . $plan->failed_renewals_count . " notices={$notices}\n");
        $this->assertTrue(true);
    }

    /** PROBE 6: a plan recorded from an APPROVED (not yet ACTIVE) subscription. */
    public function test_probe_pending_plan_is_invisible_to_archive_sweep(): void
    {
        $this->subscriptionStatus = 'APPROVED';
        $reference = $this->createRecurringDonation();
        $this->recordSubscription($reference, 'I-PROBE-PENDING');

        $plan = $this->plans()->findBySubscriptionId('paypal', 'I-PROBE-PENDING');
        fwrite(STDERR, "\n[P6] plan status from APPROVED subscription: " . var_export($plan?->status, true) . "\n");
        if ($plan) {
            RecurringPlan::query()->where('id', (int) $plan->id)->update(['campaign_id' => 4242]);
            $live = $this->plans()->liveForCampaign(4242);
            fwrite(STDERR, '[P6] liveForCampaign(4242) = ' . wp_json_encode($live) . "\n");
        }
        $this->assertTrue(true);
    }
}
