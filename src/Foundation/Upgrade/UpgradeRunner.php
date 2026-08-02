<?php

declare(strict_types=1);

namespace Dono\Foundation\Upgrade;

/**
 * Runs the outstanding data migrations, one bounded step at a time.
 *
 * Ordering matters and is fixed: schema first, then routines. A routine that
 * backfills a column added in the same release would otherwise run against a
 * table that does not have it yet.
 *
 * Completion is recorded per routine id rather than as a version number,
 * because a site can be several releases behind and needs every routine it
 * missed, in order, not just the newest one.
 *
 * @version 1.0.0
 */
final class UpgradeRunner
{
    public const OPTION_DONE = 'dono_upgrade_routines_done';

    /** @var list<UpgradeRoutine> */
    private array $routines;

    /** @param list<UpgradeRoutine> $routines */
    public function __construct(array $routines = [])
    {
        // Add-ons register their own; core's ship in the order they are listed.
        $filtered = (array) apply_filters('dono.upgrade.routines', $routines);

        $this->routines = array_values(array_filter(
            $filtered,
            static fn ($r): bool => $r instanceof UpgradeRoutine
        ));
    }

    /** @return list<UpgradeRoutine> */
    public function all(): array
    {
        return $this->routines;
    }

    /** @return list<UpgradeRoutine> in registration order */
    public function pending(): array
    {
        $done = self::completed();

        return array_values(array_filter(
            $this->routines,
            static fn (UpgradeRoutine $r): bool => ! in_array($r->id(), $done, true)
        ));
    }

    public function hasPending(): bool
    {
        return $this->pending() !== [];
    }

    /**
     * Advance the first outstanding routine by one step.
     *
     * One routine at a time, in order, because a later one may depend on an
     * earlier one having finished.
     *
     * @return bool true while work remains, so the caller knows to come back
     */
    public function step(): bool
    {
        $pending = $this->pending();
        if ($pending === []) {
            return false;
        }

        $routine = $pending[0];

        try {
            $finished = $routine->step();
        } catch (\Throwable $e) {
            // Left unstamped on purpose: a routine that threw has not finished,
            // and marking it done would strand whatever it had not reached. The
            // admin notice keeps saying so, which is the point.
            error_log(sprintf(
                'dono: upgrade routine %s failed: %s',
                $routine->id(),
                $e->getMessage()
            ));

            return false;
        }

        if ($finished) {
            self::markDone($routine->id());
        }

        return $this->hasPending();
    }

    /** @return list<string> */
    public static function completed(): array
    {
        $done = get_option(self::OPTION_DONE, []);

        return is_array($done) ? array_values(array_map('strval', $done)) : [];
    }

    public static function markDone(string $id): void
    {
        $done = self::completed();
        if (in_array($id, $done, true)) {
            return;
        }

        $done[] = $id;
        update_option(self::OPTION_DONE, $done, true);
    }

    /**
     * Record every routine as already run, without running any.
     *
     * For a fresh install: a new site has nothing to migrate, and running a
     * backfill over an empty table is at best wasted work and at worst wrong,
     * since some routines assume the shape of data an older release wrote.
     */
    public static function markAllDone(UpgradeRunner $runner): void
    {
        foreach ($runner->all() as $routine) {
            self::markDone($routine->id());
        }
    }
}
