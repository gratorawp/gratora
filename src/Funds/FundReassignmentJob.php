<?php

declare(strict_types=1);

namespace Dono\Funds;

use Dono\Async\AsyncDispatcher;
use Dono\Campaigns\Campaign;
use Dono\Donations\AggregateSyncer;
use Dono\Donations\Donation;
use Dono\Forms\Form;
use Dono\Recurring\RecurringPlan;
use Dono\Foundation\Batch\BatchProcessor;

/**
 * Moves donations, campaign + form default-fund pointers, and recurring-plan
 * fund pointers off a fund in bounded resumable batches, then deletes the
 * now-unreferenced fund.
 *
 * The fund stays deactivated until this job confirms zero remaining
 * references; interrupted runs resume from the database. Idempotent.
 *
 * @version 1.0.0
 */
final class FundReassignmentJob
{
    public const HOOK = 'dono.async.reassign_fund';

    private const OPTION = 'dono_fund_reassignments';
    private const BATCH  = 500;

    public function __construct(
        private AsyncDispatcher $async,
        private AggregateSyncer $aggregates,
    ) {
    }

    public function register(): void
    {
        add_action(self::HOOK, [$this, 'run']);
    }

    /**
     * Action Scheduler passes args positionally so ['fund_id' => N] arrives
     * as the first scalar; direct callers may pass the array. Target is read
     * from the pending map, never from args.
     *
     * @param mixed $args fund id (AS) or ['fund_id' => N] (direct)
     */
    public function run(mixed $args = null): void
    {
        $fundId = is_array($args)
            ? (int) ($args['fund_id'] ?? ($args[0] ?? 0))
            : (int) $args;
        if ($fundId <= 0) return;

        $source = Fund::query()->where('id', $fundId)->get();
        if (! $source) {
            // Already finished on an earlier tick.
            self::clearPending($fundId);
            return;
        }

        $targetId = (int) (self::pending()[$fundId] ?? 0);
        $target   = $targetId > 0 ? Fund::query()->where('id', $targetId)->get() : null;
        if (! $target) {
            // No usable target: clear the marker so the fund settles as Inactive
            // rather than "Reassigning" forever. Donations stay on the deactivated
            // source - never orphaned.
            self::clearPending($fundId);
            do_action('dono.fund.reassign_failed', $source, $targetId);
            return;
        }

        $donationsLeft = BatchProcessor::step(
            fn (int $n) => Donation::query()->where('fund_id', $fundId)->limit($n)->pluck('id'),
            fn (array $ids) => Donation::query()->whereIn('id', $ids)->update(['fund_id' => $targetId]),
            self::BATCH
        );
        if ($donationsLeft) {
            $this->async->enqueue(self::HOOK, ['fund_id' => $fundId]);
            return;
        }

        $campaignsLeft = BatchProcessor::step(
            fn (int $n) => Campaign::query()->where('default_fund_id', $fundId)->limit($n)->pluck('id'),
            fn (array $ids) => Campaign::query()->whereIn('id', $ids)->update(['default_fund_id' => $targetId]),
            self::BATCH
        );
        if ($campaignsLeft) {
            $this->async->enqueue(self::HOOK, ['fund_id' => $fundId]);
            return;
        }

        // Forms default new donations to a fund; move those pointers too or new
        // donations would land on the deleted fund.
        $formsLeft = BatchProcessor::step(
            fn (int $n) => Form::query()->where('default_fund_id', $fundId)->limit($n)->pluck('id'),
            fn (array $ids) => Form::query()->whereIn('id', $ids)->update(['default_fund_id' => $targetId]),
            self::BATCH
        );
        if ($formsLeft) {
            $this->async->enqueue(self::HOOK, ['fund_id' => $fundId]);
            return;
        }

        // Recurring plans carry the fund onto every renewal donation; repoint
        // them or renewals would keep crediting the deleted fund.
        $plansLeft = BatchProcessor::step(
            fn (int $n) => RecurringPlan::query()->where('fund_id', $fundId)->limit($n)->pluck('id'),
            fn (array $ids) => RecurringPlan::query()->whereIn('id', $ids)->update(['fund_id' => $targetId]),
            self::BATCH
        );
        if ($plansLeft) {
            $this->async->enqueue(self::HOOK, ['fund_id' => $fundId]);
            return;
        }

        $remaining = (int) Donation::query()->where('fund_id', $fundId)->count()
            + (int) Campaign::query()->where('default_fund_id', $fundId)->count()
            + (int) Form::query()->where('default_fund_id', $fundId)->count()
            + (int) RecurringPlan::query()->where('fund_id', $fundId)->count();
        if ($remaining > 0) {
            $this->async->enqueue(self::HOOK, ['fund_id' => $fundId]);
            return;
        }

        // The moved donations now belong to the target fund; recompute its
        // denormalised totals so raised/donations/donors reflect the inflow.
        // (The source fund is about to be deleted, so it needs no resync.)
        $this->aggregates->syncFund($targetId);

        Fund::query()->where('id', $fundId)->delete();
        self::clearPending($fundId);
        do_action('dono.fund.reassigned', $source, $target);
        do_action('dono.fund.deleted', $source);
    }

    /** @return array<int,int> fund_id => target_id */
    public static function pending(): array
    {
        $map = get_option(self::OPTION, []);
        return is_array($map) ? array_map('intval', $map) : [];
    }

    public static function markPending(int $fundId, int $targetId): void
    {
        $map           = self::pending();
        $map[$fundId]  = $targetId;
        update_option(self::OPTION, $map, false);
    }

    public static function clearPending(int $fundId): void
    {
        $map = self::pending();
        if (! array_key_exists($fundId, $map)) return;
        unset($map[$fundId]);
        update_option(self::OPTION, $map, false);
    }

    /**
     * Ensures every pending reassignment has a live Action Scheduler job.
     * Re-enqueues any that were dropped. Safe to call on every admin funds load.
     */
    public static function reconcile(AsyncDispatcher $async): void
    {
        foreach (array_keys(self::pending()) as $fundId) {
            $fundId = (int) $fundId;
            if ($fundId <= 0) {
                continue;
            }
            if (\as_has_scheduled_action(self::HOOK, ['fund_id' => $fundId], AsyncDispatcher::GROUP)) {
                continue;
            }
            $async->enqueue(self::HOOK, ['fund_id' => $fundId]);
        }
    }
}
