<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Admin\ExtensionAssets;

/**
 * The extension-panel seam.
 *
 * Each surface name is a published contract: an add-on registers against a
 * string, and if core stops firing that string the add-on's panel silently
 * stops appearing with no error anywhere. Renaming one is a breaking change to
 * every add-on, so the names are pinned here rather than left to whichever
 * page happens to call enqueue().
 */
final class ExtensionSurfacesTest extends IntegrationTestCase
{
    /** @var array<int, string> */
    private array $fired = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->fired = [];
        add_action(ExtensionAssets::ACTION, function (string $surface): void {
            $this->fired[] = $surface;
        });
    }

    /** @return array<string, array{0: string}> */
    public static function surfaces(): array
    {
        return [
            'campaign'          => ['campaign'],
            'campaign settings' => ['campaign-settings'],
            'portal'            => ['portal'],
            'donation'          => ['donation'],
            'donor'             => ['donor'],
        ];
    }

    /**
     * @dataProvider surfaces
     */
    public function test_a_surface_lets_add_ons_enqueue_against_it(string $surface): void
    {
        ExtensionAssets::enqueue($surface);

        $this->assertSame([$surface], $this->fired);
    }

    /** The registry script must exist before an app tries to read it. */
    public function test_enqueueing_a_surface_registers_the_registry(): void
    {
        ExtensionAssets::enqueue('donation');

        $this->assertTrue(wp_script_is(ExtensionAssets::HANDLE, 'registered'));
        $this->assertTrue(wp_script_is(ExtensionAssets::HANDLE, 'enqueued'));
    }

    /**
     * Two surfaces on one screen is the campaigns page's arrangement, so
     * registering twice must not blow up or re-register.
     */
    public function test_two_surfaces_can_share_a_screen(): void
    {
        ExtensionAssets::enqueue('campaign');
        ExtensionAssets::enqueue('campaign-settings');

        $this->assertSame(['campaign', 'campaign-settings'], $this->fired);
    }

    /**
     * The donations page depends on the registry handle, so the registry is
     * defined before the app reads it. Without the dependency the app can
     * render before window.dono.tabs exists and show nothing.
     */
    public function test_the_donations_app_depends_on_the_registry(): void
    {
        global $wp_scripts;

        $page = new \Dono\Admin\Pages\DonationsPage();
        set_current_screen('dono_page_dono-donations');
        wp_set_current_user(1);

        ob_start();
        $page->render();
        ob_end_clean();

        $registered = $wp_scripts->registered['dono-admin-donations'] ?? null;
        $this->assertNotNull($registered, 'The donations bundle should be registered.');
        $this->assertContains(ExtensionAssets::HANDLE, $registered->deps);
    }

    /**
     * The donor surface, for add-ons that hold something about a person rather
     * than about a payment: a Gift Aid declaration, a communication preference.
     * Same dependency requirement as the donations app.
     */
    public function test_the_donors_app_depends_on_the_registry(): void
    {
        global $wp_scripts;

        $page = new \Dono\Admin\Pages\DonorsPage();
        set_current_screen('dono_page_dono-donors');
        wp_set_current_user(1);

        ob_start();
        $page->render();
        ob_end_clean();

        $registered = $wp_scripts->registered['dono-admin-donors'] ?? null;
        $this->assertNotNull($registered, 'The donors bundle should be registered.');
        $this->assertContains(ExtensionAssets::HANDLE, $registered->deps);
        $this->assertContains('donor', $this->fired);
    }
}
