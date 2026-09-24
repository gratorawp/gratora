<?php

declare(strict_types=1);

namespace Gratora\Campaigns\Styling;

/**
 * Ink for anything drawn on a ground the org chose.
 *
 * A filled surface cannot pick its own contrast: the colour is the org's to
 * choose, and reversing everything out in white assumes it is dark. A campaign
 * on yellow gets white on yellow. Measuring the ground instead means one rule
 * covers both, and what sits on a surface reads these rather than the page ink,
 * which is chosen against the page background and knows nothing about a panel.
 *
 * Hex, rgb() and hsl() are what a colour reaches this in: the control stores the
 * first two and a theme.json palette can state the third. A keyword yields
 * nothing, and the stylesheet's own fallback stands. A translucent ground is
 * read as it lands on white, the page the shipped ink is chosen for and the
 * paper a receipt prints on; translucent ink is read over the ground it is on.
 *
 * @since 1.0.0
 */
final class Ink
{
    private const ON_DARK  = ['#ffffff', 'rgba(255,255,255,.26)'];
    private const ON_LIGHT = ['#10162a', 'rgba(16,22,42,.16)'];

    /** The alpha muted ink starts from, in hundredths. */
    private const MUTED_FROM = ['#ffffff' => 72, '#10162a' => 62];

    /** The required marker's colour, as --gratora-required ships it. */
    private const REQUIRED = '#d63384';

    /** The share of it the stylesheet mixes toward the ink, in hundredths. */
    private const REQUIRED_FROM = 72;

    /** The danger red the portal ships. */
    private const DANGER = '#b91c1c';

    private const NUMBER = '[+-]?(?:\d+\.?\d*|\.\d+)';

    private const ANGLE = self::NUMBER . '(?:deg|grad|rad|turn)?';

    /**
     * An hsl() CSS parses, as a pattern to match without regard to case: the
     * space form, where any slot may be none and an alpha may follow a slash,
     * or the comma form with percentages. Nothing else reads as hsl() here.
     *
     * @since 1.0.0
     */
    public const HSL = 'hsla?\(\s*(?:'
        . '(?:' . self::ANGLE . '|none)\s+(?:' . self::NUMBER . '%|none)\s+(?:' . self::NUMBER . '%|none)'
        . '(?:\s*\/\s*(?:' . self::NUMBER . '%?|none))?'
        . '|' . self::ANGLE . '\s*,\s*' . self::NUMBER . '%\s*,\s*' . self::NUMBER . '%(?:\s*,\s*' . self::NUMBER . '%?)?'
        . ')\s*\)';

    /** Degrees in one of each CSS angle unit. */
    private const PER_DEGREE = ['deg' => 1.0, 'grad' => 0.9, 'rad' => 180 / M_PI, 'turn' => 360.0];

    /**
     * Ink, muted ink and hairline for a ground, or null when it cannot be read.
     * The ink is white or the dark one, whichever reaches the higher contrast.
     *
     * @return array{0:string,1:string,2:string}|null
     *
     * @since 1.0.0
     */
    public static function on(string $ground): ?array
    {
        $rgb = self::rgb($ground);
        if ($rgb === null) {
            return null;
        }

        /** @var array{0:int,1:int,2:int} $white */
        $white = self::rgb(self::ON_DARK[0]);
        /** @var array{0:int,1:int,2:int} $dark */
        $dark = self::rgb(self::ON_LIGHT[0]);

        [$ink, $line] = self::ratio($white, $rgb) >= self::ratio($dark, $rgb) ? self::ON_DARK : self::ON_LIGHT;

        return [$ink, self::muted($rgb, $ink), $line];
    }

    /**
     * What color-mix(in srgb, a share, b) paints, as #rrggbb, or null when
     * either colour cannot be read. The share is a's part, from 0 to 1.
     *
     * @since 1.0.0
     */
    public static function mix(string $a, string $b, float $share): ?string
    {
        $x = self::rgba($a);
        $y = self::rgb($b);
        if ($x === null || $y === null) {
            return null;
        }

        return self::hexOf(self::blend(self::over($x, $y), $y, $share));
    }

