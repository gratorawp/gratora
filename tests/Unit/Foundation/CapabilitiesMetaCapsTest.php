<?php

declare(strict_types=1);

namespace GiveFlow\Tests\Unit\Foundation;

use GiveFlow\Foundation\Auth\Capabilities;
use PHPUnit\Framework\TestCase;

/**
 * Pure logic of the admin-menu meta-caps (grantMetaCaps is a plain array
 * transform driven by Capabilities::MENU_AREAS, so no WP needed here).
 * Confirms a scoped role only gets the menu meta-cap for its own area.
 */
final class CapabilitiesMetaCapsTest extends TestCase
{
    public function test_no_caps_grants_nothing(): void
    {
        $out = Capabilities::grantMetaCaps([]);
        $this->assertArrayNotHasKey('giveflow_access', $out);
        foreach (array_keys(Capabilities::MENU_AREAS) as $area) {
            $this->assertArrayNotHasKey($area, $out);
        }
    }

    public function test_manage_options_grants_every_area_and_umbrella(): void
    {
        $out = Capabilities::grantMetaCaps(['manage_options' => true]);
        $this->assertTrue($out['giveflow_access']);
        foreach (array_keys(Capabilities::MENU_AREAS) as $area) {
            $this->assertTrue($out[$area], "$area granted to super-admin");
        }
    }

    public function test_manage_giveflow_umbrella_sees_menu_but_no_area(): void
    {
        $out = Capabilities::grantMetaCaps([Capabilities::MANAGE => true]);
        $this->assertTrue($out['giveflow_access'], 'umbrella sees the top menu');
        foreach (array_keys(Capabilities::MENU_AREAS) as $area) {
            $this->assertArrayNotHasKey($area, $out, "$area is not granted by the umbrella alone");
        }
    }

    public function test_granular_cap_grants_only_its_own_area(): void
    {
        $out = Capabilities::grantMetaCaps(['giveflow_view_donations' => true]);
        $this->assertTrue($out['giveflow_access']);
        $this->assertTrue($out['giveflow_access_donations']);
        $this->assertArrayNotHasKey('giveflow_access_donors', $out);
        $this->assertArrayNotHasKey('giveflow_access_settings', $out);
        $this->assertArrayNotHasKey('giveflow_access_reports', $out);
        $this->assertArrayNotHasKey('giveflow_access_campaigns', $out);
        $this->assertArrayNotHasKey('giveflow_access_forms', $out);
    }

    public function test_manage_campaigns_covers_campaigns_area(): void
    {
        $out = Capabilities::grantMetaCaps(['giveflow_manage_campaigns' => true]);
        $this->assertTrue($out['giveflow_access_campaigns']);
        $this->assertTrue($out['giveflow_access']);
        $this->assertArrayNotHasKey('giveflow_access_donors', $out);
    }

    public function test_existing_caps_are_preserved(): void
    {
        $out = Capabilities::grantMetaCaps(['read' => true, 'giveflow_view_donors' => true]);
        $this->assertTrue($out['read']);
        $this->assertTrue($out['giveflow_view_donors']);
        $this->assertTrue($out['giveflow_access_donors']);
    }

    public function test_every_menu_area_maps_to_a_real_granular_cap(): void
    {
        foreach (Capabilities::MENU_AREAS as $virtual => $real) {
            $this->assertStringStartsWith('giveflow_access_', $virtual);
            $this->assertContains($real, Capabilities::ALL, "$real is a real granular cap");
        }
    }
}
