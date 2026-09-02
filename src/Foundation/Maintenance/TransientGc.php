<?php

declare(strict_types=1);

namespace FundKit\Foundation\Maintenance;

use FundKit\Async\AsyncDispatcher;
use FundKit\Foundation\Batch\BatchProcessor;
use FundKit\Vendor\Queryable\DB;

/**
 * Defensive GC for FundKit's own expired transients.
 *
 * Runs independently of wp_scheduled_delete (which may be disabled by perf plugins),
 * and independently of whether an object cache is present: delete_transient clears
 * the cached entry, and the rows are deleted outright because the rate-limit
 * counters are written to wp_options directly. Capped per run.
 *
 * @since 1.0.0
 */
final class TransientGc
{
    public const HOOK = 'fundkit.cron.transient_gc';
    private const DAILY = 86400;
    private const BATCH = 2000;

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
        $now = time();

        // Shrinking set: each key's timeout row is deleted below, so re-querying
        // the first N is safe without OFFSET. Not transactional (touches wp_options).
        $more = BatchProcessor::step(
            // Prefix LIKE (no leading %) keeps the option_name index usable.
            fn (int $n) => DB::table('options')
                ->select('option_name')
                ->whereLike('option_name', '_transient_timeout_fundkit_%')
                ->where('option_value', (string) $now, '<')
                ->limit($n)
                ->getAll(),
            function (array $rows): void {
                foreach ($rows as $row) {
                    $timeoutName = (string) ($row['option_name'] ?? '');
                    if ($timeoutName === '') continue;
                    $key = substr($timeoutName, strlen('_transient_timeout_'));
                    if ($key === '') continue;

                    // Clears the object-cache entry where there is one.
                    delete_transient($key);

                    // And the rows themselves. The rate-limit counters are
                    // written straight to wp_options so they can be incremented
                    // atomically, and with an object cache in front of it
                    // delete_transient never reaches a row it did not write:
                    // those counters accumulate one pair per address, forever,
                    // on exactly the sites big enough to run Redis. This is
                    // also what keeps the batch above shrinking, so a run
                    // cannot re-read the same rows and re-enqueue itself.
                    DB::table('options')
                        ->whereIn('option_name', ['_transient_' . $key, $timeoutName])
                        ->delete();
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