    /**
     * Ink for the grounds the page ink was not chosen for: the card behind the
     * form and the panels, the selected tint, and the accent drawn as text on
     * the page. A surface that paints one of these restates its ink from here.
     * Where the authored ink already reads, the value names it.
     *
     * @param array<string,string> $tokens
     *
     * @since 1.0.0
     */
    public static function groundDeclarations(array $tokens): string
    {
        $text   = (string) ($tokens['gratora-text'] ?? '');
        $muted  = (string) ($tokens['gratora-text-muted'] ?? '');
        $accent = (string) ($tokens['gratora-accent'] ?? '');
        $card   = (string) ($tokens['gratora-bg'] ?? '');
        $tint   = (string) ($tokens['gratora-accent-soft'] ?? '');

        // The page's colour is not known, so the accent is measured against the
        // ground the page ink was chosen for: white under dark ink, dark under light.
        $page   = self::on($text);
        $onCard = self::on($card);

        return '--gratora-text-accent:'
                . ($page !== null && self::carries($accent, $page[0]) ? 'var(--gratora-accent)' : 'var(--gratora-text)') . ';'
            . '--gratora-on-bg:'
                . (self::carries($text, $card) || $onCard === null ? 'var(--gratora-text)' : $onCard[0]) . ';'
            . '--gratora-on-bg-muted:'
                . (self::carries($muted, $card) || $onCard === null ? 'var(--gratora-text-muted)' : $onCard[1]) . ';'
            . self::accentOnCard($accent, $card, $tint !== '' ? $tint : (self::mix($accent, $card, .12) ?? ''));
    }

    /**
     * The keyboard ring on each ground, drawn outside a control on the ground
     * around it: the ring the org chose where it reads there, else the accent
     * as that ground reads it. The page is measured as the accent is, against
     * the ground the page ink was chosen for.
     *
     * @param array<string,string> $tokens
     *
     * @since 1.0.0
     */
    public static function ringDeclarations(array $tokens): string
    {
        $ring = (string) ($tokens['gratora-focus-ring'] ?? '');
        $page = self::on((string) ($tokens['gratora-text'] ?? ''));

        $grounds = [
            'text-ring'      => [$page[0] ?? '', 'var(--gratora-text-accent)'],
            'on-bg-ring'     => [(string) ($tokens['gratora-bg'] ?? ''), 'var(--gratora-on-bg-accent)'],
            'on-soft-ring'   => [(string) ($tokens['gratora-bg-soft'] ?? ''), 'var(--gratora-on-soft-accent)'],
            'on-accent-ring' => [(string) ($tokens['gratora-accent'] ?? ''), 'var(--gratora-on-accent)'],
            'on-field-ring'  => [(string) ($tokens['gratora-field-bg'] ?? ''), 'var(--gratora-on-field)'],
        ];

        $css = '';
        foreach ($grounds as $name => [$ground, $own]) {
            $css .= '--gratora-' . $name . ':' . ($ring !== '' && self::carries($ring, $ground) ? 'var(--gratora-focus-ring)' : $own) . ';';
        }

        return $css;
    }

    /**
     * Ink for a hovered button, which paints the hover colour the org chose or
     * else its own fill darkened to 78%: the button's ink where it still reads
     * there, measured ink where it does not. A fill it cannot read emits
     * nothing, and the button keeps its ink.
     *
     * @param array<string,string> $tokens
     *
     * @since 1.0.0
     */
    public static function hoverDeclarations(array $tokens): string
    {
        $fill  = (string) ($tokens['gratora-button-bg'] ?? '');
        $fill  = $fill !== '' ? $fill : (string) ($tokens['gratora-accent'] ?? '');
        $hover = (string) ($tokens['gratora-button-hover-bg'] ?? '');
        $hover = $hover !== '' ? $hover : (self::mix($fill, '#000000', .78) ?? '');
        $on    = self::on($hover);
        if ($on === null) {
            return '';
        }

        $chosen = (string) ($tokens['gratora-button-fg'] ?? '');
        [$ink, $name] = $chosen !== ''
            ? [$chosen, 'var(--gratora-button-fg)']
            : [self::on((string) ($tokens['gratora-accent'] ?? ''))[0] ?? '', 'var(--gratora-on-accent)'];

        return '--gratora-on-button-hover:' . (self::carries($ink, $hover) ? $name : $on[0]) . ';';
    }

