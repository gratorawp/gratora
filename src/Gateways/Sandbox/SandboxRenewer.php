<?php

declare(strict_types=1);

namespace Gratora\Gateways\Sandbox;

use Gratora\Async\AsyncDispatcher;
use Gratora\Donations\DonationService;
use Gratora\Foundation\Batch\BatchProcessor;
use Gratora\Foundation\Time\Clock;
use Gratora\Gateways\TestMode;
use Gratora\Recurring\PlanStatus;
use Gratora\Recurring\RecurringPlan;
use Gratora\Recurring\RecurringPlanRepository;
use Throwable;

/**
 * Renew sandbox plans through the normal donation flow on a compressed clock. Stop after twelve
 * cycles or when test mode is disabled; every outcome must advance the batch.
 *
 * @since 1.0.0
 */
final class SandboxRenewer
{
    public const HOOK = 'gratora.cron.sandbox_renew';

    /**
     * Where the rehearsal stops. Twelve is enough to watch a plan mature, fail
     * nothing and end tidily; without a cap a forgotten test site writes
     * thousands of donation rows a month.
     *
     * @since 1.0.0
     */
    public const MAX_CYCLES = 12;

    private const EVERY = 300;
    private const BATCH = 25;

    /** @since 1.0.0 */
    public function __construct(
        private RecurringPlanRepository $plans,
        private DonationService $donations,
        private TestMode $testMode,
        private Clock $clock,
        private AsyncDispatcher $async,
    ) {
    }

    /** @since 1.0.0 */
    public function register(): void
    {
        add_action(self::HOOK, [$this, 'run']);
        add_action('init', fn () => $this->async->scheduleRecurring(self::HOOK, self::EVERY));
    }

    /** @since 1.0.0 */
    public function run(): void
    {
        // Registered unconditionally, because this is also what ends the
        // rehearsal after test mode goes off. The sandbox gateway deregisters
        // itself then, and a plan whose gateway is gone cannot be cancelled at
        // all: RecurringCanceller has nothing to call and throws.
        if (! $this->testMode->forForm(null)) {
            $this->expireAll(__('Test mode was switched off.', 'gratora-donation-platform'));
            return;
        }

        $now = $this->clock->now()->format('Y-m-d H:i:s');

        $more = BatchProcessor::step(
            fn (int $n) => RecurringPlan::query()
                ->where('gateway', 'sandbox')
                ->where('status', 'active')
                ->whereIsNotNull('next_payment_at')
                ->where('next_payment_at', $now, '<=')
                ->orderBy('id')
                ->limit($n)
                ->getAll(),
            function (array $plans): void {
                foreach ($plans as $plan) {
                    $this->renew($plan);
                }
            },
            self::BATCH,
            false
        );

        if ($more) {
            $this->async->enqueue(self::HOOK);
        }
    }

    /** @since 1.0.0 */
    private function renew(RecurringPlan $plan): void
    {
        if ((int) $plan->payments_count >= self::MAX_CYCLES) {
            $this->expire($plan, sprintf(
                /* translators: %d: how many simulated cycles the plan ran for. */
                __('Sandbox rehearsal completed after %d cycles.', 'gratora-donation-platform'),
                self::MAX_CYCLES
            ));
            return;
        }

        $now    = $this->clock->now();
        $nowStr = $now->format('Y-m-d H:i:s');
        $nextAt = SandboxGateway::nextCycleAt($now, (int) $plan->interval_count);

        // Derived from the counter so each cycle is its own id. A constant one
        // would make createRenewal report created=false forever, and every
        // renewal after the first would record nothing.
        $intentId = sprintf(
            'sandbox_renewal_%d_%d',
            (int) $plan->id,
            (int) $plan->payments_count + 1
        );

        try {
            $renewal = $this->donations->createRenewal(
                $plan,
                (int) $plan->amount_cents,
                (string) $plan->currency,
                'sandbox',
                $intentId,
                [
                    'success'        => true,
                    'gateway_txn_id' => $intentId,
                    'payment_method' => 'test',
                ],
            );
        } catch (Throwable $e) {
            // Push the cycle out rather than retrying immediately: the plan
            // stays in the sweep but leaves this batch, which is what stops a
            // failing plan spinning it.
            $this->push($plan, $nextAt, $nowStr);
            do_action('gratora.sandbox.renewal_failed', $plan, $e);
            return;
        }

        if (! $renewal['created']) {
            $this->push($plan, $nextAt, $nowStr);
            return;
        }

        $fresh = $this->plans->findBySubscriptionId('sandbox', (string) $plan->gateway_subscription_id);
        if ($fresh !== null) {
            $this->plans->recordPayment($fresh, (int) $plan->amount_cents, $nowStr, $nextAt);
        }
    }

    /** @since 1.0.0 */
    private function push(RecurringPlan $plan, string $nextAt, string $now): void
    {
        RecurringPlan::query()
            ->where('id', (int) $plan->id)
            ->update(['next_payment_at' => $nextAt, 'updated_at' => $now]);
    }

    /** @since 1.0.0 */
    private function expire(RecurringPlan $plan, string $reason): void
    {
        $now = $this->clock->now()->format('Y-m-d H:i:s');

        // 'expired' is terminal, so the Subscriptions screen stops offering
        // actions on it rather than showing a plan nothing will ever renew.
        RecurringPlan::query()
            ->where('id', (int) $plan->id)
            ->update([
                'status'              => 'expired',
                'next_payment_at'     => null,
                // RecurringResumer excludes only 'cancelled', so a kept
                // resume_at would match it every day, for good.
                'resume_at'           => null,
                'cancellation_reason' => $reason,
                'updated_at'          => $now,
            ]);

        do_action('gratora.sandbox.rehearsal_ended', $plan, $reason);
    }

    /** @since 1.0.0 */
    private function expireAll(string $reason): void
    {
        // Not just the active ones: the gateway deregisters when the
        // rehearsal ends, so a paused plan could never be cancelled again.
        $plans = RecurringPlan::query()
            ->where('gateway', 'sandbox')
            ->whereNotIn('status', PlanStatus::TERMINAL)
            ->limit(self::BATCH)
            ->getAll();

        foreach ($plans as $plan) {
            $this->expire($plan, $reason);
        }

        if (count($plans) === self::BATCH) {
            $this->async->enqueue(self::HOOK);
        }
    }
}
