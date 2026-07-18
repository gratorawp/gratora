<?php

declare(strict_types=1);

namespace Dono\Tests\Unit\Foundation;

use Dono\Foundation\Helpers\Money;
use PHPUnit\Framework\TestCase;

/**
 * Money::format renders each currency with its own decimal count. Every
 * currency is stored as major*100, so the fix is display-only: zero-decimal
 * currencies drop the cents, three-decimal ones show three places, the rest
 * keep two. The org's own default currency (USD here, no option set) uses the
 * org-configured decimal places.
 */
final class MoneyFormatTest extends TestCase
{
    public function test_zero_decimal_currency_drops_cents(): void
    {
        // ¥1,000 is stored as 100000 (major*100).
        $this->assertSame('¥1,000', Money::format(100000, 'JPY'));
    }

    public function test_three_decimal_currency_shows_three_places(): void
    {
        $this->assertSame('BHD10.500', Money::format(1050, 'BHD'));
    }

    public function test_two_decimal_currency_keeps_cents(): void
    {
        $this->assertSame('€10.50', Money::format(1050, 'EUR'));
    }

    public function test_default_currency_uses_org_decimal_places(): void
    {
        $this->assertSame('$10.50', Money::format(1050, 'USD'));
    }
}
