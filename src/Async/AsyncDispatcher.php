<?php

declare(strict_types=1);

namespace Gratora\Async;

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
     * Idempotent: no-op if this hook is already scheduled, else run every
     * $intervalSeconds starting one minute from now.
     *
     * @param array<array-key,mixed> $args
     * @since 1.0.0
     */
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

        // hook and args, not just the expiry: this map is also what
        // deactivation reads to know what to unschedule.
        $known[$key] = ['hook' => $hook, 'args' => $args, 'until' => $now + self::RECHECK];
        update_option(self::INSTALLED_OPTION, $known, true);
    }
}
