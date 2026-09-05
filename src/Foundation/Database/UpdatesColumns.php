<?php

declare(strict_types=1);

namespace FundKit\Foundation\Database;

defined('ABSPATH') || exit;

/**
 * Write named columns, leaving every other one as it stands in the database.
 *
 * Queryable's save() has no dirty tracking: it rebuilds the UPDATE from every
 * property the row carried when it was loaded. A request that reads a row,
 * spends time on something else and then saves therefore writes its whole
 * snapshot back over anything that committed in between. That is how a portal
 * preference save loses the lifetime giving totals a renewal webhook had just
 * recomputed, and how an anonymity toggle un-refunds a donation.
 *
 * @since 1.0.0
 */
trait UpdatesColumns
{
    /**
     * @param array<string,mixed> $columns
     *
     * @since 1.0.0
     */
    public function updateColumns(array $columns): void
    {
        $write = [];
        foreach ($columns as $col => $value) {
            // The same encoding save() applies to a JSON column, which the
            // builder's own update() does not do.
            $write[$col] = is_array($value)
                ? json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : $value;
        }

        static::query()->where('id', (int) $this->id)->update($write);

        // The caller's model reflects the write, the way save() leaves it.
        foreach ($columns as $col => $value) {
            $this->{$col} = $value;
        }
    }
}