    /**
     * The required marker on the page and on the card, mixed toward the ink
     * each reads. Mixing alone cannot lift it on a mid-tone card, so the share
     * of the marker colour is measured there. A ground that cannot be read
     * emits nothing, and the stylesheet's own mix stands.
     *
     * @param array<string,string> $tokens
     *
     * @since 1.0.0
     */
    public static function requiredDeclarations(array $tokens): string
    {
        $text = (string) ($tokens['gratora-text'] ?? '');
        $card = (string) ($tokens['gratora-bg'] ?? '');
        $css  = '';

        $page = self::on($text);
        if ($page !== null) {
            $css .= '--gratora-text-required:' . self::toward(self::REQUIRED, self::REQUIRED_FROM, $text, $page[0]) . ';';
        }

        $onCard = self::on($card);
        if ($onCard !== null) {
            $css .= '--gratora-on-bg-required:'
                . self::toward(self::REQUIRED, self::REQUIRED_FROM, self::carries($text, $card) ? $text : $onCard[0], $card) . ';';
        }

        return $css;
    }

    /**
     * The danger red on the page, the card and the soft ground: the red where
     * it reads, moved toward the ink each ground reads until it does. A ground
     * that cannot be read emits nothing, and the stylesheet's red stands.
     *
     * @param array<string,string> $tokens
     *
     * @since 1.0.0
     */
    public static function dangerDeclarations(array $tokens): string
    {
        $text = (string) ($tokens['gratora-text'] ?? '');
        $card = (string) ($tokens['gratora-bg'] ?? '');
        $soft = (string) ($tokens['gratora-bg-soft'] ?? '');
        $css  = '';

        $page = self::on($text);
        if ($page !== null) {
            $css .= '--gratora-text-danger:' . self::toward(self::DANGER, 100, $text, $page[0]) . ';';
        }

        $onCard = self::on($card);
        if ($onCard !== null) {
            $css .= '--gratora-on-bg-danger:'
                . self::toward(self::DANGER, 100, self::carries($text, $card) ? $text : $onCard[0], $card) . ';';
        }

        $onSoft = self::on($soft);
        if ($onSoft !== null) {
            $css .= '--gratora-on-soft-danger:' . self::toward(self::DANGER, 100, $onSoft[0], $soft) . ';';
        }

        return $css;
    }

    /**
     * Ink for the tint the campaign page mixes from the accent into white,
     * where avatars and tags draw the accent darkened. The darkened accent is
     * kept where it reads on the tint, and measured ink takes over where it
     * does not.
     *
     * @param array<string,string> $tokens
     *
     * @since 1.0.0
     */
    public static function pageTintDeclarations(array $tokens): string
    {
        $accent = (string) ($tokens['gratora-accent'] ?? '');
        $tint   = (string) ($tokens['gratora-accent-soft'] ?? '');
        $tint   = $tint !== '' ? $tint : (self::mix($accent, '#ffffff', .12) ?? '');
        $dark   = self::mix($accent, '#000000', .78);
        $on     = self::on($tint);
        if ($dark === null || $on === null) {
            return '';
        }

        return '--gratora-on-page-tint:' . (self::carries($dark, $tint) ? $dark : $on[0]) . ';';
    }

    /**
     * A grid card paints the page's card under its own campaign's accent, so
     * the accent as text and its selected tint are measured on that card.
     *
     * @since 1.0.0
     */
    public static function cardAccentDeclarations(string $accent, string $ground): string
    {
        return self::accentOnCard($accent, $ground, self::mix($accent, $ground, .12) ?? '');
    }

    /**
     * The colour as it lands on white, as opaque #rrggbb, or null when it
     * cannot be read.
     *
     * Built from the measured channels, so the return carries no character of
     * the input and is safe wherever a colour literal is.
     *
     * @since 1.0.0
     */
    public static function hex(string $value): ?string
    {
        $rgb = self::rgb($value);

        return $rgb === null ? null : self::hexOf($rgb);
    }

    /**
     * Declarations for a style attribute or rule body, or '' when the accent
     * cannot be read.
     *
     * @since 1.0.0
     */
    public static function declarationsFor(string $accent): string
    {
        $on = self::on($accent);
        if ($on === null) {
            return '';
        }

        return '--gratora-on-accent:' . $on[0] . ';'
            . '--gratora-on-accent-muted:' . $on[1] . ';'
            . '--gratora-on-accent-line:' . $on[2] . ';';
    }

