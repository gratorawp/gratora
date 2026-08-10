<?php

declare(strict_types=1);

namespace Dono\Gateways\PayPal;

use Dono\Currency\Currency;

/**
 * Amount conversion at the PayPal boundary.
 *
 * Dono stores money as major units x 100 for every currency. PayPal does not
 * take minor units like Stripe: it takes a decimal string carrying exactly the
 * currency's own number of decimal places, and rejects the value otherwise
 * (JPY "1000" is fine, JPY "1000.00" is not).
 *
 * @since 1.0.0
 */
final class PayPalMoney
{
    /**
     * Internal stored cents -> the decimal string PayPal expects.
     *
     * @since 1.0.0
     */
    public static function toValue(int $storedCents, string $code): string
    {
        $decimals = Currency::minorUnits($code);
        return number_format($storedCents / 100, $decimals, '.', '');
    }

    /**
     * A PayPal decimal string -> internal stored cents (major x 100).
     *
     * @since 1.0.0
     */
    public static function toStoredCents(string $value, string $code): int
    {
        return (int) round(((float) $value) * 100);
    }
}
