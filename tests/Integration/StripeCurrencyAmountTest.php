<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Currency\FxRates;
use Dono\Donations\DonationRepository;
use Dono\Donations\Refund;
use Dono\Foundation\Plugin;
use Dono\Gateways\GatewayManager;
use Dono\Gateways\Stripe\StripeAccount;
use Dono\Gateways\Stripe\StripeGateway;
use WP_REST_Request;

/**
 * End-to-end currency scaling at the Stripe boundary: a donation stored as
 * major*100 must reach Stripe in the currency's smallest unit, and amounts
 * coming back (refunds) must be rescaled to the internal *100 convention.
 *
 * Zero-decimal (JPY): 1000 yen is stored 100000, charged 1000.
 * Two-decimal (USD):  $25.00 is stored 2500,    charged 2500 (unchanged).
 * Three-decimal (BHD): 5.000 BHD is stored 500,  charged 5000.
 *
 * All Stripe HTTP is intercepted via `pre_http_request`.
 */
final class StripeCurrencyAmountTest extends IntegrationTestCase
{
    private string $secret;

    /** @var array<int,array{method:string,url:string,body:string}> */
    private array $stripeCalls = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->secret = 'whsec_test_' . bin2hex(random_bytes(8));
        update_option('dono_gateway_config', [
            'test_mode' => true,
            'stripe'    => ['webhook_secret' => $this->secret],
        ]);

        // This suite exercises zero-/three-decimal currencies, so the org must
        // accept them or the create-path supported-currency gate rejects them.
        update_option('dono_currency_locale', [
            'default_currency'     => 'USD',
            'supported_currencies' => ['USD', 'EUR', 'JPY', 'BHD'],
        ]);

        // FX snapshot so non-base donation currencies clear the intake gate.
        update_option(FxRates::OPTION, [
            'base'       => 'USD',
            'date'       => gmdate('Y-m-d'),
            'fetched_at' => gmdate('c'),
            'rates'      => ['USD' => 1.0, 'JPY' => 150.0, 'BHD' => 0.376, 'EUR' => 0.92],
        ], false);

        $c = Plugin::instance()->container;
        $stripeAcct = $c->get(StripeAccount::class);
        $stripeAcct->saveKeys(true, 'sk_test_connected', 'pk_test_seed');
        $stripeAcct->saveKeys(false, 'sk_live_connected', 'pk_live_seed');
        $stripeAcct->refresh(['id' => 'acct_test_123', 'charges_enabled' => true]);

        $manager = $c->get(GatewayManager::class);
        if (! $manager->get('stripe')) {
            $manager->register(new StripeGateway(
                $c->get(\Dono\Gateways\Stripe\StripeApi::class),
                $c->get(DonationRepository::class),
                $c->get(\Dono\Donations\DonationService::class),
                $c->get(StripeAccount::class),
                $c->get(\Dono\Donors\DonorRepository::class),
                $c->get(\Dono\Donors\DonorService::class),
                $c->get(\Dono\Foundation\Time\Clock::class),
                $c->get(\Dono\Recurring\RecurringPlanRepository::class),
            ));
        }

