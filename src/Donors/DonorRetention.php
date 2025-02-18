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
 * @version 1.0.0
 */
final class DonorRetention
{
    public const HOOK = 'dono.cron.donor_retention';
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

        $prefix = DB::getPrefix();
        $cutoff = gmdate('Y-m-d H:i:s', time() - ($years * 365 * self::DAILY));

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

    private function retentionYears(): int
    {
        $opt = get_option('dono_privacy', []);
        $stored = is_array($opt) ? (int) ($opt['donor_retention_years'] ?? 10) : 10;
        return (int) apply_filters('dono.donor.retention_years', $stored);
    }
}
