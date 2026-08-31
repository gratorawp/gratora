<?php

declare(strict_types=1);

namespace FundKit\Tests\Integration;

use FundKit\Admin\AdminFooter;

/**
 * admin_footer_text is global: every plugin that hooks it is fighting for one
 * line on every screen in wp-admin. Ours has to leave the other screens exactly
 * as it found them.
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
    public function test_the_link_is_a_directory_url_and_not_a_filesystem_path(): void
    {
        $out = $this->footerOn('fundkit');

        $this->assertMatchesRegularExpression('#https://wordpress\.org/support/plugin/[a-z0-9-]+/reviews/\?rate=5#', $out);
        $this->assertStringNotContainsString('%2F', $out);
        $this->assertStringNotContainsString('%20', $out);
    }

    /**
     * update_footer is core's, and it carries the WordPress version. Taking it
     * for a plugin version is the kind of thing that gets a footer filter
     * removed by the site owner.
     */
    public function test_it_does_not_touch_the_wordpress_version_slot(): void
    {
        // has_filter() is the wrong probe here: core registers its own
        // core_update_footer. Read what this provider declares instead.
        $filters = (new \ReflectionMethod(AdminFooter::class, 'filters'));
        $filters->setAccessible(true);

        $this->assertSame(['admin_footer_text'], array_keys($filters->invoke(new AdminFooter())));
    }
}
