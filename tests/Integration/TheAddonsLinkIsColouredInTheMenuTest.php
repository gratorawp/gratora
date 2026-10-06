<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Admin\Addons\AddonsCatalog;
use Gratora\Admin\Pages\AddonsPage;
use Gratora\Foundation\License\LicenseService;
use Gratora\Foundation\Time\SystemClock;
use WP_Styles;

/**
 * The menu is on every admin screen, so the style that colours its Add-ons
 * link has to be too, and only for someone the link is shown to.
 */
final class TheAddonsLinkIsColouredInTheMenuTest extends IntegrationTestCase
{
    private ?WP_Styles $stylesBefore = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stylesBefore   = $GLOBALS['wp_styles'] ?? null;
        $GLOBALS['wp_styles'] = new WP_Styles();
        (new AddonsPage(new AddonsCatalog(new SystemClock()), new LicenseService()))->register();
    }

    protected function tearDown(): void
    {
        $GLOBALS['wp_styles'] = $this->stylesBefore;
        parent::tearDown();
    }

    private function stylesOfTheDashboard(): string
    {
        set_current_screen('dashboard');
        do_action('admin_enqueue_scripts', 'index.php');

        ob_start();
        wp_styles()->do_items();

        return (string) ob_get_clean();
    }

    public function test_a_screen_outside_the_plugin_carries_the_colour_of_the_link(): void
    {
        $this->assertStringContainsString('page=gratora-addons', $this->stylesOfTheDashboard());
    }

    public function test_someone_the_link_is_not_shown_to_gets_no_style_for_it(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));

        $this->assertStringNotContainsString('page=gratora-addons', $this->stylesOfTheDashboard());
    }
}
