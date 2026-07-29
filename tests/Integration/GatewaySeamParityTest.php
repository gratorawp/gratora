<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Foundation\Plugin;
use Dono\Gateways\BrowserAware;
use Dono\Gateways\GatewayIntentResult;
use Dono\Gateways\Razorpay\RazorpayAccount;
use Dono\Gateways\Razorpay\RazorpayGateway;

/**
 * Every gateway reaches the browser the same way.
 *
 * Core publishes `BrowserAware` for exactly this, and calls it: the donation
 * response merges whatever a gateway's `browserPayload()` returns, and the form
 * config merges its `publicConfig()`. Two gateways that shipped before that seam
 * existed still had hand-written branches beside it, which meant a gateway could
 * not be moved into an add-on without editing core's money route, and the seam
 * was carrying only the gateways nobody had special-cased yet.
 *
 * These pin the behaviour so the branches can go.
 */
final class GatewaySeamParityTest extends IntegrationTestCase
{
    /**
     * Built the way the app builds it. Core only registers the gateway once the
     * account is connected, so the keys go in first.
     */
    private function gateway(): RazorpayGateway
    {
        $container = Plugin::instance()->container;

        return new RazorpayGateway(
            new \Dono\Gateways\Razorpay\RazorpayApi($container->get(RazorpayAccount::class)),
            $container->get(RazorpayAccount::class),
            $container->get(\Dono\Donations\DonationRepository::class),
            $container->get(\Dono\Donations\DonationService::class),
            $container->get(\Dono\Gateways\Razorpay\RazorpayPlans::class),
            $container->get(\Dono\Recurring\RecurringPlanRepository::class),
            $container->get(\Dono\Foundation\Time\Clock::class),
        );
    }

    private function connect(): void
    {
        Plugin::instance()->container->get(RazorpayAccount::class)
            ->saveKeys(true, 'rzp_test_abc123', 'shhh');
    }

    public function test_razorpay_reaches_the_browser_through_the_shared_seam(): void
    {
        $this->assertInstanceOf(BrowserAware::class, $this->gateway());
    }

    /**
     * The same three fields the hand-written branch whitelisted. Metadata is
     * never echoed wholesale, so a field added to the gateway later cannot
     * reach the page by accident.
     */
    public function test_the_payload_carries_the_checkout_fields_and_nothing_else(): void
    {
        $payload = $this->gateway()->browserPayload(new GatewayIntentResult(
            intent_id: 'order_1',
            metadata:  [
                'razorpay_kind'            => 'order',
                'razorpay_order_id'        => 'order_1',
                'razorpay_subscription_id' => '',
                'razorpay_secret_ish'      => 'must-not-travel',
            ],
        ));

        $this->assertSame(['kind', 'order_id', 'subscription_id'], array_keys((array) $payload));
        $this->assertSame('order', $payload['kind']);
        $this->assertSame('order_1', $payload['order_id']);
    }

    /** Nothing to hand the browser when the intent never named a kind. */
    public function test_an_intent_with_no_kind_yields_no_payload(): void
    {
        $this->assertNull($this->gateway()->browserPayload(new GatewayIntentResult(intent_id: 'x')));
    }

    /**
     * The key id is public by design, like a Stripe publishable key, and
     * Checkout cannot open without it.
     */
    public function test_the_public_config_carries_the_key_id_for_the_mode(): void
    {
        $this->connect();

        $config = $this->gateway()->publicConfig(true, 'INR');

        $this->assertSame('rzp_test_abc123', $config['keyId']);
        $this->assertSame('INR', $config['currency']);
    }

    /** The secret is not public, whatever else is. */
    public function test_the_public_config_never_carries_the_secret(): void
    {
        $this->connect();

        $config = (string) wp_json_encode($this->gateway()->publicConfig(true, 'INR'));

        $this->assertStringNotContainsString('shhh', $config);
    }

    /**
     * A form that does not offer Razorpay must not carry its config, and a
     * store with no keys has nothing to carry.
     */
    public function test_an_unconfigured_mode_yields_no_config(): void
    {
        // Live keys were never entered, so there is nothing to open Checkout
        // with and the form must carry nothing rather than a blank key.
        $this->assertSame([], $this->gateway()->publicConfig(false, 'INR'));
    }
}
