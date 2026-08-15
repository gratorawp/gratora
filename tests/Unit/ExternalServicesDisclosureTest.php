<?php

declare(strict_types=1);

namespace Dono\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * readme.txt's External services section is what a reviewer, and any admin
 * running a donation site under the GDPR, reads to learn who else sees donor
 * data. It opens by saying a fresh install talks to no one, which only holds
 * while every service the plugin can reach is listed with the switch that turns
 * it on.
 *
 * Gravatar is the one host the plugin can cause a request to without owning the
 * request: avatars go through core's get_avatar_url, so WordPress decides
 * whether anything leaves and under whose settings. That is why the section has
 * no Gravatar entry, and why the check below is inverted.
 */
final class ExternalServicesDisclosureTest extends TestCase
{
    /**
     * A gravatar.com host with a scheme or a protocol-relative prefix, which is
     * what a URL somebody assembled looks like. Prose that merely names the
     * service, in a comment or in admin copy, does not match.
     */
    private const GRAVATAR_URL = '#(?:https?:)?//[A-Za-z0-9.-]*gravatar\.com#i';

    /**
     * Hosts that appear in source without the plugin ever requesting them, each
     * with the reason it is not a disclosure. Anything not listed here has to be
     * named in the section.
     */
    private const NOT_A_REQUEST = [
        'www.w3.org'    => 'the SVG namespace identifier, which is never dereferenced',
        'wordpress.org' => 'a link an admin clicks, not a request this plugin makes',
        'example.org'   => 'placeholder copy in the email-token preview',
    ];

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

    /** The section only, so a mention elsewhere in the readme cannot pass for a disclosure. */
    private function section(): string
    {
        $matched = preg_match(
            '/^== External services ==$(.*?)^== /ms',
            $this->readme(),
            $m
        );
        $this->assertSame(1, $matched, 'readme.txt has no External services section.');

        return (string) $m[1];
    }

    /** @return list<string> one block per **Service** entry */
    private function entries(): array
    {
        $blocks = preg_split('/\n(?=\*\*)/', trim($this->section()));
        $blocks = array_values(array_filter(
            $blocks,
            static fn (string $b): bool => str_starts_with(trim($b), '**')
        ));

        $this->assertNotSame([], $blocks, 'the section lists no services at all.');

        return $blocks;
    }

