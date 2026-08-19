<?php

declare(strict_types=1);

namespace Dono\Tests\Unit;

use Dono\Tests\Unit\Support\DistPayload;
use PHPUnit\Framework\TestCase;

/**
 * readme.txt is the submission. Everything the WordPress.org directory decides
 * about this plugin before a human opens a PHP file comes from its header and
 * its prose, and most of what it claims is checkable from a checkout.
 *
 * The header fields are pinned against the plugin header and composer.json
 * because a disagreement between them is a rejection, not a bug report.
 *
 * Plugin Guideline 4 is answered by prose: the zip carries compiled JavaScript
 * in build/, and readme.txt names the public repository holding the sources it
 * was built from. That sentence is payload, so it is pinned like payload.
 */
final class ReadmeDirectorySubmissionTest extends TestCase
{
    /**
     * The one bundled asset whose licence is neither GPL nor carried by the
     * package that ships it. Dompdf brings DejaVu without its notice, and the
     * Bitstream Vera terms require the notice to travel with every copy.
     */

    /**
     * The compiled output, and the four files enqueued straight from assets/
     * without passing through the build. A .distignore rule taking assets/
     * wholesale strips the campaign page's styling and two dialogs, and every
     * other test still passes.
     *
     * @var list<string>
     */
    private const RUNTIME_PAYLOAD = [
        'build',
        'assets/deactivation/dialog.css',
        'assets/deactivation/dialog.js',
        'assets/donate-button/modal.js',
        'assets/campaign-page/page.css',
    ];

    /** Where readme.txt sends a reviewer for the sources behind build/. */
    private const REPOSITORY = 'https://github.com/dono-platform/dono';

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

