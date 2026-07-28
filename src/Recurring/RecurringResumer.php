<?php

declare(strict_types=1);

namespace Dono\Recurring;

use Dono\Async\AsyncDispatcher;
use Dono\Foundation\Batch\BatchProcessor;
use Dono\Foundation\Time\Clock;
use Dono\Gateways\GatewayManager;
use Dono\Gateways\SubscriptionAware;
use Throwable;

/**
 * Restarts paused plans when their pause window closes.
 *
 * `SubscriptionAware::pauseSubscription()` documents `$resumesAt` as honoured.
 * Only Stripe actually can: PayPal's suspend and Razorpay's pause are both
 * indefinite and were dropping the date on the floor while the portal wrote a
 * next payment date the donor could see. A donor clicking "skip this month"
 * had in fact cancelled, and neither they nor the org would find out until
 * someone went looking for the missing revenue.
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
 * @version 1.0.0
 */
final class RecurringResumer
{
    public const HOOK = 'dono.cron.recurring_resume';
    private const DAILY = 86400;
    private const BATCH = 100;

    public function __construct(
        private GatewayManager $gateways,
        private Clock $clock,
        private AsyncDispatcher $async,
    ) {
    }

    public function register(): void
    {
        add_action(self::HOOK, [$this, 'run']);
        add_action('init', fn () => $this->async->scheduleRecurring(self::HOOK, self::DAILY));
    }

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

    private function resume(RecurringPlan $plan): void
    {
        $gateway = $this->gateways->get((string) $plan->gateway);

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
                $plan->resume_at  = $now->modify('+1 day')->format('Y-m-d H:i:s');
                $plan->updated_at = $now->format('Y-m-d H:i:s');
                $plan->save();
                do_action('dono.recurring.resume_failed', $plan, $e);
                return;
            }
        }

        $plan->status     = 'active';
        $plan->resume_at  = null;
        $plan->updated_at = $this->clock->now()->format('Y-m-d H:i:s');
        $plan->save();

        do_action('dono.recurring.plan_resumed', $plan);
    }
}
