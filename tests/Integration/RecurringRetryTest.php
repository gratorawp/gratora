<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Foundation\Plugin;
use Dono\Gateways\GatewayIntentResult;
use Dono\Gateways\GatewayConfirmResult;
use Dono\Gateways\GatewayManager;
use Dono\Gateways\PaymentGateway;
use Dono\Gateways\PaymentRetryUnavailable;
use Dono\Gateways\RefundResult;
use Dono\Gateways\SubscriptionAware;
use Dono\Gateways\SupportsPaymentRetry;
use Dono\Recurring\RecurringPlan;
use Dono\Recurring\RecurringPlanActions;
use Dono\Recurring\RecurringPlanChange;
use Dono\Recurring\RecurringPlanRepository;
use WP_REST_Request;

/**
 * Retrying a declined renewal.
 *
 * The donor screen told admins to "open the Recurring tab to retry" on every
 * gateway, and no retry existed anywhere. Now that one does, the thing worth
 * pinning is that it is only offered where it can actually work: PayPal runs
 * its own retry schedule and publishes no endpoint to force one, so a button
 * there would do nothing at all.
 */
final class RecurringRetryTest extends IntegrationTestCase
{
    private function plan(string $gateway, int $failures = 1): RecurringPlan
    {
        $p = RecurringPlan::make();
        $p->donor_id                = 1;
        $p->gateway                 = $gateway;
        $p->gateway_subscription_id = 'sub_' . uniqid();
        $p->amount_cents            = 2500;
        $p->currency                = 'USD';
        $p->interval_unit           = 'month';
        $p->interval_count          = 1;
        $p->status                  = 'past_due';
        $p->failed_renewals_count   = $failures;
        $p->started_at              = gmdate('Y-m-d H:i:s');
        $p->created_at              = gmdate('Y-m-d H:i:s');
        $p->updated_at              = gmdate('Y-m-d H:i:s');
        $p->save();

        return $p;
    }

    private function actions(): RecurringPlanActions
    {
        return Plugin::instance()->container->get(RecurringPlanActions::class);
    }

    public function test_a_gateway_that_can_retry_is_asked_to_collect(): void
    {
        $gateway = new RetryableGateway('retryable_ok');
        Plugin::instance()->container->get(GatewayManager::class)->register($gateway);

        $plan = $this->plan('retryable_ok');
        $this->actions()->retryPayment($plan, RecurringPlanChange::byAdmin('retry', false));

        $this->assertSame(1, $gateway->retries, 'the gateway was asked exactly once');
    }

    public function test_a_gateway_with_no_retry_endpoint_refuses_rather_than_pretending(): void
    {
        // PayPal's shape: it can pause and cancel, but cannot force a charge.
        Plugin::instance()->container->get(GatewayManager::class)->register(new NoRetryGateway());

        $plan = $this->plan('noretry');

        $this->expectException(\InvalidArgumentException::class);
        $this->actions()->retryPayment($plan, RecurringPlanChange::byAdmin('retry', false));
    }

    /** Nothing outstanding is not a failure to try again; it is a different answer. */
    public function test_nothing_to_collect_is_reported_as_such(): void
    {
        $gateway = new RetryableGateway('retryable_empty');
        $gateway->nothingToCollect = true;
        Plugin::instance()->container->get(GatewayManager::class)->register($gateway);

        $plan = $this->plan('retryable_empty');

        $this->expectException(PaymentRetryUnavailable::class);
        $this->actions()->retryPayment($plan, RecurringPlanChange::byAdmin('retry', false));
    }

    public function test_the_admin_route_reports_nothing_to_collect_as_409(): void
    {
        $gateway = new RetryableGateway('retryable_rest');
        $gateway->nothingToCollect = true;
        Plugin::instance()->container->get(GatewayManager::class)->register($gateway);

        $plan = $this->plan('retryable_rest');
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        $req = new WP_REST_Request('POST', '/dono/v1/admin/recurring/' . (int) $plan->id . '/action');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode(['action' => 'retry']));
        $res = rest_do_request($req);

