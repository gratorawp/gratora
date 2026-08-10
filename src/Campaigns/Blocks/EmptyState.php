<?php

declare(strict_types=1);

namespace Dono\Campaigns\Blocks;

/**
 * Which empty state on a page gets to be the full invitation.
 *
 * Recent donations and top donors both go quiet on a campaign nobody has given
 * to yet, and they are usually placed together, so the same "be the first"
 * card renders twice under two different headings. The first one claims the
 * full card and the rest fall back to a single line.
 *
 * @since 1.0.0
 */
final class EmptyState
{
    private static bool $claimed = false;

    /** @since 1.0.0 */
    public static function claimFull(): bool
    {
        if (self::$claimed) {
            return false;
        }

        return self::$claimed = true;
    }
}
