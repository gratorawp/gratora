<?php

declare(strict_types=1);

namespace Dono\Foundation\Upgrade;

use Dono\Async\AsyncDispatcher;

/**
 * Drains the outstanding upgrade routines off the request.
 *
 * A backfill over a few hundred thousand donations cannot run inside the
 * request that noticed the plugin had been updated, and the site would be down
 * for the length of it if it tried. Each tick does one bounded step and
 * re-enqueues while anything is left.
 *
 * Action Scheduler rides WP-cron, which is disabled or throttled on plenty of
 * hosts, so this is not the only way the routines can run: UpgradeRunner::step
 * is callable directly and the Advanced screen exposes a button that does
 * exactly that. Without it a broken cron would leave a site silently
 * half-migrated with no way to recover short of shell access, which is not
 * something to assume on someone else's install.
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
