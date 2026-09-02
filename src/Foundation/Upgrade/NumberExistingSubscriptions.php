<?php

declare(strict_types=1);

namespace FundKit\Foundation\Upgrade;

use FundKit\Recurring\RecurringPlan;

/**
 * Gives plans created before the series existed a number in it.
 *
 * Oldest first, so the numbering runs in the order the plans were started
 * rather than the order a sweep happened to reach them.
 *
 * @since 1.0.0
 */
final class NumberExistingSubscriptions implements UpgradeRoutine
{
    private const OPTION_CURSOR = 'fundkit_upgrade_number_subscriptions_cursor';
    private const BATCH         = 200;

    /** @since 1.0.0 */
    public function id(): string
    {
        return '2026-09-02-number-existing-subscriptions';
    }

    /** @since 1.0.0 */
    public function description(): string
    {
        return __('Numbering recurring donations that started before they were numbered.', 'fundraising-toolkit');
    }

    /** @since 1.0.0 */
    public function step(): bool
    {
        $cursor = (int) get_option(self::OPTION_CURSOR, 0);

        $plans = RecurringPlan::query()
            ->where('reference', '')
            ->where('id', $cursor, '>')
            ->orderBy('id', 'ASC')
            ->limit(self::BATCH)
            ->getAll();

        if ($plans === []) {
            delete_option(self::OPTION_CURSOR);

            return true;
        }

        foreach ($plans as $plan) {
            RecurringPlan::query()
                ->where('id', (int) $plan->id)
                ->where('reference', '')
                ->update(['reference' => RecurringPlan::mintReference((bool) $plan->is_test)]);

            $cursor = (int) $plan->id;
        }

        update_option(self::OPTION_CURSOR, $cursor, false);

        return false;
    }
}
