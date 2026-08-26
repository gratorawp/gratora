<?php

declare(strict_types=1);

namespace GiveFlow\Tests\Integration;

use GiveFlow\Admin\AdminGlobals;
use GiveFlow\Foundation\License\LicenseService;
use GiveFlow\Foundation\Plugin;

/**
 * window.giveflow carries the org's number format, among other things. A screen
 * that does not receive it renders money in a default format while every other
 * screen uses the org's, and the totals look like they disagree.
 */
final class AdminGlobalsPageMatchTest extends IntegrationTestCase
{
    /**
     * The payload rides an enqueued src-less handle now, so what a screen
     * receives is observed the way WordPress serves it: the inline script
     * attached to the handle, printed by wp_scripts.
     */
    private function payloadOn(?string $page): string
    {
        if ($page === null) {
            unset($_GET['page']);
        } else {
            $_GET['page'] = $page;
        }

        wp_deregister_script('giveflow-admin-globals');
        (new AdminGlobals(Plugin::instance()->container->get(LicenseService::class)))->inject();

        unset($_GET['page']);

        $data = wp_scripts()->get_data('giveflow-admin-globals', 'after');
        return is_array($data) ? implode('', array_filter($data)) : '';
    }

    private function emitsOn(?string $page): bool
    {
        return str_contains($this->payloadOn($page), 'window.giveflow');
    }

    public function test_the_dashboard_gets_the_config_object(): void
    {
        // Its slug is the bare "giveflow", not "giveflow-something", so a prefix-only
        // match skips the first screen a new install opens.
        $this->assertTrue($this->emitsOn('giveflow'));
    }

    public function test_every_other_giveflow_screen_gets_it(): void
    {
        foreach (['giveflow-campaigns', 'giveflow-donations', 'giveflow-donors', 'giveflow-forms', 'giveflow-funds', 'giveflow-settings', 'giveflow-tools', 'giveflow-onboarding'] as $page) {
            $this->assertTrue($this->emitsOn($page), "{$page} should receive window.giveflow");
        }
    }

    public function test_it_stays_off_screens_that_are_not_ours(): void
    {
        foreach ([null, '', 'wc-settings', 'givewp-donations', 'donations'] as $page) {
            $this->assertFalse($this->emitsOn($page), var_export($page, true) . ' should not receive window.giveflow');
        }
    }

    public function test_a_slug_that_merely_starts_with_giveflow_is_not_ours(): void
    {
        // "metropolis" is not a GiveFlow screen; the guard matches a boundary.
        $this->assertFalse($this->emitsOn('metropolis'));
    }

    public function test_the_payload_carries_the_org_number_format(): void
    {
        $this->assertStringContainsString('number_format', $this->payloadOn('giveflow'));
    }
}
