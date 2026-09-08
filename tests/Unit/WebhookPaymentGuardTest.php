<?php

declare(strict_types=1);

namespace FundKit\Tests\Unit;

use FundKit\Donations\Donation;
use FundKit\Gateways\WebhookPaymentGuard;
use FundKit\Recurring\RecurringPlan;
use PHPUnit\Framework\TestCase;

/**
 * The guard that stands between a verified signature and a paid donation.
 *
 * Every case here is a hole the 2026-07-28 QA sweep proved open on the live
 * site, so each test names the money it would have moved.
 */
final class WebhookPaymentGuardTest extends TestCase
{
    private function donation(array $overrides = []): Donation
    {
        $d = Donation::make();
        $d->gateway      = $overrides['gateway'] ?? 'paypal';
        $d->is_test      = $overrides['is_test'] ?? false;
        $d->amount_cents = $overrides['amount_cents'] ?? 1000000;
        $d->currency     = $overrides['currency'] ?? 'USD';
        return $d;
    }

    public function test_a_matching_payment_is_allowed(): void
    {
        $this->assertNull(WebhookPaymentGuard::refuse(
            $this->donation(),
            'paypal',
            false,
            1000000,
            'USD'
        ));
    }

    public function test_an_underpayment_is_refused(): void
    {
        $reason = WebhookPaymentGuard::refuse($this->donation(), 'paypal', false, 1, 'USD');

        $this->assertNotNull($reason);
        $this->assertStringContainsString('paid 1 but the donation is for 1000000', $reason);
    }

    public function test_an_overpayment_is_refused_too(): void
    {
        $this->assertNotNull(WebhookPaymentGuard::refuse($this->donation(), 'paypal', false, 2000000, 'USD'));
    }

    public function test_a_different_currency_is_refused(): void
    {
        $reason = WebhookPaymentGuard::refuse($this->donation(), 'paypal', false, 1000000, 'MXN');

        $this->assertNotNull($reason);
        $this->assertStringContainsString('MXN', $reason);
    }

    public function test_an_event_from_another_gateway_is_refused(): void
    {
        $reason = WebhookPaymentGuard::refuse(
            $this->donation(['gateway' => 'stripe']),
            'paypal',
            false,
            1000000,
            'USD'
        );

        $this->assertNotNull($reason);
        $this->assertStringContainsString('stripe', $reason);
    }

    public function test_a_test_secret_cannot_confirm_a_live_donation(): void
    {
        $reason = WebhookPaymentGuard::refuse(
            $this->donation(['is_test' => false]),
            'paypal',
            true,
            1000000,
            'USD'
        );

        $this->assertNotNull($reason);
        $this->assertStringContainsString('test-mode secret', $reason);
    }

    public function test_a_live_secret_cannot_confirm_a_test_donation(): void
    {
        $this->assertNotNull(WebhookPaymentGuard::refuse(
            $this->donation(['is_test' => true]),
            'paypal',
            false,
            1000000,
            'USD'
        ));
    }

    public function test_matching_test_mode_is_allowed(): void
    {
        $this->assertNull(WebhookPaymentGuard::refuse(
            $this->donation(['is_test' => true]),
            'paypal',
            true,
            1000000,
            'USD'
        ));
    }

    public function test_an_unknown_verifying_mode_is_refused(): void
    {
        $this->assertNotNull(WebhookPaymentGuard::refuse($this->donation(), 'paypal', null, 1000000, 'USD'));
    }

    public function test_an_absent_amount_is_refused(): void
    {
        $reason = WebhookPaymentGuard::refuse($this->donation(), 'paypal', false, null, 'USD');

        $this->assertNotNull($reason);
        $this->assertStringContainsString('does not state an amount', $reason);
    }

    public function test_currency_may_be_skipped(): void
    {
        $this->assertNull(WebhookPaymentGuard::refuse($this->donation(), 'paypal', false, 1000000, null));
    }

    public function test_currency_comparison_ignores_case(): void
    {
        $this->assertNull(WebhookPaymentGuard::refuse(
            $this->donation(['currency' => 'USD']),
            'paypal',
            false,
            1000000,
            'usd'
        ));
    }

    // -- events that reverse rather than confirm ------------------------------

    /**
     * Refund, failure, and cancellation events require gateway/mode checks without
     * confirmation-specific amount validation.
     */
    public function test_a_test_secret_cannot_refund_a_live_donation(): void
    {
        $reason = WebhookPaymentGuard::refuseToTouch($this->donation(['is_test' => false]), 'paypal', true);

        $this->assertNotNull($reason);
        $this->assertStringContainsString('test-mode secret', $reason);
    }

    public function test_a_live_secret_cannot_fail_a_test_donation(): void
    {
        $this->assertNotNull(
            WebhookPaymentGuard::refuseToTouch($this->donation(['is_test' => true]), 'paypal', false)
        );
    }

    public function test_an_event_from_another_gateway_cannot_touch_the_donation(): void
    {
        $reason = WebhookPaymentGuard::refuseToTouch($this->donation(['gateway' => 'stripe']), 'paypal', false);

        $this->assertNotNull($reason);
        $this->assertStringContainsString('stripe', $reason);
    }

    public function test_an_unknown_verifying_mode_cannot_touch_the_donation(): void
    {
        $this->assertNotNull(WebhookPaymentGuard::refuseToTouch($this->donation(), 'paypal', null));
    }

    public function test_a_matching_gateway_and_mode_may_touch_the_donation(): void
    {
        $this->assertNull(WebhookPaymentGuard::refuseToTouch($this->donation(), 'paypal', false));
    }

    public function test_a_test_secret_cannot_cancel_a_live_plan(): void
    {
        $plan          = RecurringPlan::make();
        $plan->gateway = 'paypal';
        $plan->is_test = false;

        $this->assertNotNull(WebhookPaymentGuard::refuseToTouchPlan($plan, 'paypal', true));
    }

    public function test_a_matching_mode_may_touch_the_plan(): void
    {
        $plan          = RecurringPlan::make();
        $plan->gateway = 'paypal';
        $plan->is_test = true;

        $this->assertNull(WebhookPaymentGuard::refuseToTouchPlan($plan, 'paypal', true));
    }
}
