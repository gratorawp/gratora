<?php

declare(strict_types=1);

namespace Gratora\Foundation\Upgrade;

use Gratora\Funds\Fund;

/**
 * Makes the default fund open again.
 *
 * The default is where a donation lands when nothing else claims it, so a
 * window on it is a date after which those donations stop arriving: the fund
 * reads closed, FundResolver skips it, and untagged money is filed against
 * whichever other fund happens to sort first, silently. Earlier builds let an
 * admin set one, and a site carrying one cannot fix it by upgrading, because
 * the columns only change when something writes them.
 *
 * Inactive is the other half of closed, and a restore could leave two rows
 * flagged, so this settles all three: one default, active, no window.
 *
 * @since 1.0.0
 */
final class OpenTheDefaultFund implements UpgradeRoutine
{
    /** @since 1.0.0 */
    public function id(): string
    {
        return '2026-09-06-open-the-default-fund';
    }

    /** @since 1.0.0 */
    public function description(): string
    {
        return __('Reopening the default fund. Donations already filed against another fund stay where they are.', 'gratora');
    }

    /** @since 1.0.0 */
    public function step(): bool
    {
        $flagged = Fund::query()->where('is_default', 1)->orderBy('id', 'ASC')->getAll();
        if ($flagged === []) {
            return true;
        }

        // The oldest wins, because it is the one the site has been filing
        // against; a second flag can only have arrived from a restore.
        $keep = (int) $flagged[0]->id;

        Fund::query()
            ->where('id', $keep)
            ->update(['is_active' => 1, 'starts_at' => null, 'ends_at' => null]);

        Fund::query()
            ->where('is_default', 1)
            ->where('id', $keep, '!=')
            ->update(['is_default' => 0]);

        return true;
    }
}
