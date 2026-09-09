<?php

declare(strict_types=1);

namespace Gratora\Analytics;

use Gratora\Async\AsyncDispatcher;
use Gratora\Foundation\Batch\BatchProcessor;
use Gratora\Vendor\Queryable\DB;

/**
 * Caps gratora_events growth by deleting rows older than the retention window.
 *
 * Default: 730 days. Override via `gratora.event.retention_days` filter or
 * the `gratora_privacy.event_retention_days` option. 0 disables pruning.
 *
 * @since 1.0.0
 */
final class EventRetention
{
    public const HOOK = 'gratora.cron.event_retention';
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
                    "SELECT id FROM {$prefix}gratora_events
                     WHERE occurred_at < %s
                       AND type NOT LIKE 'donor.%%'
                     ORDER BY id ASC
                     LIMIT %d",
                    [$cutoff, $n]
                )['rows'] ?? []
            ),
            function (array $ids): void {
                if ($ids) {
                    DB::table('gratora_events')->whereIn('id', $ids)->delete();
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
        $opt = get_option('gratora_privacy', []);
        $stored = is_array($opt) ? (int) ($opt['event_retention_days'] ?? 730) : 730;
        return (int) apply_filters('gratora.event.retention_days', $stored);
    }
}
