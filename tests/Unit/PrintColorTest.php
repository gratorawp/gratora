<?php

declare(strict_types=1);

namespace Gratora\Tests\Unit;

use Gratora\Campaigns\Styling\Tokens;
use PHPUnit\Framework\TestCase;

/**
 * The brand accent reaches the receipt PDF through a renderer that parses a
 * narrower colour grammar than the settings screen accepts, so a receipt has to
 * decide for itself which of the org's notations it can print rather than
 * dropping every accent that is not plain hex back to the shipped navy.
 */
final class PrintColorTest extends TestCase
{
    private const FALLBACK = '#211d3f';

    /** @return array<string,array{0:string}> */
    public static function printable(): array
    {
        return [
            'six-digit hex'   => ['#0F3D5C'],
            'short hex'       => ['#abc'],
            'hex with alpha'  => ['#0f3d5cff'],
            'short with alpha' => ['#abcd'],
            'rgb'             => ['rgb(15 61 92)'],
            'rgba'            => ['rgba(15, 61, 92, 1)'],
        ];
    }

    /** @dataProvider printable */
    public function test_a_colour_the_renderer_reads_survives(string $value): void
    {
        $this->assertSame($value, Tokens::printColor($value, self::FALLBACK));
    }

    /** @return array<string,array{0:string}> */
    public static function unprintable(): array
    {
        return [
            'hsl short a channel'              => ['hsl(203 60%)'],
            'hsl carrying a brace'             => ['hsl(203 60% 21%)}body{color:red'],
            'transparent, which is no ink'     => ['transparent'],
            'a keyword with nothing to inherit from' => ['currentColor'],
            'inherit'                          => ['inherit'],
            'five-digit hex'                   => ['#0f3d5'],
            'anything carrying a brace'        => ['#fff}body{display:none'],
            'anything carrying a semicolon'    => ['red;position:absolute'],
        ];
    }

    /** @dataProvider unprintable */
    public function test_a_colour_the_renderer_cannot_read_falls_back(string $value): void
    {
        $this->assertSame(self::FALLBACK, Tokens::printColor($value, self::FALLBACK));
    }

    /** @return array<string,array{0:string,1:string}> */
    public static function converted(): array
    {
        return [
            'comma hsl'         => ['hsl(203, 60%, 21%)', '#153d56'],
            'space hsl'         => ['hsl(203 60% 21%)', '#153d56'],
            'hsl yellow'        => ['hsl(45, 90%, 60%)', '#f5c73d'],
            'hsla loses alpha'  => ['hsla(280, 50%, 40%, .5)', '#773399'],
            'hue below zero'    => ['hsl(-30 100% 50%)', '#ff0080'],
            'hue in degrees'    => ['hsl(160deg 60% 80%)', '#adebd6'],
            'hue in turns'      => ['hsl(0.4444turn 60% 80%)', '#adebd6'],
            'hue in radians'    => ['hsl(2.7925rad 60% 80%)', '#adebd6'],
            'hue in gradians'   => ['hsl(177.78grad 60% 80%)', '#adebd6'],
        ];
    }

    /**
     * The accent drawn as text on white paper has to read there. A pale
     * accent gives way to dark ink; one that reads keeps its colour.
     *
     * @return array<string,array{0:string,1:string}>
     */
    public static function inks(): array
    {
        return [
            'pale accent'      => ['#fde68a', '#10162a'],
            'dark accent'      => ['#46277c', '#46277c'],
            'dark hsl accent'  => ['hsl(262deg 52% 32%)', '#46277c'],
            'pale hsl accent'  => ['hsl(160deg 60% 80%)', '#10162a'],
            'unprintable'      => ['currentColor', self::FALLBACK],
        ];
    }

    /** @dataProvider inks */
    public function test_text_in_the_accent_reads_on_paper(string $value, string $expected): void
    {
        $this->assertSame($expected, Tokens::printInk($value, self::FALLBACK));
    }

    /**
     * @dataProvider converted
     */
    public function test_an_hsl_accent_reaches_the_page_as_the_colour_it_is(string $value, string $expected): void
    {
        $this->assertSame($expected, Tokens::printColor($value, self::FALLBACK));
    }

    /**
     * @dataProvider converted
     */
    public function test_a_converted_accent_cannot_break_out_of_the_rule(string $value): void
    {
        $this->assertSame(0, preg_match('/[^#0-9a-f]/', Tokens::printColor($value, self::FALLBACK)));
    }
}
