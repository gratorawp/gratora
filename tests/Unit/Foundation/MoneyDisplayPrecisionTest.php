<?php

declare(strict_types=1);

namespace Dono\Tests\Unit\Foundation;

use Dono\Foundation\Helpers\Money;
use PHPUnit\Framework\TestCase;

/**
 * An org choosing "0 (no cents)" is saying how whole amounts should look. It is
 * not permission to restate an amount: a receipt that says $26 for a donation of
 * $25.50 acknowledges money the donor never gave.
 *
 * Money memoizes the org format for the request, so this class sets the option
 * before its own first call and asserts the memo holds it: on a suite where
 * something else formatted money first, it fails loudly instead of passing on
 * the standard two-decimal setting.
 *
 * @since 1.0.0
 */
final class MoneyDisplayPrecisionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['_dono_test_options']['dono_currency_locale'] = [
            'default_currency' => 'USD',
            'format'           => ['decimal_places' => 0],
        ];
        $this->assertSame(
            0,
            Money::numberFormat()['decimal_places'],
            'Money already memoized another org format; this class has to run before any other Money caller.'
        );
    }

    protected function tearDown(): void
    {
        ($GLOBALS['_dono_reset_options'])();
        parent::tearDown();
    }

    public function test_the_display_setting_cannot_round_away_cents_that_were_charged(): void
    {
        $this->assertSame('$25.50', Money::format(2550));
    }

    public function test_the_display_setting_still_drops_cents_there_are_none_of(): void
    {
        $this->assertSame('$25', Money::format(2500));
    }

    public function test_the_bare_major_number_states_the_amount_it_was_given(): void
    {
        $this->assertSame('25.50', Money::major(2550));
        $this->assertSame('25', Money::major(2500));
    }

    /** The setting is the org's own currency's; every other currency is ISO. */
    public function test_foreign_currencies_keep_their_own_precision(): void
    {
        $this->assertSame('€25.50', Money::format(2550, 'EUR'));
        $this->assertSame('¥1,000', Money::format(100000, 'JPY'));
    }
}
