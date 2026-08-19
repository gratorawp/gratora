<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donations\Donation;
use Dono\Donations\DonationRepository;
use Dono\Donations\Refund;
use Dono\Foundation\Plugin;
use Dono\Gateways\GatewayManager;
use Dono\Gateways\PayPal\PayPalAccount;
use Dono\Gateways\PayPal\PayPalApi;
use Dono\Gateways\PayPal\PayPalGateway;
use Dono\Gateways\PayPal\PayPalPlans;
use Dono\Recurring\RecurringPlan;
use WP_REST_Request;

/** THROWAWAY PROBE - delete before finishing. */
final class ZzIdemProbeTest extends IntegrationTestCase
{
    /** @var array<int,array{method:string,url:string,body:array<string,mixed>}> */
    private array $calls = [];

    /** @var array<string,mixed> */
    private array $subResponse = [];

    protected function setUp(): void
    {
        parent::setUp();

        update_option('dono_gateway_config', ['test_mode' => true]);
        update_option('dono_currency_locale', [
            'default_currency'     => 'USD',
            'supported_currencies' => ['USD', 'EUR'],
        ]);

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
                $c->get(\Dono\Recurring\RecurringPlanRepository::class),
                $c->get(\Dono\Foundation\Time\Clock::class),
                $c->get(\Dono\Gateways\PayPal\PayPalPlanRecorder::class),
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
                'body'     => (string) wp_json_encode($this->canned($path, $body)),
                'response' => ['code' => 200, 'message' => 'OK'],
                'cookies'  => [], 'filename' => null,
            ];
        }, 10, 3);
    }

    /** @param array<string,mixed> $body @return array<string,mixed> */
    private function canned(string $path, array $body): array
    {
        if (str_contains($path, '/v1/oauth2/token')) return ['access_token' => 'A21AAF_test', 'expires_in' => 32400];
        if (str_contains($path, '/verify-webhook-signature')) return ['verification_status' => 'SUCCESS'];
        if (str_contains($path, '/v1/billing/subscriptions/') && $this->subResponse !== []) return $this->subResponse;
        if (str_contains($path, '/capture')) {
            return [
                'id' => 'ORDER-1', 'status' => 'COMPLETED',
                'purchase_units' => [['payments' => ['captures' => [[
                    'id' => 'CAPTURE-1', 'status' => 'COMPLETED',
                ]]]]],
            ];
        }
        if (str_contains($path, '/v2/checkout/orders')) return ['id' => 'ORDER-1', 'status' => 'CREATED'];
        if (str_contains($path, '/v1/billing/plans')) return ['id' => 'P-PLAN-1', 'status' => 'ACTIVE'];
        return ['id' => 'OBJ-1'];
    }

    private function createDonation(string $currency = 'USD', int $amount = 2500, string $email = 'pp@example.test', string $frequency = 'one_time'): string
    {
        $req = new WP_REST_Request('POST', '/dono/v1/donations');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode([
            'email'        => $email,
            'amount_cents' => $amount,
            'currency'     => $currency,
            'gateway'      => 'paypal',
            'frequency'    => $frequency,
            'profile'      => ['first_name' => 'Pay', 'last_name' => 'Pal'],
        ]));
        $res  = rest_do_request($req);
        $data = $res->get_data();
        if (! isset($data['reference'])) $this->fail('create failed: ' . wp_json_encode($data));
        return $data['reference'];
    }

    /** @param array<string,mixed> $resource */
    private function postWebhook(string $type, array $resource, ?string $eventId = null): \WP_REST_Response
    {
        $req = new WP_REST_Request('POST', '/dono/v1/webhooks/paypal');
        $req->set_header('content-type', 'application/json');
        foreach ([
            'paypal_transmission_id'   => 'tx-1',
            'paypal_transmission_time' => gmdate('c'),
            'paypal_transmission_sig'  => 'sig',
            'paypal_cert_url'          => 'https://api.paypal.com/cert',
            'paypal_auth_algo'         => 'SHA256withRSA',
        ] as $k => $v) {
            $req->set_header($k, $v);
        }
        $req->set_body((string) wp_json_encode([
            'id'         => $eventId ?? ('WH-EVT-' . bin2hex(random_bytes(4))),
            'event_type' => $type,
            'resource'   => $resource,
        ]));
        return rest_do_request($req);
    }

    private function donations(): DonationRepository
    {
        return Plugin::instance()->container->get(DonationRepository::class);
    }

    // ------------------------------------------------------------- PROBE A

    /**
     * PayPal refunds a donation the site never banked (held eCheck, refused
     * capture event, an already fully refunded row). Stripe answers 200 for
     * exactly this. What does PayPal's handler answer?
     */
    public function test_probe_a_paypal_refund_on_a_non_paid_donation(): void
    {
        $reference = $this->createDonation();

        // Leave it at pending: PayPal has the money, the site never booked it.
        $res = $this->postWebhook('PAYMENT.CAPTURE.REFUNDED', [
            'id'        => 'REFUND-A-1',
            'custom_id' => $reference,
            'amount'    => ['value' => '25.00', 'currency_code' => 'USD'],
        ]);

        fwrite(STDERR, "\nPROBE A status=" . $res->get_status() . ' data=' . wp_json_encode($res->get_data()) . "\n");
        $this->assertTrue(true);
    }

    /** Same, but with the donation already fully refunded (redelivery with a new refund id). */
    public function test_probe_a2_paypal_second_refund_after_full(): void
    {
        $reference = $this->createDonation();
        $this->postWebhook('PAYMENT.CAPTURE.COMPLETED', [
            'id' => 'CAPTURE-1', 'custom_id' => $reference,
            'amount' => ['value' => '25.00', 'currency_code' => 'USD'],
        ]);
        $this->postWebhook('PAYMENT.CAPTURE.REFUNDED', [
            'id' => 'REFUND-A2-1', 'custom_id' => $reference,
            'amount' => ['value' => '25.00', 'currency_code' => 'USD'],
        ]);
        $res = $this->postWebhook('PAYMENT.CAPTURE.REFUNDED', [
            'id' => 'REFUND-A2-2', 'custom_id' => $reference,
            'amount' => ['value' => '25.00', 'currency_code' => 'USD'],
        ]);

        fwrite(STDERR, "\nPROBE A2 status=" . $res->get_status() . ' data=' . wp_json_encode($res->get_data()) . "\n");
        $this->assertTrue(true);
    }

    // ------------------------------------------------------------- PROBE B

    /**
     * The opening PAYMENT.SALE.COMPLETED for a PayPal subscription, delivered
     * twice. Money must be banked once and the plan credited once.
     */
    public function test_probe_b_opening_sale_redelivered(): void
    {
        $reference = $this->createDonation('USD', 2500, 'ppsub@example.test', 'monthly');
        $donation  = $this->donations()->findByReference($reference);

        $this->subResponse = [
            'id'         => 'I-SUB-B',
            'status'     => 'ACTIVE',
            'custom_id'  => $reference,
            'plan_id'    => (string) (($donation->gateway_metadata ?? [])['paypal_plan_id'] ?? 'P-PLAN-1'),
            'subscriber' => ['payer_id' => 'PAYER-1'],
        ];

        // Browser records the plan.
        $req = new WP_REST_Request('POST', '/dono/v1/gateways/paypal/subscription');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode([
            'reference'       => $reference,
            'status_token'    => $this->stampStatusToken($reference),
            'subscription_id' => 'I-SUB-B',
        ]));
        $res = rest_do_request($req);
        fwrite(STDERR, "\nPROBE B record-sub status=" . $res->get_status() . ' data=' . wp_json_encode($res->get_data()) . "\n");

        $sale = [
            'id' => 'SALE-B-1',
            'billing_agreement_id' => 'I-SUB-B',
            'amount' => ['total' => '25.00', 'currency' => 'USD'],
        ];
        $this->postWebhook('PAYMENT.SALE.COMPLETED', $sale, 'WH-SALE-B');
        $this->postWebhook('PAYMENT.SALE.COMPLETED', $sale, 'WH-SALE-B');

        $plan = RecurringPlan::query()->where('gateway_subscription_id', 'I-SUB-B')->get();
        $rows = Donation::query()->where('recurring_plan_id', (int) ($plan->id ?? 0))->getAll();

        fwrite(STDERR, 'PROBE B payments_count=' . (int) ($plan->payments_count ?? -1)
            . ' total_paid=' . (int) ($plan->total_paid_cents ?? -1)
            . ' donations=' . count($rows)
            . ' statuses=' . wp_json_encode(array_map(static fn ($d) => $d->status . '/' . $d->amount_cents, $rows)) . "\n");
        $this->assertTrue(true);
    }

    /**
     * Second month's sale on a plan whose opening donation was never settled.
     * Which row does the money land on?
     */
    public function test_probe_b2_second_sale_while_signup_still_pending(): void
    {
        $reference = $this->createDonation('USD', 2500, 'ppsub2@example.test', 'monthly');
        $donation  = $this->donations()->findByReference($reference);

        $this->subResponse = [
            'id'         => 'I-SUB-B2',
            'status'     => 'ACTIVE',
            'custom_id'  => $reference,
            'plan_id'    => (string) (($donation->gateway_metadata ?? [])['paypal_plan_id'] ?? 'P-PLAN-1'),
            'subscriber' => ['payer_id' => 'PAYER-1'],
        ];

        $req = new WP_REST_Request('POST', '/dono/v1/gateways/paypal/subscription');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode([
            'reference'       => $reference,
            'status_token'    => $this->stampStatusToken($reference),
            'subscription_id' => 'I-SUB-B2',
        ]));
        rest_do_request($req);

        // The opening sale never arrives (PayPal gave up while the plan row was
        // still missing). A month later the second sale lands.
        $this->postWebhook('PAYMENT.SALE.COMPLETED', [
            'id' => 'SALE-B2-MONTH2',
            'billing_agreement_id' => 'I-SUB-B2',
            'amount' => ['total' => '25.00', 'currency' => 'USD'],
        ], 'WH-SALE-B2-M2');

        $plan = RecurringPlan::query()->where('gateway_subscription_id', 'I-SUB-B2')->get();
        $rows = Donation::query()->where('recurring_plan_id', (int) ($plan->id ?? 0))->getAll();

        fwrite(STDERR, "\nPROBE B2 payments_count=" . (int) ($plan->payments_count ?? -1)
            . ' donations=' . count($rows)
            . ' rows=' . wp_json_encode(array_map(static fn ($d) => [
                'ref' => $d->reference, 'status' => $d->status, 'intent' => $d->gateway_intent_id, 'txn' => $d->gateway_txn_id,
            ], $rows)) . "\n");
        $this->assertTrue(true);
    }

    // ------------------------------------------------------------- PROBE D

    /** A PayPal refund denominated in a currency the donation is not in. */
    public function test_probe_d_refund_in_a_foreign_currency(): void
    {
        $reference = $this->createDonation('USD', 2500, 'ppfx@example.test');
        $this->postWebhook('PAYMENT.CAPTURE.COMPLETED', [
            'id' => 'CAPTURE-1', 'custom_id' => $reference,
            'amount' => ['value' => '25.00', 'currency_code' => 'USD'],
        ]);

        $res = $this->postWebhook('PAYMENT.CAPTURE.REFUNDED', [
            'id' => 'REFUND-D-1', 'custom_id' => $reference,
            'amount' => ['value' => '25.00', 'currency_code' => 'EUR'],
        ]);

        $refund = Refund::query()->where('gateway_refund_id', 'REFUND-D-1')->get();
        $fresh  = $this->donations()->findByReference($reference);
        fwrite(STDERR, "\nPROBE D status=" . $res->get_status()
            . ' refund=' . wp_json_encode($refund ? ['amount' => $refund->amount_cents, 'currency' => $refund->currency] : null)
            . ' donation=' . $fresh->status . '/' . $fresh->refunded_cents . "\n");
        $this->assertTrue(true);
    }
}
