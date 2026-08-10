<?php

declare(strict_types=1);

namespace Dono\Foundation\Batch;

use Closure;
use Dono\Vendor\Queryable\DB;

/**
 * One bounded, resumable tick of a batched data operation. The caller must operate
 * on a shrinking match set (items handled by $apply stop matching $next) so
 * re-querying the first N stays correct without OFFSET; batches may be transactional.
 *
 * @since 1.0.0
 */
final class BatchProcessor
{
    /**
     * @param Closure(int):array<mixed> $next   up to $size items still needing work
     * @param Closure(array<mixed>):void $apply process them (must remove them from $next)
     * @return bool true if the batch was full (more may remain - re-enqueue),
     *              false once the set is drained
     * @since 1.0.0
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
