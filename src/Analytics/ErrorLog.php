<?php

declare(strict_types=1);

namespace Dono\Analytics;

/**
 * Records a failure where the site owner can see it.
 *
 * Writes to dono_events as `error.<source>`, and to error_log as well: when the
 * database is what broke, the row write fails too.
 *
 * dono_events rather than its own table, so errors inherit the retention window
 * and the erasure handler that already clear it.
 *
 * @since 1.0.0
 */
final class ErrorLog
{
    public const PREFIX = 'error.';

    /**
     * Newest errors kept. The retention window governs dono_events as a whole
     * and is measured in days, which bounds nothing when a webhook retries
     * every minute for a week. Errors are read newest-first from one screen,
     * so anything past this is unreachable anyway.
     */
    public const KEEP = 500;

    /** Overhang tolerated before pruning, so the delete runs once per SLACK errors. */
    private const SLACK = 100;

    /**
     * The one place this plugin writes to PHP's log, and only where a developer
     * asked for one. A plugin that writes to a production error log on its own
     * schedule fills a disk it does not own, and the failures worth seeing are
     * on the Tools screen either way.
     *
     * @since 1.0.0
     */
    public static function toDebugLog(string $message): void
    {
        if (! defined('WP_DEBUG') || ! WP_DEBUG) {
            return;
        }

        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- the WP_DEBUG guard above is the developer opt-in, and this is the only error_log() call in the plugin.
        error_log('[dono] ' . $message);
    }

    /**
     * @param string              $source  dotted scope, e.g. 'gateway.paypal'
     * @param array<string,mixed> $context ids and detail; stored, so no secrets
     *
     * @since 1.0.0
     */
    public static function record(string $source, string $message, array $context = []): void
    {
        $source  = preg_replace('/[^a-z0-9_.]/', '', strtolower($source)) ?: 'unknown';
        $message = trim($message);

        self::toDebugLog(sprintf('%s: %s', $source, $message));

        $recorder = self::recorder();
        if ($recorder === null) {
            return;
        }

        $scoped = ['donor_id', 'donation_id', 'recurring_plan_id', 'campaign_id', 'form_id'];

        $recorder->record(self::PREFIX . $source, array_merge(
            // Ids EventRecorder promotes to columns stay top level, so an error
            // filters like any other event.
            array_intersect_key($context, array_flip($scoped)),
            ['payload' => ['message' => mb_substr($message, 0, 1000)]
                + array_diff_key($context, array_flip($scoped))]
        ));

        self::prune();
    }

    /**
     * Drop everything past the newest KEEP errors. The count runs on every
     * write, over an index and a set bounded by KEEP + SLACK, so it stays
     * cheap precisely when errors are arriving fast.
     *
     * @since 1.0.0
     */
    private static function prune(): void
    {
        $total = (int) Event::query()->whereLike('type', self::PREFIX . '%')->count();
        if ($total <= self::KEEP + self::SLACK) {
            return;
        }

        // The id of the oldest error worth keeping. Deleting by id beats an
        // OFFSET delete, which MySQL does not allow.
        $oldestKept = Event::query()
            ->whereLike('type', self::PREFIX . '%')
            ->orderBy('id', 'DESC')
            ->limit(1)
            ->offset(self::KEEP - 1)
            ->get();

        if (! $oldestKept) {
            return;
        }

        Event::query()
            ->whereLike('type', self::PREFIX . '%')
            ->where('id', (int) $oldestKept->id, '<')
            ->delete();
    }

    /**
     * Null before the container is up; the error_log line above still lands.
     *
     * @since 1.0.0
     */
    private static function recorder(): ?EventRecorder
    {
        try {
            $container = \Dono\Foundation\Plugin::instance()->container;

            return $container->has(EventRecorder::class)
                ? $container->get(EventRecorder::class)
                : null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
