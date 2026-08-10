<?php

declare(strict_types=1);

namespace Dono\Webhooks;

use Dono\Async\AsyncDispatcher;
use Dono\Foundation\Batch\BatchProcessor;
use Dono\Vendor\Queryable\DB;

/**
 * Caps `dono_webhooks_log` growth. Runs daily via Action Scheduler.
 *
 * Retention default 30 days; filter `dono.webhook_log.retention_days`.
 * Set to 0 or negative to disable.
 *
 * @since 1.0.0
 */
final class WebhookLogRetention
{
    public const HOOK = 'dono.cron.webhook_log_retention';
    private const DAILY = 86400;
    private const DEFAULT_RETENTION_DAYS = 30;
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
        $days = (int) apply_filters('dono.webhook_log.retention_days', self::DEFAULT_RETENTION_DAYS);
        if ($days <= 0) return;

        $prefix = DB::getPrefix();
        $cutoff = gmdate('Y-m-d H:i:s', time() - ($days * self::DAILY));

        // Bounded batches, re-enqueued while a full batch came back, so the
        // first prune of a large backlog can't hold locks or hit
        // max_execution_time mid-statement (mirrors DonorRetention/TransientGc).
        $more = BatchProcessor::step(
            fn (int $n) => array_map(
                static fn ($r) => (int) ($r->id ?? 0),
                DB::raw(
                    "SELECT id FROM {$prefix}dono_webhooks_log
                     WHERE received_at < %s
                     ORDER BY id ASC
                     LIMIT %d",
                    [$cutoff, $n]
                )['rows'] ?? []
            ),
            function (array $ids): void {
                if ($ids) {
                    DB::table('dono_webhooks_log')->whereIn('id', $ids)->delete();
                }
            },
            self::BATCH,
            false
        );

        if ($more) {
            $this->async->enqueue(self::HOOK);
        }
    }
}
