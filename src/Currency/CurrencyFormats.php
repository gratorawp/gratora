<?php

declare(strict_types=1);

namespace FundKit\Currency;

/**
 * How each currency is conventionally written.
 *
 * These are presets, not rules. The org's stored format stays a single block
 * that applies to every amount it renders; choosing a base currency fills that
 * block from here so the default is right for the money the org actually
 * counts, and the admin can still change any of it afterwards.
 *
 * @since 1.0.0
 */
final class CurrencyFormats
{
    /**
     * Decimal places here are the org's display preference, which Money treats
     * as a ceiling on the base currency alone, so a zero-decimal currency asks
     * for none rather than printing hundredths it cannot be charged in.
     *
     * Stored positionally as [places, decimal, thousand, position]; for()
     * names the parts.
     *
     * @var array<string, array{0:int, 1:string, 2:string, 3:string}>
     */
    private const CONVENTIONS = [
        'USD' => [2, '.', ',', 'before'],
        'GBP' => [2, '.', ',', 'before'],
        'AUD' => [2, '.', ',', 'before'],
        'CAD' => [2, '.', ',', 'before'],
        'NZD' => [2, '.', ',', 'before'],
        'SGD' => [2, '.', ',', 'before'],
        'HKD' => [2, '.', ',', 'before'],
        'MXN' => [2, '.', ',', 'before'],
        'INR' => [2, '.', ',', 'before'],
        'CNY' => [2, '.', ',', 'before'],
        'JPY' => [0, '.', ',', 'before'],
        'CHF' => [2, '.', "'", 'before'],
        'BRL' => [2, ',', '.', 'before'],
        'ZAR' => [2, ',', ' ', 'before'],
        'EUR' => [2, ',', '.', 'after'],
        'DKK' => [2, ',', '.', 'after'],
        'SEK' => [2, ',', ' ', 'after'],
        'NOK' => [2, ',', ' ', 'after'],
        'PLN' => [2, ',', ' ', 'after'],
        'CZK' => [2, ',', ' ', 'after'],
        'HUF' => [2, ',', ' ', 'after'],
    ];

    /**
     * The preset for one code. An unlisted currency gets the plain grouping
     * rather than a guess, with the places its minor units allow.
     *
     * @return array{decimal_places:int, decimal_sep:string, thousand_sep:string, symbol_position:string}
     * @since 1.0.0
     */
    public static function for(string $code): array
    {
        $code = strtoupper(trim($code));
        $row  = self::CONVENTIONS[$code] ?? null;

        if ($row === null) {
            return [
                'decimal_places'  => min(2, Currency::minorUnits($code)),
                'decimal_sep'     => '.',
                'thousand_sep'    => ',',
                'symbol_position' => 'before',
            ];
        }

        return [
            'decimal_places'  => $row[0],
            'decimal_sep'     => $row[1],
            'thousand_sep'    => $row[2],
            'symbol_position' => $row[3],
        ];
    }

    /**
     * Every preset, keyed by code, for the admin bridge.
     *
     * @return array<string, array{decimal_places:int, decimal_sep:string, thousand_sep:string, symbol_position:string}>
     * @since 1.0.0
     */
    public static function all(): array
    {
        $out = [];
        foreach (array_keys(self::CONVENTIONS) as $code) {
            $out[$code] = self::for($code);
        }
        return $out;
    }
}
