<?php

declare(strict_types=1);

namespace Gratora\Gateways;

defined('ABSPATH') || exit;

/**
 * What a processor says a subscription's schedule is, after being asked to
 * change it.
 *
 * next_payment_at has three different owners: Stripe reads it off the invoice
 * line period end, PayPal recomputes it from the cadence, and the sandbox
 * counts minutes and ignores interval_unit entirely. There is no local formula
 * that is right for all three, so a schedule change asks and writes back what
 * it was told rather than guessing.
 *
 * Null means the gateway did not say, which is not the same as "now": a caller
 * that gets null leaves the stored date alone.
 *
 * @since 1.0.0
 */
final class SubscriptionSchedule
{
    /** @since 1.0.0 */
    private function __construct(
        public readonly ?string $nextPaymentAt = null,
        public readonly ?string $currentPeriodEnd = null,
    ) {
    }

    /** @since 1.0.0 */
    public static function at(?string $nextPaymentAt, ?string $currentPeriodEnd = null): self
    {
        return new self($nextPaymentAt, $currentPeriodEnd);
    }

    /**
     * The gateway applied the change but told us nothing about when it next
     * charges, or has not applied it yet and will say later.
     *
     * @since 1.0.0
     */
    public static function unknown(): self
    {
        return new self(null, null);
    }

    /**
     * @param array<string,mixed> $sub a Stripe Subscription object
     *
     * @since 1.0.0
     */
    public static function fromStripe(array $sub): self
    {
        $end = $sub['current_period_end']
            ?? ($sub['items']['data'][0]['current_period_end'] ?? null);

        if (! is_int($end) && ! ctype_digit((string) $end)) {
            return self::unknown();
        }

        // gmdate, not date: the column is UTC everywhere else, and date() would
        // shift the next charge by the site's offset.
        $at = gmdate('Y-m-d H:i:s', (int) $end);

        return new self($at, $at);
    }
}
