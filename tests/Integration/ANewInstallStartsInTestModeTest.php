<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Core\Activator;
use Gratora\Foundation\Plugin;
use Gratora\Gateways\TestMode;

/**
 * A site whose activation has never completed is a new site, and it starts in
 * test mode. One that has been activated before is left as it is.
 */
final class ANewInstallStartsInTestModeTest extends IntegrationTestCase
{
    private function aSiteNeverActivated(): void
    {
        delete_option(Activator::OPT_ACTIVATED_AT);
        delete_option('gratora_gateway_config');
    }

    public function test_switching_the_plugin_on_for_the_first_time_turns_test_mode_on(): void
    {
        $this->aSiteNeverActivated();

        Plugin::onPluginActivated();

        $this->assertTrue(TestMode::siteWide());
    }

    /** A subsite of a network, or a site whose activation hook was cut short. */
    public function test_a_site_that_finishes_activating_on_a_later_request_starts_in_it_too(): void
    {
        $this->aSiteNeverActivated();

        Plugin::runSchemaGate();

        $this->assertTrue(TestMode::siteWide());
    }

    public function test_payment_settings_that_are_already_stored_are_left_alone(): void
    {
        $this->aSiteNeverActivated();
        update_option('gratora_gateway_config', [
            'test_mode' => false,
            'offline'   => ['instructions' => 'Pay by transfer.'],
        ]);

        Plugin::onPluginActivated();

        $this->assertFalse(TestMode::siteWide());
        $this->assertSame('Pay by transfer.', get_option('gratora_gateway_config')['offline']['instructions']);
    }

    public function test_switching_the_plugin_back_on_does_not_turn_test_mode_on(): void
    {
        delete_option('gratora_gateway_config');

        Plugin::onPluginActivated();

        $this->assertFalse(TestMode::siteWide());
    }

    public function test_a_site_already_activated_is_left_alone_by_later_requests(): void
    {
        delete_option('gratora_gateway_config');

        Plugin::runSchemaGate();

        $this->assertFalse(TestMode::siteWide());
    }

    /**
     * Every add-on's test rig lays a site out this way and then measures
     * donations on it as real ones. A rig is not a site somebody is setting up.
     */
    public function test_laying_a_site_out_without_switching_it_on_leaves_the_mode_alone(): void
    {
        $this->aSiteNeverActivated();

        Plugin::onActivation();

        $this->assertFalse(TestMode::siteWide());
    }
}
