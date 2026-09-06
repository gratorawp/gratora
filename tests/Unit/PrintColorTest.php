<?php

declare(strict_types=1);

namespace FundKit\Tests\Unit;

use FundKit\Campaigns\Styling\Tokens;
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
            'hsl the parser has no branch for' => ['hsl(203 60% 21%)'],
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
}
