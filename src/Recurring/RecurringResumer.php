<?php

declare(strict_types=1);

namespace Dono\Recurring;

use Dono\Analytics\ErrorLog;
use Dono\Async\AsyncDispatcher;
use Dono\Foundation\Batch\BatchProcessor;
use Dono\Foundation\Time\Clock;
use Dono\Gateways\GatewayManager;
use Dono\Gateways\SubscriptionAware;
use Throwable;

/**
 * Restarts paused plans when their pause window closes.
 *
 * `SubscriptionAware::pauseSubscription()` documents `$resumesAt` as honored,
 * but only Stripe can honor it. PayPal's suspend is indefinite, so without this
 * sweep a donor who clicks "skip this month" has in fact cancelled, behind a
 * next payment date the portal still shows them.
 *
 * Rather than teach each gateway to schedule its own resume, the schedule
 * lives here once and applies to all of them. Stripe will already have
 * restarted on its side by the time this runs; resuming twice is a no-op there
 * and still corrects the local status, so the sweep does not need to know
 * which gateways can do it themselves.
 *
 * Keyed on `resume_at` rather than on status, because the two portal actions
 * differ: "pause for N months" marks the plan paused, while "skip next payment"
 * leaves it active (one missed cycle is not a pause) and still needs the
 * gateway un-paused afterwards.
 *
 * Daily and self-healing: a missed run resumes on the next one rather than
 * leaving the plan stopped forever, which a one-shot scheduled action months
 * out would not survive.
 *
 * @since 1.0.0
 */
final class RecurringResumer
{
    public const HOOK = 'dono.cron.recurring_resume';
    private const DAILY = 86400;
    private const BATCH = 100;

    /** @since 1.0.0 */
    public function __construct(
        private GatewayManager $gateways,
        private Clock $clock,
        private AsyncDispatcher $async,
    ) {
    }

    /** @since 1.0.0 */
    public function register(): void
    {
        add_action(self::HOOK, [$this, 'run']);
        add_action('init', fn () => $this->async->scheduleRecurring(self::HOOK, self::DAILY));
    }

    /** @since 1.0.0 */
    public function run(): void
    {
        $now = $this->clock->now()->format('Y-m-d H:i:s');

        // Both outcomes move resume_at (cleared on success, pushed a day out on
        // failure), so handled plans leave this set. Without that a batch of
        // failures would re-match, re-enqueue and spin.
        $more = BatchProcessor::step(
            fn (int $n) => RecurringPlan::query()
                ->whereIsNotNull('resume_at')
                ->where('resume_at', $now, '<=')
                ->where('status', 'cancelled', '<>')
                ->orderBy('id')
                ->limit($n)
                ->getAll(),
            function (array $plans): void {
                foreach ($plans as $plan) {
                    $this->resume($plan);
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
    private function resume(RecurringPlan $plan): void
    {
        $gateway = $this->gateways->get((string) $plan->gateway);

        // Absent is not the same as "has no subscriptions". Stripe and PayPal
        // register only while their credentials are stored, so a missing one
        // means the subscription is still suspended at the processor. Falling
        // through would mark the plan active, put it back in MRR, and clear the
        // marker that is the only record it was ever owed a resume.
        if ($gateway === null) {
            $now = $this->clock->now();
            $this->writeColumns($plan, [
                'resume_at'  => $now->modify('+1 day')->format('Y-m-d H:i:s'),
                'updated_at' => $now->format('Y-m-d H:i:s'),
            ]);
            ErrorLog::record('recurring', sprintf(
                'Cannot resume plan %1$d: the %2$s gateway is not available, so it is still paused at the processor.',
                (int) $plan->id,
                (string) $plan->gateway
            ), ['plan_id' => (int) $plan->id, 'gateway' => (string) $plan->gateway]);

            return;
        }

        if ($gateway instanceof SubscriptionAware) {
            try {
                $gateway->resumeSubscription($plan);
            } catch (Throwable $e) {
                // Back off a day rather than clearing resume_at: the plan is
                // still owed a resume, and going active locally while the
                // gateway is paused would be the same silent lie in the other
                // direction. Moving the date also takes the plan out of this
                // batch, which is what stops the sweep spinning on a gateway
                // that is failing every call.
                $now = $this->clock->now();
                $this->writeColumns($plan, [
                    'resume_at'  => $now->modify('+1 day')->format('Y-m-d H:i:s'),
                    'updated_at' => $now->format('Y-m-d H:i:s'),
                ]);
                ErrorLog::record('recurring', sprintf(
                    'Resuming plan %1$d at %2$s failed, so it is still paused at the processor: %3$s',
                    (int) $plan->id,
                    (string) $plan->gateway,
                    $e->getMessage()
                ), ['plan_id' => (int) $plan->id, 'gateway' => (string) $plan->gateway]);

                do_action('dono.recurring.resume_failed', $plan, $e);
                return;
            }
        }

        // Guarded on the same predicate the batch selected with. The batch was
        // read before a gateway call that blocks per plan, so a donor can
        // cancel while the sweep is partway through it, and going 'active'
        // unconditionally would restart a subscription that is already gone.
        // Not "still paused": a plan that reads active with a stale resume_at
        // is a skipped cycle, and clearing its marker is the point of the sweep.
        $written = $this->writeColumns($plan, [
            'status'     => 'active',
            'resume_at'  => null,
            'updated_at' => $this->clock->now()->format('Y-m-d H:i:s'),
        ], true);

        if ($written) {
            do_action('dono.recurring.plan_resumed', $plan);
        }
    }

    /**
     * Write named columns, never the whole row.
     *
     * Model::save() UPDATEs every column from the snapshot the batch was loaded
     * with, so anything that committed during the sweep - a cancel from the
     * portal, a renewal counter from a webhook - is written back to its old
     * value. One blocking gateway call per plan makes that window wide.
     *
     * @param array<string,mixed> $columns
     * @param bool                $skipIfCancelled leave a plan alone that
     *                                             reached a terminal state
     *                                             while this sweep was running
     *
     * @since 1.0.0
     */
    private function writeColumns(RecurringPlan $plan, array $columns, bool $skipIfCancelled = false): bool
    {
        $q = RecurringPlan::query()->where('id', (int) $plan->id);
        if ($skipIfCancelled) {
            $q = $q->where('status', 'cancelled', '<>');
        }

        $result = $q->update($columns);
        if ((int) $result->affectedRows === 0) {
            return false;
        }

        foreach ($columns as $col => $value) {
            $plan->{$col} = $value;
        }

        return true;
    }
}
