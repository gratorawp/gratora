<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Donations\Donation;
use Gratora\Donations\DonationRepository;
use Gratora\Donations\DonationService;
use Gratora\Donors\DonorRepository;
use Gratora\Donors\DonorService;
use Gratora\Foundation\Plugin;
use Gratora\Foundation\Time\Clock;
use Gratora\Gateways\GatewayManager;
use Gratora\Gateways\Stripe\StripeAccount;
use Gratora\Gateways\Stripe\StripeApi;
use Gratora\Gateways\Stripe\StripeGateway;
use Gratora\Recurring\RecurringPlanRepository;
use WP_REST_Request;

/**
 * `gratora.gateway.stripe.automatic_payment_methods` is deliberately the
 * narrowest filter that lets an add-on turn redirect methods off. What it must
 * not reach is the rest of the PaymentIntent: capture_method, customer,
 * setup_future_usage and payment_method_types each break a donation, a renewal
 * or the create call itself, and none of them would show up as a wrong amount.
 *
 * Everything here is measured on the form-encoded body that leaves for Stripe.
 */
final class StripeAutomaticPaymentMethodsFilterTest extends IntegrationTestCase
{
    /** @var list<array{url:string,body:string}> */
    private array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();

        update_option('gratora_gateway_config', [
            'test_mode' => true,
            'stripe'    => ['webhook_secret_test' => 'whsec_apm'],
        ]);
        update_option('gratora_currency_locale', [
            'default_currency'     => 'USD',
            'supported_currencies' => ['USD'],
        ]);

        $c       = Plugin::instance()->container;
        $account = $c->get(StripeAccount::class);
        $account->saveKeys(true, 'sk_test_apm', 'pk_test_apm');
        $account->refresh(['id' => 'acct_apm', 'charges_enabled' => true]);

        $manager = $c->get(GatewayManager::class);
        if (! $manager->get('stripe')) {
            $manager->register(new StripeGateway(
                $c->get(StripeApi::class),
                $c->get(DonationRepository::class),
                $c->get(DonationService::class),
                $account,
                $c->get(DonorRepository::class),
                $c->get(DonorService::class),
                $c->get(Clock::class),
                $c->get(RecurringPlanRepository::class),
            ));
        }

