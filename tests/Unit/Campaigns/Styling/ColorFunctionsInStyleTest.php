<?php

declare(strict_types=1);

namespace Gratora\Tests\Unit\Campaigns\Styling;

use Gratora\Campaigns\Styling\CampaignStyleVars;
use PHPUnit\Framework\TestCase;

/**
 * A style attribute keeps a declaration whose only parentheses are colour
 * functions CSS parses. Anything else in them is left for core to refuse.
 */
final class ColorFunctionsInStyleTest extends TestCase
{
    /** @return array<string,array{0:string}> */
    public static function colours(): array
    {
        return [
            'hsl in degrees'  => ['--gratora-accent:hsl(160deg 60% 80%)'],
            'hsl with alpha'  => ['box-shadow:0 4px 14px hsl(160deg 60% 80% / .5)'],
            'hsl with commas' => ['color:hsla(160, 60%, 80%, .5)'],
            'rgb'             => ['box-shadow:0 1px 2px rgba(15, 23, 42, .04)'],
        ];
    }

    /** @dataProvider colours */
    public function test_a_colour_css_parses_is_allowed(string $declaration): void
    {
        $this->assertTrue(CampaignStyleVars::allowColorFunctions(false, $declaration));
    }

    /** @return array<string,array{0:string}> */
    public static function refused(): array
    {
        return [
            'a unit apart from its hue' => ['--gratora-accent:hsl(160 deg 60% 80%)'],
            'a unit and nothing else'   => ['--gratora-accent:hsl(deg)'],
            'a url inside rgb'          => ['box-shadow:0 0 0 rgb(url(x))'],
            'a url inside hsl'          => ['box-shadow:0 0 0 hsl(url(x) 1 1)'],
            'a colour then a brace'     => ['color:hsl(160deg 60% 80%)}body{'],
        ];
    }

    /** @dataProvider refused */
    public function test_anything_else_is_left_to_core(string $declaration): void
    {
        $this->assertFalse(CampaignStyleVars::allowColorFunctions(false, $declaration));
    }
}
