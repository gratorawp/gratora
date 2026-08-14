<?php

declare(strict_types=1);

namespace Dono\Tests\Unit\Support;

/**
 * Answers "does this path reach a customer?" from .distignore.
 *
 * bin/package.mjs is the only thing that reads .distignore for real, and it
 * cannot be asked one path at a time: it copies a tree and needs a vendor/
 * installed with --no-dev before it will run at all. This mirrors the rule
 * subset that script documents, which is WP-CLI's dist-archive subset:
 *
 *   /foo    anchored at the plugin root
 *   foo     a file or directory anywhere in the tree
 *   *.log   glob, matched against the basename
 *   #       comment
 */
final class DistPayload
{
    /** dist/ is excluded by the packager itself rather than by a rule. */
    private const OUTPUT_DIR = 'dist';

    public static function excluded(string $root, string $rel): bool
    {
        $rel = trim($rel, '/');

        if ($rel === self::OUTPUT_DIR || str_starts_with($rel, self::OUTPUT_DIR . '/')) {
            return true;
        }

        foreach (self::rules($root) as $rule) {
            if (self::matches($rule, $rel)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private static function rules(string $root): array
    {
        $lines = preg_split('/\R/', (string) file_get_contents($root . '/.distignore')) ?: [];

        return array_values(array_filter(
            array_map('trim', $lines),
            static fn (string $l): bool => $l !== '' && ! str_starts_with($l, '#')
        ));
    }

    private static function matches(string $rule, string $rel): bool
    {
        $anchored = str_starts_with($rule, '/');
        $body     = rtrim($anchored ? substr($rule, 1) : $rule, '/');

        if (str_contains($body, '*')) {
            $pattern = '#^' . str_replace('\*', '.*', preg_quote($body, '#')) . '$#';

            return preg_match($pattern, basename($rel)) === 1;
        }

        if ($anchored) {
            return $rel === $body || str_starts_with($rel, $body . '/');
        }

        return $rel === $body
            || str_ends_with($rel, '/' . $body)
            || str_starts_with($rel, $body . '/')
            || str_contains($rel, '/' . $body . '/');
    }
}
