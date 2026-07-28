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
 * The Razorpay one-time money route end to end: an Order is created up front so
 * the donation carries a gateway id before the donor pays, the capture route
 * verifies Razorpay's signature before confirming, and webhook events are
 * idempotent.
 *
 * All Razorpay HTTP is intercepted, so nothing here touches the real API.
 */
final class RazorpayGatewayTest extends IntegrationTestCase
{
    private const KEY_SECRET     = 'test_secret_abcd';
    private const WEBHOOK_SECRET = 'whsec_razor';

    /** @var array<int,array{method:string,url:string,body:array<string,mixed>}> */
    private array $calls = [];

    /** Payment status the canned /v1/payments/{id} read reports. */
    private string $paymentStatus = 'captured';

    protected function setUp(): void
    {
        parent::setUp();

        update_option('dono_gateway_config', ['test_mode' => true]);
        update_option('dono_currency_locale', [
            'default_currency'     => 'INR',
            'supported_currencies' => ['INR'],
        ]);

        $c = Plugin::instance()->container;
        $account = $c->get(RazorpayAccount::class);
        $account->forget();
        $account->saveKeys(true, 'rzp_test_abc123', self::KEY_SECRET);
        $account->saveWebhookSecret(true, self::WEBHOOK_SECRET);

        $this->mockRazorpay();

        // CoreModule registers Razorpay only when keys exist at boot, and these
        // are created in setUp, so register it by hand here.
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
                'body'     => (string) wp_json_encode($this->cannedResponse($path, $body)),
                'response' => ['code' => 200, 'message' => 'OK'],
                'cookies'  => [], 'filename' => null,
            ];
        }, 10, 3);
    }

    /** @param array<string,mixed> $body @return array<string,mixed> */
    private function cannedResponse(string $path, array $body): array
    {
        if (str_contains($path, '/refund')) {
            return [
                'id'         => 'rfnd_1',
                'status'     => 'processed',
                'amount'     => (int) ($body['amount'] ?? 0),
                'payment_id' => 'pay_1',
            ];
        }
        if (str_contains($path, '/capture')) {
            return $this->paymentObject('captured');
        }
        if (str_contains($path, '/v1/payments/')) {
            return $this->paymentObject($this->paymentStatus);
        }
        if (str_contains($path, '/v1/orders')) {
            return ['id' => 'order_1', 'status' => 'created', 'amount' => (int) ($body['amount'] ?? 0)];
        }
        return ['id' => 'obj_1'];
    }

    /** @return array<string,mixed> */
    private function paymentObject(string $status): array
    {
        return [
            'id'       => 'pay_1',
            'entity'   => 'payment',
            'amount'   => 250000,
            'currency' => 'INR',
            'status'   => $status,
            'order_id' => 'order_1',
            'method'   => 'upi',
            'fee'      => 5900,
            'tax'      => 900,
        ];
    }

    private function createDonation(int $amount = 250000, string $email = 'rz@example.test'): string
    {
        $req = new WP_REST_Request('POST', '/dono/v1/donations');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode([
            'email'        => $email,
            'amount_cents' => $amount,
            'currency'     => 'INR',
            'gateway'      => 'razorpay',
            'frequency'    => 'one_time',
            'profile'      => ['first_name' => 'Raz', 'last_name' => 'Pay'],
        ]));
        $res  = rest_do_request($req);
        $data = $res->get_data();
        if (! isset($data['reference'])) {
            $this->fail('Donation creation failed: ' . wp_json_encode($data));
        }
        return $data['reference'];
    }

    private function captureRequest(string $reference, string $paymentId, ?string $signature = null): \WP_REST_Response
    {
        $signature ??= RazorpaySignature::forOrder('order_1', $paymentId, self::KEY_SECRET);

        $req = new WP_REST_Request('POST', '/dono/v1/gateways/razorpay/capture');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode([
            'reference'  => $reference,
            'payment_id' => $paymentId,
            'signature'  => $signature,
        ]));
        return rest_do_request($req);
    }

    /** @param array<string,mixed> $event */
    private function webhook(array $event, ?string $secret = null): \WP_REST_Response
    {
        $raw = (string) wp_json_encode($event);

        $req = new WP_REST_Request('POST', '/dono/v1/webhooks/razorpay');
        $req->set_header('content-type', 'application/json');
        $req->set_header('x-razorpay-signature', RazorpaySignature::forWebhook($raw, $secret ?? self::WEBHOOK_SECRET));
        $req->set_header('x-razorpay-event-id', 'evt_' . md5($raw));
        $req->set_body($raw);
        return rest_do_request($req);
    }

    /** @return array<string,mixed>|null */
    private function findCall(string $needle, ?string $method = null): ?array
    {
        foreach ($this->calls as $call) {
            if (! str_contains($call['url'], $needle)) continue;
            if ($method !== null && $call['method'] !== $method) continue;
            return $call;
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

        $order = $this->findCall('/v1/orders');
        $this->assertNotNull($order, 'an order was created');
        $this->assertSame(250000, $order['body']['amount'] ?? null, 'rupees are sent as paise');
        $this->assertSame('INR', $order['body']['currency'] ?? null);
        // The reference rides the order so a webhook can match it back.
        $this->assertSame($reference, $order['body']['notes']['dono_reference'] ?? null);

        $donation = $this->donations()->findByReference($reference);
        $this->assertSame('order_1', $donation->gateway_intent_id, 'order id is stored for later matching');
        $this->assertSame('pending', $donation->status);
    }

    public function test_capture_route_verifies_the_signature_and_confirms(): void
    {
        $reference = $this->createDonation();

        $res = $this->captureRequest($reference, 'pay_1');

        $this->assertSame(200, $res->get_status(), wp_json_encode($res->get_data()));
        $this->assertSame('paid', $res->get_data()['status'] ?? null);

        $donation = $this->donations()->findByReference($reference);
        $this->assertSame('paid', $donation->status);
        $this->assertSame('pay_1', $donation->gateway_txn_id);
        // Razorpay reports its fee in paise; 5900 paise is 59.00 INR.
        $this->assertSame(5900, (int) $donation->fee_cents);
    }

    /**
     * The signature is the only thing standing between a stranger and a free
     * "paid" donation, since the capture route is necessarily public.
     */
    public function test_a_forged_signature_is_refused_and_the_donation_stays_pending(): void
    {
        $reference = $this->createDonation();

        $res = $this->captureRequest($reference, 'pay_1', 'deadbeef');

        $this->assertSame(400, $res->get_status());
        $this->assertSame('pending', $this->donations()->findByReference($reference)->status);
    }

    /**
     * A signature that is genuine but was issued for a different order must not
     * pay off this donation.
     */
    public function test_a_signature_for_another_order_is_refused(): void
    {
        $reference = $this->createDonation();

        $otherOrder = RazorpaySignature::forOrder('order_999', 'pay_1', self::KEY_SECRET);
        $res = $this->captureRequest($reference, 'pay_1', $otherOrder);

        $this->assertSame(400, $res->get_status());
        $this->assertSame('pending', $this->donations()->findByReference($reference)->status);
    }

    /**
     * An authorised payment is money held, not taken. Razorpay voids anything
     * left authorised, so the capture call has to actually happen.
     */
    public function test_an_authorized_payment_is_captured_before_confirming(): void
    {
        $this->paymentStatus = 'authorized';
        $reference = $this->createDonation();

        $res = $this->captureRequest($reference, 'pay_1');

        $this->assertSame(200, $res->get_status(), wp_json_encode($res->get_data()));
        $this->assertNotNull($this->findCall('/v1/payments/pay_1/capture', 'POST'), 'capture was called');
        $this->assertSame('paid', $this->donations()->findByReference($reference)->status);
    }

    /** An already-captured payment needs no second capture call. */
    public function test_an_already_captured_payment_is_not_captured_again(): void
    {
        $reference = $this->createDonation();

        $this->captureRequest($reference, 'pay_1');

        $this->assertNull($this->findCall('/v1/payments/pay_1/capture', 'POST'));
    }

    public function test_refund_sends_paise_and_records_the_refund(): void
    {
        $reference = $this->createDonation();
        $this->captureRequest($reference, 'pay_1');

        $donation = $this->donations()->findByReference($reference);
        $gateway  = Plugin::instance()->container->get(GatewayManager::class)->require('razorpay');

        $result = $gateway->refund($donation, 100000, 'Donor asked');

        $this->assertTrue($result->success, (string) $result->error);
        $this->assertSame('rfnd_1', $result->gateway_refund_id);
        $this->assertSame(100000, $result->amount_cents);

        $call = $this->findCall('/v1/payments/pay_1/refund');
        $this->assertSame(100000, $call['body']['amount'] ?? null);
    }

    public function test_webhook_confirms_a_captured_payment(): void
    {
        $reference = $this->createDonation();

        $res = $this->webhook([
            'event'   => 'payment.captured',
            'payload' => ['payment' => ['entity' => [
                'id'       => 'pay_1',
                'amount'   => 250000,
                'currency' => 'INR',
                'status'   => 'captured',
                'order_id' => 'order_1',
                'method'   => 'upi',
                'notes'    => ['dono_reference' => $reference],
            ]]],
        ]);

        $this->assertSame(200, $res->get_status());
        $this->assertSame('paid', $this->donations()->findByReference($reference)->status);
    }

    /** Razorpay redelivers events; confirming twice must not double count. */
    public function test_a_redelivered_capture_webhook_is_idempotent(): void
    {
        $reference = $this->createDonation();
        $event = [
            'event'   => 'payment.captured',
            'payload' => ['payment' => ['entity' => [
                'id'       => 'pay_1',
                'amount'   => 250000,
                'currency' => 'INR',
                'status'   => 'captured',
                'order_id' => 'order_1',
                'method'   => 'upi',
                'notes'    => ['dono_reference' => $reference],
            ]]],
        ];

        $this->webhook($event);
        $this->webhook($event);

        $paid = Donation::query()->where('reference', $reference)->getAll();
        $this->assertCount(1, $paid, 'no duplicate donation row');
        $this->assertSame('paid', $paid[0]->status);
    }

    public function test_an_unsigned_webhook_is_rejected(): void
    {
        $reference = $this->createDonation();

        $res = $this->webhook([
            'event'   => 'payment.captured',
            'payload' => ['payment' => ['entity' => [
                'id' => 'pay_1', 'order_id' => 'order_1', 'status' => 'captured',
                'notes' => ['dono_reference' => $reference],
            ]]],
        ], 'the_wrong_secret');

        $this->assertNotSame(200, $res->get_status());
        $this->assertSame('pending', $this->donations()->findByReference($reference)->status);
    }

    public function test_a_failed_payment_webhook_marks_the_donation_failed(): void
    {
        $reference = $this->createDonation();

        $this->webhook([
            'event'   => 'payment.failed',
            'payload' => ['payment' => ['entity' => [
                'id'                => 'pay_1',
                'order_id'          => 'order_1',
                'status'            => 'failed',
                'error_description' => 'Payment was not completed',
                'notes'             => ['dono_reference' => $reference],
            ]]],
        ]);

        $this->assertSame('failed', $this->donations()->findByReference($reference)->status);
    }

    /**
     * A subscription's own charge also arrives as payment.captured. Only
     * subscription.charged does the renewal bookkeeping, so this path must not
     * confirm the same money down a second route.
     */
    public function test_a_subscription_payment_is_ignored_by_the_plain_capture_handler(): void
    {
        $reference = $this->createDonation();

        $res = $this->webhook([
            'event'   => 'payment.captured',
            'payload' => ['payment' => ['entity' => [
                'id'              => 'pay_sub_1',
                'amount'          => 250000,
                'currency'        => 'INR',
                'status'          => 'captured',
                'subscription_id' => 'sub_1',
                'invoice_id'      => 'inv_1',
                'notes'           => ['dono_reference' => $reference],
            ]]],
        ]);

        $this->assertSame(200, $res->get_status());
        $this->assertSame('pending', $this->donations()->findByReference($reference)->status);
    }

    public function test_a_dashboard_refund_webhook_is_recorded(): void
    {
        $reference = $this->createDonation();
        $this->captureRequest($reference, 'pay_1');

        $this->webhook([
            'event'   => 'refund.processed',
            'payload' => ['refund' => ['entity' => [
                'id'         => 'rfnd_dash',
                'payment_id' => 'pay_1',
                'amount'     => 50000,
                'currency'   => 'INR',
                'status'     => 'processed',
            ]]],
        ]);

        $donation = $this->donations()->findByReference($reference);
        $this->assertSame(50000, (int) $donation->refunded_cents);
    }
}
