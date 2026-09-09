<?php

declare(strict_types=1);

namespace Gratora\Recurring;

use RuntimeException;

/**
 * Bridge between Gratora donation frequencies and Stripe billing intervals.
 *
 * Gratora frequencies are user-facing labels chosen on the form: one_time, weekly,
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
            default     => throw new RuntimeException(esc_html("Cannot map non-recurring frequency '{$frequency}' to a Stripe interval.")),
        };
    }

    /**
     * Anchor renewal after the first installment to prevent a second immediate charge.
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
            default => throw new RuntimeException(esc_html("Unknown interval '{$interval}'.")),
        };

        return (new \DateTimeImmutable("@{$nowEpoch}"))
            ->modify($modifier)
            ->getTimestamp();
    }

    /**
     * The frequencies this product can name, which is narrower than what a
     * processor accepts. A plan put on a cadence outside this list has no label
     * on any screen and no matching option on any form.
     *
     * @return list<string>
     *
     * @since 1.0.0
     */
    public static function recurringFrequencies(): array
    {
        return ['weekly', 'biweekly', 'monthly', 'quarterly', 'yearly'];
    }

    /**
     * The frequency an interval pair stands for, or null for a pair this
     * product has no name for.
     *
     * @since 1.0.0
     */
    public static function fromInterval(string $unit, int $count): ?string
    {
        foreach (self::recurringFrequencies() as $frequency) {
            [$u, $c] = self::toStripe($frequency);
            if ($u === $unit && $c === $count) {
                return $frequency;
            }
        }

        return null;
    }

    /** @since 1.0.0 */
    public static function label(string $frequency): string
    {
        return match ($frequency) {
            'weekly'    => __('every week', 'gratora'),
            'biweekly'  => __('every 2 weeks', 'gratora'),
            'monthly'   => __('every month', 'gratora'),
            'quarterly' => __('every 3 months', 'gratora'),
            'yearly'    => __('every year', 'gratora'),
            default     => $frequency,
        };
    }

    /** @since 1.0.0 */
    public static function isRecurring(string $frequency): bool
    {
        return $frequency !== '' && $frequency !== 'one_time';
    }
}