    /**
     * The soft ground carries the amount tiles, the order summary and the
     * secondary buttons, so what sits on it needs ink of its own: the page ink
     * is chosen against --gratora-bg and knows nothing about this one.
     *
     * @param array<string,string> $tokens
     *
     * @since 1.0.0
     */
    public static function softDeclarations(array $tokens): string
    {
        $on = self::on((string) ($tokens['gratora-bg-soft'] ?? ''));
        if ($on === null) {
            return '';
        }

        $css = '--gratora-on-soft:' . $on[0] . ';'
            . '--gratora-on-soft-muted:' . $on[1] . ';';

        // The total on the order summary is drawn in the accent, which reads on
        // the shipped near-white ground and can vanish on a chosen one. Keeping
        // the accent where it carries and standing it down where it does not is
        // what leaves the shipped look untouched.
        $accent = (string) ($tokens['gratora-accent'] ?? '');
        $css .= '--gratora-on-soft-accent:'
            . (self::carries($accent, (string) ($tokens['gratora-bg-soft'] ?? '')) ? $accent : $on[0])
            . ';';

        return $css . self::softHovers($tokens, $on[0]);
    }

    /**
     * What a hovered tile and a hovered secondary button paint. The tile moves
     * its fill 8% toward its ink and the button mixes in 45% of the border,
     * which on a mid-tone ground can take the ink under 4.5:1: there the tile
     * moves toward the other ink instead, and the button paints the tile's.
     *
     * @param array<string,string> $tokens
     */
    private static function softHovers(array $tokens, string $ink): string
    {
        $soft  = (string) ($tokens['gratora-bg-soft'] ?? '');
        $tile  = self::mix($ink, $soft, .08);
        $other = $ink === self::ON_DARK[0] ? self::ON_LIGHT[0] : self::ON_DARK[0];
        if ($tile === null || ! self::carries($ink, $tile)) {
            $tile = self::mix($other, $soft, .08);
        }
        if ($tile === null) {
            return '';
        }

        $button = self::mix((string) ($tokens['gratora-border'] ?? ''), $soft, .45);

        return '--gratora-soft-hover:' . $tile . ';'
            . '--gratora-secondary-hover:' . ($button !== null && self::carries($ink, $button) ? $button : $tile) . ';';
    }

    /**
     * The fields keep a ground of their own so a coloured page does not paint
     * the boxes a donor types in. The page ink is chosen against --gratora-bg
     * and knows nothing about that one, so on a dark page it is white and the
     * fields are still white.
     *
     * @param array<string,string> $tokens
     *
     * @since 1.0.0
     */
    public static function fieldDeclarations(array $tokens): string
    {
        $on = self::on((string) ($tokens['gratora-field-bg'] ?? ''));
        if ($on === null) {
            return '';
        }

        return '--gratora-on-field:' . $on[0] . ';'
            . '--gratora-on-field-muted:' . $on[1] . ';';
    }

    /**
     * Whether ink clears WCAG AA body text against a ground. Unreadable colours
     * yield false, so the caller falls back to measured ink.
     *
     * @since 1.0.0
     */
    public static function carries(string $ink, string $ground): bool
    {
        $a = self::rgba($ink);
        $b = self::rgb($ground);
        if ($a === null || $b === null) {
            return false;
        }

        return self::ratio(self::over($a, $b), $b) >= 4.5;
    }

    private static function accentOnCard(string $accent, string $card, string $tint): string
    {
        return '--gratora-on-bg-accent:'
                . (self::carries($accent, $card) ? 'var(--gratora-accent)' : 'var(--gratora-on-bg)') . ';'
            . '--gratora-on-accent-soft:'
                . (self::carries($accent, $tint) ? 'var(--gratora-accent)' : (self::on($tint)[0] ?? 'var(--gratora-accent)')) . ';';
    }

