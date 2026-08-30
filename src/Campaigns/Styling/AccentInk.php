<?php

declare(strict_types=1);

namespace FundKit\Campaigns\Styling;

/**
 * Ink for anything drawn on the campaign's accent.
 *
 * A filled panel cannot pick its own contrast: the accent is the campaign's to
 * choose, and reversing everything out in white assumes it is dark. A campaign
 * on yellow gets white on yellow. Measuring the accent instead means one rule
 * covers both, and blocks inside the panel read these rather than the page ink,
 * which is chosen against the page background and knows nothing about a panel.
 *
 * Hex and rgb() are what the colour control stores. Anything else (hsl, a
 * keyword) yields nothing, and the stylesheet's own fallback stands.
 *
 * @since 1.0.0
 */
final class AccentInk
{
    /**
     * Relative luminance at which black and white contrast equally against the
     * same background: (L + 0.05)^2 = 1.05 * 0.05. Above it, dark ink wins.
     */
    private const FLIP = 0.1791;

    private const ON_DARK  = ['#ffffff', 'rgba(255,255,255,.72)', 'rgba(255,255,255,.26)'];
    private const ON_LIGHT = ['#10162a', 'rgba(16,22,42,.62)',    'rgba(16,22,42,.16)'];

    /**
     * Declarations for a style attribute or rule body, or '' when the accent
     * cannot be read.
     *
     * @since 1.0.0
     */
    public static function declarationsFor(string $accent): string
    {
        $rgb = self::rgb($accent);
        if ($rgb === null) {
            return '';
        }

        [$ink, $muted, $line] = self::luminance($rgb) > self::FLIP ? self::ON_LIGHT : self::ON_DARK;

        return '--fundkit-on-accent:' . $ink . ';'
            . '--fundkit-on-accent-muted:' . $muted . ';'
            . '--fundkit-on-accent-line:' . $line . ';';
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
