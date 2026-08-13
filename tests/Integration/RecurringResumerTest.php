<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donations\Donation;
use Dono\Foundation\Plugin;
use Dono\Gateways\GatewayConfirmResult;
use Dono\Gateways\GatewayIntentResult;
use Dono\Gateways\GatewayManager;
use Dono\Gateways\PaymentGateway;
use Dono\Gateways\RefundResult;
use Dono\Gateways\SubscriptionAware;
use Dono\Gateways\WebhookOutcome;
use Dono\Recurring\RecurringPlan;
use Dono\Recurring\RecurringResumer;
use RuntimeException;
use WP_REST_Request;

/**
 * `pauseSubscription()` documents `$resumesAt` as honoured, and only Stripe
 * can. PayPal's suspend is indefinite and was dropping
 * the date, so a donor's "skip next payment" stopped the subscription for good
 * behind a next-payment date the portal kept showing them.
 *
 * The schedule now lives here once instead of in each gateway.
 */
final class RecurringResumerTest extends IntegrationTestCase
{
    private function resumer(): RecurringResumer
    {
        return Plugin::instance()->container->get(RecurringResumer::class);
    }

    private function plan(array $attrs = []): RecurringPlan
    {
        $now = gmdate('Y-m-d H:i:s');
        $p = RecurringPlan::make();
        $p->donor_id       = 1;
        $p->gateway        = $attrs['gateway'] ?? 'offline';
        $p->gateway_subscription_id = 'sub_' . uniqid();
        $p->amount_cents   = 2500;
        $p->currency       = 'USD';
        $p->interval_unit  = 'month';
        $p->interval_count = 1;
        $p->status         = $attrs['status'] ?? 'paused';
        $p->resume_at      = $attrs['resume_at'] ?? null;
        $p->is_test        = false;
        $p->started_at     = $now;
        $p->created_at     = $now;
        $p->updated_at     = $now;
        $p->save();
        return $p;
    }

    private function reload(RecurringPlan $p): RecurringPlan
    {
        return RecurringPlan::query()->find('id', (int) $p->id);
    }

    public function test_a_pause_whose_window_has_closed_is_lifted(): void
    {
        $plan = $this->plan(['resume_at' => gmdate('Y-m-d H:i:s', time() - 3600)]);

        $this->resumer()->run();

        $fresh = $this->reload($plan);
        $this->assertSame('active', $fresh->status);
        $this->assertNull($fresh->resume_at, 'the plan stops matching the sweep');
    }

    public function test_a_pause_still_inside_its_window_is_left_alone(): void
    {
        $plan = $this->plan(['resume_at' => gmdate('Y-m-d H:i:s', time() + 86400)]);

        $this->resumer()->run();

        $this->assertSame('paused', $this->reload($plan)->status);
    }

    public function test_an_indefinite_pause_is_never_auto_resumed(): void
    {
        $plan = $this->plan(['resume_at' => null]);

        $this->resumer()->run();

        $this->assertSame('paused', $this->reload($plan)->status, 'no resume date means no resume');
    }

    public function test_a_cancelled_plan_is_never_resumed(): void
    {
        $plan = $this->plan([
            'status'    => 'cancelled',
            'resume_at' => gmdate('Y-m-d H:i:s', time() - 3600),
        ]);

        $this->resumer()->run();

        $this->assertSame('cancelled', $this->reload($plan)->status);
    }

    /**
     * "Skip next payment" leaves the plan active (one missed cycle is not a
     * pause) but still pauses it at the gateway, so it has to be un-paused too.
     * Keying the sweep on status would have missed exactly this case.
     */
    public function test_a_skipped_cycle_is_swept_even_though_the_plan_reads_active(): void
    {
        $plan = $this->plan([
            'status'    => 'active',
            'resume_at' => gmdate('Y-m-d H:i:s', time() - 3600),
        ]);

        $this->resumer()->run();

        $fresh = $this->reload($plan);
        $this->assertSame('active', $fresh->status);
        $this->assertNull($fresh->resume_at, 'the gateway was told to resume, so the marker clears');
    }

    /**
     * A gateway failing every call must not leave the sweep re-selecting the
     * same rows and re-enqueuing itself forever.
     */
    public function test_a_failing_gateway_backs_off_instead_of_spinning(): void
    {
        $gateway = new class implements PaymentGateway, SubscriptionAware {
            public function id(): string { return 'explodes'; }
            public function label(): string { return 'Explodes'; }
            public function description(): string { return ''; }
            public function frequencies(): array { return ['one_time']; }
            public function paymentMethods(): array { return []; }
            public function countries(): array { return []; }
            public function currencies(): array { return []; }
            public function canCharge(): bool { return true; }
            public function createIntent(Donation $donation): GatewayIntentResult { return new GatewayIntentResult(); }
            public function confirm(Donation $donation, array $payload = []): GatewayConfirmResult { return new GatewayConfirmResult(success: false); }
            public function handleWebhook(WP_REST_Request $request): WebhookOutcome { return new WebhookOutcome(signature_ok: false); }
            public function refund(Donation $donation, int $amountCents, ?string $reason = null): RefundResult { return new RefundResult(success: false); }
            public function cancelSubscription(RecurringPlan $plan, ?string $reason = null): void {}
            public function pauseSubscription(RecurringPlan $plan, ?string $resumesAt = null): void {}
            public function updateSubscriptionAmount(RecurringPlan $plan, int $amountCents): void {}

            public function resumeSubscription(RecurringPlan $plan): void
            {
                throw new RuntimeException('gateway is down');
            }
        };
        Plugin::instance()->container->get(GatewayManager::class)->register($gateway);

        $due  = gmdate('Y-m-d H:i:s', time() - 3600);
        $plan = $this->plan(['gateway' => 'explodes', 'resume_at' => $due]);

        $this->resumer()->run();

        $fresh = $this->reload($plan);
        $this->assertSame('paused', $fresh->status, 'still paused: the gateway never lifted it');
        $this->assertNotNull($fresh->resume_at, 'still owed a resume');
        $this->assertGreaterThan($due, (string) $fresh->resume_at, 'pushed out, so the batch drains');
    }
    public function test_a_plan_whose_gateway_is_gone_is_not_marked_active(): void
    {
        // Stripe registers only while its credentials are stored, so an absent
        // gateway means the subscription is still suspended at the processor.
        // Marking the row active would put it back in MRR behind a next payment
        // that never comes.
        $plan = $this->plan([
            'gateway'   => 'stripe',
            'status'    => 'paused',
            'resume_at' => gmdate('Y-m-d H:i:s', time() - 3600),
        ]);

        $this->resumer()->run();

        $after = $this->reload($plan);
        $this->assertSame('paused', $after->status, 'it stays paused');
        $this->assertNotNull($after->resume_at, 'and keeps the marker that it is owed a resume');
        $this->assertGreaterThan(gmdate('Y-m-d H:i:s'), (string) $after->resume_at, 'backed off rather than retried in a spin');
    }
}