    /**
     * The ink at the lowest alpha, from the shipped one up, whose composite on
     * the ground reaches 4.5:1. Where none below opaque does, the ink itself.
     *
     * @param array{0:int,1:int,2:int} $ground
     */
    private static function muted(array $ground, string $ink): string
    {
        /** @var array{0:int,1:int,2:int} $channels */
        $channels = self::rgb($ink);

        for ($n = self::MUTED_FROM[$ink]; $n < 100; $n++) {
            if (self::ratio(self::blend($channels, $ground, $n / 100), $ground) >= 4.5) {
                return sprintf('rgba(%d,%d,%d,.%d)', $channels[0], $channels[1], $channels[2], $n % 10 === 0 ? $n / 10 : $n);
            }
        }

        return $ink;
    }

    /**
     * The colour mixed toward the ink at the largest share, from the one given
     * in hundredths down, that reaches 4.5:1 on the ground. Where none does,
     * the ink.
     */
    private static function toward(string $color, int $from, string $ink, string $ground): string
    {
        /** @var array{0:int,1:int,2:int} $marker */
        $marker = self::rgb($color);
        $inkRgb = self::rgba($ink);
        $bg     = self::rgb($ground);
        if ($inkRgb === null || $bg === null) {
            return $ink;
        }
        $inkRgb = self::over($inkRgb, $bg);

        for ($n = $from; $n > 0; $n--) {
            $mixed = self::blend($marker, $inkRgb, $n / 100);
            if (self::ratio($mixed, $bg) >= 4.5) {
                return self::hexOf($mixed);
            }
        }

        return self::hexOf($inkRgb);
    }

    /**
     * @param array{0:int,1:int,2:int} $a
     * @param array{0:int,1:int,2:int} $b
     * @return array{0:int,1:int,2:int}
     */
    private static function blend(array $a, array $b, float $share): array
    {
        $out = [];
        foreach ([0, 1, 2] as $i) {
            $out[] = (int) round($share * max(0, min(255, $a[$i])) + (1 - $share) * max(0, min(255, $b[$i])));
        }

        /** @var array{0:int,1:int,2:int} $out */
        return $out;
    }

    /**
     * @param array{0:int,1:int,2:int} $a
     * @param array{0:int,1:int,2:int} $b
     */
    private static function ratio(array $a, array $b): float
    {
        $l1 = self::luminance($a);
        $l2 = self::luminance($b);

        return (max($l1, $l2) + 0.05) / (min($l1, $l2) + 0.05);
    }

    /**
     * @param array{0:int,1:int,2:int} $rgb
     */
    private static function hexOf(array $rgb): string
    {
        $out = '#';
        foreach ($rgb as $channel) {
            $out .= sprintf('%02x', max(0, min(255, $channel)));
        }

        return $out;
    }

    /**
     * The colour as it lands on white.
     *
     * @return array{0:int,1:int,2:int}|null
     */
    private static function rgb(string $value): ?array
    {
        $rgba = self::rgba($value);

        return $rgba === null ? null : self::over($rgba, [255, 255, 255]);
    }

    /**
     * A colour, translucent or not, painted over an opaque one.
     *
     * @param array{0:int,1:int,2:int,3:float} $top
     * @param array{0:int,1:int,2:int} $under
     * @return array{0:int,1:int,2:int}
     */
    private static function over(array $top, array $under): array
    {
        $rgb = [$top[0], $top[1], $top[2]];

        return $top[3] >= 1 ? $rgb : self::blend($rgb, $under, $top[3]);
    }

