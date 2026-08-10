<?php

declare(strict_types=1);

namespace Dono\Recurring;

use RuntimeException;

/**
 * Bridge between Dono donation frequencies and Stripe billing intervals.
 *
 * Dono frequencies are user-facing labels chosen on the form: one_time, weekly,
 * biweekly, monthly, quarterly, yearly. Stripe Prices take an `interval`
 * (day|week|month|year) plus `interval_count`; this mapper produces the pair.
 *
 * @since 1.0.0
 */
final class FrequencyMap
{
    /**
     * @return array{0:string,1:int} [interval_unit, interval_count]
     *
     * @since 1.0.0
     */
    public static function toStripe(string $frequency): array
    {
        return match ($frequency) {
            'weekly'    => ['week',  1],
            'biweekly'  => ['week',  2],
            'monthly'   => ['month', 1],
            'quarterly' => ['month', 3],
            'yearly'    => ['year',  1],
            default     => throw new RuntimeException("Cannot map non-recurring frequency '{$frequency}' to a Stripe interval."),
        };
    }

    /**
     * Used as the `billing_cycle_anchor` so Stripe doesn't double-charge a donor
     * who just paid their first installment through the one-off PaymentIntent.
     *
     * @since 1.0.0
     */
    public static function nextRenewalAfter(int $nowEpoch, string $interval, int $intervalCount): int
    {
        $modifier = match ($interval) {
            'day'   => "+{$intervalCount} day",
            'week'  => '+' . ($intervalCount * 7) . ' day',
            'month' => "+{$intervalCount} month",
            'year'  => "+{$intervalCount} year",
            default => throw new RuntimeException("Unknown interval '{$interval}'."),
        };

        return (new \DateTimeImmutable("@{$nowEpoch}"))
            ->modify($modifier)
            ->getTimestamp();
    }

    /** @since 1.0.0 */
    public static function isRecurring(string $frequency): bool
    {
        return $frequency !== '' && $frequency !== 'one_time';
    }
}
