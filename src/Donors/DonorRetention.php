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
 * does not start the day it is installed. An org importing years of history
 * would otherwise have part of it redacted before they had seen the setting.
 *
 * @version 1.0.0
 */
final class DonorRetention
{
    public const HOOK = 'dono.cron.donor_retention';

    /** Stamped forward on activation, and by anything that bulk-loads donors. */
    public const STARTS_AT_OPTION = 'dono_retention_starts_at';
    public const GRACE_DAYS = 30;

    private const DAILY = 86400;
    private const BATCH = 100;

    public function __construct(
        private DonorService $donorService,
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

    public function retentionYears(): int
    {
        $opt = get_option('dono_privacy', []);
        $stored = is_array($opt) ? (int) ($opt['donor_retention_years'] ?? 7) : 7;

        // An add-on with a legal floor of its own raises it here. Gift Aid
        // needs the donor's name and address for six years after the tax year,
        // and redaction takes exactly those.
        return (int) apply_filters('dono.donor.retention_years', $stored);
    }

    private static function cutoff(int $years): string
    {
        return gmdate('Y-m-d H:i:s', time() - ($years * 365 * self::DAILY));
    }

    /** When the sweep is first allowed to run. */
    public static function startsAt(): int
    {
        $stored = (int) get_option(self::STARTS_AT_OPTION, 0);

        return (int) apply_filters('dono.donor.retention_starts_at', $stored);
    }

    /**
     * Pushes the first sweep out. Called on activation, and by anything that
     * loads a pile of donors at once: an import is exactly the moment when
     * years of history arrive and none of it has been looked at yet.
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
     * @return array{eligible_now:int, within_days:int, days:int, starts_at:int, years:int}
     */
    public function preview(int $days = 30): array
    {
        $years = $this->retentionYears();
        if ($years <= 0) {
            return ['eligible_now' => 0, 'within_days' => 0, 'days' => $days, 'starts_at' => self::startsAt(), 'years' => 0];
        }

        return [
            'eligible_now' => $this->countBefore(self::cutoff($years)),
            // Same cutoff moved forward: a donor becomes eligible as the window
            // slides past them, so "soon" is the same query dated later.
            'within_days'  => $this->countBefore(gmdate(
                'Y-m-d H:i:s',
                time() + ($days * self::DAILY) - ($years * 365 * self::DAILY)
            )),
            'days'         => $days,
            'starts_at'    => self::startsAt(),
            'years'        => $years,
        ];
    }

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