    /**
     * The one build dependency that is not on npm, as `owner/repo#ref`.
     *
     * @return array{owner: string, repo: string, ref: string}|null
     */
    private function gitDependency(): ?array
    {
        $package = json_decode(
            (string) file_get_contents($this->root() . '/package.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        foreach ($package['dependencies'] ?? [] as $spec) {
            if (preg_match('~^github:([^/]+)/([^#]+)#(.+)$~', (string) $spec, $m) === 1) {
                return ['owner' => $m[1], 'repo' => $m[2], 'ref' => $m[3]];
            }
        }

        return null;
    }

    /** @return int the status a HEAD request to $url answers with */
    private function statusOf(string $url): int
    {
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_NOBODY         => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_RETURNTRANSFER => true,
        ]);
        curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        return $status;
    }

    /**
     * A private repository answers 404 to the reviewer exactly as a deleted one
     * does, and the readme reads the same either way. Naming the URL is half the
     * obligation; the other half is only observable from outside.
     *
     * Opt-in because it needs the network and says nothing without it. Run with
     * DONO_NETWORK_TESTS=1 before submitting.
     */
    public function test_the_repository_the_readme_names_is_reachable_to_a_stranger(): void
    {
        if (getenv('DONO_NETWORK_TESTS') !== '1') {
            $this->markTestSkipped('set DONO_NETWORK_TESTS=1 to check the repository against the network');
        }

        if (! extension_loaded('curl')) {
            $this->markTestSkipped('no curl extension to ask with');
        }

        $this->assertSame(
            200,
            $this->statusOf(self::REPOSITORY),
            self::REPOSITORY . ' does not resolve for a logged-out visitor, so readme.txt sends a'
                . ' reviewer nowhere and nothing says where build/ came from.'
        );
    }

    /**
     * readme.txt sends a reviewer to the repository to rebuild build/, and one
     * of the dependencies package.json pins is fetched from GitHub at a tag
     * rather than from the registry. That makes the tag part of the build
     * instruction: delete it and `npm install` fails on the one line nobody
     * reads.
     *
     * Opt-in because it needs the network and says nothing without it. Run with
     * DONO_NETWORK_TESTS=1 before submitting.
     */
    public function test_the_tag_the_build_depends_on_is_still_published(): void
    {
        if (getenv('DONO_NETWORK_TESTS') !== '1') {
            $this->markTestSkipped('set DONO_NETWORK_TESTS=1 to check the build dependency against the network');
        }

        if (! extension_loaded('curl')) {
            $this->markTestSkipped('no curl extension to ask with');
        }

        $git = $this->gitDependency();
        if ($git === null) {
            $this->markTestSkipped('package.json pins nothing outside the npm registry, so no tag has to stay published');
        }

        $url = "https://github.com/{$git['owner']}/{$git['repo']}/tree/{$git['ref']}";

        $this->assertSame(
            200,
            $this->statusOf($url),
            "$url does not resolve, so `npm install` cannot fetch the build dependency."
        );
    }

    /**
     * Guideline 4 asks where the compiled JavaScript came from, and readme.txt
     * answering it is the whole reason the webpack input does not have to ship.
     * A copy edit that drops the URL takes the answer with it.
     */
    public function test_the_readme_names_the_repository_build_came_from(): void
    {
        $this->assertStringContainsString(
            self::REPOSITORY,
            $this->readme(),
            'readme.txt does not name the repository, so nothing says where build/ came from.'
        );
    }

    /**
     * bin/verify-zip.sh gates the same list, but only on a tag build, so a
     * .distignore rule that strips one of these reaches a reviewer before it
     * reaches a release. DistPackagingTest does not cover it either: that test
     * asserts the packager and the matcher agree, and both would agree about a
     * new rule.
     */
    public function test_the_files_the_plugin_loads_at_runtime_are_not_stripped(): void
    {
        $stripped = [];
        foreach (self::RUNTIME_PAYLOAD as $rel) {
            $this->assertFileExists(
                $this->root() . '/' . $rel,
                "$rel is not in the checkout, so it cannot be in the zip."
            );

            if (DistPayload::excluded($this->root(), $rel)) {
                $stripped[] = $rel;
            }
        }

        $this->assertSame(
            [],
            $stripped,
            ".distignore keeps these out of the zip, so the plugin ships without them:\n"
                . implode("\n", $stripped)
        );
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

        // Every pair below is asserted with assertSame, which is happiest when
        // both sides are missing. A deleted header is the failure this is for.
        foreach (['Stable tag', 'Requires PHP', 'Requires at least', 'License'] as $field) {
            $this->assertArrayHasKey($field, $headers, "readme.txt has no $field header.");
        }

        preg_match('/^\s*\*\s*Version:\s*(\S+)$/m', $plugin, $version);
        $this->assertNotEmpty($version, 'dono.php has no Version header.');
        $this->assertSame(
            $version[1],
            $headers['Stable tag'],
            'Stable tag and the plugin Version have to be the same release.'
        );

        preg_match('/^\s*\*\s*Requires PHP:\s*(\S+)$/m', $plugin, $php);
        $this->assertNotEmpty($php, 'dono.php has no Requires PHP header.');
        $this->assertSame($php[1], $headers['Requires PHP']);
        $this->assertStringContainsString(
            $headers['Requires PHP'],
            (string) ($composer['require']['php'] ?? ''),
            'composer.json and readme.txt disagree about the oldest PHP this runs on.'
        );

        preg_match('/^\s*\*\s*Requires at least:\s*(\S+)$/m', $plugin, $wp);
        $this->assertNotEmpty($wp, 'dono.php has no Requires at least header.');
        $this->assertSame($wp[1], $headers['Requires at least']);

        $this->assertSame($composer['license'] ?? null, $headers['License']);
    }

    /** The directory keeps five tags and truncates a short description at 150 characters. */
    public function test_the_tags_and_short_description_fit_what_the_directory_shows(): void
    {
        $tags = array_filter(array_map('trim', explode(',', $this->headers()['Tags'] ?? '')));
        $this->assertGreaterThan(0, count($tags), 'readme.txt has no Tags header, so the listing has no tags.');
        $this->assertLessThanOrEqual(5, count($tags), 'Tags past the fifth are dropped.');

        $matched = preg_match('/^\s*$\n(.+)$/m', explode('== Description ==', $this->readme())[0], $m);
        $this->assertSame(1, $matched, 'readme.txt has no short description.');
        $this->assertLessThanOrEqual(150, strlen(trim($m[1])));
    }
}
