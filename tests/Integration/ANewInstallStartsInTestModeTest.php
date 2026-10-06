<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Foundation\Plugin;
use Gratora\Gateways\TestMode;

final class ANewInstallStartsInTestModeTest extends IntegrationTestCase
{
    public function test_switching_the_plugin_on_for_the_first_time_turns_test_mode_on(): void
    {
        delete_option('gratora_db_version');
        delete_option('gratora_gateway_config');

        Plugin::onPluginActivated();

        $this->assertTrue(TestMode::siteWide());
    }

    public function test_payment_settings_that_are_already_stored_are_left_alone(): void
    {
        update_option('gratora_gateway_config', [
            'test_mode' => false,
            'offline'   => ['instructions' => 'Pay by transfer.'],
        ]);

        Plugin::onActivation(true);

        $this->assertFalse(TestMode::siteWide());
        $this->assertSame('Pay by transfer.', get_option('gratora_gateway_config')['offline']['instructions']);
    }

    public function test_switching_the_plugin_back_on_does_not_turn_test_mode_on(): void
    {
        delete_option('gratora_gateway_config');

        Plugin::onPluginActivated();

        $this->assertFalse(TestMode::siteWide());
    }
}
