<?php

declare(strict_types=1);

namespace FundKit\Foundation\Upgrade;

use FundKit\Funds\Fund;

/**
 * Clears the schedule off the default fund.
 *
 * The default is where a donation lands when nothing else claims it, so a
 * window on it is a date after which those donations stop arriving: the fund
 * reads closed, FundResolver skips it, and untagged money is filed against
 * whichever other fund happens to sort first, silently. Earlier builds let an
 * admin set one, and a site carrying one cannot fix it by upgrading, because
 * the columns only change when something writes them.
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
        return __('Clearing the schedule from the default fund.', 'fundraising-toolkit');
    }

    /**
     * One statement over a table with a handful of rows, so there is nothing
     * to page: it runs once and reports itself done.
     *
     * @since 1.0.0
     */
    public function step(): bool
    {
        Fund::query()
            ->where('is_default', 1)
            ->update(['starts_at' => null, 'ends_at' => null]);

        return true;
    }
}
