<?php

declare(strict_types=1);

namespace FundKit\Tests\Unit\Campaigns\Styling;

use FundKit\Campaigns\Styling\Ink;
use PHPUnit\Framework\TestCase;

/**
 * A filled panel reverses its contents out against the campaign's accent, which
 * the campaign chooses. Assuming the accent is dark is what puts white on
 * yellow, so the decision is measured rather than assumed.
 */
final class InkTest extends TestCase
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
        $css = Ink::declarationsFor($accent);

        $this->assertStringContainsString(
            $expectsLight ? '--fundkit-on-accent:#ffffff' : '--fundkit-on-accent:#10162a',
            $css,
            $accent . ' got the wrong ink'
        );
    }

    public function test_every_declaration_is_emitted_together(): void
    {
        $css = Ink::declarationsFor('#211d3f');

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
        $this->assertSame('', Ink::declarationsFor($accent));
    }

    /** @return array<string,array{0:string}> */
    public function unreadable(): array
    {
        return [
            'empty'       => [''],
            'keyword'     => ['transparent'],
            'currentcolor'=> ['currentColor'],
            'nonsense'    => ['not-a-colour'],
            'short rgb'   => ['rgb(10, 20)'],
        ];
    }

    /**
     * The amount tiles, the order summary and the secondary buttons sit on the
     * soft ground, not on the page. The page ink is chosen against
     * --fundkit-bg and knows nothing about this one.
     */
    public function test_the_soft_ground_gets_ink_of_its_own(): void
    {
        $css = Ink::softDeclarations(['fundkit-bg-soft' => '#101828', 'fundkit-accent' => '#452ef5']);

        $this->assertStringContainsString('--fundkit-on-soft:#ffffff;', $css);
        $this->assertStringContainsString('--fundkit-on-soft-muted:rgba(255,255,255,.72);', $css);
    }

    public function test_a_pale_soft_ground_gets_dark_ink(): void
    {
        $this->assertStringContainsString(
            '--fundkit-on-soft:#10162a;',
            Ink::softDeclarations(['fundkit-bg-soft' => '#f8fafb', 'fundkit-accent' => '#211d3f'])
        );
    }

    /**
     * The total is drawn in the accent. It reads on the shipped near-white
     * ground and can vanish on a chosen one, so it stands down where it does
     * not carry and is left alone where it does.
     */
    public function test_the_accent_keeps_the_total_where_it_reads(): void
    {
        $this->assertStringContainsString(
            '--fundkit-on-soft-accent:#211d3f;',
            Ink::softDeclarations(['fundkit-bg-soft' => '#f8fafb', 'fundkit-accent' => '#211d3f'])
        );
    }

    public function test_the_accent_stands_down_where_it_does_not(): void
    {
        // Violet on this blue measures 2.5:1, so the total would be a smudge.
        $this->assertStringContainsString(
            '--fundkit-on-soft-accent:#10162a;',
            Ink::softDeclarations(['fundkit-bg-soft' => '#05a2f0', 'fundkit-accent' => '#452ef5'])
        );
    }

    public function test_an_unreadable_soft_ground_leaves_the_stylesheet_its_fallback(): void
    {
        $this->assertSame('', Ink::softDeclarations(['fundkit-bg-soft' => 'var(--wp--preset--color--x)']));
    }

    /**
     * The colour control stores hsl() as readily as hex. A ground nothing can
     * read leaves every derived ink at its stylesheet fallback, which is the
     * white-on-pale the measuring exists to prevent.
     *
     * @return array<string,array{0:string,1:bool}>
     */
    public function hslGrounds(): array
    {
        return [
            'hsl black'       => ['hsl(0, 0%, 0%)', true],
            'hsl white'       => ['hsl(0, 0%, 100%)', false],
            'hsl brand navy'  => ['hsl(249, 37%, 18%)', true],
            'hsl yellow'      => ['hsl(50, 100%, 50%)', false],
            'hsl space units' => ['hsl(210deg 40% 20%)', true],
            'hsla with alpha' => ['hsla(50, 100%, 50%, 0.9)', false],
        ];
    }

    /** @dataProvider hslGrounds */
    public function test_an_hsl_ground_is_measured_like_any_other(string $ground, bool $expectsLight): void
    {
        $this->assertStringContainsString(
            $expectsLight ? '--fundkit-on-accent:#ffffff;' : '--fundkit-on-accent:#10162a;',
            Ink::declarationsFor($ground)
        );
    }

    public function test_an_hsl_that_is_not_a_colour_still_yields_nothing(): void
    {
        $this->assertSame('', Ink::declarationsFor('hsl(no, thanks)'));
        $this->assertSame('', Ink::declarationsFor('hsl(10)'));
    }

    /** @return array<string,array{0:string,1:string}> */
    public function hexes(): array
    {
        return [
            'hsl'            => ['hsl(203 60% 21%)', '#153d56'],
            'hsla'           => ['hsla(280, 50%, 40%, .5)', '#773399'],
            'rgb'            => ['rgb(20, 66, 95)', '#14425f'],
            'shorthand hex'  => ['#fff', '#ffffff'],
            'black'          => ['hsl(0 0% 0%)', '#000000'],
        ];
    }

    /** @dataProvider hexes */
    public function test_a_readable_colour_yields_six_digits(string $value, string $expected): void
    {
        $this->assertSame($expected, Ink::hex($value));
    }

    public function test_a_channel_past_the_range_still_yields_six_digits(): void
    {
        $this->assertSame('#ff0000', Ink::hex('rgb(300, -20, 0)'));
    }

    public function test_a_colour_it_cannot_read_yields_nothing(): void
    {
        $this->assertNull(Ink::hex('transparent'));
        $this->assertNull(Ink::hex('currentColor'));
        $this->assertNull(Ink::hex('#fff}body{display:none'));
    }
}
