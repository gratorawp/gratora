<?php

declare(strict_types=1);

namespace Dono\Tests\Unit\Currency;

use Dono\Currency\Currency;
use PHPUnit\Framework\TestCase;

/**
 * Pins the minor-unit exponent table and the storage <-> processor-amount
 * rescale. The bug these guard against: storing every currency as major*100
 * and handing that straight to Stripe, which 100x-overcharges zero-decimal
 * currencies (JPY) and 10x-undercharges three-decimal ones (BHD).
 */
final class CurrencyTest extends TestCase
{
    /**
     * @dataProvider exponents
     */
    public function test_minor_units_exponent(string $code, int $expected): void
    {
        $this->assertSame($expected, Currency::minorUnits($code));
    }

    /** @return array<string,array{0:string,1:int}> */
    public static function exponents(): array
    {
        return [
            'JPY is zero-decimal'      => ['JPY', 0],
            'KRW is zero-decimal'      => ['KRW', 0],
            'VND is zero-decimal'      => ['VND', 0],
            'XOF is zero-decimal'      => ['XOF', 0],
            'USD is two-decimal'       => ['USD', 2],
            'EUR is two-decimal'       => ['EUR', 2],
            'GBP is two-decimal'       => ['GBP', 2],
            'BHD is three-decimal'     => ['BHD', 3],
            'KWD is three-decimal'     => ['KWD', 3],
            'OMR is three-decimal'     => ['OMR', 3],
            // Stripe special cases: charged as major*100, so they must stay at 2.
            'ISK stays two-decimal'    => ['ISK', 2],
            'HUF stays two-decimal'    => ['HUF', 2],
            'TWD stays two-decimal'    => ['TWD', 2],
            'UGX stays two-decimal'    => ['UGX', 2],
            'lowercase is normalised'  => ['jpy', 0],
            'unknown defaults to two'  => ['ZZZ', 2],
            'empty defaults to two'    => ['', 2],
        ];
    }

    /**
     * @dataProvider toMinor
     */
    public function test_to_minor_units(int $stored, string $code, int $expected): void
    {
        $this->assertSame($expected, Currency::toMinorUnits($stored, $code));
    }

    /** @return array<string,array{0:int,1:string,2:int}> */
    public static function toMinor(): array
    {
        return [
            'JPY 1000 charged as 1000'   => [100000, 'JPY', 1000],   // was 100000 -> 100x overcharge
            'USD 25.00 charged as 2500'  => [2500,   'USD', 2500],   // unchanged
            'EUR 9.99 charged as 999'    => [999,    'EUR', 999],
            'BHD 5.000 charged as 5000'  => [500,    'BHD', 5000],   // was 500 -> 10x undercharge
            'KWD 12.340 charged as 12340'=> [1234,   'KWD', 12340],
            'UGX 50.00 charged as 5000'  => [5000,   'UGX', 5000],   // special case: stays major*100
        ];
    }

    /**
     * @dataProvider fromMinor
     */
    public function test_from_minor_units(int $minor, string $code, int $expected): void
    {
        $this->assertSame($expected, Currency::fromMinorUnits($minor, $code));
    }

    /** @return array<string,array{0:int,1:string,2:int}> */
    public static function fromMinor(): array
    {
        return [
            'JPY refund 1000 -> 100000'  => [1000,  'JPY', 100000],
            'USD refund 2500 -> 2500'    => [2500,  'USD', 2500],
            'BHD refund 5000 -> 500'     => [5000,  'BHD', 500],
            'KWD refund 12340 -> 1234'   => [12340, 'KWD', 1234],
        ];
    }

    /**
     * Outbound then inbound returns the original stored value for any amount the
     * storage can represent, so a refund recorded from Stripe matches the charge.
     *
     * @dataProvider roundTrip
     */
    public function test_round_trip_is_identity(int $stored, string $code): void
    {
        $this->assertSame($stored, Currency::fromMinorUnits(Currency::toMinorUnits($stored, $code), $code));
    }

    /** @return array<string,array{0:int,1:string}> */
    public static function roundTrip(): array
    {
        return [
            'JPY' => [100000, 'JPY'],
            'USD' => [2500,   'USD'],
            'BHD' => [500,    'BHD'],
            'HUF' => [100000, 'HUF'],
            'UGX' => [50000,  'UGX'],
        ];
    }

    /** Stripe rejects three-decimal amounts whose last digit isn't 0; the *100 storage guarantees it. */
    public function test_three_decimal_amounts_are_multiples_of_ten(): void
    {
        foreach ([100, 250, 999, 12345] as $stored) {
            $this->assertSame(0, Currency::toMinorUnits($stored, 'BHD') % 10);
        }
    }

    /** Regression guard: UGX/ISK/HUF/TWD must not be divided like true zero-decimal currencies. */
    public function test_special_case_currencies_pass_through_unchanged(): void
    {
        foreach (['ISK', 'HUF', 'TWD', 'UGX'] as $code) {
            $this->assertSame(123456, Currency::toMinorUnits(123456, $code), "{$code} must stay major*100");
            $this->assertSame(123456, Currency::fromMinorUnits(123456, $code), "{$code} must stay major*100");
        }
    }
}
