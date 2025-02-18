<?php

declare(strict_types=1);

namespace Dono\Foundation\Maintenance;

use Dono\Async\AsyncDispatcher;
use Dono\Foundation\Batch\BatchProcessor;
use Dono\Vendor\Queryable\DB;

/**
 * Defensive GC for Dono's own expired transients.
 *
 * Runs independently of wp_scheduled_delete (which may be disabled by perf plugins).
 * Uses delete_transient() so object cache entries are cleared too. Capped per run.
 *
 * @version 1.0.0
 */
final class TransientGc
{
    public const HOOK = 'dono.cron.transient_gc';
    private const DAILY = 86400;
    private const BATCH = 2000;

    public function __construct(private AsyncDispatcher $async)
    {
    }

    /** Register the GC action and schedule the daily recurring job. */
    public function register(): void
    {
        add_action(self::HOOK, [$this, 'run']);
        add_action('init', fn () => $this->async->scheduleRecurring(self::HOOK, self::DAILY));
    }

    public function run(): void
    {
        // Object cache means transients bypass wp_options entirely.
        if (wp_using_ext_object_cache()) return;

        $now = time();

        // Shrinking set: delete_transient() removes the timeout row so re-querying
        // the first N is safe without OFFSET. Not transactional (touches wp_options).
        $more = BatchProcessor::step(
            // Prefix LIKE (no leading %) keeps the option_name index usable.
            fn (int $n) => DB::table('options')
                ->select('option_name')
                ->whereLike('option_name', '_transient_timeout_dono_%')
                ->where('option_value', (string) $now, '<')
                ->limit($n)
                ->getAll(),
            function (array $rows): void {
                foreach ($rows as $row) {
                    $timeoutName = (string) ($row['option_name'] ?? '');
                    if ($timeoutName === '') continue;
                    $key = substr($timeoutName, strlen('_transient_timeout_'));
                    if ($key !== '') {
                        delete_transient($key);
                    }
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
