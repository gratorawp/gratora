<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Admin\AdminFooter;

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
        // The dashboard slug is the bare "gratora", so a prefix-only match
        // would skip the first screen a new install opens.
        $this->assertStringContainsString('wordpress.org', $this->footerOn('gratora'));
    }

    public function test_every_other_gratora_screen_gets_it(): void
    {
        foreach (['gratora-campaigns', 'gratora-donations', 'gratora-donors', 'gratora-forms', 'gratora-funds', 'gratora-settings', 'gratora-tools'] as $page) {
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
        $out = $this->footerOn('gratora');

        $this->assertSame(5, substr_count($out, 'dashicons-star-filled'));
        $this->assertStringContainsString('rate=5', $out);
    }

    public function test_the_link_points_at_the_assigned_permalink(): void
    {
        $this->assertStringContainsString(
            'https://wordpress.org/support/plugin/gratora/reviews/?rate=5#new-post',
            $this->footerOn('gratora')
        );
    }

    /**
     * The slug has to match the text domain, which has to match the folder the
     * zip installs to. Reading it off a header keeps the three from drifting
     * apart silently, which is how the link ends up 404ing after a rename.
     */
    public function test_the_slug_matches_the_plugins_text_domain(): void
    {
        $header = get_file_data(GRATORA_FILE, ['TextDomain' => 'Text Domain']);

        $this->assertSame(
            $header['TextDomain'],
            'gratora',
            'the review link slug and the Text Domain header have drifted apart'
        );
    }

    public function test_it_does_not_touch_the_wordpress_version_slot(): void
    {
        // has_filter() is the wrong probe here: core registers its own
        // core_update_footer. Read what this provider declares instead.
        $filters = (new \ReflectionMethod(AdminFooter::class, 'filters'));
        $filters->setAccessible(true);

        $this->assertSame(['admin_footer_text'], array_keys($filters->invoke(new AdminFooter())));
    }
}
