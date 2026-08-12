<?php

declare(strict_types=1);

namespace Dono\Donors;

use Dono\Async\AsyncDispatcher;
use Dono\Foundation\Batch\BatchProcessor;
use Dono\Vendor\Queryable\DB;

/**
 * Daily GDPR retention runner. Soft-redacts donors whose last activity
 * exceeds the configured window and have no active/paused recurring plan.
 *
 * This is the only thing in Dono that destroys data without being asked, so it
 * takes two things to reach a donor. The privacy setting `erase_inactive_donors`
 * has to be switched on: nothing is swept on a site whose admin never asked for
 * it. And the sweep does not start the day it is switched on, because an org
 * importing years of history would otherwise have part of it redacted before
 * they had seen the window.
 *
 * The grace period is therefore measured from whichever of those came last,
 * which is why switching the setting on stamps it again.
 *
 * @since 1.0.0
 */
final class DonorRetention
{
    public const HOOK = 'dono.cron.donor_retention';

    /** Stamped forward on activation, and by anything that bulk-loads donors. */
    public const STARTS_AT_OPTION = 'dono_retention_starts_at';
    public const GRACE_DAYS = 30;

    private const DAILY = 86400;
    private const BATCH = 100;

    /** @since 1.0.0 */
    public function __construct(
        private DonorService $donorService,
        private AsyncDispatcher $async,
    ) {
    }

    /** @since 1.0.0 */
    public function register(): void
    {
        add_action(self::HOOK, [$this, 'run']);
        add_action('init', fn () => $this->async->scheduleRecurring(self::HOOK, self::DAILY));

        // $previous defaults so a caller firing the action with two arguments
        // is a re-arm rather than a fatal.
        add_action('dono.settings.updated', static function (string $group, array $next, array $previous = []): void {
            if ($group !== 'privacy') return;
            if (empty($next['erase_inactive_donors']) || ! empty($previous['erase_inactive_donors'])) return;

            // The stamp from activation says nothing about a site that has been
            // running for a year: without this, the first sweep an org ever
            // asks for takes everyone that same night. Only the transition,
            // never a resave, or an org that edits this screen every month
            // would push its own sweep away forever.
            self::deferBy();
        }, 10, 3);
    }

    /** @since 1.0.0 */
    public function run(): void
    {
        $years = (int) $this->retentionYears();
        if ($years <= 0) return;
        if (time() < self::startsAt()) return;

        $prefix = DB::getPrefix();
        $cutoff = self::cutoff($years);

        $more = BatchProcessor::step(
            fn (int $n) => array_map(
                static fn ($r) => (int) ($r->id ?? 0),
                DB::raw(
                    "SELECT id FROM {$prefix}dono_donors d
                     WHERE d.redacted_at IS NULL
                       AND COALESCE(d.last_donation_at, d.created_at) < %s
                       AND NOT EXISTS (
                           SELECT 1 FROM {$prefix}dono_recurring_plans p
                           WHERE p.donor_id = d.id
                             AND p.status IN ('active', 'paused')
                       )
                     ORDER BY id ASC
                     LIMIT %d",
                    [$cutoff, $n]
                )['rows'] ?? []
            ),
            function (array $ids): void {
                foreach ($ids as $id) {
                    $donor = Donor::query()->where('id', $id)->get();
                    if ($donor) $this->donorService->redact($donor);
                }
            },
            self::BATCH,
            false
        );

        if ($more) {
            $this->async->enqueue(self::HOOK);
        }
    }

    /**
     * The window in force, in years. Zero means nothing is swept, and every
     * caller reads the sweep through here so they cannot disagree about it.
     *
     * @since 1.0.0
     */
    public function retentionYears(): int
    {
        $opt = get_option('dono_privacy', []);

        // Returns before the filter, not after: an add-on may widen a window
        // that is in force, but nothing outside this option gets to start
        // erasing donors on a site that did not ask for it.
        if (! is_array($opt) || empty($opt['erase_inactive_donors'])) return 0;

        $stored = (int) ($opt['donor_retention_years'] ?? 7);

        // An add-on with a legal floor of its own raises it here. Gift Aid
        // needs the donor's name and address for six years after the tax year,
        // and redaction takes exactly those.
        return (int) apply_filters('dono.donor.retention_years', $stored);
    }

    /** @since 1.0.0 */
    private static function cutoff(int $years): string
    {
        return gmdate('Y-m-d H:i:s', time() - ($years * 365 * self::DAILY));
    }

    /**
     * When the sweep is first allowed to run.
     *
     * @since 1.0.0
     */
    public static function startsAt(): int
    {
        $stored = (int) get_option(self::STARTS_AT_OPTION, 0);

        return (int) apply_filters('dono.donor.retention_starts_at', $stored);
    }

    /**
     * Pushes the first sweep out. Called when erasure is switched on, on
     * activation, and by anything that loads a pile of donors at once: an
     * import is exactly the moment when years of history arrive and none of it
     * has been looked at yet. The latest of those wins.
     *
     * @since 1.0.0
     */
    public static function deferBy(int $days = self::GRACE_DAYS): void
    {
        $until = time() + ($days * self::DAILY);
        if ($until > self::startsAt()) {
            update_option(self::STARTS_AT_OPTION, $until, false);
        }
    }

    /**
     * What the next sweeps would take, without taking it.
     *
     * $years answers for a window that is not saved yet, which is the only
     * moment the number is still worth anything to whoever is choosing it. It
     * goes through the same filter as the window in force, or an add-on floor
     * would hold back donors this promised to erase. Nothing here destroys, so
     * it is deliberately not behind the opt-in gate.
     *
     * @return array{eligible_now:int, within_days:int, days:int, starts_at:int, years:int}
     *
     * @since 1.0.0
     */
    public function preview(int $days = 30, ?int $years = null): array
    {
        $window = $years === null
            ? $this->retentionYears()
            : (int) apply_filters('dono.donor.retention_years', max(0, $years));

        if ($window <= 0) {
            return ['eligible_now' => 0, 'within_days' => 0, 'days' => $days, 'starts_at' => self::startsAt(), 'years' => 0];
        }

        return [
            'eligible_now' => $this->countBefore(self::cutoff($window)),
            // Same cutoff moved forward: a donor becomes eligible as the window
            // slides past them, so "soon" is the same query dated later.
            'within_days'  => $this->countBefore(gmdate(
                'Y-m-d H:i:s',
                time() + ($days * self::DAILY) - ($window * 365 * self::DAILY)
            )),
            'days'         => $days,
            'starts_at'    => self::startsAt(),
            'years'        => $window,
        ];
    }

    /** @since 1.0.0 */
    private function countBefore(string $cutoff): int
    {
        $prefix = DB::getPrefix();

        $rows = DB::raw(
            "SELECT COUNT(*) AS n FROM {$prefix}dono_donors d
             WHERE d.redacted_at IS NULL
               AND COALESCE(d.last_donation_at, d.created_at) < %s
               AND NOT EXISTS (
                   SELECT 1 FROM {$prefix}dono_recurring_plans p
                   WHERE p.donor_id = d.id
                     AND p.status IN ('active', 'paused')
               )",
            [$cutoff]
        )['rows'] ?? [];

        return (int) ($rows[0]->n ?? 0);
    }
}
