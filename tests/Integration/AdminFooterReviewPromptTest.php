<?php

declare(strict_types=1);

namespace FundKit\Tests\Integration;

use FundKit\Admin\AdminFooter;

/**
 * The footer filters are global: every plugin that hooks them is fighting for
 * one line on every screen in wp-admin. Ours has to leave the other screens
 * exactly as it found them.
 */
final class AdminFooterReviewPromptTest extends IntegrationTestCase
{
    private function footerOn(?string $page, string $original = 'ORIGINAL'): string
    {
        if ($page === null) {
            unset($_GET['page']);
        } else {
            $_GET['page'] = $page;
        }

        $out = (new AdminFooter())->reviewPrompt($original);

        unset($_GET['page']);

        return $out;
    }

    private function versionOn(?string $page, string $original = 'ORIGINAL'): string
    {
        if ($page === null) {
            unset($_GET['page']);
        } else {
            $_GET['page'] = $page;
        }

        $out = (new AdminFooter())->version($original);

        unset($_GET['page']);

        return $out;
    }

    public function test_the_dashboard_gets_the_prompt(): void
    {
        // The dashboard slug is the bare "fundkit", so a prefix-only match
        // would skip the first screen a new install opens.
        $this->assertStringContainsString('wordpress.org', $this->footerOn('fundkit'));
    }

    public function test_every_other_fundkit_screen_gets_it(): void
    {
        foreach (['fundkit-campaigns', 'fundkit-donations', 'fundkit-donors', 'fundkit-forms', 'fundkit-funds', 'fundkit-settings', 'fundkit-tools'] as $page) {
            $this->assertStringContainsString('wordpress.org', $this->footerOn($page), "{$page} should carry the prompt");
        }
    }

    public function test_it_leaves_every_other_screen_alone(): void
    {
        foreach ([null, '', 'wc-settings', 'givewp-donations', 'donations', 'metropolis'] as $page) {
            $this->assertSame('ORIGINAL', $this->footerOn($page), var_export($page, true) . ' should keep its own footer');
            $this->assertSame('ORIGINAL', $this->versionOn($page), var_export($page, true) . ' should keep its own version slot');
        }
    }

    public function test_the_prompt_asks_for_five_stars(): void
    {
        $out = $this->footerOn('fundkit');

        $this->assertSame(5, substr_count($out, 'dashicons-star-filled'));
        $this->assertStringContainsString('rate=5', $out);
    }

    /**
     * The slug is derived from where the plugin sits on disk, and the first
     * attempt at that emitted the whole absolute path into the href. Assert the
     * shape of a directory slug rather than recomputing it, so the test can
     * still fail when the derivation is wrong.
     */
    public function test_the_links_are_directory_urls_and_not_a_filesystem_path(): void
    {
        $out = $this->footerOn('fundkit');

        $this->assertMatchesRegularExpression('#https://wordpress\.org/plugins/[a-z0-9-]+/#', $out);
        $this->assertMatchesRegularExpression('#https://wordpress\.org/support/plugin/[a-z0-9-]+/reviews/\?rate=5#', $out);
        $this->assertStringNotContainsString('%2F', $out);
        $this->assertStringNotContainsString('%20', $out);
    }

    public function test_both_links_name_the_same_slug(): void
    {
        $out = $this->footerOn('fundkit');

        preg_match('#/plugins/([a-z0-9-]+)/#', $out, $plugin);
        preg_match('#/support/plugin/([a-z0-9-]+)/#', $out, $support);

        $this->assertNotEmpty($plugin[1] ?? '');
        $this->assertSame($plugin[1], $support[1] ?? '');
    }

    public function test_the_version_slot_names_the_running_version(): void
    {
        $this->assertStringContainsString(FUNDKIT_VERSION, $this->versionOn('fundkit'));
    }
}
