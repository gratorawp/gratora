<?php

declare(strict_types=1);

namespace Dono\Gateways\Razorpay;

use Dono\Currency\Currency;

/**
 * Amount conversion at the Razorpay boundary.
 *
 * Dono stores money as major units x 100 for every currency. Razorpay takes an
 * integer in the currency's smallest unit, like Stripe: INR 250.00 is 25000
 * paise. For INR that happens to equal the stored value, which is exactly why
 * the conversion goes through Currency rather than being assumed: an org with
 * international acceptance charging JPY would be overcharged 100x otherwise.
 *
 * @version 1.0.0
 */
final class RazorpayMoney
{
    /** Internal stored cents -> the integer Razorpay charges. */
    public static function toAmount(int $storedCents, string $code): int
    {
        return Currency::toMinorUnits($storedCents, $code);
    }

    /** A Razorpay amount -> internal stored cents (major x 100). */
    public static function toStoredCents(int $amount, string $code): int
    {
        return Currency::fromMinorUnits($amount, $code);
    }
}
