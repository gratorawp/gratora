<?php

declare(strict_types=1);

namespace FundKit\Tests\Integration;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Keep untrusted input out of PHP deserialization. Any required sink must accept only
 * plugin-written values with allowed_classes=false.
 */
final class NoPhpDeserializationTest extends IntegrationTestCase
{
    /** @return list<string> "path:line  code" for each sink found */
    private function sinks(string $dir): array
    {
        $found = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $lines = file($file->getPathname()) ?: [];
            foreach ($lines as $i => $line) {
                // The call, not the word: a comment naming it is how the rule
                // gets explained, and this test's own docblock would trip it.
                if (preg_match('/(?<![\w$>])(maybe_)?unserialize\s*\(/', $line)) {
                    $found[] = str_replace(FUNDKIT_DIR, '', $file->getPathname())
                        . ':' . ($i + 1) . '  ' . trim($line);
                }
            }
        }

        return $found;
    }

    public function test_the_plugin_never_deserializes_php(): void
    {
        $this->assertSame(
            [],
            $this->sinks(FUNDKIT_DIR . 'src'),
            "PHP deserialization reached the source. If the value is attacker-influenced this is
             object injection (CWE-502). Store JSON instead, or pass allowed_classes: false."
        );
    }

    public function test_the_detector_finds_a_sink(): void
    {
        $dir = get_temp_dir() . 'fundkit-deser-' . wp_generate_password(8, false);
        mkdir($dir);
        file_put_contents($dir . '/Sink.php', "<?php\n\$x = unserialize(\$untrusted);\n");
        file_put_contents($dir . '/Fine.php', "<?php\n// unserialize is never called here\n\$x = json_decode(\$s);\n");

        try {
            $hits = $this->sinks($dir);

            $this->assertCount(1, $hits, 'the detector missed a real call, or flagged a comment');
            $this->assertStringContainsString('Sink.php', $hits[0]);
        } finally {
            array_map('unlink', glob($dir . '/*.php') ?: []);
            rmdir($dir);
        }
    }
}
