<?php

declare(strict_types=1);

namespace Dono\Gateways\Stripe;

/**
 * The platform's cut of a payment. One rule set, shared by intents and
 * subscription renewals: nothing when licensed, nothing on non-donation
 * kinds (an event ticket order is not a donation the platform takes a cut
 * of), otherwise the filterable basis-point rate clamped to sane bounds.
 */
final class PlatformFee
{
    /** Platform fee in basis points (200 = 2%). Filterable via dono.stripe.application_fee_bps. */
    public const BPS = 200;

    public static function cents(int $amountCents, string $kind, bool $isPro): int
    {
        if ($isPro || $kind !== 'donation') {
            return 0;
        }
        $fee = (int) floor($amountCents * self::bps() / 10000);
        return max(0, min($fee, $amountCents));
    }

    public static function percent(string $kind, bool $isPro): float
    {
        if ($isPro || $kind !== 'donation') {
            return 0.0;
        }
        return self::bps() / 100;
    }

    private static function bps(): int
    {
        $bps = (int) apply_filters('dono.stripe.application_fee_bps', self::BPS);
        return max(0, min(10000, $bps));
    }
}