        // Not 502: the gateway did not fail, there is simply nothing owing, and
        // an error telling the admin to try again would be wrong.
        $this->assertSame(409, $res->get_status());
    }

    /** A recovered plan must not keep wearing a warning for a decline it survived. */
    public function test_a_successful_renewal_clears_the_failure_count(): void
    {
        $plan = $this->plan('retryable', 3);

        Plugin::instance()->container->get(RecurringPlanRepository::class)
            ->recordPayment($plan, 2500, gmdate('Y-m-d H:i:s'));

        $fresh = RecurringPlan::query()->find('id', (int) $plan->id);
        $this->assertSame(0, (int) $fresh->failed_renewals_count, 'the counter is consecutive failures, not a lifetime tally');
        $this->assertSame(0, (int) $plan->failed_renewals_count, 'and the in-memory model agrees with the row');
    }
}

/** Collects on demand, the way Stripe does through its open invoice. */
final class RetryableGateway implements PaymentGateway, SubscriptionAware, SupportsPaymentRetry
{
    public int $retries = 0;
    public bool $nothingToCollect = false;

    public function __construct(private string $id = 'retryable') {}

    public function id(): string { return $this->id; }
    public function label(): string { return 'Retryable'; }
    public function description(): string { return ''; }
    public function frequencies(): array { return ['monthly']; }
    public function paymentMethods(): array { return []; }
    public function countries(): array { return []; }
    public function currencies(): array { return ['USD']; }
    public function canCharge(): bool { return true; }

    public function createIntent(\Dono\Donations\Donation $d): GatewayIntentResult
    {
        return new GatewayIntentResult(ok: false, error: 'not used');
    }
    public function confirm(\Dono\Donations\Donation $d, array $payload = []): GatewayConfirmResult
    {
        return new GatewayConfirmResult(ok: false, error: 'not used');
    }
    public function handleWebhook(WP_REST_Request $r): \Dono\Gateways\WebhookOutcome
    {
        return new \Dono\Gateways\WebhookOutcome(signature_ok: false, external_id: '', event_type: '', handled: false);
    }
    public function refund(\Dono\Donations\Donation $d, int $cents, ?string $reason = null): RefundResult
    {
        return new RefundResult(ok: false, error: 'not used');
    }

    public function cancelSubscription(RecurringPlan $p, ?string $reason = null): void {}
    public function pauseSubscription(RecurringPlan $p, ?string $resumesAt = null): void {}
    public function resumeSubscription(RecurringPlan $p): void {}
    public function updateSubscriptionAmount(RecurringPlan $p, int $cents): void {}

    public function retryPayment(RecurringPlan $plan): void
    {
        if ($this->nothingToCollect) {
            throw new PaymentRetryUnavailable('Nothing outstanding.');
        }
        $this->retries++;
    }
}

/** PayPal's shape: subscription-aware, but no way to force a collection. */
final class NoRetryGateway implements PaymentGateway, SubscriptionAware
{
    public function __construct(private string $id = 'noretry') {}

    public function id(): string { return $this->id; }
    public function label(): string { return 'No retry'; }
    public function description(): string { return ''; }
    public function frequencies(): array { return ['monthly']; }
    public function paymentMethods(): array { return []; }
    public function countries(): array { return []; }
    public function currencies(): array { return ['USD']; }
    public function canCharge(): bool { return true; }

    public function createIntent(\Dono\Donations\Donation $d): GatewayIntentResult
    {
        return new GatewayIntentResult(ok: false, error: 'not used');
    }
    public function confirm(\Dono\Donations\Donation $d, array $payload = []): GatewayConfirmResult
    {
        return new GatewayConfirmResult(ok: false, error: 'not used');
    }
    public function handleWebhook(WP_REST_Request $r): \Dono\Gateways\WebhookOutcome
    {
        return new \Dono\Gateways\WebhookOutcome(signature_ok: false, external_id: '', event_type: '', handled: false);
    }
    public function refund(\Dono\Donations\Donation $d, int $cents, ?string $reason = null): RefundResult
    {
        return new RefundResult(ok: false, error: 'not used');
    }

    public function cancelSubscription(RecurringPlan $p, ?string $reason = null): void {}
    public function pauseSubscription(RecurringPlan $p, ?string $resumesAt = null): void {}
    public function resumeSubscription(RecurringPlan $p): void {}
    public function updateSubscriptionAmount(RecurringPlan $p, int $cents): void {}
}
