<?php

declare(strict_types=1);

namespace Dono\Gateways\Sandbox;

use Dono\Async\AsyncDispatcher;
use Dono\Donations\DonationService;
use Dono\Foundation\Batch\BatchProcessor;
use Dono\Foundation\Time\Clock;
use Dono\Gateways\TestMode;
use Dono\Recurring\RecurringPlan;
use Dono\Recurring\RecurringPlanRepository;
use Throwable;

/**
 * Drives renewals for sandbox plans, on a clock compressed to minutes.
 *
 * Stripe and PayPal renew because the gateway's own scheduler charges the card
 * and posts a webhook. The sandbox has neither, so a plan it created would sit
 * at one payment forever and the thing worth rehearsing before launch, a
 * renewal arriving with its receipt and its rollups, would never happen.
 *
 * A renewal here runs the same two calls a Stripe renewal runs, so every
 * downstream effect fires unchanged rather than through a parallel path.
 *
 * Bounded on three sides, because a rehearsal that never ends is a site
 * quietly writing donations forever: twelve cycles, then the plan expires;
 * test mode off ends every sandbox plan on the next sweep; and each outcome
 * moves the plan out of the match set so a batch cannot spin.
 *
 * @since 1.0.0
 */
final class SandboxRenewer
{
    public const HOOK = 'dono.cron.sandbox_renew';

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
            $this->expireAll(__('Test mode was switched off.', 'dono-fundraising-platform'));
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
                __('Sandbox rehearsal completed after %d cycles.', 'dono-fundraising-platform'),
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
            do_action('dono.sandbox.renewal_failed', $plan, $e);
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
                'cancellation_reason' => $reason,
                'updated_at'          => $now,
            ]);

        do_action('dono.sandbox.rehearsal_ended', $plan, $reason);
    }

    /** @since 1.0.0 */
    private function expireAll(string $reason): void
    {
        $plans = RecurringPlan::query()
            ->where('gateway', 'sandbox')
            ->where('status', 'active')
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
