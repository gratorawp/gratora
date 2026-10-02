<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use ActionScheduler;
use ActionScheduler_Store;
use Gratora\Async\AsyncDispatcher;

/**
 * A recurring action schedules its own successor, so a sweep that is in the
 * queue twice runs twice per interval for as long as the site lives. Two
 * requests arriving together both find nothing scheduled, and both schedule.
 */
final class RecurringScheduleOnceTest extends IntegrationTestCase
{
    private const HOOK = 'gratora.test.once';

    private const OTHER = 'gratora.test.once_other';

    /**
     * Through init, with every sweep the plugin registers: that is the path a
     * site takes, and a sequential pair of calls cannot show the fault because
     * the second one sees what the first scheduled.
     */
    public function test_two_requests_scheduling_together_leave_each_sweep_in_the_queue_once(): void
    {
        AsyncDispatcher::forgetRecurring();
        $raced = $this->raceEverySchedule();

        $this->fireInit();

        $sweeps = $this->sweeps();
        $this->assertNotEmpty($sweeps);
        $this->assertEqualsCanonicalizing(array_column($sweeps, 'hook'), $raced->getArrayCopy(), 'every sweep was scheduled by both requests');
        foreach ($sweeps as $sweep) {
            $this->assertSame(['recurring' => 1, 'once' => 0], $this->pending($sweep['hook'], $sweep['args']), $sweep['hook']);
        }
    }

    public function test_copies_already_in_the_queue_are_reduced_to_one_by_the_daily_check(): void
    {
        $sweeps = $this->sweeps();
        $this->assertNotEmpty($sweeps);
        foreach ($sweeps as $sweep) {
            $this->scheduleCopies($sweep['hook'], 1, $sweep['args']);
            $this->assertSame(2, $this->pending($sweep['hook'], $sweep['args'])['recurring'], $sweep['hook']);
        }
        $this->expireMemo();

        $this->fireInit();

        foreach ($sweeps as $sweep) {
            $this->assertSame(1, $this->pending($sweep['hook'], $sweep['args'])['recurring'], $sweep['hook']);
        }
    }

    /**
     * A sweep with more work than one run takes queues itself once more under
     * its own hook. That is the rest of today's work, not a second schedule,
     * and it is the newest action here so that keeping the newest of any kind
     * would keep the wrong one.
     */
    public function test_a_sweep_finishing_its_own_backlog_is_not_a_copy(): void
    {
        $async = new AsyncDispatcher();
        $this->scheduleCopies(self::HOOK, 2);
        $async->enqueue(self::HOOK);

        $async->scheduleRecurring(self::HOOK, HOUR_IN_SECONDS);

        $this->assertSame(['recurring' => 1, 'once' => 1], $this->pending(self::HOOK));
    }

    public function test_only_copies_of_the_same_sweep_are_canceled(): void
    {
        $this->scheduleCopies(self::HOOK, 2);
        $this->scheduleCopies(self::HOOK, 1, ['site' => 2]);
        $this->scheduleCopies(self::OTHER, 1);
        as_schedule_recurring_action(time() + 60, HOUR_IN_SECONDS, self::HOOK, [], 'someone-else');

        (new AsyncDispatcher())->scheduleRecurring(self::HOOK, HOUR_IN_SECONDS);

        $this->assertSame(1, $this->pending(self::HOOK)['recurring']);
        $this->assertSame(1, $this->pending(self::HOOK, ['site' => 2])['recurring'], 'other arguments are another schedule');
        $this->assertSame(1, $this->pending(self::OTHER)['recurring'], 'another sweep is not this one to cancel');
        $this->assertSame(1, $this->pending(self::HOOK, [], 'someone-else')['recurring'], 'nor is another group');
    }

    /** @return array<string, array{0:string}> */
    public function copyTheQueueRuns(): array
    {
        return ['the older copy' => ['min'], 'the newer copy' => ['max']];
    }

    /**
     * The queue gets to one copy while two requests are cleaning up around it.
     * Its successor is a third action neither request listed at first, and the
     * sweep has a backlog, so a request asking only whether anything is pending
     * is told yes by the one-off.
     *
     * @dataProvider copyTheQueueRuns
     */
    public function test_two_cleanups_around_a_queue_run_leave_the_sweep_scheduled(string $pick): void
    {
        add_action(self::HOOK, static fn () => (new AsyncDispatcher())->enqueue(self::HOOK));
        $this->scheduleCopies(self::HOOK, 2);
        $runs = $pick($this->copies());

        $interleaved = false;
        add_filter('query', static function ($sql) use (&$interleaved, $runs) {
            if ($interleaved || ! preg_match('/^\s*UPDATE\s+\S*actionscheduler_actions/i', (string) $sql)) {
                return $sql;
            }

            $interleaved = true;
            ActionScheduler::runner()->process_action($runs);
            (new AsyncDispatcher())->scheduleRecurring(self::HOOK, HOUR_IN_SECONDS);

            return $sql;
        });

        (new AsyncDispatcher())->scheduleRecurring(self::HOOK, HOUR_IN_SECONDS);

        $this->assertTrue($interleaved);
        $this->assertSame(1, $this->pending(self::HOOK)['recurring']);
    }

