<?php

declare(strict_types=1);

namespace Dono\Tests\Unit;

use Dono\Gateways\Razorpay\RazorpayMoney;
use PHPUnit\Framework\TestCase;

/**
 * Razorpay charges an integer in the currency's smallest unit. For INR that is
 * the same number Dono stores, which is exactly the trap: a conversion that
 * "works" for the only currency anyone tests would be 100x wrong the moment an
 * account with international acceptance charges yen.
 */
final class RazorpayMoneyTest extends TestCase
{
    public function test_rupees_are_sent_as_paise(): void
    {
        // 250.00 INR stored as 25000 -> 25000 paise.
        $this->assertSame(25000, RazorpayMoney::toAmount(25000, 'INR'));
    }

    public function test_zero_decimal_currency_is_not_multiplied(): void
    {
        // 1000 JPY stored as 100000 -> 1000, not 100000.
        $this->assertSame(1000, RazorpayMoney::toAmount(100000, 'JPY'));
    }

    public function test_three_decimal_currency_scales_up(): void
    {
        // 1.000 KWD stored as 100 -> 1000 fils.
        $this->assertSame(1000, RazorpayMoney::toAmount(100, 'KWD'));
    }

    public function test_amounts_round_trip(): void
    {
        foreach ([['INR', 25000], ['JPY', 100000], ['KWD', 100], ['USD', 999]] as [$code, $stored]) {
            $this->assertSame(
                $stored,
                RazorpayMoney::toStoredCents(RazorpayMoney::toAmount($stored, $code), $code),
                "{$code} survives the round trip"
            );
        }
    }

    public function test_fees_reported_by_razorpay_convert_back(): void
    {
        // A 590 paise fee is 5.90 INR, stored as 590.
        $this->assertSame(590, RazorpayMoney::toStoredCents(590, 'INR'));
    }
}
