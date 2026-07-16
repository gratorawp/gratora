<?php

declare(strict_types=1);

namespace Dono\Currency;

/**
 * Currency minor-unit exponents and the storage <-> processor charge-amount conversion.
 * Storage is always major units x 100 regardless of the currency's real precision; the
 * rescale to the processor's smallest unit happens only here, at the gateway boundary
 * (prevents the 100x JPY / 10x BHD mischarge).
 */
final class Currency
{
    /** Charge amount is the whole-unit value (major x 10^0). e.g. 500 JPY -> 500. */
    private const ZERO_DECIMAL = [
        'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA',
        'PYG', 'RWF', 'VND', 'VUV', 'XAF', 'XOF', 'XPF',
    ];

    /** Charge amount is in thousandths (major x 10^3); last digit is always 0. */
    private const THREE_DECIMAL = ['BHD', 'IQD', 'JOD', 'KWD', 'LYD', 'OMR', 'TND'];

    /**
     * ISO 4217 minor-unit exponent: 0, 2 (default) or 3.
     *
     * ISK, HUF, TWD and UGX are intentionally left at 2: current Stripe charges
     * expect them as major x 100 (ISK/UGX are represented two-decimal with the
     * decimals always 00; HUF/TWD's divisible-by-100 rule applies to payouts,
     * not charges), which is exactly what the internal x100 storage holds.
     */
    public static function minorUnits(string $code): int
    {
        $code = strtoupper(trim($code));
        if (in_array($code, self::ZERO_DECIMAL, true)) {
            return 0;
        }
        if (in_array($code, self::THREE_DECIMAL, true)) {
            return 3;
        }
        return 2;
    }

    /**
     * Internal storage (major x 100) -> smallest currency unit (processor amount).
     * Exact integer math; never invents sub-unit precision the currency lacks
     * (for 3-decimal currencies the result is always a multiple of 10, which is
     * what Stripe requires).
     */
    public static function toMinorUnits(int $storedCents, string $code): int
    {
        $exponent = self::minorUnits($code);
        if ($exponent === 2) {
            return $storedCents;
        }
        if ($exponent > 2) {
            return $storedCents * (10 ** ($exponent - 2));
        }
        return (int) round($storedCents / (10 ** (2 - $exponent)));
    }

    /** Smallest currency unit (processor amount) -> internal storage (major x 100). */
    public static function fromMinorUnits(int $minorUnits, string $code): int
    {
        $exponent = self::minorUnits($code);
        if ($exponent === 2) {
            return $minorUnits;
        }
        if ($exponent > 2) {
            return (int) round($minorUnits / (10 ** ($exponent - 2)));
        }
        return $minorUnits * (10 ** (2 - $exponent));
    }
}