        $this->mockStripe();
    }

    public function test_with_no_listener_the_intent_carries_exactly_what_it_carried_before(): void
    {
        $this->donate();

        $this->assertSame(
            ['enabled' => 'true'],
            $this->paymentIntentBody()['automatic_payment_methods'],
            'an uninstalled add-on adds no key to the wire'
        );
    }

    public function test_a_listener_can_set_allow_redirects(): void
    {
        $seen = null;
        add_filter('gratora.gateway.stripe.automatic_payment_methods', function (array $apm, $donation) use (&$seen) {
            $seen = $donation;
            return $apm + ['allow_redirects' => 'never'];
        }, 10, 2);

        $reference = $this->donate();

        $this->assertInstanceOf(Donation::class, $seen, 'the filter ran and was handed the donation');
        $this->assertSame($reference, $seen->reference, 'the donation handed over is the one being charged');
        $this->assertSame(
            ['enabled' => 'true', 'allow_redirects' => 'never'],
            $this->paymentIntentBody()['automatic_payment_methods']
        );
    }

    public function test_a_listener_cannot_turn_the_automatic_methods_off(): void
    {
        $ran = false;
        add_filter('gratora.gateway.stripe.automatic_payment_methods', function (array $apm) use (&$ran) {
            $ran = true;
            return ['enabled' => 'false'];
        });

        $this->donate();

        $this->assertTrue($ran, 'the filter ran, so the assertion below is about the clamp');
        $this->assertSame(
            ['enabled' => 'true'],
            $this->paymentIntentBody()['automatic_payment_methods'],
            'off is not on offer: a PaymentIntent with no payment methods is unpayable'
        );
    }

    public function test_a_listener_that_returns_nothing_usable_leaves_the_methods_on(): void
    {
        $ran = false;
        add_filter('gratora.gateway.stripe.automatic_payment_methods', function ($apm) use (&$ran) {
            $ran = true;
            return null;
        });

        $this->donate();

        $this->assertTrue($ran, 'the filter ran');
        $this->assertSame(['enabled' => 'true'], $this->paymentIntentBody()['automatic_payment_methods']);
    }

    public function test_a_listener_cannot_introduce_any_other_intent_key(): void
    {
        $ran = false;
        add_filter('gratora.gateway.stripe.automatic_payment_methods', function (array $apm) use (&$ran) {
            $ran = true;
            return $apm + [
                'capture_method'         => 'manual',
                'customer'               => 'cus_attacker',
                'setup_future_usage'     => 'off_session',
                'payment_method_types'   => ['card'],
                'transfer_data'          => ['destination' => 'acct_attacker'],
                'on_behalf_of'           => 'acct_attacker',
                'application_fee_amount' => 500,
            ];
        });

        $this->donate();

        $body = $this->paymentIntentBody();
        $this->assertTrue($ran, 'the filter ran');

        foreach (['capture_method', 'customer', 'setup_future_usage', 'payment_method_types', 'transfer_data', 'on_behalf_of', 'application_fee_amount'] as $key) {
            $this->assertArrayNotHasKey($key, $body, "`{$key}` did not reach the PaymentIntent");
        }

        $this->assertSame(
            ['enabled' => 'true'],
            $body['automatic_payment_methods'],
            'nor did any of it land inside automatic_payment_methods'
        );
    }

    public function test_a_listener_cannot_drop_what_a_recurring_intent_needs(): void
    {
        $ran = false;
        add_filter('gratora.gateway.stripe.automatic_payment_methods', function (array $apm) use (&$ran) {
            $ran = true;
            return [];
        });

        $this->donate('monthly');

        $body = $this->paymentIntentBody();
        $this->assertTrue($ran, 'the filter ran');
        $this->assertSame(['enabled' => 'true'], $body['automatic_payment_methods']);

        // Without these the card is never attached to a reusable identity and
        // every renewal after the first has nothing to bill.
        $this->assertSame('off_session', $body['setup_future_usage'] ?? null);
        $this->assertSame('cus_apm', $body['customer'] ?? null);
    }

    private function donate(string $frequency = 'one_time'): string
    {
        $req = new WP_REST_Request('POST', '/gratora/v1/donations');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode([
            'email'        => 'apm@example.test',
            'amount_cents' => 2500,
            'currency'     => 'USD',
            'gateway'      => 'stripe',
            'frequency'    => $frequency,
            'profile'      => ['first_name' => 'Apm', 'last_name' => 'Filter'],
        ]));

        $data = rest_do_request($req)->get_data();
        $this->assertArrayHasKey('reference', $data, (string) wp_json_encode($data));

        return (string) $data['reference'];
    }

    /** @return array<string,mixed> The form-encoded PaymentIntent body, parsed. */
    private function paymentIntentBody(): array
    {
        foreach ($this->calls as $call) {
            if (! str_contains($call['url'], '/payment_intents')) {
                continue;
            }

            parse_str($call['body'], $parsed);
            $this->assertIsArray(
                $parsed['automatic_payment_methods'] ?? null,
                'the intent body carries a nested automatic_payment_methods'
            );

            return $parsed;
        }

        $this->fail('no PaymentIntent was created');
    }

    private function mockStripe(): void
    {
        add_filter('pre_http_request', function ($pre, $args, $url) {
            if (! is_string($url) || ! str_contains($url, 'stripe.com')) return $pre;

            $this->calls[] = ['url' => $url, 'body' => (string) ($args['body'] ?? '')];

            $body = ['id' => 'obj_apm'];
            if (str_contains($url, '/customers')) {
                $body = ['id' => 'cus_apm'];
            } elseif (str_contains($url, '/payment_intents')) {
                $body = [
                    'id'            => 'pi_apm',
                    'client_secret' => 'pi_apm_secret',
                    'status'        => 'requires_payment_method',
                    'livemode'      => false,
                ];
            }

            return [
                'headers'  => [],
                'body'     => (string) wp_json_encode($body),
                'response' => ['code' => 200, 'message' => 'OK'],
                'cookies'  => [],
                'filename' => null,
            ];
        }, 10, 3);
    }
}
