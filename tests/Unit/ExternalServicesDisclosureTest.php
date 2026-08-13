<?php

declare(strict_types=1);

namespace Dono\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * readme.txt's External services section is what a reviewer, and any admin
 * running a donation site under the GDPR, reads to learn who else sees donor
 * data. It opens by saying a fresh install talks to no one, which only holds
 * while every service the plugin can reach is listed with the switch that turns
 * it on.
 *
 * Gravatar was missed because no gravatar.com literal exists in the source: the
 * URL is built by WordPress, from a hash of the donor's email, and requested by
 * the visitor's browser on a public campaign page. So the plugin's use of
 * get_avatar_url is what this test reads, not a string it could grep for.
 */
final class ExternalServicesDisclosureTest extends TestCase
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

    public function test_gravatar_is_disclosed_because_the_plugin_asks_for_avatar_urls(): void
    {
        $avatars = (string) file_get_contents($this->root() . '/src/Donors/DonorAvatars.php');

        $this->assertStringContainsString(
            'get_avatar_url(',
            $avatars,
            'Donor avatars no longer reach Gravatar, so this test is measuring nothing.'
        );

        $this->assertStringContainsStringIgnoringCase(
            'gravatar',
            $this->section(),
            'Donor pictures can be loaded from Gravatar, which the section has to say.'
        );
    }

    /** What is sent, and the fact that the donor's browser is what sends it. */
    public function test_the_gravatar_entry_says_what_leaves_the_page(): void
    {
        $matched = preg_match('/\*\*Gravatar\*\*.*?(?=\n\*\*|\z)/s', $this->section(), $m);
        $this->assertSame(1, $matched, 'the Gravatar entry is not a service entry of its own.');

        $entry = (string) $m[0];

        $this->assertStringContainsString('secure.gravatar.com', $entry, 'the host is named');
        $this->assertStringContainsStringIgnoringCase('hash', $entry, 'and that a hash is what is sent');
        $this->assertStringContainsStringIgnoringCase('email', $entry, 'of the donor email');
        $this->assertStringContainsStringIgnoringCase('browser', $entry, 'from the visitor browser');
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

            if ($hasLink && $hasTerms) continue;

            $offenders[] = trim(explode("\n", $entry)[0]);
        }

        $this->assertSame(
            [],
            $offenders,
            "Every external service entry links its terms or privacy policy:\n" . implode("\n", $offenders)
        );
    }
}
