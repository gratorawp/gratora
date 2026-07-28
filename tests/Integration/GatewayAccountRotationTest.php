<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Foundation\Plugin;
use Dono\Gateways\PayPal\PayPalAccount;
use Dono\Gateways\PayPal\PayPalPlans;
use Dono\Gateways\Razorpay\RazorpayAccount;
use Dono\Gateways\Razorpay\RazorpayPlans;

/**
 * A Plan or Product lives inside one merchant account and means nothing in
 * another, but all three gateways cached them under a key naming only the mode.
 * Connect a different account and the old ids kept being handed to it, so every
 * recurring donation failed and no admin action could clear the cache.
 */
final class GatewayAccountRotationTest extends IntegrationTestCase
{
    /** @var array<int,array{url:string,body:array<string,mixed>}> */
    private array $calls = [];

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        delete_option('dono_razorpay_plans');
        delete_option('dono_paypal_plans');
        delete_option('dono_paypal_product');

        add_filter('pre_http_request', function ($pre, $args, $url) {
            if (! is_string($url)) return $pre;
            if (! str_contains($url, 'razorpay.com') && ! str_contains($url, 'paypal.com')) return $pre;

            $decoded = json_decode((string) ($args['body'] ?? ''), true);
            $this->calls[] = ['url' => $url, 'body' => is_array($decoded) ? $decoded : []];

            $this->seq++;
            $id = str_contains($url, 'razorpay.com') ? "plan_r{$this->seq}" : "P-{$this->seq}";

            return [
                'headers'  => [],
                'body'     => (string) wp_json_encode(['id' => $id, 'access_token' => 't', 'expires_in' => 3600]),
                'response' => ['code' => 200, 'message' => 'OK'],
            ];
        }, 10, 3);
    }

    protected function tearDown(): void
    {
        delete_option('dono_razorpay_plans');
        delete_option('dono_paypal_plans');
        delete_option('dono_paypal_product');
        parent::tearDown();
    }

    public function test_razorpay_provisions_a_fresh_plan_after_an_account_change(): void
    {
        $account = Plugin::instance()->container->get(RazorpayAccount::class);
        $plans   = Plugin::instance()->container->get(RazorpayPlans::class);

        $account->forget();
        $account->saveKeys(true, 'rzp_test_FIRSTMERCHANT', 'secret-one');
        $first = $plans->resolvePlan(true, 50000, 'INR', 'monthly', 1);

        // Same shape again on the same account: served from cache, no new call.
        $before = count($this->calls);
        $this->assertSame($first, $plans->resolvePlan(true, 50000, 'INR', 'monthly', 1));
        $this->assertCount($before, $this->calls, 'an unchanged account reuses its plan');

        $account->saveKeys(true, 'rzp_test_OTHERMERCHANT', 'secret-two');
        $second = $plans->resolvePlan(true, 50000, 'INR', 'monthly', 1);

        $this->assertNotSame($first, $second, 'the new merchant gets its own plan, not the old one');
        $this->assertGreaterThan($before, count($this->calls), 'which means it was actually provisioned');
    }

    /** Rotating back finds the original rather than provisioning a third. */
    public function test_razorpay_rotating_back_reuses_the_original_plan(): void
    {
        $account = Plugin::instance()->container->get(RazorpayAccount::class);
        $plans   = Plugin::instance()->container->get(RazorpayPlans::class);

        $account->forget();
        $account->saveKeys(true, 'rzp_test_FIRSTMERCHANT', 'secret-one');
        $first = $plans->resolvePlan(true, 50000, 'INR', 'monthly', 1);

        $account->saveKeys(true, 'rzp_test_OTHERMERCHANT', 'secret-two');
        $plans->resolvePlan(true, 50000, 'INR', 'monthly', 1);

        $account->saveKeys(true, 'rzp_test_FIRSTMERCHANT', 'secret-one');
        $this->assertSame($first, $plans->resolvePlan(true, 50000, 'INR', 'monthly', 1));
    }

    public function test_paypal_provisions_a_fresh_plan_after_an_account_change(): void
    {
        $account = Plugin::instance()->container->get(PayPalAccount::class);
        $plans   = Plugin::instance()->container->get(PayPalPlans::class);

        $account->forget();
        $account->saveKeys(true, 'client-first', 'secret-one');
        $first = $plans->resolvePlan(true, 2500, 'USD', 'MONTH', 1);

        $account->saveKeys(true, 'client-second', 'secret-two');
        $second = $plans->resolvePlan(true, 2500, 'USD', 'MONTH', 1);

        $this->assertNotSame($first, $second);
    }

    /**
     * The product is the worse half: a plan hangs off it, so a stale product id
     * fails plan creation for the new account too.
     */
    public function test_paypal_products_are_scoped_to_the_account(): void
    {
        $account = Plugin::instance()->container->get(PayPalAccount::class);
        $plans   = Plugin::instance()->container->get(PayPalPlans::class);

        $account->forget();
        $account->saveKeys(true, 'client-first', 'secret-one');
        $plans->resolvePlan(true, 2500, 'USD', 'MONTH', 1);

        $account->saveKeys(true, 'client-second', 'secret-two');
        $plans->resolvePlan(true, 2500, 'USD', 'MONTH', 1);

        $stored = get_option('dono_paypal_product', []);
        $this->assertCount(2, $stored, 'one product per account, not one shared across both');
    }
}
