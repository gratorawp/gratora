<?php

declare(strict_types=1);

namespace Gratora\Donors;

/**
 * Who the donor figures are drawn from.
 *
 * The population rule lived as the same raw fragment in eight queries across
 * two classes, so nothing could be read to prove the eight agreed. They did
 * not: a count taken under one of them is handed to medianLtv as an offset
 * into a query filtered by another.
 *
 * Both forms are here on purpose. The builder scope is for a query that owns
 * its table, and the string is for the ones that already pass a raw fragment,
 * because Queryable's whereRaw contributes no AND connector: a second
 * whereRaw, or a chained whereIsNull after one, runs into the first and the
 * statement does not parse.
 *
 * @since 1.0.0
 */
final class DonorQueries
{
    /**
     * A row that still stands for a person.
     *
     * Redaction is the one state that empties a donor of identity while
     * keeping their money on the books, so every figure about people excludes
     * it and every figure about money does not.
     *
     * Pass the alias when the query names the table, and pass it for a
     * correlated subquery in particular: an unqualified column there binds to
     * whichever table the optimiser reaches first.
     *
     * @since 1.0.0
     */
    public static function notRedactedPredicate(?string $alias = null): string
    {
        $qualifier = $alias !== null && $alias !== '' ? $alias . '.' : '';

        return "{$qualifier}redacted_at IS NULL";
    }

    /**
     * The same rule for a query that owns the donors table.
     *
     * @template T
     * @param  T $q
     * @return T
     *
     * @since 1.0.0
     */
    public static function notRedacted($q)
    {
        return $q->whereIsNull('redacted_at');
    }
}