    /**
     * @return array{0:int,1:int,2:int,3:float}|null
     */
    private static function rgba(string $value): ?array
    {
        $value = trim($value);

        if (preg_match('/^#([0-9a-fA-F]{3,8})$/', $value, $m) === 1) {
            $hex   = $m[1];
            $alpha = match (strlen($hex)) {
                4       => hexdec($hex[3] . $hex[3]) / 255,
                8       => hexdec(substr($hex, 6, 2)) / 255,
                default => 1.0,
            };
            if (strlen($hex) === 3 || strlen($hex) === 4) {
                $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
            }
            if (strlen($hex) < 6) {
                return null;
            }
            return [
                (int) hexdec(substr($hex, 0, 2)),
                (int) hexdec(substr($hex, 2, 2)),
                (int) hexdec(substr($hex, 4, 2)),
                (float) $alpha,
            ];
        }

        if (preg_match('/^hsla?\(/i', $value) === 1) {
            return preg_match('/^' . self::HSL . '$/i', $value) === 1
                ? self::fromHsl(substr($value, (int) strpos($value, '(') + 1, -1))
                : null;
        }

        if (preg_match('/^rgba?\(([^)]*)\)$/i', $value, $m) === 1) {
            $parts = preg_split('#[\s,/]+#', trim($m[1])) ?: [];
            $parts = array_values(array_filter($parts, static fn($p): bool => $p !== ''));
            if (count($parts) < 3) {
                return null;
            }
            $out = [];
            foreach (array_slice($parts, 0, 3) as $part) {
                if (! is_numeric(rtrim($part, '%'))) {
                    return null;
                }
                $n = (float) rtrim($part, '%');
                $out[] = (int) round(str_contains($part, '%') ? $n * 2.55 : $n);
            }
            $alpha = self::alpha($parts[3] ?? null);
            if ($alpha === null) {
                return null;
            }
            $out[] = $alpha;
            /** @var array{0:int,1:int,2:int,3:float} $out */
            return $out;
        }

        return null;
    }

    /** Opacity from 0 to 1, or null when the bit is not one. */
    private static function alpha(?string $bit): ?float
    {
        if ($bit === null) {
            return 1.0;
        }
        if (strtolower($bit) === 'none') {
            return 0.0;
        }
        if (! is_numeric(rtrim($bit, '%'))) {
            return null;
        }

        $n = (float) rtrim($bit, '%');

        return max(0.0, min(1.0, str_ends_with($bit, '%') ? $n / 100 : $n));
    }

    /**
     * The colour control stores hsl() as readily as hex, and a ground nothing
     * can read leaves every derived ink at its stylesheet fallback: white on a
     * pale accent, exactly what the measuring exists to prevent.
     *
     * @return array{0:int,1:int,2:int,3:float}|null
     */
    private static function fromHsl(string $parts): ?array
    {
        $bits = preg_split('#[\s,/]+#', trim($parts)) ?: [];
        $bits = array_values(array_filter($bits, static fn ($p): bool => $p !== ''));
        if (count($bits) < 3) {
            return null;
        }

        $hue   = self::hue($bits[0]);
        $sat   = self::percent($bits[1]);
        $light = self::percent($bits[2]);
        $alpha = self::alpha($bits[3] ?? null);
        if ($hue === null || $sat === null || $light === null || $alpha === null) {
            return null;
        }

        $h = fmod($hue, 360);
        if ($h < 0) {
            $h += 360;
        }
        $s = max(0, min(100, $sat)) / 100;
        $l = max(0, min(100, $light)) / 100;

        $c = (1 - abs(2 * $l - 1)) * $s;
        $x = $c * (1 - abs(fmod($h / 60, 2) - 1));
        $m = $l - $c / 2;

        $sector = (int) floor($h / 60);
        $rgb = [
            [$c, $x, 0.0], [$x, $c, 0.0], [0.0, $c, $x],
            [0.0, $x, $c], [$x, 0.0, $c], [$c, 0.0, $x],
        ][$sector % 6];

        return [
            (int) round(($rgb[0] + $m) * 255),
            (int) round(($rgb[1] + $m) * 255),
            (int) round(($rgb[2] + $m) * 255),
            $alpha,
        ];
    }

    /** Degrees, or null when the bit is not an angle. */
    private static function hue(string $bit): ?float
    {
        if (strtolower($bit) === 'none') {
            return 0.0;
        }

        if (preg_match('/^(' . self::NUMBER . ')(deg|grad|rad|turn)?$/i', $bit, $m) !== 1) {
            return null;
        }

        return (float) $m[1] * self::PER_DEGREE[strtolower($m[2] ?? '') ?: 'deg'];
    }

    private static function percent(string $bit): ?float
    {
        if (strtolower($bit) === 'none') {
            return 0.0;
        }

        return preg_match('/^(' . self::NUMBER . ')%?$/', $bit, $m) === 1 ? (float) $m[1] : null;
    }

    /**
     * WCAG relative luminance.
     *
     * @param array{0:int,1:int,2:int} $rgb
     */
    private static function luminance(array $rgb): float
    {
        $channels = [];
        foreach ($rgb as $raw) {
            $c = max(0, min(255, $raw)) / 255;
            $channels[] = $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }
}
