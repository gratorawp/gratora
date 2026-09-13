<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Donations\Donation;
use Gratora\Foundation\Plugin;
use Gratora\Gateways\GatewayConfirmResult;
use Gratora\Gateways\GatewayIntentResult;
use Gratora\Gateways\GatewayManager;
use Gratora\Gateways\PaymentGateway;
use Gratora\Gateways\RefundResult;
use Gratora\Gateways\SupportsPaymentRetry;
use Gratora\Gateways\WebhookOutcome;
use Gratora\Recurring\PlanRow;
use Gratora\Recurring\RecurringPlan;
use WP_REST_Request;

/**
 * A plan that says "Past due, 3 failures" and offers nothing to do about it.
 *
 * Retry is offered per gateway, and DataViews drops an action a row is not
 * eligible for, so the one control that answers the row's own complaint just
 * was not there. The reason is knowable and was never said: on this owner's
 * site the plans are Stripe and Stripe is not connected, so nothing could be
 * asked of it.
 *
 * The two reasons are not the same and must not read the same. One names
 * something to go and do; the other says there is nothing to do because the
 * processor keeps its own schedule.
 */
final class WhyAPlanCannotBeRetriedTest extends IntegrationTestCase
{
    private function plan(string $gateway): RecurringPlan
    {
        $now = gmdate('Y-m-d H:i:s');

        $p = RecurringPlan::make();
        $p->donor_id                = 1;
        $p->gateway                 = $gateway;
        $p->gateway_subscription_id = 'sub_' . bin2hex(random_bytes(4));
        $p->amount_cents            = 2000;
        $p->currency                = 'USD';
        $p->interval_unit           = 'month';
        $p->interval_count          = 1;
        $p->status                  = 'past_due';
        $p->failed_renewals_count   = 3;
        $p->started_at              = $now;
        $p->created_at              = $now;
        $p->updated_at              = $now;
        $p->save();

        return $p;
    }

    /** @return array<string,mixed> */
    private function row(RecurringPlan $plan): array
    {
        return PlanRow::common($plan, Plugin::instance()->container->get(GatewayManager::class));
    }

    public function test_a_gateway_that_is_not_connected_says_so_and_names_it(): void
    {
        $row = $this->row($this->plan('stripe'));

        $this->assertFalse($row['can_retry']);
        $this->assertNotNull($row['retry_blocked']);
        $this->assertStringContainsString('Stripe', $row['retry_blocked'], 'names the gateway, not the slug');
    }

    /**
     * Offline settles out of band and is connected here, so it is the case of
     * a registered gateway with no retry endpoint: there is nothing to ask.
     */
    public function test_a_gateway_with_no_retry_endpoint_says_it_keeps_its_own_schedule(): void
    {
        $this->makeOfflinePayable();

        $row = $this->row($this->plan('offline'));

        $this->assertFalse($row['can_retry']);
        $this->assertNotNull($row['retry_blocked']);
        $this->assertStringNotContainsString(
            'connect',
            strtolower((string) $row['retry_blocked']),
            'connecting it would change nothing, so it must not be offered as the way out'
        );
    }

    /** The two causes do not read the same, or the sentence says nothing. */
    public function test_the_two_reasons_differ(): void
    {
        $this->makeOfflinePayable();

        $missing  = $this->row($this->plan('stripe'))['retry_blocked'];
        $noEndpoint = $this->row($this->plan('offline'))['retry_blocked'];

        $this->assertNotSame($missing, $noEndpoint);
    }

    /**
     * A plan that can be retried carries no reason at all.
     *
     * Stripe is the only core gateway with a retry endpoint and it registers
     * only while credentials are stored, so the capability is stood up here
     * rather than left to whatever the suite happens to have.
     */
    public function test_a_retryable_plan_has_nothing_to_explain(): void
    {
        $gateways = new GatewayManager();
        $gateways->register(new class implements PaymentGateway, SupportsPaymentRetry {
            public function id(): string { return 'retryable'; }
            public function label(): string { return 'Retryable'; }
            public function description(): string { return ''; }
            public function frequencies(): array { return ['recurring']; }
            public function paymentMethods(): array { return []; }
            public function countries(): array { return []; }
            public function currencies(): array { return []; }
            public function canCharge(): bool { return true; }
            public function createIntent(Donation $donation): GatewayIntentResult { return new GatewayIntentResult(); }
            public function confirm(Donation $donation, array $payload = []): GatewayConfirmResult { return new GatewayConfirmResult(success: false); }
            public function handleWebhook(WP_REST_Request $request): WebhookOutcome { return new WebhookOutcome(signature_ok: false); }
            public function refund(Donation $donation, int $amountCents, ?string $reason = null): RefundResult { return new RefundResult(success: false); }
            public function retryPayment(RecurringPlan $plan): void {}
        });

        $row = PlanRow::common($this->plan('retryable'), $gateways);

        $this->assertTrue($row['can_retry']);
        $this->assertNull($row['retry_blocked']);
    }
}
