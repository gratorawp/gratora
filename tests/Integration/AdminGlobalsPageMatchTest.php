<?php

declare(strict_types=1);

namespace FundKit\Tests\Integration;

use FundKit\Admin\AdminGlobals;
use FundKit\Foundation\License\LicenseService;
use FundKit\Foundation\Plugin;

/**
 * window.fundkit carries the org's number format, among other things. A screen
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

        wp_deregister_script('fundkit-admin-globals');
        (new AdminGlobals(Plugin::instance()->container->get(LicenseService::class)))->inject();

        unset($_GET['page']);

        $data = wp_scripts()->get_data('fundkit-admin-globals', 'after');
        return is_array($data) ? implode('', array_filter($data)) : '';
    }

    private function emitsOn(?string $page): bool
    {
        return str_contains($this->payloadOn($page), 'window.fundkit');
    }

    public function test_the_dashboard_gets_the_config_object(): void
    {
        // Its slug is the bare "fundkit", not "fundkit-something", so a prefix-only
        // match skips the first screen a new install opens.
        $this->assertTrue($this->emitsOn('fundkit'));
    }

    public function test_every_other_fundkit_screen_gets_it(): void
    {
        foreach (['fundkit-campaigns', 'fundkit-donations', 'fundkit-donors', 'fundkit-forms', 'fundkit-funds', 'fundkit-settings', 'fundkit-tools', 'fundkit-onboarding'] as $page) {
            $this->assertTrue($this->emitsOn($page), "{$page} should receive window.fundkit");
        }
    }

    public function test_it_stays_off_screens_that_are_not_ours(): void
    {
        foreach ([null, '', 'wc-settings', 'givewp-donations', 'donations'] as $page) {
            $this->assertFalse($this->emitsOn($page), var_export($page, true) . ' should not receive window.fundkit');
        }
    }

    public function test_a_slug_that_merely_starts_with_fundkit_is_not_ours(): void
    {
        // "metropolis" is not a FundKit screen; the guard matches a boundary.
        $this->assertFalse($this->emitsOn('metropolis'));
    }

    public function test_the_payload_carries_the_org_number_format(): void
    {
        $this->assertStringContainsString('number_format', $this->payloadOn('fundkit'));
    }

    /**
     * The plan menus on Subscriptions and the donor profile are gated on this,
     * so a reader who cannot change what is charged is not offered the actions
     * the route will refuse.
     */
    public function test_the_payload_says_whether_this_reader_may_change_a_plan(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $payload = $this->payloadOn('fundkit-subscriptions');
        $this->assertStringContainsString('"refund_donations":true', $payload);

        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));
        $payload = $this->payloadOn('fundkit-subscriptions');
        $this->assertStringContainsString('"refund_donations":false', $payload);
    }

    /**
     * The settings panel fills the format from window.fundkit.currency_formats
     * when a base currency is picked. Without it the pick still saves and the
     * format silently stays whatever it was, which is the bug this replaced.
     */
    public function test_the_payload_carries_the_currency_format_presets(): void
    {
        $payload = $this->payloadOn('fundkit-settings');

        $this->assertStringContainsString('currency_formats', $payload);
        $this->assertStringContainsString('"USD"', $payload);
        $this->assertStringContainsString('"EUR"', $payload);
    }
}
