<?php

declare(strict_types=1);

namespace Dono\Async;

/**
 * Wrapper over Action Scheduler.
 *
 * @since 1.0.0
 */
final class AsyncDispatcher
{
    public const GROUP = 'dono';

    /**
     * @param array<string,mixed> $args
     * @since 1.0.0
     */
    public function enqueue(string $hook, array $args = []): void
    {
        \as_enqueue_async_action($hook, $args, self::GROUP);
    }

    /**
     * @param array<string,mixed> $args
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
     * @param array<string,mixed> $args
     * @since 1.0.0
     */
    public function scheduleRecurring(string $hook, int $intervalSeconds, array $args = []): void
    {
        if (\as_has_scheduled_action($hook, $args, self::GROUP)) {
            return;
        }

        \as_schedule_recurring_action(time() + 60, $intervalSeconds, $hook, $args, self::GROUP);
    }
}
