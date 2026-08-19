<?php

declare(strict_types=1);

namespace Dono\Foundation\Helpers;

use Dono\Currency\Currency;

/**
 * Canonical money formatter for human-facing cents values.
 * DB/API/repository layers keep working in integer cents; only display calls this.
 *
 * @since 1.0.0
 */
final class Money
{
    /** ISO 4217 to symbol map. */
    private const SYMBOLS = [
        'USD' => '$',
        'EUR' => '€',
        'GBP' => '£',
        'AUD' => 'A$',
        'CAD' => 'C$',
        'CHF' => 'CHF',
        'JPY' => '¥',
        'CNY' => '¥',
        'SEK' => 'kr',
        'NOK' => 'kr',
        'DKK' => 'kr',
        'PLN' => 'zł',
        'CZK' => 'Kč',
        'HUF' => 'Ft',
        'BRL' => 'R$',
        'MXN' => 'Mex$',
        'INR' => '₹',
        'NZD' => 'NZ$',
        'ZAR' => 'R',
        'SGD' => 'S$',
        'HKD' => 'HK$',
    ];

    /**
     * @param int    $cents    Whole-cents amount (negative allowed for refunds).
     * @param string $currency ISO 4217 code. Empty falls back to org default_currency.
     * @param bool   $compact  Drop trailing .00 on whole amounts.
     * @since 1.0.0
     */
    public static function format(int $cents, string $currency = '', bool $compact = false): string
    {
        $code     = strtoupper(trim($currency)) ?: self::defaultCurrency();
        $major    = $cents / 100;
        $isWhole  = ($cents % 100) === 0;
        $fmt      = self::numberFormat();
        $decimals = ($compact && $isWhole) ? 0 : self::decimalsFor($code, $cents);

        $number = number_format(
            abs($major),
            $decimals,
            (string) $fmt['decimal_sep'],
            (string) $fmt['thousand_sep'],
        );
        if ($major < 0) $number = '-' . $number;

        $symbol = self::SYMBOLS[$code] ?? $code;
        return $fmt['symbol_position'] === 'after'
            ? $number . ' ' . $symbol
            : $symbol . $number;
    }

    /**
     * Display decimal places for an amount: the currency's ISO minor-unit count,
     * so JPY shows none and BHD shows three. The org's configured places apply to
     * its own default currency only when the amount has no minor units to drop,
     * so a display preference can never render an amount other than the one
     * charged. Amounts are stored as major x 100 regardless, so only rendering
     * changes.
     *
     * The preference is a ceiling on places, never a floor: an org whose base is
     * yen has no hundredths to show, so asking for two would print a precision
     * the currency does not have.
     *
     * @since 1.0.0
     */
    private static function decimalsFor(string $code, int $cents): int
    {
        $units = Currency::minorUnits($code);
        if ($code === self::defaultCurrency() && ($cents % 100) === 0) {
            return min($units, (int) self::numberFormat()['decimal_places']);
        }
        return $units;
    }

    /**
     * Org-wide number-format settings, cached per request.
     *
     * @return array{decimal_places:int, decimal_sep:string, thousand_sep:string, symbol_position:string}
     * @since 1.0.0
     */
    public static function numberFormat(): array
    {
        static $cached = null;
        if ($cached !== null) return $cached;

        $opt = get_option('dono_currency_locale', []);
        $f   = is_array($opt['format'] ?? null) ? $opt['format'] : [];

        return $cached = [
            'decimal_places'  => (int) ($f['decimal_places']  ?? 2),
            'decimal_sep'     => (string) ($f['decimal_sep']  ?? '.'),
            'thousand_sep'    => (string) ($f['thousand_sep'] ?? ','),
            'symbol_position' => ($f['symbol_position'] ?? '') === 'after' ? 'after' : 'before',
        ];
    }

    /** @since 1.0.0 */
    public static function compact(int $cents, string $currency = ''): string
    {
        return self::format($cents, $currency, true);
    }

    /**
     * Org number format in the JS shape consumed by @dono/ui's formatAmount
     * (window.dono.number_format). Symbol is the org default currency's;
     * formatAmount falls back to its own table for other currencies.
     *
     * @return array{decimalPlaces:int, decimalSep:string, thousandSep:string, symbolPosition:string, symbol:string}
     * @since 1.0.0
     */
    public static function jsNumberFormat(): array
    {
        $fmt = self::numberFormat();
        return [
            'decimalPlaces'  => (int) $fmt['decimal_places'],
            'decimalSep'     => (string) $fmt['decimal_sep'],
            'thousandSep'    => (string) $fmt['thousand_sep'],
            'symbolPosition' => (string) $fmt['symbol_position'],
            'symbol'         => self::symbolFor(self::defaultCurrency()),
        ];
    }

    /** @since 1.0.0 */
    public static function symbolFor(string $currency): string
    {
        $code = strtoupper(trim($currency));
        return self::SYMBOLS[$code] ?? $code;
    }

    /**
     * Bare major-unit number with org grouping, no symbol. The amount is the org
     * default currency's, so it is rendered at that currency's precision.
     *
     * @since 1.0.0
     */
    public static function major(int $cents, bool $compact = false): string
    {
        $major    = $cents / 100;
        $isWhole  = ($cents % 100) === 0;
        $fmt      = self::numberFormat();
        $decimals = ($compact && $isWhole) ? 0 : self::decimalsFor(self::defaultCurrency(), $cents);
        $out      = number_format(abs($major), $decimals, (string) $fmt['decimal_sep'], (string) $fmt['thousand_sep']);
        return $major < 0 ? '-' . $out : $out;
    }

    /** @since 1.0.0 */
    public static function defaultCurrency(): string
    {
        static $cached = null;
        if ($cached !== null) return $cached;

        $opt = get_option('dono_currency_locale');
        if (is_array($opt) && ! empty($opt['default_currency'])) {
            return $cached = strtoupper((string) $opt['default_currency']);
        }
        return $cached = 'USD';
    }
}
