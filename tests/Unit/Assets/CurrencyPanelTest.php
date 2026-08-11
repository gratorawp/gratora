<?php

declare(strict_types=1);

namespace Dono\Tests\Unit\Assets;

use PHPUnit\Framework\TestCase;

/**
 * Guards the two money-facing parts of the Currency settings panel.
 *
 *  - The exchange-rate field is a free text box. Read with a bare parseFloat,
 *    a European '1,09' is committed as the rate 1.00000000, and every donation
 *    made afterwards is valued one-for-one against the base currency.
 *  - The unconvertible-currency notice is the only place an admin can learn
 *    what offering a rate-less currency does. It must not read as though the
 *    only casualty is reporting: the donation form offers preset amounts at
 *    face value in that currency, so the org collects the preset's number
 *    rather than its worth.
 *
 * @since 1.0.0
 */
final class CurrencyPanelTest extends TestCase
{
    private function source(): string
    {
        $path = dirname(__DIR__, 3) . '/assets/admin/settings/panels/CurrencyPanel.jsx';
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /** The whole body of the rate parser. */
    private function parser(): string
    {
        $matched = preg_match('/export function parseRate\s*\(.*?\n\}/s', $this->source(), $m);
        $this->assertSame(
            1,
            $matched,
            'CurrencyPanel must expose a single parseRate() the rate field reads through.'
        );

        return (string) $m[0];
    }

    public function test_rate_field_is_not_read_with_a_bare_parse_float(): void
    {
        $this->assertDoesNotMatchRegularExpression(
            '/parseFloat\s*\(\s*e\.target\.value/',
            $this->source(),
            "parseFloat('1,09') is 1, and 1 passes a positive-number check, so the wrong rate commits silently."
        );
    }

    public function test_rate_parser_normalises_a_comma_decimal_separator(): void
    {
        $this->assertMatchesRegularExpression(
            "/replace\(\s*',',\s*'\.'\s*\)/",
            $this->parser(),
            'A comma decimal separator must become a period before the rate is read.'
        );
    }

    public function test_rate_parser_refuses_what_it_cannot_read_whole(): void
    {
        $parser = $this->parser();

        $this->assertStringContainsString(
            'return null',
            $parser,
            'An unreadable entry must be refused, not rounded down to whatever the prefix parses to.'
        );
        $this->assertStringContainsString(
            'n > 0',
            $parser,
            'Only a positive rate may commit.'
        );
    }

    public function test_rate_field_commits_only_what_the_parser_accepts(): void
    {
        $this->assertMatchesRegularExpression(
            '/parseRate\(\s*e\.target\.value\s*\)/',
            $this->source(),
            'The field must route the typed value through parseRate() before committing it.'
        );
    }

    public function test_unconvertible_notice_does_not_promise_the_donation_is_taken_in_full(): void
    {
        $this->assertStringNotContainsString(
            'still accepted in full',
            $this->source(),
            'A rate-less currency is not taken in full: presets are offered at face value in it.'
        );
    }

    public function test_unconvertible_notice_names_the_preset_consequence(): void
    {
        $this->assertStringContainsString(
            'face value',
            $this->source(),
            'The notice is the only warning an admin gets before offering a rate-less currency.'
        );
    }
}
