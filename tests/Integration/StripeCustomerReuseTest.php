<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donations\DonationRepository;
use Dono\Donations\DonationService;
use Dono\Donors\DonorRepository;
use Dono\Donors\DonorService;
use Dono\Foundation\Plugin;
use Dono\Foundation\Time\Clock;
use Dono\Gateways\GatewayManager;
use Dono\Gateways\Stripe\StripeAccount;
use Dono\Gateways\Stripe\StripeApi;
use Dono\Gateways\Stripe\StripeGateway;
use Dono\Recurring\RecurringPlan;
use Dono\Recurring\RecurringPlanRepository;
use WP_REST_Request;

/**
 * Stripe refuses an idempotency key replayed with different parameters, so a key
 * that is stable per donor while the body carries the donation is not a
 * safeguard, it is a guaranteed failure on that donor's second donation. The
 * Customer belongs to the donor, so it holds nothing donation-specific and is
 * reused once the donor has one.
 */
final class StripeCustomerReuseTest extends IntegrationTestCase
{
    /** @var list<array{url:string,body:string,key:string}> */
    private array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();

        update_option('dono_gateway_config', [
            'test_mode' => true,
            'stripe'    => ['webhook_secret_test' => 'whsec_reuse'],
        ]);
        update_option('dono_currency_locale', [
            'default_currency'     => 'USD',
            'supported_currencies' => ['USD'],
        ]);

        $c = Plugin::instance()->container;
        $account = $c->get(StripeAccount::class);
        $account->saveKeys(true, 'sk_test_reuse', 'pk_test_reuse');
        $account->refresh(['id' => 'acct_reuse', 'charges_enabled' => true]);

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

        add_filter('pre_http_request', function ($pre, $args, $url) {
            if (! is_string($url) || ! str_contains($url, 'stripe.com')) return $pre;

            $this->calls[] = [
                'url'  => $url,
                'body' => (string) ($args['body'] ?? ''),
                'key'  => (string) ($args['headers']['Idempotency-Key'] ?? ''),
            ];

            static $seq = 0;
            $seq++;
            $body = ['id' => 'obj_reuse_' . $seq];
            if (str_contains($url, '/customers')) {
                $body = ['id' => 'cus_reuse'];
            } elseif (str_contains($url, '/payment_intents')) {
                $body = ['id' => 'pi_reuse_' . $seq, 'client_secret' => 'pi_reuse_secret_' . $seq, 'status' => 'requires_payment_method'];
            }

            return [
                'headers'  => [],
                'body'     => (string) wp_json_encode($body),
                'response' => ['code' => 200, 'message' => 'OK'],
                'cookies'  => [], 'filename' => null,
            ];
        }, 10, 3);
    }

    private function donate(string $frequency = 'weekly'): string
    {
        $create = new WP_REST_Request('POST', '/dono/v1/donations');
        $create->set_header('content-type', 'application/json');
        $create->set_body((string) wp_json_encode([
            'email'        => 'reuse@example.test',
            'amount_cents' => 2500,
            'currency'     => 'USD',
            'gateway'      => 'stripe',
            'frequency'    => $frequency,
            'profile'      => ['first_name' => 'Re', 'last_name' => 'Use'],
        ]));
        $data = rest_do_request($create)->get_data();
        $this->assertArrayHasKey('reference', $data, (string) wp_json_encode($data));

        return (string) $data['reference'];
    }

    /** @return list<array{url:string,body:string,key:string}> */
    private function customerCalls(): array
    {
        return array_values(array_filter(
            $this->calls,
            static fn (array $c): bool => str_contains($c['url'], '/customers')
        ));
    }

    public function test_the_customer_carries_nothing_about_the_donation_that_created_it(): void
    {
        $this->donate();

        $calls = $this->customerCalls();
        $this->assertNotEmpty($calls, 'a customer is created for a recurring donation');

        // A Customer outlives the donation that prompted it, so stamping a
        // donation id on it is wrong on its own terms, and it is also what makes
        // the body differ between two donations by the same donor.
        $this->assertStringNotContainsString('dono_donation_id', $calls[0]['body']);
        $this->assertStringContainsString('dono_donor_id', $calls[0]['body']);
    }

    public function test_a_second_donation_by_the_same_donor_does_not_reuse_a_key_with_a_different_body(): void
    {
        $this->donate();
        $this->donate();

        $calls = $this->customerCalls();
        $this->assertGreaterThanOrEqual(2, count($calls), 'both donations reached customer creation');

        // Stripe answers a replayed key carrying different parameters with a
        // hard error, which fails the donation before it has a PaymentIntent.
        // Either the bodies match, or the keys differ. Never the same key with
        // different bodies.
        $byKey = [];
        foreach ($calls as $call) {
            $byKey[$call['key']][] = $call['body'];
        }
        foreach ($byKey as $key => $bodies) {
            $this->assertCount(
                1,
                array_unique($bodies),
                "idempotency key {$key} was replayed with a different body"
            );
        }
    }

    public function test_a_donor_who_already_has_a_plan_reuses_its_customer(): void
    {
        $reference = $this->donate();
        $donation  = Plugin::instance()->container->get(DonationRepository::class)->findByReference($reference);

        $plan = RecurringPlan::make();
        $plan->donor_id            = (int) $donation->donor_id;
        $plan->gateway             = 'stripe';
        $plan->is_test             = true;
        $plan->gateway_customer_id = 'cus_existing';
        $plan->gateway_subscription_id = 'sub_existing';
        $plan->amount_cents        = 2500;
        $plan->currency            = 'USD';
        $plan->interval_unit       = 'week';
        $plan->interval_count      = 1;
        $plan->status              = 'active';
        $plan->started_at          = '2026-08-01 00:00:00';
        $plan->next_payment_at     = '2026-08-18 00:00:00';
        $plan->created_at          = '2026-08-01 00:00:00';
        $plan->save();

        $this->calls = [];
        $this->donate();

        // One donor, one Customer: the portal's change-card path works from a
        // single record, and a second Customer splits a donor's cards across
        // records neither screen can reconcile.
        $this->assertSame([], $this->customerCalls(), 'no second customer is created');
    }
}
