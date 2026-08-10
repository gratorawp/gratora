<?php

declare(strict_types=1);

namespace Dono\Recurring;

use Dono\Analytics\ErrorLog;
use Dono\Async\AsyncDispatcher;

/**
 * Cancels a campaign's live recurring plans in bounded, resumable batches.
 *
 * Cancelling costs one blocking gateway round trip per plan, so a campaign with
 * a few thousand monthly donors needs more wall time than PHP or any reverse
 * proxy allows inside the PUT /campaigns/{id} request. The cursor is persisted
 * so a run that dies partway resumes where it stopped.
 *
 * A cursor rather than "the next N active plans" on purpose. A plan the gateway
 * refuses to cancel stays active, so re-reading the same window would hand back
 * the same failure forever. The cursor steps past it, the failure is logged,
 * and the run still finishes.
 *
 * @since 1.0.0
 */
final class CampaignCancelRecurringJob
{
    public const HOOK = 'dono.async.cancel_campaign_recurring';

    private const OPTION = 'dono_campaign_cancel_recurring';

    /** Gateway round trips per tick, not rows: each one is an HTTPS call. */
    private const BATCH = 25;

    /** @since 1.0.0 */
    public function __construct(
        private AsyncDispatcher $async,
        private RecurringCanceller $canceller,
    ) {
    }

    /** @since 1.0.0 */
    public function register(): void
    {
        add_action(self::HOOK, [$this, 'run']);
    }

    /** @since 1.0.0 */
    public function start(int $campaignId, ?string $reason = null): void
    {
        if ($campaignId <= 0) {
            return;
        }

        self::setCursor($campaignId, 0);
        $this->reason($campaignId, $reason);
        $this->async->enqueue(self::HOOK, ['campaign_id' => $campaignId]);
    }

    /**
     * Action Scheduler passes args positionally, so ['campaign_id' => N]
     * arrives as the first scalar; direct callers may pass the array.
     *
     * @since 1.0.0
     */
    public function run(mixed $args = null): void
    {
        $campaignId = is_array($args)
            ? (int) ($args['campaign_id'] ?? ($args[0] ?? 0))
            : (int) $args;

        if ($campaignId <= 0 || ! array_key_exists($campaignId, self::pending())) {
            return;
        }

        $after  = self::cursor($campaignId);
        $reason = $this->reason($campaignId);

        $plans = RecurringPlan::query()
            ->where('campaign_id', $campaignId)
            ->where('status', 'active')
            ->where('is_test', false)
            ->where('id', $after, '>')
            ->orderBy('id', 'ASC')
            ->limit(self::BATCH)
            ->getAll();

        if ($plans === []) {
            self::clear($campaignId);
            do_action('dono.campaign.recurring_cancelled', $campaignId);
            return;
        }

        foreach ($plans as $plan) {
            $after = (int) $plan->id;
            try {
                $this->canceller->cancel($plan, $reason);
            } catch (\Throwable $e) {
                // One donor's gateway failure must not strand the rest.
                ErrorLog::record(
                    'recurring.cancel',
                    'Could not cancel this plan at the gateway: ' . $e->getMessage(),
                    ['recurring_plan_id' => (int) $plan->id]
                );
            }
        }

        self::setCursor($campaignId, $after);
        $this->async->enqueue(self::HOOK, ['campaign_id' => $campaignId]);
    }

    /** @since 1.0.0 */
    public static function remainingFor(int $campaignId): int
    {
        if (! array_key_exists($campaignId, self::pending())) {
            return 0;
        }

        return (int) RecurringPlan::query()
            ->where('campaign_id', $campaignId)
            ->where('status', 'active')
            ->where('is_test', false)
            ->where('id', self::cursor($campaignId), '>')
            ->count();
    }

    /** @since 1.0.0 */
    public static function isRunning(int $campaignId): bool
    {
        return array_key_exists($campaignId, self::pending());
    }

    /**
     * @return array<int,int> campaign_id => cursor
     *
     * @since 1.0.0
     */
    public static function pending(): array
    {
        $map = get_option(self::OPTION, []);

        return is_array($map) ? array_map('intval', $map) : [];
    }

    /** @since 1.0.0 */
    private static function cursor(int $campaignId): int
    {
        return (int) (self::pending()[$campaignId] ?? 0);
    }

    /** @since 1.0.0 */
    private static function setCursor(int $campaignId, int $after): void
    {
        $map               = self::pending();
        $map[$campaignId]  = $after;
        update_option(self::OPTION, $map, false);
    }

    /** @since 1.0.0 */
    private static function clear(int $campaignId): void
    {
        $map = self::pending();
        unset($map[$campaignId]);
        update_option(self::OPTION, $map, false);

        $reasons = get_option(self::OPTION . '_reasons', []);
        if (is_array($reasons) && array_key_exists($campaignId, $reasons)) {
            unset($reasons[$campaignId]);
            update_option(self::OPTION . '_reasons', $reasons, false);
        }
    }

    /**
     * The cancellation reason reaches the donor's notice, so it has to survive
     * between ticks rather than ride in the job args.
     *
     * @since 1.0.0
     */
    private function reason(int $campaignId, ?string $set = null): ?string
    {
        $reasons = get_option(self::OPTION . '_reasons', []);
        $reasons = is_array($reasons) ? $reasons : [];

        if ($set !== null) {
            $reasons[$campaignId] = $set;
            update_option(self::OPTION . '_reasons', $reasons, false);
            return $set;
        }

        $value = $reasons[$campaignId] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Idempotent and cheap: one option read when nothing is pending.
     *
     * @since 1.0.0
     */
    public function reconcilePending(): void
    {
        self::reconcile($this->async);
    }

    /**
     * Re-enqueues any run whose job was dropped. Safe to call on every admin
     * campaigns load.
     *
     * @since 1.0.0
     */
    public static function reconcile(AsyncDispatcher $async): void
    {
        foreach (array_keys(self::pending()) as $campaignId) {
            $campaignId = (int) $campaignId;
            if ($campaignId <= 0) {
                continue;
            }
            if (\as_has_scheduled_action(self::HOOK, ['campaign_id' => $campaignId], AsyncDispatcher::GROUP)) {
                continue;
            }
            $async->enqueue(self::HOOK, ['campaign_id' => $campaignId]);
        }
    }
}
