<?php

declare(strict_types=1);

namespace FundKit\Tests\Unit\Campaigns\Styling;

use FundKit\Campaigns\Styling\AccentInk;
use PHPUnit\Framework\TestCase;

/**
 * A filled panel reverses its contents out against the campaign's accent, which
 * the campaign chooses. Assuming the accent is dark is what puts white on
 * yellow, so the decision is measured rather than assumed.
 */
final class AccentInkTest extends TestCase
{
    /** @return array<string,array{0:string,1:bool}> accent, expects light ink */
    public function accents(): array
    {
        return [
            'near black'        => ['#000000', true],
            'brand navy'        => ['#211d3f', true],
            'the panel navy'    => ['#14425f', true],
            'mid blue'          => ['#2563eb', true],
            'white'             => ['#ffffff', false],
            'yellow'            => ['#ffe066', false],
            'pale mint'         => ['#d6f5e3', false],
            'shorthand hex'     => ['#fff',    false],
            'hex with alpha'    => ['#000000ff', true],
            'rgb notation'      => ['rgb(20, 66, 95)', true],
            'rgb with spaces'   => ['rgba(255, 224, 102, 0.9)', false],
        ];
    }

    /** @dataProvider accents */
    public function test_ink_is_chosen_against_the_accent(string $accent, bool $expectsLight): void
    {
        $css = AccentInk::declarationsFor($accent);

        $this->assertStringContainsString(
            $expectsLight ? '--fundkit-on-accent:#ffffff' : '--fundkit-on-accent:#10162a',
            $css,
            $accent . ' got the wrong ink'
        );
    }

    public function test_every_declaration_is_emitted_together(): void
    {
        $css = AccentInk::declarationsFor('#211d3f');

        $this->assertStringContainsString('--fundkit-on-accent:', $css);
        $this->assertStringContainsString('--fundkit-on-accent-muted:', $css);
        $this->assertStringContainsString('--fundkit-on-accent-line:', $css);
    }

    /**
     * Nothing beats a wrong guess: with no declaration the stylesheet's own
     * fallback stands, which is the reversed-out look these panels already had.
     *
     * @dataProvider unreadable
     */
    public function test_an_unreadable_accent_yields_nothing(string $accent): void
    {
        $this->assertSame('', AccentInk::declarationsFor($accent));
    }

    /** @return array<string,array{0:string}> */
    public function unreadable(): array
    {
        return [
            'empty'       => [''],
            'keyword'     => ['transparent'],
            'currentcolor'=> ['currentColor'],
            'hsl'         => ['hsl(210, 60%, 20%)'],
            'nonsense'    => ['not-a-colour'],
            'short rgb'   => ['rgb(10, 20)'],
        ];
    }
}
