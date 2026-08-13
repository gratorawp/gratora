<?php

declare(strict_types=1);

namespace Dono\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * readme.txt is the submission. Everything the WordPress.org directory decides
 * about this plugin before a human opens a PHP file comes from its header and
 * its prose, and two claims in it are ones a checkout can verify.
 *
 * The first is Plugin Guideline 4: the zip carries compiled JavaScript and CSS
 * in build/, which is only allowed when the readable source is reachable, and
 * the only thing in the payload that can point at it is readme.txt. The second
 * is the bundled fonts. Dompdf ships DejaVu without its notice, and the
 * Bitstream Vera terms require the notice to travel with every copy, so the
 * file readme.txt names has to be a file that exists.
 *
 * The header fields are pinned against the plugin header and composer.json
 * because a disagreement between them is a rejection, not a bug report.
 */
final class ReadmeDirectorySubmissionTest extends TestCase
{
    private function root(): string
    {
        return dirname(__DIR__, 2);
    }

    private function readme(): string
    {
        $path = $this->root() . '/readme.txt';
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /** @return array<string, string> the header block above the short description */
    private function headers(): array
    {
        preg_match('/^=== .+ ===$(.*?)^\s*$/ms', $this->readme(), $m);
        $this->assertNotEmpty($m, 'readme.txt has no header block.');

        $headers = [];
        foreach (preg_split('/\R/', trim((string) $m[1])) ?: [] as $line) {
            if (preg_match('/^([A-Za-z ]+):\s*(.+)$/', trim($line), $pair) === 1) {
                $headers[$pair[1]] = trim($pair[2]);
            }
        }

        return $headers;
    }

    private function sourceSection(): string
    {
        $matched = preg_match('/^== Source code ==$(.*?)^== /ms', $this->readme(), $m);
        $this->assertSame(
            1,
            $matched,
            'readme.txt has no Source code section, so nothing in the zip says where build/ came from.'
        );

        return (string) $m[1];
    }

    public function test_the_zip_ships_compiled_assets_so_the_readme_has_to_name_their_repository(): void
    {
        $compiled = glob($this->root() . '/build/*/*/*.js') ?: [];
        $this->assertNotSame(
            [],
            $compiled,
            'No compiled assets ship, so this test is measuring a rule that no longer applies.'
        );

        $this->assertMatchesRegularExpression(
            '#https://github\.com/[A-Za-z0-9._-]+/[A-Za-z0-9._-]+#',
            $this->sourceSection(),
            'The Source code section names no repository, which is what Guideline 4 asks for.'
        );
    }

    /**
     * A build instruction nobody can run is worse than none: it reads as
     * compliance and sends a reviewer somewhere that does not exist.
     */
    public function test_every_path_the_source_section_names_is_a_path_that_exists(): void
    {
        preg_match_all('/`([^`]+)`/', $this->sourceSection(), $m);
        $quoted = array_filter(
            $m[1],
            static fn (string $q): bool => ! str_contains($q, ' ') && ! str_starts_with($q, 'npm')
        );

        $this->assertNotSame([], $quoted, 'The Source code section names no paths at all.');

        $missing = [];
        foreach ($quoted as $rel) {
            if (! file_exists($this->root() . '/' . rtrim($rel, '/'))) {
                $missing[] = $rel;
            }
        }

        $this->assertSame([], $missing, "readme.txt points at paths the plugin does not have:\n" . implode("\n", $missing));
    }

    public function test_the_build_command_the_source_section_gives_is_a_real_npm_script(): void
    {
        $matched = preg_match('/npm run ([a-z:-]+)/', $this->sourceSection(), $m);
        $this->assertSame(1, $matched, 'The Source code section gives no build command.');

        $package = json_decode(
            (string) file_get_contents($this->root() . '/package.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $this->assertArrayHasKey(
            $m[1],
            $package['scripts'] ?? [],
            "readme.txt tells a reviewer to run `npm run {$m[1]}`, which package.json does not define."
        );
    }

    /**
     * The fonts are the one bundled asset whose licence is not GPL and not
     * carried by the package that ships it.
     */
    public function test_the_bundled_font_licence_is_reproduced_where_the_readme_says_it_is(): void
    {
        $matched = preg_match('/`(licenses\/[^`]+)`/', $this->sourceSection(), $m);
        $this->assertSame(1, $matched, 'readme.txt does not say where the font licence is reproduced.');

        $notice = (string) file_get_contents($this->root() . '/' . $m[1]);

        $this->assertStringContainsString('Bitstream Vera Fonts Copyright', $notice);
        $this->assertStringContainsString('Arev Fonts Copyright', $notice);
    }

    public function test_the_header_agrees_with_the_plugin_file_and_composer(): void
    {
        $headers = $this->headers();
        $plugin  = (string) file_get_contents($this->root() . '/dono.php');
        $composer = json_decode(
            (string) file_get_contents($this->root() . '/composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        preg_match('/^\s*\*\s*Version:\s*(\S+)$/m', $plugin, $version);
        $this->assertSame(
            $version[1] ?? null,
            $headers['Stable tag'] ?? null,
            'Stable tag and the plugin Version have to be the same release.'
        );

        preg_match('/^\s*\*\s*Requires PHP:\s*(\S+)$/m', $plugin, $php);
        $this->assertSame($php[1] ?? null, $headers['Requires PHP'] ?? null);
        $this->assertStringContainsString(
            (string) ($headers['Requires PHP'] ?? ''),
            (string) ($composer['require']['php'] ?? ''),
            'composer.json and readme.txt disagree about the oldest PHP this runs on.'
        );

        preg_match('/^\s*\*\s*Requires at least:\s*(\S+)$/m', $plugin, $wp);
        $this->assertSame($wp[1] ?? null, $headers['Requires at least'] ?? null);

        $this->assertSame($composer['license'] ?? null, $headers['License'] ?? null);
    }

    /** The directory keeps five tags and truncates a short description at 150 characters. */
    public function test_the_tags_and_short_description_fit_what_the_directory_shows(): void
    {
        $tags = array_filter(array_map('trim', explode(',', $this->headers()['Tags'] ?? '')));
        $this->assertLessThanOrEqual(5, count($tags), 'Tags past the fifth are dropped.');

        $matched = preg_match('/^\s*$\n(.+)$/m', explode('== Description ==', $this->readme())[0], $m);
        $this->assertSame(1, $matched, 'readme.txt has no short description.');
        $this->assertLessThanOrEqual(150, strlen(trim($m[1])));
    }
}
