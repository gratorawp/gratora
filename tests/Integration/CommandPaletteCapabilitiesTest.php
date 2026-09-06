<?php

declare(strict_types=1);

namespace FundKit\Tests\Integration;

use FundKit\Admin\AdminMenu;
use FundKit\Admin\Pages\CampaignsPage;
use FundKit\Admin\Pages\DonationsPage;
use FundKit\Admin\Pages\DonorsPage;
use FundKit\Admin\Pages\SettingsPage;

/**
 * The palette is enqueued on the umbrella capability, which any single area
 * cap grants. Without a per-destination answer it offers a bookkeeper the
 * donors, campaigns and settings screens, and following one costs them the
 * screen they were on for "Sorry, you are not allowed to access this page".
 */
final class CommandPaletteCapabilitiesTest extends IntegrationTestCase
{
    /**
     * CoreModule registers the pages behind is_admin(), which the suite runs
     * outside, so the filter they answer is empty here until they are booted.
     */
    protected function setUp(): void
    {
        parent::setUp();

        foreach ([DonationsPage::class, DonorsPage::class, CampaignsPage::class, SettingsPage::class] as $page) {
            (new $page())->register();
        }
    }

    /**
     * @return array<string, bool>
     */
    private function canFor(int $userId): array
    {
        wp_set_current_user($userId);

        // A second localize on a live handle appends another var of the same
        // name, so the decode below would have two objects to choose from.
        wp_deregister_script('fundkit-admin-command-palette');
        (new AdminMenu())->enqueueCommandPalette();

        $data = wp_scripts()->get_data('fundkit-admin-command-palette', 'data');
        $this->assertIsString($data, 'the palette script was not enqueued; run npm run build');

        preg_match('/var fundkitCommandPalette = (.*);/', $data, $m);
        $payload = json_decode($m[1] ?? '', true);

        return $payload['can'] ?? [];
    }

    public function test_a_bookkeeper_is_offered_only_the_donations_screen(): void
    {
        $user = self::factory()->user->create_and_get(['role' => 'subscriber']);
        $user->add_cap('fundkit_view_donations');

        $can = $this->canFor($user->ID);

        $this->assertTrue($can['fundkit-donations']);
        $this->assertFalse($can['fundkit-donors']);
        $this->assertFalse($can['fundkit-settings']);
        $this->assertFalse($can['fundkit-campaigns']);
    }

    public function test_an_administrator_is_offered_all_of_them(): void
    {
        $can = $this->canFor(self::factory()->user->create(['role' => 'administrator']));

        $this->assertNotEmpty($can);
        foreach ($can as $page => $allowed) {
            $this->assertTrue($allowed, "{$page} should be reachable by an administrator");
        }
    }

    /**
     * The dashboard forwards a reader who cannot hold its data to the first
     * page they can open, so it is a redirect rather than a refusal.
     */
    public function test_the_dashboard_stays_offered(): void
    {
        $user = self::factory()->user->create_and_get(['role' => 'subscriber']);
        $user->add_cap('fundkit_view_donations');

        $this->assertTrue($this->canFor($user->ID)['fundkit']);
    }
}