    /**
     * The queue can get to a copy between the moment it is listed and the
     * moment it would be canceled. A run that finished is not then recorded
     * as one that never happened.
     */
    public function test_a_copy_the_queue_got_to_first_is_left_as_it_ran(): void
    {
        add_action(self::HOOK, '__return_null');
        $this->scheduleCopies(self::HOOK, 2);
        $older = min($this->copies());

        $ran = false;
        add_filter('query', static function ($sql) use (&$ran, $older) {
            if ($ran || ! preg_match('/^\s*SELECT a\.\*.+actionscheduler_actions/is', (string) $sql)) {
                return $sql;
            }

            $ran = true;
            ActionScheduler::runner()->process_action($older);

            return $sql;
        });

        (new AsyncDispatcher())->scheduleRecurring(self::HOOK, HOUR_IN_SECONDS);

        $this->assertTrue($ran);
        $this->assertSame(ActionScheduler_Store::STATUS_COMPLETE, ActionScheduler::store()->get_status($older));
    }

    /** More copies than a race can leave: one request must not take them all on. */
    public function test_a_queue_full_of_copies_is_worked_off_over_several_checks(): void
    {
        $this->scheduleCopies(self::HOOK, 40);

        $left = [];
        for ($day = 0; $day < 4; $day++) {
            $this->expireMemo();
            (new AsyncDispatcher())->scheduleRecurring(self::HOOK, HOUR_IN_SECONDS);
            $left[] = $this->pending(self::HOOK)['recurring'];
        }

        $this->assertGreaterThan(1, $left[0]);
        $this->assertSame(1, end($left));
    }

    /**
     * This runs on init for every visitor until it has finished once, so a
     * database that refuses the write must cost a copy for a day, not the site.
     */
    public function test_a_copy_that_cannot_be_canceled_does_not_stop_the_request(): void
    {
        $this->scheduleCopies(self::HOOK, 2);
        $refused = 0;
        add_filter('query', static function ($sql) use (&$refused) {
            if (! preg_match('/^\s*UPDATE\s+\S*actionscheduler_actions/i', (string) $sql)) {
                return $sql;
            }
            $refused++;

            return '';
        });

        (new AsyncDispatcher())->scheduleRecurring(self::HOOK, HOUR_IN_SECONDS);
        (new AsyncDispatcher())->scheduleRecurring(self::HOOK, HOUR_IN_SECONDS);

        $this->assertSame(1, $refused, 'the next request does not try again');
        $this->assertSame(2, $this->pending(self::HOOK)['recurring']);
    }

    /**
     * The second request, arriving after the first has found nothing scheduled
     * and before it has scheduled anything.
     *
     * @return \ArrayObject<int,string> the hooks it got in ahead on
     */
    private function raceEverySchedule(): \ArrayObject
    {
        $raced  = new \ArrayObject();
        $racing = false;

        add_filter(
            'pre_as_schedule_recurring_action',
            static function ($pre, $timestamp, $interval, $hook, $args, $group) use ($raced, &$racing) {
                if ($racing || $group !== AsyncDispatcher::GROUP) {
                    return $pre;
                }

                $racing  = true;
                $raced[] = (string) $hook;
                (new AsyncDispatcher())->scheduleRecurring((string) $hook, (int) $interval, (array) $args);
                $racing = false;

                return $pre;
            },
            10,
            6
        );

        return $raced;
    }

    private function fireInit(): void
    {
        do_action('init');

        // Everything WordPress registered on the first init is offered again
        // and refused by name.
        foreach (array_keys($this->caught_doing_it_wrong) as $caught) {
            if (preg_match('/^WP_\w+Registry::register$/', $caught)) {
                $this->expected_doing_it_wrong[] = $caught;
            }
        }
    }

    /** @return list<array{hook:string,args:array<array-key,mixed>}> */
    private function sweeps(): array
    {
        $sweeps = [];
        foreach ((array) get_option(AsyncDispatcher::INSTALLED_OPTION, []) as $entry) {
            $sweeps[] = ['hook' => (string) $entry['hook'], 'args' => (array) $entry['args']];
        }

        return $sweeps;
    }

    private function expireMemo(): void
    {
        $known = (array) get_option(AsyncDispatcher::INSTALLED_OPTION, []);
        foreach ($known as $key => $entry) {
            $known[$key]['until'] = time() - 1;
        }
        update_option(AsyncDispatcher::INSTALLED_OPTION, $known, true);
    }

    /** @return list<int> the pending actions under the test hook */
    private function copies(): array
    {
        return array_map('intval', as_get_scheduled_actions([
            'hook'   => self::HOOK,
            'group'  => AsyncDispatcher::GROUP,
            'status' => ActionScheduler_Store::STATUS_PENDING,
        ], 'ids'));
    }

    /**
     * Straight into the scheduler, the way a request that lost the race put
     * them there.
     *
     * @param array<array-key,mixed> $args
     */
    private function scheduleCopies(string $hook, int $count, array $args = []): void
    {
        for ($i = 0; $i < $count; $i++) {
            as_schedule_recurring_action(time() + 60, HOUR_IN_SECONDS, $hook, $args, AsyncDispatcher::GROUP);
        }
    }

    /**
     * @param array<array-key,mixed> $args
     *
     * @return array{recurring:int,once:int}
     */
    private function pending(string $hook, array $args = [], string $group = AsyncDispatcher::GROUP): array
    {
        $pending = ['recurring' => 0, 'once' => 0];

        $actions = as_get_scheduled_actions([
            'hook'     => $hook,
            'args'     => $args,
            'group'    => $group,
            'status'   => ActionScheduler_Store::STATUS_PENDING,
            'per_page' => 50,
        ]);
        foreach ($actions as $action) {
            $pending[$action->get_schedule()->is_recurring() ? 'recurring' : 'once']++;
        }

        return $pending;
    }
}
