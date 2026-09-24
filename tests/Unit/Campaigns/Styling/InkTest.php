<?php

declare(strict_types=1);

namespace Gratora\Tests\Unit\Campaigns\Styling;

use Gratora\Campaigns\Styling\Ink;
use Gratora\Campaigns\Styling\Tokens;
use PHPUnit\Framework\TestCase;

/**
 * A filled panel reverses its contents out against the campaign's accent, which
 * the campaign chooses. Assuming the accent is dark is what puts white on
 * yellow, so the decision is measured rather than assumed.
 */
final class InkTest extends TestCase
{
    private const SHIPPED = [
        'gratora-accent'      => '#211d3f',
        'gratora-accent-soft' => '#efedf8',
        'gratora-text'        => '#111827',
        'gratora-text-muted'  => '#6b7280',
        'gratora-bg'          => '#ffffff',
        'gratora-field-bg'    => '#ffffff',
        'gratora-bg-soft'     => '#f8fafb',
    ];

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
            'translucent navy'  => ['rgba(33, 29, 63, 0.12)', false],
            'faint black hex'   => ['#0000001a', false],
        ];
    }

    /** @dataProvider accents */
    public function test_ink_is_chosen_against_the_accent(string $accent, bool $expectsLight): void
    {
        $css = Ink::declarationsFor($accent);

        $this->assertStringContainsString(
            $expectsLight ? '--gratora-on-accent:#ffffff' : '--gratora-on-accent:#10162a',
            $css,
            $accent . ' got the wrong ink'
        );
    }

    public function test_every_declaration_is_emitted_together(): void
    {
        $css = Ink::declarationsFor('#211d3f');

        $this->assertStringContainsString('--gratora-on-accent:', $css);
        $this->assertStringContainsString('--gratora-on-accent-muted:', $css);
        $this->assertStringContainsString('--gratora-on-accent-line:', $css);
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
     * --gratora-bg and knows nothing about this one.
     */
    public function test_the_soft_ground_gets_ink_of_its_own(): void
    {
        $css = Ink::softDeclarations(['gratora-bg-soft' => '#101828', 'gratora-accent' => '#452ef5']);

        $this->assertStringContainsString('--gratora-on-soft:#ffffff;', $css);
        $this->assertStringContainsString('--gratora-on-soft-muted:rgba(255,255,255,.72);', $css);
    }

    public function test_a_pale_soft_ground_gets_dark_ink(): void
    {
        $this->assertStringContainsString(
            '--gratora-on-soft:#10162a;',
            Ink::softDeclarations(['gratora-bg-soft' => '#f8fafb', 'gratora-accent' => '#211d3f'])
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
            '--gratora-on-soft-accent:#211d3f;',
            Ink::softDeclarations(['gratora-bg-soft' => '#f8fafb', 'gratora-accent' => '#211d3f'])
        );
    }

    public function test_the_accent_stands_down_where_it_does_not(): void
    {
        // Violet on this blue measures 2.5:1, so the total would be a smudge.
        $this->assertStringContainsString(
            '--gratora-on-soft-accent:#10162a;',
            Ink::softDeclarations(['gratora-bg-soft' => '#05a2f0', 'gratora-accent' => '#452ef5'])
        );
    }

    public function test_an_unreadable_soft_ground_leaves_the_stylesheet_its_fallback(): void
    {
        $this->assertSame('', Ink::softDeclarations(['gratora-bg-soft' => 'var(--wp--preset--color--x)']));
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
            $expectsLight ? '--gratora-on-accent:#ffffff;' : '--gratora-on-accent:#10162a;',
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
            'hsla'           => ['hsla(280, 50%, 40%, .5)', '#bb99cc'],
            'faint hex'      => ['#10162a26', '#dbdcdf'],
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

    /**
     * A theme.json palette states its colours in whatever CSS accepts, and a
     * hue in turns, radians or gradians is the same colour as one in degrees.
     *
     * @return array<string,array{0:string,1:string}>
     */
    public function hueUnits(): array
    {
        return [
            'turn'      => ['hsl(0.4444turn 60% 80%)', '#adebd6'],
            'rad'       => ['hsl(2.7925rad 60% 80%)', '#adebd6'],
            'grad'      => ['hsl(177.78grad 60% 80%)', '#adebd6'],
            'deg'       => ['hsl(160deg 60% 80%)', '#adebd6'],
            'upper'     => ['HSL(160DEG 60% 80%)', '#adebd6'],
            'none hue'  => ['hsl(none 0% 80%)', '#cccccc'],
        ];
    }

    /** @dataProvider hueUnits */
    public function test_a_hue_in_any_unit_is_the_colour_it_names(string $value, string $expected): void
    {
        $this->assertSame($expected, Ink::hex($value));
    }

    /** A translucent ink is what it paints over the ground, not the colour it is mixed from. */
    public function test_a_translucent_ink_is_measured_as_it_paints(): void
    {
        $this->assertFalse(Ink::carries('rgba(16,22,42,.15)', '#ffffff'));
        $this->assertFalse(Ink::carries('#10162a26', '#ffffff'));
        $this->assertTrue(Ink::carries('rgba(16,22,42,.9)', '#ffffff'));
        $this->assertFalse(Ink::carries('rgba(255,255,255,.2)', '#15142b'));
    }

    public function test_a_faint_accent_is_not_page_text_or_card_text(): void
    {
        $css = Ink::groundDeclarations(['gratora-accent' => 'rgba(16,22,42,.15)'] + array_diff_key(self::SHIPPED, ['gratora-accent-soft' => 1]));

        $this->assertStringContainsString('--gratora-text-accent:var(--gratora-text);', $css);
        $this->assertStringContainsString('--gratora-on-bg-accent:var(--gratora-on-bg);', $css);
    }

    /** color-mix() over an opaque ground paints the translucent colour over that ground first. */
    public function test_a_translucent_colour_mixes_as_it_lies_on_the_other(): void
    {
        $this->assertSame(
            Ink::mix(Ink::mix('#fde68a', '#15142b', .5) ?? '', '#15142b', .12),
            Ink::mix('rgba(253,230,138,.5)', '#15142b', .12)
        );
    }

    /** @return array<string,array{0:string}> */
    public static function malformedHsl(): array
    {
        return [
            'a unit apart from its hue' => ['hsl(160 deg 60% 80%)'],
            'a unit and nothing else'   => ['hsl(deg)'],
            'a fourth channel'          => ['hsl(160 60% 80% 90%)'],
            'none in the comma form'    => ['hsl(none, 60%, 80%)'],
            'commas and spaces mixed'   => ['hsl(160deg, 60% 80%)'],
        ];
    }

    /** @dataProvider malformedHsl */
    public function test_an_hsl_css_cannot_parse_is_no_colour(string $value): void
    {
        $this->assertNull(Ink::hex($value));
        $this->assertNull(Ink::on($value));
    }

    public function test_a_unit_on_a_percentage_is_not_a_colour(): void
    {
        $this->assertNull(Ink::hex('hsl(160 60deg 80%)'));
        $this->assertNull(Ink::hex('hsl(160turnx 60% 80%)'));
    }

    /**
     * White and the dark ink reach the same contrast where (L + .05)^2 equals
     * 1.05 times the dark ink's L + .05, about .198, not at black's .179.
     * Between the two white reads better, and on the first three only white
     * reaches 4.5:1.
     *
     * @return array<string,array{0:string}>
     */
    public function whiteReadsBetter(): array
    {
        return [
            'blue'      => ['#0072f0'],
            'bluer'     => ['#006ffa'],
            'grey'      => ['#767676'],
            'mid grey'  => ['#777777'],
            'mid red'   => ['#ed1212'],
        ];
    }

    /** @dataProvider whiteReadsBetter */
    public function test_a_ground_takes_white_where_white_reads_better(string $ground): void
    {
        $this->assertSame('#ffffff', Ink::on($ground)[0] ?? null);
        $this->assertStringContainsString('--gratora-on-accent:#ffffff;', Ink::declarationsFor($ground));
    }

    public function test_white_reaches_the_bar_on_the_grounds_the_dark_ink_misses(): void
    {
        foreach (['#0072f0', '#006ffa', '#767676'] as $ground) {
            $this->assertTrue(Ink::carries('#ffffff', $ground), $ground);
            $this->assertFalse(Ink::carries('#10162a', $ground), $ground);
        }
    }

    public function test_the_ink_reads_at_least_as_well_as_the_other_on_every_grey(): void
    {
        for ($v = 0; $v <= 255; $v++) {
            $ground = sprintf('#%1$02x%1$02x%1$02x', $v);
            $ink    = Ink::on($ground)[0] ?? '';
            $other  = $ink === '#ffffff' ? '#10162a' : '#ffffff';

            // Whichever the other carries, the chosen ink carries too.
            if (Ink::carries($other, $ground)) {
                $this->assertTrue(Ink::carries($ink, $ground), $ground . ' took ' . $ink);
            }
        }

        $this->assertSame('#10162a', Ink::on('#7b7b7b')[0] ?? null);
        $this->assertSame('#10162a', Ink::on('#f55151')[0] ?? null);
    }

    /**
     * Muted ink is the ink at the lowest alpha, from the shipped one up, that
     * still reads on the ground. A fixed alpha tuned against white fell under
     * 4.5:1 on a mid ground while the full ink passed.
     *
     * @return array<string,array{0:string,1:string}>
     */
    public function mutedGrounds(): array
    {
        return [
            'white'          => ['#ffffff', 'rgba(16,22,42,.62)'],
            'QA card'        => ['#15142b', 'rgba(255,255,255,.72)'],
            'QA soft'        => ['#221f3d', 'rgba(255,255,255,.72)'],
            'pale yellow'    => ['#fde68a', 'rgba(16,22,42,.62)'],
            'coral'          => ['#f55151', 'rgba(16,22,42,.86)'],
            'violet'         => ['#452ef5', 'rgba(255,255,255,.74)'],
            'mid blue'       => ['#2563eb', 'rgba(255,255,255,.91)'],
            'mid red'        => ['#ed1212', '#ffffff'],
            'mid grey'       => ['#777777', '#ffffff'],
        ];
    }

    /** @dataProvider mutedGrounds */
    public function test_muted_ink_is_measured_on_its_ground(string $ground, string $expected): void
    {
        $this->assertSame($expected, Ink::on($ground)[1] ?? null);
    }

    public function test_muted_ink_reads_on_every_grey(): void
    {
        for ($v = 0; $v <= 255; $v++) {
            $ground = sprintf('#%1$02x%1$02x%1$02x', $v);
            [$ink, $muted] = Ink::on($ground) ?? ['', ''];

            if ($muted === $ink) {
                $this->assertFalse(Ink::carries(Ink::mix($ink, $ground, .99) ?? '', $ground), $ground);
                continue;
            }

            $this->assertSame(1, preg_match('/^rgba\(\d+,\d+,\d+,\.(\d+)\)$/', $muted, $m), $ground . ' spelled ' . $muted);
            $alpha = (float) ('0.' . $m[1]);

            $this->assertTrue(Ink::carries(Ink::mix($ink, $ground, $alpha) ?? '', $ground), $ground . ' muted ' . $muted);
            if ($alpha > ($ink === '#ffffff' ? .72 : .62)) {
                $this->assertFalse(
                    Ink::carries(Ink::mix($ink, $ground, round($alpha - .01, 2)) ?? '', $ground),
                    $ground . ' takes more ink than it needs'
                );
            }
        }
    }

    public function test_whether_ink_carries_on_a_ground_is_asked_directly(): void
    {
        $this->assertTrue(Ink::carries('#111827', '#ffffff'));
        $this->assertFalse(Ink::carries('#fde68a', '#ffffff'));
        $this->assertFalse(Ink::carries('inherit', '#ffffff'));
    }

    public function test_a_mix_is_what_color_mix_paints(): void
    {
        $this->assertSame('#312d36', Ink::mix('#fde68a', '#15142b', .12));
        $this->assertSame('#fffdeb', Ink::mix('#ffee58', '#ffffff', .12));
        $this->assertSame('#794057', Ink::mix('#452ef5', '#804242', .12));
        $this->assertNull(Ink::mix('inherit', '#ffffff', .12));
    }

    /**
     * @param array<string,string> $tokens
     * @return array<string,string>
     */
    private function grounds(array $tokens): array
    {
        preg_match_all('/(--[a-z-]+):([^;]*);/', Ink::groundDeclarations($tokens), $m, PREG_SET_ORDER);

        $out = [];
        foreach ($m as $decl) {
            $out[$decl[1]] = $decl[2];
        }

        return $out;
    }

    /**
     * QA Dark Pale: a navy card under a pale accent. The page keeps the ink it
     * was chosen for; the card, the tint and the accent as text are measured.
     */
    public function test_each_ground_gets_ink_that_reads_on_it(): void
    {
        $qa = [
            'gratora-accent'     => '#fde68a',
            'gratora-text'       => '#111827',
            'gratora-text-muted' => '#6b7280',
            'gratora-bg'         => '#15142b',
            'gratora-bg-soft'    => '#221f3d',
        ];

        $this->assertSame([
            '--gratora-text-accent'    => 'var(--gratora-text)',
            '--gratora-on-bg'          => '#ffffff',
            '--gratora-on-bg-muted'    => 'rgba(255,255,255,.72)',
            '--gratora-on-bg-accent'   => 'var(--gratora-accent)',
            '--gratora-on-accent-soft' => 'var(--gratora-accent)',
        ], $this->grounds($qa));
    }

    public function test_the_shipped_brand_names_only_what_the_page_already_paints(): void
    {
        $expected = [
            '--gratora-text-accent'    => 'var(--gratora-accent)',
            '--gratora-on-bg'          => 'var(--gratora-text)',
            '--gratora-on-bg-muted'    => 'var(--gratora-text-muted)',
            '--gratora-on-bg-accent'   => 'var(--gratora-accent)',
            '--gratora-on-accent-soft' => 'var(--gratora-accent)',
        ];

        $this->assertSame($expected, $this->grounds(self::SHIPPED));
        $this->assertSame($expected, $this->grounds(Tokens::defaults()));
    }

    public function test_chosen_ink_stays_on_a_card_it_reads_on(): void
    {
        $out = $this->grounds(['gratora-bg' => '#f55151'] + self::SHIPPED);

        $this->assertSame('var(--gratora-text)', $out['--gratora-on-bg']);
        $this->assertSame('rgba(16,22,42,.86)', $out['--gratora-on-bg-muted']);
    }

    public function test_a_pale_accent_on_its_own_tint_takes_measured_ink(): void
    {
        $site = ['gratora-accent' => '#ffee58'] + self::SHIPPED;
        unset($site['gratora-accent-soft']);

        $this->assertSame('#10162a', $this->grounds($site)['--gratora-on-accent-soft']);
    }

    public function test_a_mid_accent_on_a_tinted_card_takes_white(): void
    {
        $classic = ['gratora-accent' => '#452ef5', 'gratora-bg' => '#804242'] + self::SHIPPED;
        unset($classic['gratora-accent-soft']);

        $this->assertSame('#ffffff', $this->grounds($classic)['--gratora-on-accent-soft']);
    }

    public function test_a_tint_the_map_states_is_the_one_measured(): void
    {
        $out = $this->grounds(['gratora-accent' => '#ffee58', 'gratora-accent-soft' => '#211d3f'] + self::SHIPPED);

        $this->assertSame('var(--gratora-accent)', $out['--gratora-on-accent-soft']);
    }

    public function test_the_accent_as_page_text_is_measured_against_the_ground_the_page_ink_reads_on(): void
    {
        $this->assertSame(
            'var(--gratora-accent)',
            $this->grounds(['gratora-text' => '#ffffff', 'gratora-accent' => '#fde68a'] + self::SHIPPED)['--gratora-text-accent']
        );
        $this->assertSame(
            'var(--gratora-text)',
            $this->grounds(['gratora-accent' => '#fde68a'] + self::SHIPPED)['--gratora-text-accent']
        );
    }

    /** A grid card paints the page's card under its own campaign's accent. */
    public function test_a_card_accent_is_measured_on_the_card_it_sits_on(): void
    {
        $this->assertSame(
            '--gratora-on-bg-accent:var(--gratora-on-bg);--gratora-on-accent-soft:#ffffff;',
            Ink::cardAccentDeclarations('#0f3d5c', '#15142b')
        );
        $this->assertSame(
            '--gratora-on-bg-accent:var(--gratora-on-bg);--gratora-on-accent-soft:#10162a;',
            Ink::cardAccentDeclarations('#fde68a', '#ffffff')
        );
        $this->assertSame(
            '--gratora-on-bg-accent:var(--gratora-accent);--gratora-on-accent-soft:var(--gratora-accent);',
            Ink::cardAccentDeclarations('#fde68a', '#15142b')
        );
    }

    /**
     * @return array<string,string>
     */
    private function markers(array $tokens): array
    {
        preg_match_all('/(--[a-z-]+):([^;]*);/', Ink::requiredDeclarations($tokens), $m, PREG_SET_ORDER);

        $out = [];
        foreach ($m as $decl) {
            $out[$decl[1]] = $decl[2];
        }

        return $out;
    }

    /** Where the pink mixed toward the ink already reads, it is the colour the stylesheet paints. */
    public function test_the_required_marker_keeps_its_mix_where_it_reads(): void
    {
        $qa = ['gratora-bg' => '#15142b', 'gratora-accent' => '#fde68a'] + self::SHIPPED;

        $this->assertSame(
            ['--gratora-text-required' => '#9f2b6a', '--gratora-on-bg-required' => '#e16ca6'],
            $this->markers($qa)
        );
        $this->assertSame(
            ['--gratora-text-required' => '#9f2b6a', '--gratora-on-bg-required' => '#9f2b6a'],
            $this->markers(self::SHIPPED)
        );
    }

    /** Bold's red card: the same mix reads 2.04:1 there, so more of the ink is taken. */
    public function test_the_required_marker_takes_more_ink_on_a_card_that_defeats_the_mix(): void
    {
        $bold = $this->markers(['gratora-bg' => '#f55151'] + self::SHIPPED);

        $this->assertSame('#321d37', $bold['--gratora-on-bg-required']);
        $this->assertTrue(Ink::carries($bold['--gratora-on-bg-required'], '#f55151'));
        $this->assertFalse(Ink::carries(Ink::mix('#d63384', '#111827', .18) ?? '', '#f55151'), 'takes more ink than it needs');
    }

    public function test_the_required_marker_reads_on_every_grey_card(): void
    {
        for ($v = 0; $v <= 255; $v++) {
            $card   = sprintf('#%1$02x%1$02x%1$02x', $v);
            $ink    = Ink::carries('#111827', $card) ? '#111827' : (Ink::on($card)[0] ?? '');
            $marker = $this->markers(['gratora-bg' => $card] + self::SHIPPED)['--gratora-on-bg-required'] ?? '';

            $this->assertTrue(Ink::carries($marker, $card) || $marker === $ink, $card . ' marker ' . $marker);
        }
    }

    public function test_a_ground_it_cannot_read_leaves_the_stylesheet_its_mix(): void
    {
        $this->assertSame('', Ink::requiredDeclarations(['gratora-text' => 'inherit', 'gratora-bg' => 'transparent']));
        $this->assertSame(
            ['--gratora-on-bg-required' => '#e16ca6'],
            $this->markers(['gratora-text' => 'inherit', 'gratora-bg' => '#15142b'])
        );
    }

    /**
     * @return array<string,string>
     */
    private function dangers(array $tokens): array
    {
        preg_match_all('/(--[a-z-]+):([^;]*);/', Ink::dangerDeclarations($tokens), $m, PREG_SET_ORDER);

        $out = [];
        foreach ($m as $decl) {
            $out[$decl[1]] = $decl[2];
        }

        return $out;
    }

    /** The red stands wherever it reads, and on a dark card it moves toward the white ink until it does. */
    public function test_danger_ink_keeps_the_red_where_it_reads(): void
    {
        $this->assertSame(
            ['--gratora-text-danger' => '#b91c1c', '--gratora-on-bg-danger' => '#b91c1c', '--gratora-on-soft-danger' => '#b91c1c'],
            $this->dangers(self::SHIPPED)
        );

        $qa = $this->dangers(['gratora-bg' => '#15142b', 'gratora-bg-soft' => '#221f3d', 'gratora-accent' => '#fde68a'] + self::SHIPPED);
        $this->assertSame(
            ['--gratora-text-danger' => '#b91c1c', '--gratora-on-bg-danger' => '#cd5c5c', '--gratora-on-soft-danger' => '#d26b6b'],
            $qa
        );
        $this->assertTrue(Ink::carries($qa['--gratora-on-bg-danger'], '#15142b'));
        $this->assertFalse(Ink::carries(Ink::mix('#b91c1c', '#ffffff', .73) ?? '', '#15142b'), 'takes more ink than it needs');
    }

    /** No red reads on Bold's red card, so the ink it moves toward is most of it. */
    public function test_danger_ink_on_a_red_card_is_nearly_the_card_ink(): void
    {
        $bold = $this->dangers(['gratora-bg' => '#f55151'] + self::SHIPPED);

        $this->assertSame('#3e1924', $bold['--gratora-on-bg-danger']);
        $this->assertTrue(Ink::carries($bold['--gratora-on-bg-danger'], '#f55151'));
    }

    public function test_danger_ink_reads_on_every_grey(): void
    {
        for ($v = 0; $v <= 255; $v++) {
            $grey  = sprintf('#%1$02x%1$02x%1$02x', $v);
            $inks  = $this->dangers(['gratora-bg' => $grey, 'gratora-bg-soft' => $grey] + self::SHIPPED);
            $card  = Ink::carries('#111827', $grey) ? '#111827' : (Ink::on($grey)[0] ?? '');
            $soft  = Ink::on($grey)[0] ?? '';

            $this->assertTrue(Ink::carries($inks['--gratora-on-bg-danger'], $grey) || $inks['--gratora-on-bg-danger'] === $card, $grey . ' card ' . $inks['--gratora-on-bg-danger']);
            $this->assertTrue(Ink::carries($inks['--gratora-on-soft-danger'], $grey) || $inks['--gratora-on-soft-danger'] === $soft, $grey . ' soft ' . $inks['--gratora-on-soft-danger']);
        }
    }

    public function test_a_ground_it_cannot_read_leaves_the_stylesheet_its_red(): void
    {
        $this->assertSame('', Ink::dangerDeclarations(['gratora-text' => 'inherit', 'gratora-bg' => 'transparent', 'gratora-bg-soft' => 'transparent']));
    }

    /**
     * Avatars and tags draw the accent darkened on the tint the campaign page
     * mixes into white. A pale accent leaves that at 2.03:1.
     */
    public function test_ink_on_the_page_tint_keeps_the_darkened_accent_where_it_reads(): void
    {
        $this->assertSame('--gratora-on-page-tint:#1a1731;', Ink::pageTintDeclarations(self::SHIPPED));
        $this->assertSame(
            '--gratora-on-page-tint:#10162a;',
            Ink::pageTintDeclarations(['gratora-accent' => '#fde68a', 'gratora-bg' => '#15142b'] + array_diff_key(self::SHIPPED, ['gratora-accent-soft' => 1]))
        );
    }

    public function test_a_tint_the_map_states_is_the_one_the_page_tint_ink_is_measured_on(): void
    {
        $this->assertSame(
            '--gratora-on-page-tint:#c5b36c;',
            Ink::pageTintDeclarations(['gratora-accent' => '#fde68a', 'gratora-accent-soft' => '#211d3f'])
        );
    }

    public function test_a_page_tint_it_cannot_read_leaves_the_stylesheet_its_fallback(): void
    {
        $this->assertSame('', Ink::pageTintDeclarations(['gratora-accent' => 'inherit']));
        $this->assertSame('', Ink::pageTintDeclarations(['gratora-accent' => '#fde68a', 'gratora-accent-soft' => 'transparent']));
    }
}
