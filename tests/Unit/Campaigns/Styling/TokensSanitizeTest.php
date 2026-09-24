<?php

declare(strict_types=1);

namespace Gratora\Tests\Unit\Campaigns\Styling;

use Gratora\Campaigns\Styling\Tokens;
use PHPUnit\Framework\TestCase;

/**
 * A theme.json palette may state a hue in any CSS angle unit, and the Site
 * theme preset carries it into a colour token. What is kept is an hsl() CSS
 * can parse, so nothing that ends a declaration gets through with it, and
 * nothing the browser would drop is kept.
 */
final class TokensSanitizeTest extends TestCase
{
    /** @return array<string,array{0:string}> */
    public static function kept(): array
    {
        return [
            'degrees'       => ['hsl(160deg 60% 80%)'],
            'turns'         => ['hsl(0.4444turn 60% 80%)'],
            'radians'       => ['hsl(2.7925rad 60% 80%)'],
            'gradians'      => ['hsl(177.78grad 60% 80%)'],
            'none'          => ['hsl(none 0% 80%)'],
            'hsla'          => ['hsla(160deg, 60%, 80%, .5)'],
            'plain hsl'     => ['hsl(160, 60%, 80%)'],
            'slash alpha'   => ['hsl(160deg 60% 80% / .5)'],
            'none anywhere' => ['hsl(160 none none / none)'],
            'comma alpha'   => ['hsl(160, 60%, 80%, 50%)'],
            'upper case'    => ['HSL(160DEG 60% 80%)'],
            'rgb'           => ['rgb(15 61 92)'],
        ];
    }

    /** @dataProvider kept */
    public function test_a_colour_in_any_hue_unit_is_kept(string $value): void
    {
        $this->assertSame(['gratora-accent' => $value], Tokens::sanitize(['gratora-accent' => $value]));
    }

    /** @return array<string,array{0:string}> */
    public static function dropped(): array
    {
        return [
            'breaking out of the rule'  => ['hsl(160deg 60% 80%)}body{'],
            'a unit word inside rgb'    => ['rgb(none 0 0)'],
            'a function inside hsl'     => ['hsl(url(x) 1 1)'],
            'a word that is not a unit' => ['hsl(160 60% 80%) red'],
            'expression'                => ['hsl(expression(alert(1)) 1 1)'],
            'a unit apart from its hue' => ['hsl(160 deg 60% 80%)'],
            'a unit and nothing else'   => ['hsl(deg)'],
            'a unit twice'              => ['hsl(160degdeg 60% 80%)'],
            'a unit on the lightness'   => ['hsl(160 60% 80deg)'],
            'a fourth channel'          => ['hsl(160 60% 80% 90%)'],
            'none in the comma form'    => ['hsl(none, 60%, 80%)'],
            'commas and spaces mixed'   => ['hsl(160deg, 60% 80%)'],
            'commas without percents'   => ['hsl(160, 60, 80)'],
            'two alphas'                => ['hsl(160 60% 80% / .5 / .5)'],
        ];
    }

    /** @dataProvider dropped */
    public function test_anything_else_is_dropped(string $value): void
    {
        $this->assertSame([], Tokens::sanitize(['gratora-accent' => $value]));
    }
}
