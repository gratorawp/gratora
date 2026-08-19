<?php

declare(strict_types=1);

namespace Dono\Tests\Unit\Assets;

/**
 * Executes a donation-form module in node so its behaviour can be asserted,
 * rather than its source text.
 *
 * The modules are ESM inside a CommonJS package and lean on the bundler to
 * resolve extensionless relative imports, so the plain .js files are copied to
 * .mjs under the plugin root with those specifiers completed. Copying rather
 * than importing in place is also what lets node find @dono/ui.
 *
 * The snippet gets `mod` (the entry module) and `emit( value )`, which hands
 * JSON back to PHP.
 *
 * @since 1.0.0
 */
trait RunsFormModule
{
    /** @param string $entry path under assets/donation-form, e.g. 'state/store.js' */
    private function runModule(string $entry, string $snippet): array
    {
        $root = dirname(__DIR__, 3);
        $src  = $root . '/assets/donation-form';
        $this->assertFileExists($src . '/' . $entry);

        $node = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($node === '') {
            $this->markTestSkipped('node is needed to run the form module');
        }

        // uniqid() is a microsecond clock reading, and two suites run at once
        // share this directory, so it collides across processes often enough to
        // redden a run that has nothing wrong with it.
        $dir = $root . '/.cache/js-test-' . getmypid() . '-' . bin2hex(random_bytes(8));
        if (! mkdir($dir, 0777, true) && ! is_dir($dir)) {
            $this->fail('could not create ' . $dir);
        }

        try {
            // The real directory layout, not a flattened copy: a form module
            // importing ../../_shared reaches outside donation-form, and a copy
            // that loses that shape resolves it to somewhere with nothing in it.
            @mkdir($dir . '/donation-form', 0777, true);
            $this->copyModules($src, $dir . '/donation-form');

            $shared = $root . '/assets/_shared';
            if (is_dir($shared)) {
                @mkdir($dir . '/_shared', 0777, true);
                $this->copyModules($shared, $dir . '/_shared');
            }

            // run.mjs sits inside the copied form tree, so a snippet importing
            // ./util/format.mjs means the same thing it does in the real one.
            $entryPath = './' . preg_replace('/\.js$/', '.mjs', $entry);
            file_put_contents(
                $dir . '/donation-form/run.mjs',
                "import * as mod from '" . $entryPath . "';\n"
                . "const emit = ( value ) => console.log( JSON.stringify( value ) );\n"
                . $snippet . "\n"
            );
            $raw = (string) shell_exec(
                escapeshellarg($node) . ' ' . escapeshellarg($dir . '/donation-form/run.mjs') . ' 2>&1'
            );
        } finally {
            $this->removeTree($dir);
        }

        $decoded = json_decode(trim($raw), true);
        $this->assertIsArray($decoded, 'node did not return a result: ' . $raw);

        return $decoded;
    }

    private function copyModules(string $src, string $dest, string $rel = ''): void
    {
        foreach (scandir($src . '/' . $rel) ?: [] as $name) {
            if ($name === '.' || $name === '..') continue;
            $path = ($rel === '' ? '' : $rel . '/') . $name;
            if (is_dir($src . '/' . $path)) {
                @mkdir($dest . '/' . $path, 0777, true);
                $this->copyModules($src, $dest, $path);
                continue;
            }
            if (! str_ends_with($name, '.js')) continue;
            file_put_contents(
                $dest . '/' . preg_replace('/\.js$/', '.mjs', $path),
                preg_replace(
                    "/from '(\\.[^']*)'/",
                    "from '$1.mjs'",
                    (string) file_get_contents($src . '/' . $path)
                )
            );
        }
    }

    private function removeTree(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..') continue;
            $path = $dir . '/' . $name;
            is_dir($path) ? $this->removeTree($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
