<?php

declare(strict_types=1);

namespace Dono\Foundation\Batch;

use Closure;
use Dono\Vendor\Queryable\DB;

/**
 * One bounded, resumable tick of a batched data operation.
 *
 * Invariants: the caller must operate on a shrinking match set (every item
 * handled by $apply must no longer match $next). This keeps re-querying the
 * first N correct without OFFSET. Each batch is optionally transactional so a
 * crash leaves whole batches applied or not - the job is resumable and
 * idempotent. One batch per call; the caller re-enqueues itself while true.
 *
 * @version 1.0.0
 */
final class BatchProcessor
{
    /**
     * @param Closure(int):array<mixed> $next   up to $size items still needing work
     * @param Closure(array<mixed>):void $apply process them (must remove them from $next)
     * @return bool true if the batch was full (more may remain - re-enqueue),
     *              false once the set is drained
     */
    public static function step( Closure $next, Closure $apply, int $size, bool $transactional = true): bool
    {
        $size  = max(1, $size);
        $items = $next($size);
        if (empty($items)) {
            return false;
        }

        if ($transactional) {
            DB::transaction(static fn () => $apply($items));
        } else {
            $apply($items);
        }

        return count($items) >= $size;
    }
}
