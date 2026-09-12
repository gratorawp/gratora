<?php

declare(strict_types=1);

namespace Gratora\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Ship compiled translation sources at the enqueued paths, without .min.js renaming.
 * Language-pack hashes and script-translation handles must match those paths.
 */
final class ScriptTranslationsTest extends TestCase
{
    private const DOMAIN = 'gratora-donation-platform';

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /** @return list<string> plugin-relative paths of the built bundles */
    private function bundles(): array
    {
        $found = glob($this->root() . '/build/*/*/index.js') ?: [];
        $this->assertNotSame([], $found, 'build/ is empty, so run `npm run build` before this suite.');

        return array_map(
            fn (string $abs): string => substr($abs, strlen($this->root()) + 1),
            $found
        );
    }

    /** @return list<string> every PHP file under src/ */
    private function sources(): array
    {
        $out = [];
        $dir = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root() . '/src'));
        foreach ($dir as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $out[] = $file->getPathname();
            }
        }

        return $out;
    }

    public function test_every_enqueued_bundle_with_strings_sets_its_own_translations(): void
    {
        $translated = array_values(array_filter(
            $this->bundles(),
            fn (string $rel): bool => str_contains(
                (string) file_get_contents($this->root() . '/' . $rel),
                "\"" . self::DOMAIN . "\""
            )
        ));
        $this->assertNotSame([], $translated, 'no bundle carries a translatable string, so this test is measuring nothing.');

        $wired   = [];
        $missing = [];

        foreach ($this->sources() as $path) {
            $php = (string) file_get_contents($path);

            preg_match_all('/wp_enqueue_script\(\s*([^,]+?),\s*GRATORA_URL \. ([^,]+?),/s', $php, $calls, PREG_SET_ORDER);

            foreach ($calls as $call) {
                $handle = trim($call[1]);
                $src    = $this->resolveSrc(trim($call[2]), $php);

                if ($src === null || ! in_array($src, $translated, true)) {
                    continue;
                }

                $quoted = preg_quote($handle, '/');
                $set    = preg_match(
                    "/wp_set_script_translations\(\s*{$quoted}\s*,\s*'" . self::DOMAIN . "'/",
                    $php
                ) === 1;

                $set ? $wired[] = $src : $missing[] = $src . ' (' . basename($path) . ", handle {$handle})";
            }
        }

        $this->assertSame(
            [],
            $missing,
            "these bundles are enqueued with no translations of their own:\n" . implode("\n", $missing)
        );

        $this->assertSame(
            [],
            array_values(array_diff($translated, $wired)),
            'a bundle carrying strings is enqueued somewhere this test cannot read.'
        );
    }

    /** `self::BUILD_DIR . '/index.js'` and `'build/x/y/index.js'` are the two shapes in use. */
    private function resolveSrc(string $expression, string $php): ?string
    {
        if (preg_match("/^'(build\/[^']+\.js)'$/", $expression, $literal) === 1) {
            return $literal[1];
        }

        if (preg_match("/^self::BUILD_DIR \. '(\/[^']+\.js)'$/", $expression, $suffix) !== 1) {
            return null;
        }

        if (preg_match("/const BUILD_DIR\s*=\s*'([^']+)'/", $php, $const) !== 1) {
            return null;
        }

        return $const[1] . $suffix[1];
    }
}
