<?php

declare(strict_types=1);

namespace Dono\Tests\Unit;

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

    public function test_a_vendor_with_no_composer_manifest_stops_the_build(): void
    {
        $root = $this->pluginRoot(false);
        unlink($root . '/vendor/composer/installed.json');

        $result = $this->package($root);

        $this->assertSame(1, $result['status'], "the packager built anyway:\n" . $result['output']);
        $this->assertStringContainsString('composer install --no-dev', $result['output']);
    }
}
