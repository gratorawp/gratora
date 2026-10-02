<?php

declare(strict_types=1);

namespace Gratora\Async;

use ActionScheduler_Store;
use Throwable;

/**
 * Wrapper over Action Scheduler.
 *
 * @since 1.0.0
 */
final class AsyncDispatcher
{
    public const GROUP = 'gratora';

    /** What is already installed, so init does not ask the scheduler again. */
    public const INSTALLED_OPTION = 'gratora_recurring_installed';

    private const RECHECK = 86400;

    /** What one daily check reads; anything past it waits for the next. */
    private const DUPLICATE_PROBE = 25;

    /**
     * @param array<array-key,mixed> $args
     * @since 1.0.0
     */
    public function enqueue(string $hook, array $args = []): void
    {
        \as_enqueue_async_action($hook, $args, self::GROUP);
    }

    /**
     * @param array<array-key,mixed> $args
     * @since 1.0.0
     */
    public function schedule(string $hook, int $timestamp, array $args = []): void
    {
        \as_schedule_single_action($timestamp, $hook, $args, self::GROUP);
    }

    /**
     * Take the recurring sweeps out of the queue.
     *
     * Every run of a recurring action schedules its successor whether or not a
     * callback was found, so anything left here outlives the plugin. Driven off
     * the installed map rather than cancelling the whole group, which would
     * also kill pending one-off jobs on a routine deactivate-for-update.
     *
     * @since 1.0.0
     */
    public static function forgetRecurring(): void
    {
        if (! function_exists('as_unschedule_all_actions')) {
            return;
        }

        $known = get_option(self::INSTALLED_OPTION, []);
        foreach (is_array($known) ? $known : [] as $entry) {
            if (! is_array($entry) || ! isset($entry['hook'])) {
                continue;
            }

            \as_unschedule_all_actions((string) $entry['hook'], (array) ($entry['args'] ?? []), self::GROUP);
        }

        delete_option(self::INSTALLED_OPTION);
    }

    /**
     * Take one recurring hook out of the queue, for an add-on switching off
     * while core stays on. Its memo entry goes too, or the add-on's next
     * activation would find it "installed" and not schedule it for up to a day.
     *
     * @since 1.1.0
     */
    public static function forgetRecurringHook(string $hook): void
    {
        $known   = get_option(self::INSTALLED_OPTION, []);
        $known   = is_array($known) ? $known : [];
        $argSets = [[]];

        foreach ($known as $key => $entry) {
            if (is_array($entry) && ($entry['hook'] ?? null) === $hook) {
                $argSets[] = (array) ($entry['args'] ?? []);
                unset($known[$key]);
            }
        }

        if (count($argSets) > 1) {
            update_option(self::INSTALLED_OPTION, $known, true);
        }

        if (! function_exists('as_unschedule_all_actions')) {
            return;
        }

        foreach (array_unique($argSets, SORT_REGULAR) as $args) {
            \as_unschedule_all_actions($hook, $args, self::GROUP);
        }
    }

    /**
     * Idempotent: no-op if this hook is already scheduled, else run every
     * $intervalSeconds starting one minute from now. Copies of the schedule
     * are canceled.
     *
     * @param array<array-key,mixed> $args
     * @since 1.0.0
     */
    public function scheduleRecurring(string $hook, int $intervalSeconds, array $args = []): void
    {
        // as_has_scheduled_action is an uncached join, and every registered
        // sweep fires one on init for every request the site serves. The answer
        // is remembered in an autoloaded option and revalidated daily, so a hook
        // someone unscheduled by hand comes back within a day rather than on the
        // next request. forgetRecurring() drops the memo for a caller that needs
        // it back sooner.
        $key   = $hook . '|' . md5((string) wp_json_encode($args));
        $known = get_option(self::INSTALLED_OPTION, []);
        $known = is_array($known) ? $known : [];
        $now   = time();

        if (isset($known[$key]['until']) && (int) $known[$key]['until'] > $now) {
            return;
        }

        if (! \as_has_scheduled_action($hook, $args, self::GROUP)) {
            \as_schedule_recurring_action($now + 60, $intervalSeconds, $hook, $args, self::GROUP);
        }

        $this->cancelDuplicates($hook, $args);

        // hook and args, not just the expiry: this map is also what
        // deactivation reads to know what to unschedule.
        $known[$key] = ['hook' => $hook, 'args' => $args, 'until' => $now + self::RECHECK];
        update_option(self::INSTALLED_OPTION, $known, true);
    }

    /**
     * Requests that arrive together all find nothing scheduled and all
     * schedule, and each copy reschedules itself for good. Action Scheduler's
     * $unique flag does not stop them: only the table store honors it, and a
     * new site is not on that store yet.
     *
     * The newest is the one kept. Whatever the queue reschedules gets a higher
     * id than every copy, so the action a request cancels is never the last.
     *
     * @param array<array-key,mixed> $args
     *
     * @since 1.1.1
     */
    private function cancelDuplicates(string $hook, array $args): void
    {
        $pending = \as_get_scheduled_actions([
            'hook'     => $hook,
            'args'     => $args,
            'group'    => self::GROUP,
            'status'   => ActionScheduler_Store::STATUS_PENDING,
            'per_page' => self::DUPLICATE_PROBE,
            'orderby'  => 'none',
        ], 'ids');

        if (count($pending) < 2) {
            return;
        }

        try {
            $store = ActionScheduler_Store::instance();
            $kept  = false;
            rsort($pending, SORT_NUMERIC);

            foreach ($pending as $id) {
                $action = $store->fetch_action($id);

                // Not a copy: one the queue has started on since it was listed,
                // or a sweep with a backlog queuing itself once more under its
                // own hook.
                if ($action->is_finished() || ! $action->get_schedule()->is_recurring()) {
                    continue;
                }

                if ($kept) {
                    $store->cancel_action($id);
                }

                $kept = true;
            }
        } catch (Throwable) {
            // This runs on init. The copy waits for the next daily check.
        }
    }
}
