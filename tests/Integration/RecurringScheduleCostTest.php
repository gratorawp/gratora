<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Async\AsyncDispatcher;

/**
 * Fifteen sweeps register on init between core and the add-ons, and each one
 * asked Action Scheduler whether it was already installed. That is an uncached
 * join per sweep on every request the site serves, anonymous front-end views
 * included, for hooks that were scheduled once and never change.
 */
final class RecurringScheduleCostTest extends IntegrationTestCase
{
    private const HOOK = 'gratora.test.cost';

    protected function tearDown(): void
    {
        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions(self::HOOK, [], AsyncDispatcher::GROUP);
        }
        delete_option(AsyncDispatcher::INSTALLED_OPTION);
        parent::tearDown();
    }

    public function test_an_installed_hook_costs_no_scheduler_query(): void
    {
        (new AsyncDispatcher())->scheduleRecurring(self::HOOK, HOUR_IN_SECONDS);

        $seen = [];
        $spy  = static function ($sql) use (&$seen) {
            if (stripos((string) $sql, 'actionscheduler') !== false) {
                $seen[] = $sql;
            }

            return $sql;
        };
        add_filter('query', $spy);

        // A fresh dispatcher on purpose: the memo has to be the option, not a
        // per-object static that dies with the request that wrote it.
        (new AsyncDispatcher())->scheduleRecurring(self::HOOK, HOUR_IN_SECONDS);

        remove_filter('query', $spy);

        $this->assertSame([], $seen);
        // Measured after the spy is removed so its own query is not counted;
        // without it the cheap path could be bought by not scheduling at all.
        $this->assertTrue(as_has_scheduled_action(self::HOOK, [], AsyncDispatcher::GROUP));
    }

    /**
     * A run with no callback still schedules its successor, so anything left in
     * the queue outlives the plugin and regenerates for as long as the site does.
     */
    public function test_deactivation_takes_the_sweeps_out_of_the_queue(): void
    {
        (new AsyncDispatcher())->scheduleRecurring(self::HOOK, HOUR_IN_SECONDS);
        $this->assertTrue(as_has_scheduled_action(self::HOOK, [], AsyncDispatcher::GROUP));

        AsyncDispatcher::forgetRecurring();

        $this->assertFalse(as_has_scheduled_action(self::HOOK, [], AsyncDispatcher::GROUP));
        $this->assertFalse(get_option(AsyncDispatcher::INSTALLED_OPTION), 'and the memo goes with them');
    }

    /** A hook someone cancelled by hand comes back on the daily revalidation. */
    public function test_a_hook_cancelled_out_of_band_is_reinstalled_when_the_memo_expires(): void
    {
        (new AsyncDispatcher())->scheduleRecurring(self::HOOK, HOUR_IN_SECONDS);
        as_unschedule_all_actions(self::HOOK, [], AsyncDispatcher::GROUP);

        $known = get_option(AsyncDispatcher::INSTALLED_OPTION, []);
        foreach ($known as $key => $entry) {
            $known[$key]['until'] = time() - 1;
        }
        update_option(AsyncDispatcher::INSTALLED_OPTION, $known, true);

        (new AsyncDispatcher())->scheduleRecurring(self::HOOK, HOUR_IN_SECONDS);

        $this->assertTrue(as_has_scheduled_action(self::HOOK, [], AsyncDispatcher::GROUP));
    }
}