    /**
     * The section lists services this plugin decides to contact. Avatars are
     * not one: the plugin hands an address to get_avatar_url and core builds
     * whatever URL the site's own avatar settings and filters call for, which
     * a privacy plugin or a host theme may already have replaced.
     *
     * Assemble a gravatar.com URL by hand and that stops being true. The
     * request becomes this plugin's, it outlives the site's avatar settings,
     * and a disclosure is owed. So nothing here asserts the section mentions
     * Gravatar; this asserts that nobody wrote the line that would owe one.
     */
    public function test_no_gravatar_url_is_assembled_outside_core(): void
    {
        $avatars = (string) file_get_contents($this->root() . '/src/Donors/DonorAvatars.php');
        $this->assertStringContainsString(
            'get_avatar_url(',
            $avatars,
            'Donor avatars no longer go through core, so the section owes Gravatar an entry of its own.'
        );

        $offenders = [];

        // String literals only. The reasoning above is written in comments that
        // name the host, and a comment sends no request.
        foreach ($this->sources($this->root() . '/src', ['php']) as $path) {
            foreach (token_get_all((string) file_get_contents($path)) as $token) {
                if (! is_array($token)) {
                    continue;
                }
                if (! in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_INLINE_HTML], true)) {
                    continue;
                }
                if (preg_match(self::GRAVATAR_URL, (string) $token[1]) === 1) {
                    $offenders[] = $path . ':' . $token[2];
                }
            }
        }

        // The browser can reach the host directly too, and no PHP would show it.
        foreach ($this->sources($this->root() . '/assets', ['js', 'jsx']) as $path) {
            foreach (preg_split('/\R/', (string) file_get_contents($path)) ?: [] as $i => $line) {
                if (preg_match(self::GRAVATAR_URL, $line) === 1) {
                    $offenders[] = $path . ':' . ($i + 1);
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "These build a Gravatar URL instead of asking core for one, so the request is ours to disclose:\n"
                . implode("\n", $offenders)
        );
    }

    /**
     * @param  list<string> $extensions
     * @return list<string>
     */
    private function sources(string $dir, array $extensions): array
    {
        $out = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
        foreach ($files as $file) {
            if ($file->isFile() && in_array($file->getExtension(), $extensions, true)) {
                $out[] = $file->getPathname();
            }
        }

        $this->assertNotSame([], $out, "$dir holds no source, so this test is reading nothing.");

        return $out;
    }

    /**
     * The section is only as true as its host list, and a host is easy to get
     * wrong in the direction that matters: an admin allowlisting egress from the
     * section, or an org answering where donor data goes, is told about a host
     * that is not the one the request lands on.
     *
     * So this reads the hosts back out of the code rather than trusting prose.
     * Every scheme-qualified host under src/ and assets/ must be named in the
     * section or excused above, which also means adding a gateway without
     * disclosing it fails here rather than at submission.
     */
    public function test_every_host_the_code_names_is_disclosed_or_excused(): void
    {
        $section = $this->section();
        $hosts   = [];

        $files = array_merge(
            $this->sources($this->root() . '/src', ['php']),
            $this->sources($this->root() . '/assets', ['js', 'jsx', 'css', 'scss'])
        );

        foreach ($files as $path) {
            preg_match_all(
                '#https?://([A-Za-z0-9](?:[A-Za-z0-9.-]*[A-Za-z0-9])?\.[A-Za-z]{2,})#',
                (string) file_get_contents($path),
                $m
            );
            foreach ($m[1] as $host) {
                $hosts[strtolower($host)][] = $path;
            }
        }

        $this->assertNotSame([], $hosts, 'no host was found at all, so this test is reading nothing.');

        $undisclosed = [];
        foreach ($hosts as $host => $paths) {
            if (isset(self::NOT_A_REQUEST[$host]) || str_contains($section, $host)) {
                continue;
            }
            $undisclosed[] = $host . ' (' . $paths[0] . ')';
        }

        sort($undisclosed);

        $this->assertSame(
            [],
            $undisclosed,
            "The code can reach these hosts and the External services section does not name them:\n"
                . implode("\n", $undisclosed)
        );
    }

    /**
     * A host that only ever appears as a redirect target is the case prose gets
     * wrong most easily, because the endpoint in the code reads correct and the
     * request still lands somewhere else. Frankfurter is that case today.
     */
    public function test_the_fx_endpoint_host_and_where_it_redirects_are_both_named(): void
    {
        $updater = (string) file_get_contents($this->root() . '/src/Currency/FxRatesUpdater.php');

        $matched = preg_match('#https?://([A-Za-z0-9.-]+)/#', $updater, $m);
        $this->assertSame(1, $matched, 'FxRatesUpdater names no endpoint, so nothing here is checked.');

        $section = $this->section();

        $this->assertStringContainsString(
            $m[1],
            $section,
            'The rates endpoint is not the host the External services section names.'
        );

        // api.frankfurter.app answers 301 to this, and wp_remote_get follows it,
        // so an egress allowlist built from the section alone silently fails.
        $this->assertStringContainsString(
            'api.frankfurter.dev',
            $section,
            'The section omits the host the rates request actually lands on.'
        );
    }

    /**
     * The section promises a fresh install contacts nobody, so an entry that is
     * on by default would make the whole section wrong.
     */
    public function test_the_gravatar_setting_is_off_until_an_admin_turns_it_on(): void
    {
        $settings = (string) file_get_contents($this->root() . '/src/Settings/SettingsService.php');

        $this->assertMatchesRegularExpression(
            "/'gravatar_avatars'\s*=>\s*false/",
            $settings,
            'Donor pictures would be fetched from Gravatar on a site that never asked for it.'
        );
    }

    /** An org's DPA needs the other party's own terms, so every entry carries them. */
    public function test_every_service_links_its_terms_or_privacy_policy(): void
    {
        $offenders = [];
        foreach ($this->entries() as $entry) {
            $hasLink = preg_match('/https?:\/\//', $entry) === 1;
            $hasTerms = preg_match('/terms|privacy/i', $entry) === 1;

            if ($hasLink && $hasTerms) {
                continue;
            }

            $offenders[] = trim(explode("\n", $entry)[0]);
        }

        $this->assertSame(
            [],
            $offenders,
            "Every external service entry links its terms or privacy policy:\n" . implode("\n", $offenders)
        );
    }
}
