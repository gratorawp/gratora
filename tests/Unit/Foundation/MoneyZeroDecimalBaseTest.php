<?php

declare(strict_types=1);

namespace Dono\Tests\Unit\Foundation;

use Dono\Foundation\Helpers\Money;
use PHPUnit\Framework\TestCase;

/**
 * An org based in a currency with no minor unit still gets the default "2" from
 * the number-format settings, because two is the sane default for most of the
 * world. Yen has no hundredths, so honouring that literally prints a precision
 * the currency does not have, and prints it on whole amounts only: a receipt
 * would list a run of yen figures where some carry decimals and some do not.
 *
 * Money memoizes the org format and the base currency for the whole process, so
 * this class runs isolated: another class in the same process fixes the memo to
 * its own org and this one would silently assert nothing.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class MoneyZeroDecimalBaseTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['_dono_test_options']['dono_currency_locale'] = [
            'default_currency' => 'JPY',
            'format'           => ['decimal_places' => 2],
        ];
        $this->assertSame('JPY', Money::defaultCurrency(), 'Money memoized another base currency before this class ran.');
    }

    protected function tearDown(): void
    {
        ($GLOBALS['_dono_reset_options'])();
        parent::tearDown();
    }

    public function test_the_base_currency_never_gains_places_it_does_not_have(): void
    {
        $this->assertSame('¥1,000', Money::format(100000, 'JPY'));
        $this->assertSame('¥50', Money::format(5000, 'JPY'));
    }

    public function test_every_yen_figure_on_the_page_carries_the_same_precision(): void
    {
        $this->assertSame('¥1,235', Money::format(123456, 'JPY'));
        $this->assertSame('¥1,000', Money::format(100000, 'JPY'));
    }

    public function test_the_bare_major_number_follows_the_base_currency_too(): void
    {
        $this->assertSame('1,000', Money::major(100000));
    }

    /** A two-decimal currency this org merely accepts keeps its own places. */
    public function test_a_foreign_currency_keeps_its_own_precision(): void
    {
        $this->assertSame('$26.54', Money::format(2654, 'USD'));
        $this->assertSame('$25.00', Money::format(2500, 'USD'));
    }
}
