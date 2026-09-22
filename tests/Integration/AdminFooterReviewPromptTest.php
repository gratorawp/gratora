<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Admin\AdminFooter;
use ReflectionMethod;

/**
 * admin_footer_text is global: every plugin that hooks it is fighting for one
 * line on every screen in wp-admin. Ours has to leave the other screens exactly
 * as it found them.
 */
final class AdminFooterReviewPromptTest extends IntegrationTestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['plugin_page']);
        parent::tearDown();
    }

    private function footerOn(?string $page, string $original = 'ORIGINAL'): string
    {
        if ($page === null) {
            unset($GLOBALS['plugin_page']);
        } else {
            $GLOBALS['plugin_page'] = $page;
        }

        return (new AdminFooter())->reviewPrompt($original);
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

    public function test_the_prompt_shows_five_stars(): void
    {
        $this->assertSame(5, substr_count($this->footerOn('gratora'), 'dashicons-star-filled'));
    }

    /**
     * Every review, not a view filtered to the favourable ones: the directory
     * refuses a plugin that links to reviews narrowed by rating.
     */
    public function test_the_link_opens_every_review_on_the_assigned_permalink(): void
    {
        $out = $this->footerOn('gratora');

        $this->assertStringContainsString('href="https://wordpress.org/support/plugin/gratora-donation-platform/reviews/"', $out);
        $this->assertStringNotContainsString('rate=', $out);
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
            'gratora-donation-platform',
            'the review link slug and the Text Domain header have drifted apart'
        );
    }

    public function test_it_does_not_touch_the_wordpress_version_slot(): void
    {
        // has_filter() is the wrong probe here: core registers its own
        // core_update_footer. Read what this provider declares instead.
        $filters = (new ReflectionMethod(AdminFooter::class, 'filters'));
        $filters->setAccessible(true);

        $this->assertSame(['admin_footer_text'], array_keys($filters->invoke(new AdminFooter())));
    }
}
