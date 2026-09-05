<?php

declare(strict_types=1);

namespace FundKit\Analytics;

use FundKit\Async\AsyncDispatcher;
use FundKit\Foundation\Batch\BatchProcessor;
use FundKit\Vendor\Queryable\DB;

/**
 * Caps fundkit_events growth by deleting rows older than the retention window.
 *
 * Default: 730 days. Override via `fundkit.event.retention_days` filter or
 * the `fundkit_privacy.event_retention_days` option. 0 disables pruning.
 *
 * @since 1.0.0
 */
final class EventRetention
{
    public const HOOK = 'fundkit.cron.event_retention';
    private const DAILY = 86400;
    private const BATCH = 1000;

    /** @since 1.0.0 */
    public function __construct(private AsyncDispatcher $async)
    {
    }

    /** @since 1.0.0 */
    public function register(): void
    {
        add_action(self::HOOK, [$this, 'run']);
        add_action('init', fn () => $this->async->scheduleRecurring(self::HOOK, self::DAILY));
    }

    /** @since 1.0.0 */
    public function run(): void
    {
        $days = (int) $this->retentionDays();
        if ($days <= 0) return;

        $prefix = DB::getPrefix();
        $cutoff = gmdate('Y-m-d H:i:s', time() - ($days * self::DAILY));

        // Delete in bounded batches, re-enqueuing while a full batch came back,
        // so the first prune of a large backlog can't hold locks or hit
        // max_execution_time mid-statement (mirrors DonorRetention/TransientGc).
        $more = BatchProcessor::step(
            fn (int $n) => array_map(
                static fn ($r) => (int) ($r->id ?? 0),
                DB::raw(
                    "SELECT id FROM {$prefix}fundkit_events
                     WHERE occurred_at < %s
                       AND type NOT LIKE 'donor.%%'
                     ORDER BY id ASC
                     LIMIT %d",
                    [$cutoff, $n]
                )['rows'] ?? []
            ),
            function (array $ids): void {
                if ($ids) {
                    DB::table('fundkit_events')->whereIn('id', $ids)->delete();
                }
            },
            self::BATCH,
            false
        );

        if ($more) {
            $this->async->enqueue(self::HOOK);
        }
    }

    /** @since 1.0.0 */
    private function retentionDays(): int
    {
        $opt = get_option('fundkit_privacy', []);
        $stored = is_array($opt) ? (int) ($opt['event_retention_days'] ?? 730) : 730;
        return (int) apply_filters('fundkit.event.retention_days', $stored);
    }
}
