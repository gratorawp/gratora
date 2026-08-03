<?php

declare(strict_types=1);

namespace Dono\Foundation\Upgrade;

use Dono\Analytics\ErrorLog;
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

    /** Last failure per routine id, so a stuck one is distinguishable. */
    public const OPTION_FAILED = 'dono_upgrade_routines_failed';

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
            // and marking it done would strand whatever it had not reached.
            //
            // Recorded rather than only logged. "Still outstanding" reads the
            // same whether a routine is working through a large table or has
            // failed forty times, and nobody reads the PHP error log of a site
            // they installed a plugin on.
            self::recordFailure($routine->id(), $e->getMessage());

            ErrorLog::record('upgrade', sprintf(
                'Routine %s stopped: %s',
                $routine->id(),
                $e->getMessage()
            ));

            return false;
        }

        self::clearFailure($routine->id());

        if ($finished) {
            self::markDone($routine->id());
        }

        return $this->hasPending();
    }

    /**
     * Why a routine last failed, keyed by id, with how many times running.
     *
     * @return array<string,array{message:string, attempts:int, at:string}>
     */
    public static function failures(): array
    {
        $failed = get_option(self::OPTION_FAILED, []);

        return is_array($failed) ? $failed : [];
    }

    private static function recordFailure(string $id, string $message): void
    {
        $failed = self::failures();
        $failed[$id] = [
            'message'  => mb_substr($message, 0, 500),
            'attempts' => (int) ($failed[$id]['attempts'] ?? 0) + 1,
            'at'       => gmdate('c'),
        ];

        update_option(self::OPTION_FAILED, $failed, false);
    }

    private static function clearFailure(string $id): void
    {
        $failed = self::failures();
        if (! array_key_exists($id, $failed)) {
            return;
        }

        unset($failed[$id]);
        update_option(self::OPTION_FAILED, $failed, false);
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