        $this->mockStripeApi();
    }

    /**
     * @dataProvider currencyAmounts
     */
    public function test_charge_amount_is_scaled_per_currency(string $code, int $storedCents, int $expectedStripe): void
    {
        $this->createDonation($code, $storedCents, strtolower($code) . '@example.com');

        $pi = $this->findPaymentIntentFor($code);
        $this->assertNotNull($pi, "PaymentIntent created for {$code}");

        $this->assertSame(
            (string) $expectedStripe,
            $pi['amount'],
            "{$code}: {$storedCents} stored cents must reach Stripe as {$expectedStripe}"
        );

        // The full amount settles to the organization: Dono attaches no
        // application fee of its own.
        $this->assertArrayNotHasKey('application_fee_amount', $pi, "{$code}: no platform fee is ever attached");
    }

    /** @return array<string,array{0:string,1:int,2:int}> */
    public static function currencyAmounts(): array
    {
        return [
            // code, stored (major*100), expected Stripe amount
            'JPY zero-decimal'  => ['JPY', 100000, 1000],
            'USD two-decimal'   => ['USD', 2500, 2500],
            'BHD three-decimal' => ['BHD', 500, 5000],
        ];
    }

    /**
     * The gateway scales the refund out to Stripe's unit and rescales the
     * echoed amount back to internal storage, so both directions stay correct.
     */
    public function test_refund_scales_outbound_and_inbound(): void
    {
        $reference = $this->createDonation('JPY', 100000, 'refund-jpy@example.com');
        $donation  = $this->donations()->findByReference($reference);
        $this->assertNotNull($donation->gateway_intent_id);

        $this->stripeCalls = [];
        $gateway = Plugin::instance()->container->get(GatewayManager::class)->require('stripe');
        $result  = $gateway->refund($donation, 100000, 'donor requested');

        $this->assertTrue($result->success, 'refund succeeded');

        // Outbound: 100000 internal cents -> 1000 yen sent to Stripe.
        $refundCall = $this->findCall('/refunds');
        $this->assertNotNull($refundCall, 'a /refunds call was made');
        $body = $this->parseForm($refundCall['body']);
        $this->assertSame('1000', $body['amount'], 'refund amount sent to Stripe is in whole yen');

        // Inbound: Stripe echoes 1000 yen, recorded back as 100000 internal cents.
        $this->assertSame(100000, $result->amount_cents, 'refund result is rescaled to internal storage');
    }

    /** A `charge.refunded` webhook carrying whole-yen amounts records internal *100 cents. */
    public function test_charge_refunded_webhook_records_internal_amount(): void
    {
        $reference = $this->createDonation('JPY', 100000, 'wh-refund-jpy@example.com');
        $donation  = $this->donations()->findByReference($reference);

        // Drive to paid so the refund is recordable.
        $paid = $this->postWebhook('payment_intent.succeeded', [
            'id'                   => $donation->gateway_intent_id,
            'status'               => 'succeeded',
            'payment_method_types' => ['card'],
            'latest_charge'        => 'ch_test_jpy',
        ]);
        $this->assertSame(200, $paid->get_status(), 'paid webhook accepted: ' . wp_json_encode($paid->get_data()));
        $this->assertSame('paid', $this->donations()->findByReference($reference)->status);

        $res = $this->postWebhook('charge.refunded', [
            'id'             => 'ch_test_jpy',
            'payment_intent' => $donation->gateway_intent_id,
            'refunds'        => ['data' => [[
                'id'     => 're_wh_jpy_1',
                'amount' => 1000,          // whole yen, as Stripe sends it
                'reason' => 'requested_by_customer',
            ]]],
        ]);
        $this->assertSame(200, $res->get_status(), 'refund webhook accepted: ' . wp_json_encode($res->get_data()));

        $refund = Refund::query()->where('gateway_refund_id', 're_wh_jpy_1')->get();
        $this->assertNotNull($refund, 'external refund recorded');
        $this->assertSame(100000, (int) $refund->amount_cents, '1000 yen from Stripe is stored as 100000 internal cents');

        $this->assertSame('refunded', $this->donations()->findByReference($reference)->status,
            'full-value refund flips the donation to refunded');
    }

    private function createDonation(string $currency, int $amountCents, string $email): string
    {
        $req = new WP_REST_Request('POST', '/dono/v1/donations');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode([
            'email'        => $email,
            'amount_cents' => $amountCents,
            'currency'     => $currency,
            'gateway'      => 'stripe',
            'frequency'    => 'one_time',
            'profile'      => ['first_name' => 'Cur', 'last_name' => 'Test'],
        ]));
        $res  = rest_do_request($req);
        $data = $res->get_data();
        if (! isset($data['reference'])) {
            $this->fail("Donation creation failed for {$currency}: " . wp_json_encode($data));
        }
        return $data['reference'];
    }

    /** @return array<string,string>|null Parsed PaymentIntent body for the given currency. */
    private function findPaymentIntentFor(string $code): ?array
    {
        $needle = strtolower($code);
        foreach ($this->stripeCalls as $call) {
            if ($this->path($call['url']) !== '/v1/payment_intents') continue;
            $body = $this->parseForm($call['body']);
            if (($body['currency'] ?? '') === $needle) return $body;
        }
        return null;
    }

    private function findCall(string $pathPrefix): ?array
    {
        foreach ($this->stripeCalls as $call) {
            if (str_starts_with($this->path($call['url']), '/v1' . $pathPrefix)) return $call;
        }
        return null;
    }

    private function postWebhook(string $type, array $object): \WP_REST_Response
    {
        $event = [
            'id'   => 'evt_' . bin2hex(random_bytes(6)),
            'type' => $type,
            'data' => ['object' => $object],
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

    private function mockStripeApi(): void
    {
        add_filter('pre_http_request', function ($pre, $args, $url) {
            if (! str_starts_with((string) $url, 'https://api.stripe.com/')) return $pre;

            $body = (string) ($args['body'] ?? '');
            $this->stripeCalls[] = [
                'method' => (string) ($args['method'] ?? 'POST'),
                'url'    => (string) $url,
                'body'   => $body,
            ];

            $parsed = [];
            parse_str($body, $parsed);
            return [
                'headers'  => [],
                'body'     => (string) wp_json_encode($this->cannedResponse($this->path((string) $url), $parsed)),
                'response' => ['code' => 200, 'message' => 'OK'],
                'cookies'  => [],
                'filename' => null,
            ];
        }, 10, 3);
    }

    /** @param array<string,mixed> $body */
    private function cannedResponse(string $path, array $body): array
    {
        if ($path === '/v1/payment_intents') {
            $cur = strtolower((string) ($body['currency'] ?? 'usd'));
            return [
                'id'            => 'pi_test_' . $cur,
                'client_secret' => 'pi_test_' . $cur . '_secret',
                'status'        => 'requires_confirmation',
                'livemode'      => false,
            ];
        }
        if ($path === '/v1/refunds') {
            // Echo the amount Stripe was asked to refund, as Stripe would.
            return [
                'id'       => 're_test_' . count($this->stripeCalls),
                'amount'   => (int) ($body['amount'] ?? 0),
                'status'   => 'succeeded',
                'livemode' => false,
            ];
        }
        return match (true) {
            $path === '/v1/customers', str_starts_with($path, '/v1/customers/') => ['id' => 'cus_test'],
            $path === '/v1/products'      => ['id' => 'prod_test'],
            $path === '/v1/prices'        => ['id' => 'price_test'],
            $path === '/v1/subscriptions' => ['id' => 'sub_test', 'status' => 'active'],
            default                       => ['id' => 'obj_test'],
        };
    }

    private function path(string $url): string
    {
        return (string) (parse_url($url)['path'] ?? '');
    }

    /** @return array<string,string> */
    private function parseForm(string $body): array
    {
        parse_str($body, $out);
        return $out;
    }

    private function donations(): DonationRepository
    {
        return Plugin::instance()->container->get(DonationRepository::class);
    }
}
