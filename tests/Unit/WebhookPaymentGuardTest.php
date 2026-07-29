<?php

declare(strict_types=1);

namespace Dono\Tests\Unit;

use Dono\Donations\Donation;
use Dono\Gateways\WebhookPaymentGuard;
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

    /** A $0.01 capture confirmed a $10,000 donation. */
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

    /** A USD 2,000 donation was confirmed by an MXN 1.00 capture. */
    public function test_a_different_currency_is_refused(): void
    {
        $reason = WebhookPaymentGuard::refuse($this->donation(), 'paypal', false, 1000000, 'MXN');

        $this->assertNotNull($reason);
        $this->assertStringContainsString('MXN', $reason);
    }

    /** A PayPal event confirmed a Stripe donation. */
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

    /** A test-mode secret marked a live donation paid on two gateways. */
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

    /** And the reverse, so live traffic cannot pollute test bookkeeping. */
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

    /** Unknown is not the same as fine: fail closed. */
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

    /** Currency is optional for gateways that omit it; the amount is not. */
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
}
