<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Admin\AdminGlobals;
use Dono\Foundation\License\LicenseService;
use Dono\Foundation\Plugin;

/**
 * window.dono carries the org's number format, among other things. A screen
 * that does not receive it renders money in a default format while every other
 * screen uses the org's, and the totals look like they disagree.
 */
final class AdminGlobalsPageMatchTest extends IntegrationTestCase
{
    private function emitsOn(?string $page): bool
    {
        if ($page === null) {
            unset($_GET['page']);
        } else {
            $_GET['page'] = $page;
        }

        ob_start();
        (new AdminGlobals(Plugin::instance()->container->get(LicenseService::class)))->inject();
        $out = (string) ob_get_clean();

        unset($_GET['page']);

        return str_contains($out, 'dono-admin-globals');
    }

    public function test_the_dashboard_gets_the_config_object(): void
    {
        // Its slug is the bare "dono", not "dono-something", so a prefix-only
        // match skips the first screen a new install opens.
        $this->assertTrue($this->emitsOn('dono'));
    }

    public function test_every_other_dono_screen_gets_it(): void
    {
        foreach (['dono-campaigns', 'dono-donations', 'dono-donors', 'dono-forms', 'dono-funds', 'dono-settings', 'dono-tools', 'dono-onboarding'] as $page) {
            $this->assertTrue($this->emitsOn($page), "{$page} should receive window.dono");
        }
    }

    public function test_it_stays_off_screens_that_are_not_ours(): void
    {
        foreach ([null, '', 'wc-settings', 'givewp-donations', 'donations'] as $page) {
            $this->assertFalse($this->emitsOn($page), var_export($page, true) . ' should not receive window.dono');
        }
    }

    public function test_a_slug_that_merely_starts_with_dono_is_not_ours(): void
    {
        // "donopolis" is not a Dono screen; the guard matches a boundary.
        $this->assertFalse($this->emitsOn('donopolis'));
    }

    public function test_the_payload_carries_the_org_number_format(): void
    {
        $_GET['page'] = 'dono';

        ob_start();
        (new AdminGlobals(Plugin::instance()->container->get(LicenseService::class)))->inject();
        $out = (string) ob_get_clean();

        unset($_GET['page']);

        $this->assertStringContainsString('number_format', $out);
    }
}
