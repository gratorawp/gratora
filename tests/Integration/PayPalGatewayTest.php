<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donations\DonationRepository;
use Dono\Donations\Refund;
use Dono\Foundation\Plugin;
use Dono\Gateways\GatewayManager;
use Dono\Gateways\PayPal\PayPalAccount;
use Dono\Gateways\PayPal\PayPalApi;
use Dono\Gateways\PayPal\PayPalGateway;
use Dono\Gateways\PayPal\PayPalPlans;
use WP_REST_Request;

/**
 * The PayPal money route end to end: an Order is created up front so the
 * donation carries a gateway id before the donor approves, the capture confirms
 * it, refunds scale per currency, and webhook events are idempotent.
 *
 * All PayPal HTTP is intercepted, so nothing here touches the real API.
 */
final class PayPalGatewayTest extends IntegrationTestCase
{
    /** @var array<int,array{method:string,url:string,body:array<string,mixed>}> */
    private array $calls = [];

    /** Canned capture comes back PENDING (eCheck / review hold) when set. */
    private bool $pendingCapture = false;

    protected function setUp(): void
    {
        parent::setUp();

        update_option('dono_gateway_config', ['test_mode' => true]);
        update_option('dono_currency_locale', [
            'default_currency'     => 'USD',
            'supported_currencies' => ['USD', 'JPY'],
        ]);

        $c = Plugin::instance()->container;
        $account = $c->get(PayPalAccount::class);
        $account->forget();
        $account->saveKeys(true, 'AeA1QIZ_client', 'EO422dn3_secret');
        $account->saveWebhookId(true, 'WH-TEST-1');

        $this->mockPayPal();

        // CoreModule registers PayPal only when credentials exist at boot, and
        // these are created in setUp, so register it by hand here.
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

            $this->calls[] = [
                'method' => (string) ($args['method'] ?? 'POST'),
                'url'    => $url,
                'body'   => $body,
            ];

            return [
                'headers'  => [],
                'body'     => (string) wp_json_encode($this->cannedResponse($path, $body)),
                'response' => ['code' => 200, 'message' => 'OK'],
                'cookies'  => [], 'filename' => null,
            ];
        }, 10, 3);
    }

    /** @param array<string,mixed> $body @return array<string,mixed> */
    private function cannedResponse(string $path, array $body): array
    {
        if (str_contains($path, '/v1/oauth2/token')) {
            return ['access_token' => 'A21AAF_test', 'expires_in' => 32400];
        }
        if (str_contains($path, '/verify-webhook-signature')) {
            return ['verification_status' => 'SUCCESS'];
        }
        if (str_contains($path, '/capture')) {
            return [
                'id'     => 'ORDER-1',
                'status' => 'COMPLETED',
                'purchase_units' => [[
                    'payments' => ['captures' => [[
                        'id'     => 'CAPTURE-1',
                        'status' => $this->pendingCapture ? 'PENDING' : 'COMPLETED',
                        'seller_receivable_breakdown' => [
                            'paypal_fee' => ['value' => '1.05', 'currency_code' => 'USD'],
                        ],
                    ]]],
                ]],
                'payer' => ['email_address' => 'donor@example.test'],
            ];
        }
        if (str_contains($path, '/refund')) {
            return [
                'id'     => 'REFUND-1',
                'status' => 'COMPLETED',
                'amount' => [
                    'value'         => (string) ($body['amount']['value'] ?? '0'),
                    'currency_code' => (string) ($body['amount']['currency_code'] ?? 'USD'),
                ],
            ];
        }
        if (str_contains($path, '/v2/checkout/orders')) {
            return ['id' => 'ORDER-1', 'status' => 'CREATED'];
        }
        return ['id' => 'OBJ-1'];
    }

    private function createDonation(string $currency = 'USD', int $amount = 2500, string $email = 'pp@example.test'): string
    {
        $req = new WP_REST_Request('POST', '/dono/v1/donations');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode([
            'email'        => $email,
            'amount_cents' => $amount,
            'currency'     => $currency,
            'gateway'      => 'paypal',
            'frequency'    => 'one_time',
            'profile'      => ['first_name' => 'Pay', 'last_name' => 'Pal'],
        ]));
        $res  = rest_do_request($req);
        $data = $res->get_data();
        if (! isset($data['reference'])) {
            $this->fail('Donation creation failed: ' . wp_json_encode($data));
        }
        return $data['reference'];
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

    public function test_create_intent_opens_an_order_and_stamps_it_on_the_donation(): void
    {
        $reference = $this->createDonation();

        $order = $this->findCall('/v2/checkout/orders');
        $this->assertNotNull($order, 'an order was created');
        $this->assertSame('CAPTURE', $order['body']['intent'] ?? null);
        $this->assertSame('25.00', $order['body']['purchase_units'][0]['amount']['value'] ?? null);
        $this->assertSame('USD', $order['body']['purchase_units'][0]['amount']['currency_code'] ?? null);
        // custom_id carries the reference through capture, refund and webhook.
        $this->assertSame($reference, $order['body']['purchase_units'][0]['custom_id'] ?? null);

        $donation = $this->donations()->findByReference($reference);
        $this->assertSame('ORDER-1', $donation->gateway_intent_id, 'order id is stored for later matching');
        $this->assertSame('pending', $donation->status);
    }

    /** Sandbox credentials must never reach the live host. */
    public function test_test_mode_donations_use_the_sandbox_host(): void
    {
        $this->createDonation();
        $order = $this->findCall('/v2/checkout/orders');
        $this->assertStringContainsString('sandbox', $order['url']);
    }

    /** A zero-decimal currency must be sent whole, not with cents. */
    public function test_zero_decimal_currency_is_sent_without_decimals(): void
    {
        $this->createDonation('JPY', 100000, 'jpy@example.test');

        $order = $this->findCall('/v2/checkout/orders');
        $this->assertSame('1000', $order['body']['purchase_units'][0]['amount']['value'] ?? null);
        $this->assertSame('JPY', $order['body']['purchase_units'][0]['amount']['currency_code'] ?? null);
    }

    public function test_capture_route_confirms_the_donation(): void
    {
        $reference = $this->createDonation();

        $req = new WP_REST_Request('POST', '/dono/v1/gateways/paypal/capture');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode(['reference' => $reference]));
        $res = rest_do_request($req);

        $this->assertSame(200, $res->get_status(), wp_json_encode($res->get_data()));

        $fresh = $this->donations()->findByReference($reference);
        $this->assertSame('paid', $fresh->status);
        $this->assertSame('CAPTURE-1', $fresh->gateway_txn_id);
    }

    /**
     * The browser is not trusted to say which order to capture: the route must
     * use the order stored on the donation, so a caller cannot point a capture
     * at an order that is not theirs.
     */
    public function test_capture_ignores_a_client_supplied_order_id(): void
    {
        $reference = $this->createDonation();

        $req = new WP_REST_Request('POST', '/dono/v1/gateways/paypal/capture');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode([
            'reference' => $reference,
            'order_id'  => 'ORDER-SOMEONE-ELSE',
        ]));
        rest_do_request($req);

        $capture = $this->findCall('/capture');
        $this->assertNotNull($capture);
        $this->assertStringContainsString('ORDER-1', $capture['url'], 'the stored order is captured');
        $this->assertStringNotContainsString('SOMEONE-ELSE', $capture['url']);
    }

    public function test_unknown_reference_is_refused(): void
    {
        $req = new WP_REST_Request('POST', '/dono/v1/gateways/paypal/capture');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode(['reference' => 'DONO-NOPE']));
        $res = rest_do_request($req);

        $this->assertSame(404, $res->get_status());
    }

    public function test_refund_sends_the_scaled_amount_and_records_it(): void
    {
        $reference = $this->createDonation('JPY', 100000, 'jpy-refund@example.test');

        $req = new WP_REST_Request('POST', '/dono/v1/gateways/paypal/capture');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode(['reference' => $reference]));
        rest_do_request($req);

        $donation = $this->donations()->findByReference($reference);
        $gateway  = Plugin::instance()->container->get(GatewayManager::class)->require('paypal');

        $this->calls = [];
        $result = $gateway->refund($donation, 100000, 'donor requested');

        $this->assertTrue($result->success, $result->error ?? '');
        $refund = $this->findCall('/refund');
        $this->assertNotNull($refund);
        // 100000 stored yen -> "1000", not "1000.00".
        $this->assertSame('1000', $refund['body']['amount']['value'] ?? null);
        $this->assertSame(100000, $result->amount_cents, 'the echoed amount rescales to internal cents');
    }

    /** @param array<string,mixed> $resource */
    private function postWebhook(string $type, array $resource): \WP_REST_Response
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
            'id'         => 'WH-EVT-' . bin2hex(random_bytes(4)),
            'event_type' => $type,
            'resource'   => $resource,
        ]));
        return rest_do_request($req);
    }

    public function test_capture_completed_webhook_confirms_the_donation(): void
    {
        $reference = $this->createDonation();

        $res = $this->postWebhook('PAYMENT.CAPTURE.COMPLETED', [
            'id'        => 'CAPTURE-1',
            'custom_id' => $reference,
            'amount' => ['value' => '25.00', 'currency_code' => 'USD'],
        ]);

        $this->assertSame(200, $res->get_status(), wp_json_encode($res->get_data()));
        $this->assertSame('paid', $this->donations()->findByReference($reference)->status);
    }

    /** Redelivery is normal for PayPal, so a repeat must not double-confirm. */
    public function test_capture_completed_webhook_is_idempotent(): void
    {
        $reference = $this->createDonation();

        $this->postWebhook('PAYMENT.CAPTURE.COMPLETED', ['id' => 'CAPTURE-1', 'custom_id' => $reference, 'amount' => ['value' => '25.00', 'currency_code' => 'USD'],]);
        $this->postWebhook('PAYMENT.CAPTURE.COMPLETED', ['id' => 'CAPTURE-1', 'custom_id' => $reference, 'amount' => ['value' => '25.00', 'currency_code' => 'USD'],]);

        $fresh = $this->donations()->findByReference($reference);
        $this->assertSame('paid', $fresh->status);
        $this->assertSame(0, (int) $fresh->refunded_cents);
    }

    public function test_dashboard_refund_webhook_is_recorded(): void
    {
        $reference = $this->createDonation();
        $this->postWebhook('PAYMENT.CAPTURE.COMPLETED', ['id' => 'CAPTURE-1', 'custom_id' => $reference, 'amount' => ['value' => '25.00', 'currency_code' => 'USD'],]);

        $res = $this->postWebhook('PAYMENT.CAPTURE.REFUNDED', [
            'id'        => 'REFUND-WH-1',
            'custom_id' => $reference,
            'amount'    => ['value' => '25.00', 'currency_code' => 'USD'],
        ]);

        $this->assertSame(200, $res->get_status());

        $refund = Refund::query()->where('gateway_refund_id', 'REFUND-WH-1')->get();
        $this->assertNotNull($refund, 'the dashboard refund is mirrored locally');
        $this->assertSame(2500, (int) $refund->amount_cents);
        $this->assertSame('refunded', $this->donations()->findByReference($reference)->status);
    }

    /** An event for a donation that is not ours is acknowledged, never 5xx. */
    public function test_unmatched_event_is_acknowledged_so_paypal_stops_retrying(): void
    {
        $res = $this->postWebhook('PAYMENT.CAPTURE.COMPLETED', [
            'id'        => 'CAPTURE-STRANGER',
            'custom_id' => 'DONO-NOT-OURS',
        ]);

        $this->assertSame(200, $res->get_status());
    }

    /**
     * eCheck and review holds settle later by webhook. Calling that a failed
     * payment sends a donor who has already paid back to pay again, and drops
     * the capture id the refund path and the settling webhook both need.
     */
    public function test_a_held_capture_is_processing_not_failed(): void
    {
        $this->pendingCapture = true;
        $reference = $this->createDonation();

        $req = new WP_REST_Request('POST', '/dono/v1/gateways/paypal/capture');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode(['reference' => $reference]));
        $res = rest_do_request($req);

        $this->assertSame(200, $res->get_status(), 'the donor is not told it failed');
        $this->assertSame('processing', $res->get_data()['status']);

        $donation = (new DonationRepository())->findByReference($reference);
        $this->assertSame('processing', $donation->status);
        $this->assertSame('CAPTURE-1', (string) $donation->gateway_txn_id, 'the capture id is kept');
    }

    public function test_a_held_capture_settles_when_the_webhook_lands(): void
    {
        $this->pendingCapture = true;
        $reference = $this->createDonation();

        $req = new WP_REST_Request('POST', '/dono/v1/gateways/paypal/capture');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode(['reference' => $reference]));
        rest_do_request($req);

        $this->postWebhook('PAYMENT.CAPTURE.COMPLETED', [
            'id'        => 'CAPTURE-1',
            'custom_id' => $reference,
            'amount'    => ['value' => '25.00', 'currency_code' => 'USD'],
        ]);

        $donation = (new DonationRepository())->findByReference($reference);
        $this->assertSame('paid', $donation->status, 'the hold clears to paid');
    }
}
