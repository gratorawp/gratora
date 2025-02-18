<?php

declare(strict_types=1);

namespace Dono\Foundation\Modules;

/**
 * Minimal semver constraint matcher (no Composer dependency).
 *
 * Supports: ^x.y / ^x.y.z (caret), ~x.y / ~x.y.z (tilde),
 * >=x.y (lower bound), x.y - x.z (inclusive range), x.y.z (exact).
 *
 * @version 1.0.0
 */
final class VersionConstraint
{
    public static function satisfies(string $version, string $constraint): bool
    {
        $v = self::normalize($version);
        $c = trim($constraint);

        if (str_contains($c, ' - ')) {
            [$lo, $hi] = array_map('trim', explode(' - ', $c, 2));
            return version_compare($v, self::normalize($lo), '>=')
                && version_compare($v, self::ceiling($hi), '<');
        }

        if (str_starts_with($c, '^')) {
            $base = ltrim($c, '^');
            [$maj, $min, $patch] = self::parts($base);
            if ($maj > 0) {
                $upper = ($maj + 1) . '.0.0';
            } elseif ($min > 0 || str_contains($base, '.')) {
                $upper = '0.' . ($min + 1) . '.0';
            } else {
                $upper = '0.0.' . ($patch + 1);
            }
            return version_compare($v, self::normalize($base), '>=')
                && version_compare($v, $upper, '<');
        }

        if (str_starts_with($c, '~')) {
            $base = ltrim($c, '~');
            [$maj, $min] = self::parts($base);
            return version_compare($v, self::normalize($base), '>=')
                && version_compare($v, $maj . '.' . ($min + 1) . '.0', '<');
        }

        if (str_starts_with($c, '>=')) {
            return version_compare($v, self::normalize(substr($c, 2)), '>=');
        }

        return version_compare($v, self::normalize($c), '==');
    }

    /** Pad a partial version to "a.b.c" with zeros. */
    private static function normalize(string $v): string
    {
        [$maj, $min, $patch] = self::parts($v);
        return $maj . '.' . $min . '.' . $patch;
    }

    /** Upper bound for a range endpoint: a partial "x.y" allows any patch. */
    private static function ceiling(string $v): string
    {
        $trimmed = trim($v);
        [$maj, $min, $patch] = self::parts($trimmed);
        return substr_count($trimmed, '.') < 2
            ? $maj . '.' . ($min + 1) . '.0'
            : $maj . '.' . $min . '.' . ($patch + 1);
    }

    /** @return array{0:int,1:int,2:int} */
    private static function parts(string $v): array
    {
        $bits = explode('.', trim($v));
        return [
            (int) ($bits[0] ?? 0),
            (int) ($bits[1] ?? 0),
            (int) ($bits[2] ?? 0),
        ];
    }
}
