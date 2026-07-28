<?php

declare(strict_types=1);

namespace Dono\Tests\Unit\Gateways;

use Dono\Gateways\PayPal\PayPalMoney;
use PHPUnit\Framework\TestCase;

/**
 * PayPal takes a decimal string carrying exactly the currency's own number of
 * decimal places, and rejects the amount otherwise. Dono stores major x 100 for
 * every currency, so the conversion has to vary by currency, not by a constant.
 */
final class PayPalMoneyTest extends TestCase
{
    /** @dataProvider amounts */
    public function test_stored_cents_convert_to_the_value_paypal_expects(
        int $stored,
        string $code,
        string $expected
    ): void {
        $this->assertSame($expected, PayPalMoney::toValue($stored, $code));
    }

    /** @return array<string,array{0:int,1:string,2:string}> */
    public static function amounts(): array
    {
        return [
            'USD two-decimal'      => [2500, 'USD', '25.00'],
            'USD sub-unit'         => [2599, 'USD', '25.99'],
            'JPY zero-decimal'     => [100000, 'JPY', '1000'],
            'BHD three-decimal'    => [500, 'BHD', '5.000'],
            'large amount no separators' => [123456789, 'USD', '1234567.89'],
        ];
    }

    /** @dataProvider echoes */
    public function test_paypal_values_convert_back_to_stored_cents(
        string $value,
        string $code,
        int $expected
    ): void {
        $this->assertSame($expected, PayPalMoney::toStoredCents($value, $code));
    }

    /** @return array<string,array{0:string,1:string,2:int}> */
    public static function echoes(): array
    {
        return [
            'USD'  => ['25.00', 'USD', 2500],
            'JPY'  => ['1000', 'JPY', 100000],
            'BHD'  => ['5.000', 'BHD', 500],
            'odd cents' => ['0.07', 'USD', 7],
        ];
    }

    /** @dataProvider roundTrips */
    public function test_amounts_survive_a_round_trip(int $stored, string $code): void
    {
        $this->assertSame(
            $stored,
            PayPalMoney::toStoredCents(PayPalMoney::toValue($stored, $code), $code),
            "{$code} {$stored} must survive out and back"
        );
    }

    /** @return array<string,array{0:int,1:string}> */
    public static function roundTrips(): array
    {
        return [
            'USD' => [2500, 'USD'],
            'JPY' => [100000, 'JPY'],
            'BHD' => [500, 'BHD'],
            'EUR' => [999, 'EUR'],
        ];
    }

    /** A zero-decimal currency must never be sent with a fractional part. */
    public function test_zero_decimal_currency_has_no_decimal_point(): void
    {
        $this->assertStringNotContainsString('.', PayPalMoney::toValue(100000, 'JPY'));
    }

    /** Thousands separators would be rejected by PayPal. */
    public function test_value_never_carries_a_thousands_separator(): void
    {
        $this->assertStringNotContainsString(',', PayPalMoney::toValue(123456789, 'USD'));
    }
}
