<?php

declare(strict_types=1);

namespace Dono\Tests\Unit;

use Dono\Tests\Unit\Support\DistPayload;
use PHPUnit\Framework\TestCase;

/**
 * bin/package.mjs is the only thing standing between a hand-run build and a zip
 * full of PHPUnit. .distignore cannot do it: development dependencies are
 * transitive, so the eighty-odd packages strauss and phpunit drag into vendor/
 * are named nowhere a human maintains. composer.json's require-dev is not that
 * list either, which is the hole this pins.
 *
 * The packager is run for real against fabricated plugin roots, because the
 * only useful question about a refusal is whether the process exits non-zero
 * and says why.
 */
final class DistPackagingTest extends TestCase
{
    private string $fixture = '';

    protected function tearDown(): void
    {
        if ($this->fixture !== '') {
            self::removeTree($this->fixture);
            $this->fixture = '';
        }

        parent::tearDown();
    }

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    private static function removeTree(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? self::removeTree($path) : unlink($path);
        }
        rmdir($dir);
    }

    /**
     * A plugin root the packager will accept as its own: the real script, the
     * real .distignore, the real manifests, and a vendor/ we control.
     *
     * @param list<string> $vendorPackages vendor directories to create
     */
    private function pluginRoot(bool $installedWithDev, array $vendorPackages = []): string
    {
        $dir = sys_get_temp_dir() . '/dono-packaging-' . bin2hex(random_bytes(6));
        mkdir($dir . '/bin', 0777, true);
        mkdir($dir . '/vendor/composer', 0777, true);

        // A real plugin root always carries both, and the packager refuses a
        // build older than the source it was compiled from, so a fixture
        // without them answers the freshness gate instead of the one under
        // test. Written source-first so the build reads as the newer.
        mkdir($dir . '/assets', 0777, true);
        mkdir($dir . '/build', 0777, true);
        file_put_contents($dir . '/assets/entry.js', '');
        touch($dir . '/assets/entry.js', time() - 60);
        file_put_contents($dir . '/build/entry.js', '');

        foreach (['bin/package.mjs', '.distignore', 'composer.json', 'composer.lock'] as $rel) {
            copy(self::root() . '/' . $rel, $dir . '/' . $rel);
        }

        // The packager reads the install directory out of the plugin header.
        file_put_contents(
            $dir . '/dono.php',
            "<?php\n/**\n * Plugin Name: Dono\n * Text Domain: dono-fundraising-platform\n */\n"
        );

        file_put_contents(
            $dir . '/vendor/composer/installed.json',
            (string) json_encode([
                'packages'          => [],
                'dev'               => $installedWithDev,
                'dev-package-names' => [],
            ])
        );

        foreach ($vendorPackages as $name) {
            mkdir($dir . '/vendor/' . $name, 0777, true);
        }

        return $this->fixture = $dir;
    }

    /** @return array{status: int, output: string} */
    private function package(string $root): array
    {
        $node = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($node === '') {
            $this->markTestSkipped('node is not on PATH, so bin/package.mjs cannot be run.');
        }

        $output = [];
        $status = 0;
        exec(
            escapeshellarg($node) . ' ' . escapeshellarg($root . '/bin/package.mjs') . ' 2>&1',
            $output,
            $status
        );

        return ['status' => $status, 'output' => implode("\n", $output)];
    }

    /**
     * The straggler this catches is one require-dev never mentions, so a guard
     * reading require-dev waves it through.
     */
    public function test_a_transitive_development_dependency_left_in_vendor_stops_the_build(): void
    {
        $composer = json_decode(
            (string) file_get_contents(self::root() . '/composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $lock = json_decode(
            (string) file_get_contents(self::root() . '/composer.lock'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $devNames = array_column($lock['packages-dev'] ?? [], 'name');

        $this->assertContains('symfony/console', $devNames, 'the lock no longer carries this dev package');
        $this->assertArrayNotHasKey('symfony/console', $composer['require-dev'] ?? []);

        $result = $this->package($this->pluginRoot(false, ['symfony/console']));

        $this->assertSame(1, $result['status'], "the packager built anyway:\n" . $result['output']);
        $this->assertStringContainsString('symfony/console', $result['output']);
        $this->assertStringContainsString('composer install --no-dev', $result['output']);
    }

    /**
     * Composer records the kind of install it performed, which answers the
     * question even when the directories it wrote are gone.
     */
    public function test_a_vendor_installed_with_development_dependencies_stops_the_build(): void
    {
        $result = $this->package($this->pluginRoot(true));

        $this->assertSame(1, $result['status'], "the packager built anyway:\n" . $result['output']);
        $this->assertStringContainsString('development dependencies', $result['output']);
        $this->assertStringContainsString('composer install --no-dev', $result['output']);
    }

    /**
     * Without this the refusals above would pass for a packager that refuses
     * everything.
     */
    public function test_a_vendor_installed_without_them_gets_past_the_dependency_gate(): void
    {
        $result = $this->package($this->pluginRoot(false));

        $this->assertStringNotContainsString('development dependencies', $result['output']);
        // The fixture has no Strauss output, which is the next gate along.
        $this->assertStringContainsString('vendor-prefixed', $result['output']);
    }

    /**
     * build/ ships as-is and nothing in the release path compiles it, so a
     * bundle older than the source is a zip that behaves like an earlier
     * checkout while every test agrees with the source it was never built from.
     */
    public function test_a_build_older_than_the_source_stops_the_build(): void
    {
        $root = $this->pluginRoot(false);
        touch($root . '/build/entry.js', time() - 120);

        $result = $this->package($root);

        $this->assertStringContainsString('assets/ is newer than build/', $result['output']);
        $this->assertStringContainsString('npm run build', $result['output']);
    }

    public function test_a_missing_build_directory_stops_the_build(): void
    {
        $root = $this->pluginRoot(false);
        unlink($root . '/build/entry.js');
        rmdir($root . '/build');

        $result = $this->package($root);

        $this->assertStringContainsString('no compiled assets', $result['output']);
    }

    /**
     * Three separate branches end in `composer install --no-dev`, so that string
     * alone cannot tell a missing manifest from a dev install. The refusal has to
     * name the thing that is actually absent.
     */
    public function test_a_vendor_with_no_composer_manifest_stops_the_build(): void
    {
        $root = $this->pluginRoot(false);
        unlink($root . '/vendor/composer/installed.json');

        $result = $this->package($root);

        $this->assertSame(1, $result['status'], "the packager built anyway:\n" . $result['output']);
        $this->assertStringContainsString('no composer manifest', $result['output']);
        $this->assertStringContainsString('composer install --no-dev', $result['output']);
        $this->assertStringNotContainsString(
            'development dependencies',
            $result['output'],
            'the refusal blames dev dependencies for a manifest that is simply not there.'
        );
    }

    /**
     * A manifest with no `dev` key is one composer 1 wrote, and it answers
     * nothing. Refusing is right; saying the tree has development dependencies
     * in it is a claim nothing here can make.
     */
    public function test_a_manifest_that_cannot_answer_the_question_says_so(): void
    {
        $root = $this->pluginRoot(false);
        file_put_contents($root . '/vendor/composer/installed.json', '[]');

        $result = $this->package($root);

        $this->assertSame(1, $result['status'], "the packager built anyway:\n" . $result['output']);
        $this->assertStringContainsString('installed.json', $result['output']);
        $this->assertStringNotContainsString(
            'was installed with development dependencies',
            $result['output'],
            'the refusal states as fact something the manifest does not say.'
        );
    }

    /**
     * Notes for whoever regenerates the POT, sitting one directory down where
     * the root-anchored /README.md rule cannot reach them. Nothing in the zip
     * should be addressed to us.
     */
    public function test_the_translator_notes_stay_out_of_the_zip(): void
    {
        $this->assertFileExists(self::root() . '/languages/README.md');

        $this->assertTrue(
            DistPayload::excluded(self::root(), 'languages/README.md'),
            'languages/README.md ships to every install.'
        );
    }

    /**
     * DistPayload answers one path at a time, which the packager cannot do, so
     * it is a second implementation of the same rules. This is the only thing
     * keeping the two honest: the real script, the real .distignore, and a tree
     * with one file per rule shape.
     */
    public function test_the_per_path_matcher_agrees_with_the_packager(): void
    {
        $root = $this->pluginRoot(false);
        foreach (['dono/queryable', 'dompdf/dompdf'] as $prefixed) {
            mkdir($root . '/vendor/vendor-prefixed/' . $prefixed, 0777, true);
        }

        $samples = [
            'languages/README.md',
            'package.json',
            'webpack.config.js',
            'README.md',
            'tests',
            'assets',
            'assets/admin',
            'assets/deactivation',
            'build',
            'src',
        ];

        foreach ($samples as $rel) {
            $path = $root . '/' . $rel;
            if (str_contains($rel, '.')) {
                is_dir(dirname($path)) || mkdir(dirname($path), 0777, true);
                file_put_contents($path, "x\n");
                continue;
            }
            mkdir($path . '/nested', 0777, true);
            file_put_contents($path . '/nested/file.php', "<?php\n");
        }

        $result = $this->package($root);
        $this->assertSame(0, $result['status'], "the packager refused the fixture:\n" . $result['output']);

        preg_match_all('/^  (\S+)$/m', $result['output'], $m);
        $excluded = $m[1];

        $disagreed = [];
        foreach ($samples as $rel) {
            $packager = in_array($rel, $excluded, true);
            if (DistPayload::excluded(self::root(), $rel) !== $packager) {
                $disagreed[] = $rel . ($packager ? ' (packager strips it)' : ' (packager ships it)');
            }
        }

        $this->assertSame(
            [],
            $disagreed,
            "the matcher and bin/package.mjs disagree about what ships:\n" . implode("\n", $disagreed)
        );
    }
}
