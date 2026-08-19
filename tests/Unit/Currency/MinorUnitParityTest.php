<?php

declare(strict_types=1);

namespace Dono\Tests\Unit\Currency;

use Dono\Currency\Currency;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The browser and the server have to agree how many decimals a currency has.
 *
 * A donor reads one donation in their portal and again on the receipt in their
 * inbox, and the two are rendered by different code: JavaScript there, PHP
 * here. Two lists of the same fact drift, and when they do the same money is
 * printed two ways to the same person.
 *
 * This compares the copy in assets/_shared/money.js against the one PHP charges
 * from, so a currency added to either has to be added to both.
 */
final class MinorUnitParityTest extends TestCase
{
    private const JS = __DIR__ . '/../../../assets/_shared/money.js';

    /** @return array<string,list<string>> */
    private function jsTables(): array
    {
        $this->assertFileExists(self::JS);
        $src = (string) file_get_contents(self::JS);

        $out = [];
        foreach (['ZERO_DECIMAL', 'THREE_DECIMAL'] as $name) {
            $this->assertSame(
                1,
                preg_match('/const ' . $name . ' = \[(.*?)\];/s', $src, $m),
                "assets/_shared/money.js no longer declares {$name}, so this can no longer compare the two"
            );
            preg_match_all("/'([A-Z]{3})'/", $m[1], $codes);
            $out[$name] = $codes[1];
        }

        return $out;
    }

    /** @return list<string> */
    private function phpTable(string $constant): array
    {
        $property = (new ReflectionClass(Currency::class))->getConstant($constant);
        $this->assertIsArray($property, "Currency::{$constant} is gone");

        return array_values($property);
    }

    public function test_the_zero_decimal_currencies_match(): void
    {
        $js  = $this->jsTables()['ZERO_DECIMAL'];
        $php = $this->phpTable('ZERO_DECIMAL');

        sort($js);
        sort($php);

        $this->assertSame(
            $php,
            $js,
            'the portal and the receipt would print different decimals for: '
            . implode(', ', array_merge(array_diff($php, $js), array_diff($js, $php)))
        );
    }

    public function test_the_three_decimal_currencies_match(): void
    {
        $js  = $this->jsTables()['THREE_DECIMAL'];
        $php = $this->phpTable('THREE_DECIMAL');

        sort($js);
        sort($php);

        $this->assertSame($php, $js);
    }

    /**
     * The four the shared UI package disagrees on, named so a future change back
     * to its table is a decision rather than an accident.
     */
    public function test_the_currencies_the_ui_package_disagrees_on_follow_php(): void
    {
        $zero = $this->jsTables()['ZERO_DECIMAL'];

        foreach (['ISK', 'UGX', 'XAG'] as $twoDecimal) {
            $this->assertNotContains($twoDecimal, $zero, "{$twoDecimal} is charged as major times 100");
            $this->assertSame(2, Currency::minorUnits($twoDecimal));
        }

        $this->assertContains('MGA', $zero, 'MGA is charged in whole units');
        $this->assertSame(0, Currency::minorUnits('MGA'));
    }
}
