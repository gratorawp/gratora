<?php

declare(strict_types=1);

namespace Dono\Foundation\Upgrade;

use Dono\Async\AsyncDispatcher;

/**
 * Drains the outstanding upgrade routines off the request.
 *
 * A backfill over a few hundred thousand donations would hold the request open
 * for its whole duration, so each tick does one bounded step and re-enqueues
 * while anything is left.
 *
 * Not the only way they run: Action Scheduler rides WP-cron, which many hosts
 * disable, and a site left half-migrated needs a way back that does not assume
 * shell access. UpgradeRunner::step is callable directly and the Advanced
 * screen has a button for it.
 *
 * @version 1.0.0
 */
final class UpgradeJob
{
    public const HOOK = 'dono.async.run_upgrades';

    public function __construct(
        private AsyncDispatcher $async,
        private UpgradeRunner $runner,
    ) {
    }

    public function register(): void
    {
        add_action(self::HOOK, [$this, 'run']);
    }

    /** Queue a drain if there is anything to drain. */
    public function start(): void
    {
        if (! $this->runner->hasPending()) {
            return;
        }

        $this->async->enqueue(self::HOOK, []);
    }

    public function run(): void
    {
        if ($this->runner->step()) {
            $this->async->enqueue(self::HOOK, []);
        }
    }

    /**
     * Re-enqueue a drain whose job was dropped, the way FundReassignmentJob
     * does. Safe on every admin load: one option read when nothing is pending.
     */
    public static function reconcile(AsyncDispatcher $async, UpgradeRunner $runner): void
    {
        if (! $runner->hasPending()) {
            return;
        }

        if (\as_has_scheduled_action(self::HOOK, [], AsyncDispatcher::GROUP)) {
            return;
        }

        $async->enqueue(self::HOOK, []);
    }
}
