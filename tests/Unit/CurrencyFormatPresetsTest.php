<?php

declare(strict_types=1);

namespace Gratora\Tests\Unit;

use Gratora\Currency\CurrencyFormats;
use PHPUnit\Framework\TestCase;

/**
 * The presets are the only thing that makes picking a currency change how money
 * reads. A code with no entry, or an entry with a separator that collides with
 * its own decimal mark, produces amounts nobody can parse.
 */
final class CurrencyFormatPresetsTest extends TestCase
{
    public function test_every_preset_is_internally_consistent(): void
    {
        foreach (CurrencyFormats::all() as $code => $fmt) {
            $this->assertNotSame(
                $fmt['decimal_sep'],
                $fmt['thousand_sep'],
                "{$code} uses one character for both separators"
            );
            $this->assertContains($fmt['symbol_position'], ['before', 'after'], "{$code} has an unknown symbol position");
            $this->assertGreaterThanOrEqual(0, $fmt['decimal_places'], "{$code} asks for negative places");
            $this->assertLessThanOrEqual(3, $fmt['decimal_places'], "{$code} asks for more places than any currency has");
        }
    }

    public function test_the_currencies_the_form_offers_all_have_a_preset(): void
    {
        // Anything in the symbol table can be picked as a base currency, so
        // anything in it needs a convention or the pick changes nothing.
        $symbols = new \ReflectionClassConstant(\Gratora\Foundation\Helpers\Money::class, 'SYMBOLS');
        $codes   = array_keys($symbols->getValue());
        $presets = CurrencyFormats::all();

        foreach ($codes as $code) {
            $this->assertArrayHasKey($code, $presets, "{$code} can be chosen but has no format preset");
        }
    }

    public function test_the_dollar_currencies_read_as_dollars(): void
    {
        $usd = CurrencyFormats::for('USD');

        $this->assertSame('.', $usd['decimal_sep']);
        $this->assertSame(',', $usd['thousand_sep']);
        $this->assertSame('before', $usd['symbol_position']);
    }

    public function test_the_euro_reads_as_the_euro(): void
    {
        $eur = CurrencyFormats::for('EUR');

        $this->assertSame(',', $eur['decimal_sep']);
        $this->assertSame('.', $eur['thousand_sep']);
        $this->assertSame('after', $eur['symbol_position']);
    }

    public function test_a_zero_decimal_currency_asks_for_no_places(): void
    {
        $this->assertSame(0, CurrencyFormats::for('JPY')['decimal_places']);
    }

    public function test_an_unlisted_currency_falls_back_rather_than_guessing(): void
    {
        $fmt = CurrencyFormats::for('XYZ');

        $this->assertSame('.', $fmt['decimal_sep']);
        $this->assertSame(',', $fmt['thousand_sep']);
        $this->assertSame('before', $fmt['symbol_position']);
    }

    public function test_the_lookup_is_case_and_space_insensitive(): void
    {
        $this->assertSame(CurrencyFormats::for('EUR'), CurrencyFormats::for(' eur '));
    }
}
