<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

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
 * The attacks the 2026-07-28 QA sweep performed successfully against the live
 * site. Each test is the exact scenario an agent used to move money it should
 * not have been able to move.
 *
 * These run against Razorpay because it is the cheapest gateway to sign for
 * (plain HMAC, no API round-trip to verify), but the guard they exercise is
 * shared by all three.
 */
final class WebhookForgeryTest extends IntegrationTestCase
{
    private const KEY_SECRET  = 'test_secret_abcd';
    private const TEST_HOOK   = 'whsec_test_side';
    private const LIVE_HOOK   = 'whsec_live_side';

    protected function setUp(): void
    {
        parent::setUp();

        update_option('dono_gateway_config', ['test_mode' => false]);
        update_option('dono_currency_locale', [
            'default_currency'     => 'INR',
            'supported_currencies' => ['INR'],
        ]);

        $c = Plugin::instance()->container;
        $account = $c->get(RazorpayAccount::class);
        $account->forget();
        $account->saveKeys(true, 'rzp_test_abc123', self::KEY_SECRET);
        $account->saveKeys(false, 'rzp_live_abc123', self::KEY_SECRET);
        $account->saveWebhookSecret(true, self::TEST_HOOK);
        $account->saveWebhookSecret(false, self::LIVE_HOOK);

        add_filter('pre_http_request', function ($pre, $args, $url) {
            if (! is_string($url) || ! str_contains($url, 'api.razorpay.com')) return $pre;
            return [
                'headers'  => [],
                'body'     => (string) wp_json_encode(['id' => 'order_1', 'status' => 'created']),
                'response' => ['code' => 200, 'message' => 'OK'],
                'cookies'  => [], 'filename' => null,
            ];
        }, 10, 3);

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

    /** A real, live-mode donation waiting to be paid. */
    private function liveDonation(int $amount = 777700): string
    {
        $req = new WP_REST_Request('POST', '/dono/v1/donations');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode([
            'email'        => 'victim@example.test',
            'amount_cents' => $amount,
            'currency'     => 'INR',
            'gateway'      => 'razorpay',
            'frequency'    => 'one_time',
            'profile'      => ['first_name' => 'Vic', 'last_name' => 'Tim'],
        ]));
        $data = rest_do_request($req)->get_data();
        $this->assertArrayHasKey('reference', $data, wp_json_encode($data));

        $donation = $this->donations()->findByReference($data['reference']);
        $this->assertFalse((bool) $donation->is_test, 'this fixture must be a live donation');

        return $data['reference'];
    }

    /** @param array<string,mixed> $payment */
    private function capturedEvent(array $payment): array
    {
        return ['event' => 'payment.captured', 'payload' => ['payment' => ['entity' => $payment]]];
    }

    /** @param array<string,mixed> $event */
    private function deliver(array $event, string $secret): \WP_REST_Response
    {
        $raw = (string) wp_json_encode($event);

        $req = new WP_REST_Request('POST', '/dono/v1/webhooks/razorpay');
        $req->set_header('content-type', 'application/json');
        $req->set_header('x-razorpay-signature', RazorpaySignature::forWebhook($raw, $secret));
        $req->set_header('x-razorpay-event-id', 'evt_' . md5($raw . microtime()));
        $req->set_body($raw);
        return rest_do_request($req);
    }

    private function donations(): DonationRepository
    {
        return Plugin::instance()->container->get(DonationRepository::class);
    }

    /**
     * The headline finding: a 1-unit capture confirmed a large donation because
     * nothing compared the paid amount against what was owed.
     */
    public function test_a_token_payment_cannot_confirm_a_large_donation(): void
    {
        $reference = $this->liveDonation(777700);
        $donation  = $this->donations()->findByReference($reference);

        $this->deliver($this->capturedEvent([
            'id'       => 'pay_cheap',
            'amount'   => 100,          // 1 rupee against a 7,777 rupee donation
            'currency' => 'INR',
            'status'   => 'captured',
            'order_id' => $donation->gateway_intent_id,
            'method'   => 'upi',
            'notes'    => ['dono_reference' => $reference],
        ]), self::LIVE_HOOK);

        $this->assertSame('pending', $this->donations()->findByReference($reference)->status);
    }

    /** A test-mode signing secret must not be able to touch live money. */
    public function test_a_test_secret_cannot_confirm_a_live_donation(): void
    {
        $reference = $this->liveDonation();
        $donation  = $this->donations()->findByReference($reference);

        $this->deliver($this->capturedEvent([
            'id'       => 'pay_wrongmode',
            'amount'   => 777700,       // correct amount, wrong secret
            'currency' => 'INR',
            'status'   => 'captured',
            'order_id' => $donation->gateway_intent_id,
            'method'   => 'upi',
            'notes'    => ['dono_reference' => $reference],
        ]), self::TEST_HOOK);

        $this->assertSame('pending', $this->donations()->findByReference($reference)->status);
    }

    public function test_a_payment_in_another_currency_cannot_confirm(): void
    {
        $reference = $this->liveDonation();
        $donation  = $this->donations()->findByReference($reference);

        $this->deliver($this->capturedEvent([
            'id'       => 'pay_fx',
            'amount'   => 777700,
            'currency' => 'USD',
            'status'   => 'captured',
            'order_id' => $donation->gateway_intent_id,
            'method'   => 'card',
            'notes'    => ['dono_reference' => $reference],
        ]), self::LIVE_HOOK);

        $this->assertSame('pending', $this->donations()->findByReference($reference)->status);
    }

    /** The whole point: a correct payment still goes through. */
    public function test_a_correct_payment_still_confirms(): void
    {
        $reference = $this->liveDonation();
        $donation  = $this->donations()->findByReference($reference);

        $res = $this->deliver($this->capturedEvent([
            'id'       => 'pay_good',
            'amount'   => 777700,
            'currency' => 'INR',
            'status'   => 'captured',
            'order_id' => $donation->gateway_intent_id,
            'method'   => 'upi',
            'notes'    => ['dono_reference' => $reference],
        ]), self::LIVE_HOOK);

        $this->assertSame(200, $res->get_status());
        $this->assertSame('paid', $this->donations()->findByReference($reference)->status);
    }

    /** Refusals are 200: the event is genuine, so a retry would not help. */
    public function test_a_refusal_does_not_ask_the_gateway_to_retry(): void
    {
        $reference = $this->liveDonation();
        $donation  = $this->donations()->findByReference($reference);

        $res = $this->deliver($this->capturedEvent([
            'id'       => 'pay_cheap2',
            'amount'   => 100,
            'currency' => 'INR',
            'status'   => 'captured',
            'order_id' => $donation->gateway_intent_id,
            'method'   => 'upi',
            'notes'    => ['dono_reference' => $reference],
        ]), self::LIVE_HOOK);

        $this->assertSame(200, $res->get_status());
    }
}
