<?php

declare(strict_types=1);

namespace Dono\Rest;

/**
 * The one place a page number from a request becomes a number we will do
 * arithmetic on.
 *
 * `page=9223372036854775807` overflowed `($page - 1) * $perPage` into a float,
 * and QueryBuilder::offset() is typed `int`, so every admin list route died on
 * an uncaught TypeError. The schemas set `minimum: 1` and no maximum, and six
 * controllers each read `(int) $request['page']` for themselves.
 *
 * @version 1.0.0
 */
final class Paging
{
    /**
     * Generous enough that no real list reaches it, small enough that
     * page * per_page cannot leave the integer range on any platform.
     */
    public const MAX_PAGE = 1000000;

    public static function page(mixed $raw, int $default = 1): int
    {
        $page = is_numeric($raw) ? (int) $raw : $default;

        return max(1, min($page, self::MAX_PAGE));
    }
}
