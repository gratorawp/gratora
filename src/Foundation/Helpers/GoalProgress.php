<?php

declare(strict_types=1);

namespace Gratora\Foundation\Helpers;

/**
 * Progress against a goal, and the bar that shows it.
 *
 * These are two questions, and every surface that answered them with one
 * number got one of them wrong. A track has a width, so the fill it carries
 * cannot exceed it; the figure printed beside it can, and capped, a campaign
 * standing at 112 per cent reads like one that has just arrived.
 *
 * @since 1.0.0
 */
final class GoalProgress
{
    /** @since 1.0.0 */
    public static function percent(int $current, int $target): int
    {
        return $target > 0 ? (int) round(($current / $target) * 100) : 0;
    }

    /** @since 1.0.0 */
    public static function barWidth(int $percent): int
    {
        return max(0, min(100, $percent));
    }
}
