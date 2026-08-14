<?php

declare(strict_types=1);

namespace Dono\Tests\Unit;

use Dono\Tests\Unit\Support\DistPayload;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Language packs for JavaScript are named after the md5 of the path WordPress
 * was given in wp_enqueue_script, and WordPress.org builds them by parsing the
 * files it finds in SVN. Both halves have to line up with what the zip carries:
 *
 *   1. the compiled bundles ship, so the originals wp.org extracts carry the
 *      same build/<entry>/index.js path load_script_textdomain() hashes;
 *   2. nothing renames them to *.min.js, which the extractor skips;
 *   3. every bundle carrying translatable strings is wired up with
 *      wp_set_script_translations, under the same handle it was enqueued with.
 *
 * None of it fails loudly. A locale that never renders looks like a translator
 * who never finished, because the PHP half of the same screen is translated.
 */
final class ScriptTranslationsTest extends TestCase
{
    private const DOMAIN = 'dono-fundraising-platform';

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

    /**
     * The bundles are the extraction target, so they cannot be stripped from
     * the payload and cannot be named like something already minified.
     */
    public function test_the_bundles_wordpress_org_extracts_from_are_in_the_zip(): void
    {
        foreach ($this->bundles() as $rel) {
            // Asked of the path rather than of the rule text: `/build`, `build`
            // and `build/` all strip the same tree, so pinning one spelling
            // catches only the spelling somebody happened to think of.
            $this->assertFalse(
                DistPayload::excluded($this->root(), $rel),
                "$rel is stripped from the zip, so no JS string in it can be extracted or translated."
            );

            $this->assertStringEndsNotWith(
                '.min.js',
                $rel,
                "$rel is skipped by the string extractor, so its strings reach no translator."
            );
        }
    }

    /**
     * Matching handles are the whole mechanism: the JSON is looked up per
     * registered script, so a translations call naming a different handle is
     * the same as no call at all.
     */
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

            preg_match_all('/wp_enqueue_script\(\s*([^,]+?),\s*DONO_URL \. ([^,]+?),/s', $php, $calls, PREG_SET_ORDER);

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
