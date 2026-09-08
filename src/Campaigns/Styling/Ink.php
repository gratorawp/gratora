<?php

declare(strict_types=1);

namespace FundKit\Campaigns\Styling;

/**
 * Ink for anything drawn on a ground the org chose.
 *
 * A filled surface cannot pick its own contrast: the colour is the org's to
 * choose, and reversing everything out in white assumes it is dark. A campaign
 * on yellow gets white on yellow. Measuring the ground instead means one rule
 * covers both, and what sits on a surface reads these rather than the page ink,
 * which is chosen against the page background and knows nothing about a panel.
 *
 * Hex and rgb() are what the colour control stores. Anything else (hsl, a
 * keyword) yields nothing, and the stylesheet's own fallback stands.
 *
 * @since 1.0.0
 */
final class Ink
{
    /**
     * Relative luminance at which black and white contrast equally against the
     * same background: (L + 0.05)^2 = 1.05 * 0.05. Above it, dark ink wins.
     */
    private const FLIP = 0.1791;

    private const ON_DARK  = ['#ffffff', 'rgba(255,255,255,.72)', 'rgba(255,255,255,.26)'];
    private const ON_LIGHT = ['#10162a', 'rgba(16,22,42,.62)',    'rgba(16,22,42,.16)'];

    /**
     * Ink, muted ink and hairline for a ground, or null when it cannot be read.
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

        return self::luminance($rgb) > self::FLIP ? self::ON_LIGHT : self::ON_DARK;
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

        return '--fundkit-on-accent:' . $on[0] . ';'
            . '--fundkit-on-accent-muted:' . $on[1] . ';'
            . '--fundkit-on-accent-line:' . $on[2] . ';';
    }

    /**
     * The soft ground carries the amount tiles, the order summary and the
     * secondary buttons, so what sits on it needs ink of its own: the page ink
     * is chosen against --fundkit-bg and knows nothing about this one.
     *
     * @param array<string,string> $tokens
     *
     * @since 1.0.0
     */
    public static function softDeclarations(array $tokens): string
    {
        $on = self::on((string) ($tokens['fundkit-bg-soft'] ?? ''));
        if ($on === null) {
            return '';
        }

        $css = '--fundkit-on-soft:' . $on[0] . ';'
            . '--fundkit-on-soft-muted:' . $on[1] . ';';

        // The total on the order summary is drawn in the accent, which reads on
        // the shipped near-white ground and can vanish on a chosen one. Keeping
        // the accent where it carries and standing it down where it does not is
        // what leaves the shipped look untouched.
        $accent = (string) ($tokens['fundkit-accent'] ?? '');
        $css .= '--fundkit-on-soft-accent:'
            . (self::carries($accent, (string) ($tokens['fundkit-bg-soft'] ?? '')) ? $accent : $on[0])
            . ';';

        return $css;
    }

    /**
     * The fields keep a ground of their own so a coloured page does not paint
     * the boxes a donor types in. The page ink is chosen against --fundkit-bg
     * and knows nothing about that one, so on a dark page it is white and the
     * fields are still white.
     *
     * @param array<string,string> $tokens
     *
     * @since 1.0.0
     */
    public static function fieldDeclarations(array $tokens): string
    {
        $on = self::on((string) ($tokens['fundkit-field-bg'] ?? ''));
        if ($on === null) {
            return '';
        }

        return '--fundkit-on-field:' . $on[0] . ';'
            . '--fundkit-on-field-muted:' . $on[1] . ';';
    }

    /**
     * Whether ink clears WCAG AA body text against a ground. Unreadable colours
     * yield false, so the caller falls back to measured ink.
     *
     * @since 1.0.0
     */
    private static function carries(string $ink, string $ground): bool
    {
        $a = self::rgb($ink);
        $b = self::rgb($ground);
        if ($a === null || $b === null) {
            return false;
        }

        $l1 = self::luminance($a);
        $l2 = self::luminance($b);

        return ((max($l1, $l2) + 0.05) / (min($l1, $l2) + 0.05)) >= 4.5;
    }

    /**
     * @return array{0:int,1:int,2:int}|null
     */
    private static function rgb(string $value): ?array
    {
        $value = trim($value);

        if (preg_match('/^#([0-9a-fA-F]{3,8})$/', $value, $m) === 1) {
            $hex = $m[1];
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
            ];
        }

        if (preg_match('/^hsla?\(([^)]*)\)$/i', $value, $m) === 1) {
            return self::fromHsl($m[1]);
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
            /** @var array{0:int,1:int,2:int} $out */
            return $out;
        }

        return null;
    }

    /**
     * The colour control stores hsl() as readily as hex, and a ground nothing
     * can read leaves every derived ink at its stylesheet fallback: white on a
     * pale accent, exactly what the measuring exists to prevent.
     *
     * @return array{0:int,1:int,2:int}|null
     */
    private static function fromHsl(string $parts): ?array
    {
        $bits = preg_split('#[\s,/]+#', trim($parts)) ?: [];
        $bits = array_values(array_filter($bits, static fn ($p): bool => $p !== ''));
        if (count($bits) < 3) {
            return null;
        }

        foreach (array_slice($bits, 0, 3) as $bit) {
            if (! is_numeric(rtrim($bit, '%deg'))) {
                return null;
            }
        }

        $h = fmod((float) rtrim($bits[0], 'deg'), 360);
        if ($h < 0) {
            $h += 360;
        }
        $s = max(0, min(100, (float) rtrim($bits[1], '%'))) / 100;
        $l = max(0, min(100, (float) rtrim($bits[2], '%'))) / 100;

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
        ];
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
