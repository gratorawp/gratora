<?php

declare(strict_types=1);

namespace Dono\Tests\Unit\Assets;

use PHPUnit\Framework\TestCase;

/**
 * The public form's amount box must take its decimal count from the currency
 * the donor is giving in, never from the org's "Decimal places" display
 * setting. A currency-blind input set to zero decimals strips the separator
 * out of a typed "25.50" and submits 2550 major units, so a USD donation on a
 * JPY-based org would be charged a hundred times over.
 *
 * @since 1.0.0
 */
final class DonationFormAmountInputTest extends TestCase
{
    private function source(): string
    {
        $path = dirname(__DIR__, 3) . '/assets/donation-form/components/AmountInput.jsx';
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /** The single expression that decides how many decimals the box accepts. */
    private function decimalsExpression(): string
    {
        $matched = preg_match('/const\s+dp\s*=(.*?);/s', $this->source(), $m);
        $this->assertSame(1, $matched, 'AmountInput no longer derives a single `dp` value.');

        return trim((string) $m[1]);
    }

    public function test_decimal_count_is_derived_from_the_currency(): void
    {
        $this->assertStringContainsString(
            'isZeroDecimal',
            $this->decimalsExpression(),
            'The amount box must accept cents unless the selected currency is zero-decimal.'
        );

        $this->assertMatchesRegularExpression(
            "/import\s*\{[^}]*\bisZeroDecimal\b[^}]*\}\s*from\s*'\.\.\/util\/fx'/",
            $this->source(),
            "isZeroDecimal must be imported from the form's fx util."
        );
    }

    public function test_org_number_format_does_not_govern_decimal_count(): void
    {
        $this->assertStringNotContainsString(
            'fmt.decimalPlaces',
            $this->decimalsExpression(),
            'The org number format governs separators and symbol position only.'
        );
    }

    public function test_zero_decimal_helper_exists(): void
    {
        $path = dirname(__DIR__, 3) . '/assets/donation-form/util/fx.js';
        $this->assertFileExists($path);

        $this->assertStringContainsString(
            'export function isZeroDecimal(',
            (string) file_get_contents($path),
            'The amount box relies on this helper to decide whether cents are typable.'
        );
    }
}
