<?php

declare(strict_types=1);

namespace Dono\Rest;

/**
 * The one place a page number from a request becomes a number we will do
 * arithmetic on. Callers multiply page by per_page, so an unbounded page
 * overflows into a float and breaks the int-typed offset downstream.
 *
 * @since 1.0.0
 */
final class Paging
{
    /**
     * Generous enough that no real list reaches it, small enough that
     * page * per_page cannot leave the integer range on any platform.
     */
    public const MAX_PAGE = 1000000;

    /** @since 1.0.0 */
    public static function page(mixed $raw, int $default = 1): int
    {
        $page = is_numeric($raw) ? (int) $raw : $default;

        return max(1, min($page, self::MAX_PAGE));
    }
}
