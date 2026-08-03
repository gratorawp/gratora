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
 * @version 1.0.0
 */
final class ErrorLog
{
    public const PREFIX = 'error.';

    /**
     * @param string              $source  dotted scope, e.g. 'gateway.paypal'
     * @param array<string,mixed> $context ids and detail; stored, so no secrets
     */
    public static function record(string $source, string $message, array $context = []): void
    {
        $source  = preg_replace('/[^a-z0-9_.]/', '', strtolower($source)) ?: 'unknown';
        $message = trim($message);

        error_log(sprintf('[dono] %s: %s', $source, $message));

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
    }

    /** Null before the container is up; the error_log line above still lands. */
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
