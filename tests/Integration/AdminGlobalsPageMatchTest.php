<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Admin\AdminGlobals;
use Gratora\Foundation\Auth\Capabilities;
use Gratora\Foundation\License\LicenseService;
use Gratora\Foundation\Plugin;

/**
 * window.gratora carries the org's number format, among other things. A screen
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

        wp_deregister_script('gratora-admin-globals');
        (new AdminGlobals(Plugin::instance()->container->get(LicenseService::class)))->inject();

        unset($_GET['page']);

        $data = wp_scripts()->get_data('gratora-admin-globals', 'after');
        return is_array($data) ? implode('', array_filter($data)) : '';
    }

    private function emitsOn(?string $page): bool
    {
        return str_contains($this->payloadOn($page), 'window.gratora');
    }

    public function test_the_dashboard_gets_the_config_object(): void
    {
        // Its slug is the bare "gratora", not "gratora-something", so a prefix-only
        // match skips the first screen a new install opens.
        $this->assertTrue($this->emitsOn('gratora'));
    }

    public function test_every_other_gratora_screen_gets_it(): void
    {
        foreach (['gratora-campaigns', 'gratora-donations', 'gratora-donors', 'gratora-forms', 'gratora-funds', 'gratora-settings', 'gratora-tools', 'gratora-onboarding'] as $page) {
            $this->assertTrue($this->emitsOn($page), "{$page} should receive window.gratora");
        }
    }

    public function test_it_stays_off_screens_that_are_not_ours(): void
    {
        foreach ([null, '', 'wc-settings', 'givewp-donations', 'donations'] as $page) {
            $this->assertFalse($this->emitsOn($page), var_export($page, true) . ' should not receive window.gratora');
        }
    }

    public function test_a_slug_that_merely_starts_with_gratora_is_not_ours(): void
    {
        // "metropolis" is not a Gratora screen; the guard matches a boundary.
        $this->assertFalse($this->emitsOn('metropolis'));
    }

    public function test_the_payload_carries_the_org_number_format(): void
    {
        $this->assertStringContainsString('number_format', $this->payloadOn('gratora'));
    }

    public function test_the_payload_says_whether_this_reader_may_change_a_plan(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $payload = $this->payloadOn('gratora-subscriptions');
        $this->assertStringContainsString('"refund_donations":true', $payload);

        // A reader who reaches the screen at all but may not change what is
        // charged. A subscriber is a different case: they get no payload.
        $role = 'gratora_viewer_' . uniqid();
        add_role($role, 'Viewer', ['read' => true, 'gratora_view_donations' => true]);
        wp_set_current_user(self::factory()->user->create(['role' => $role]));
        $payload = $this->payloadOn('gratora-subscriptions');
        $this->assertStringContainsString('"refund_donations":false', $payload);
        remove_role($role);
    }

    public function test_the_payload_carries_every_capability_the_screens_ask_about(): void
    {
        $payload = $this->payloadOn('gratora-donations');

        foreach (Capabilities::all() as $cap) {
            $key = substr($cap, strlen('gratora_'));
            $this->assertStringContainsString("\"{$key}\":", $payload, "{$cap} is answered");
        }
    }

    public function test_the_payload_carries_the_currency_format_presets(): void
    {
        $payload = $this->payloadOn('gratora-settings');

        $this->assertStringContainsString('currency_formats', $payload);
        $this->assertStringContainsString('"USD"', $payload);
        $this->assertStringContainsString('"EUR"', $payload);
    }
}
