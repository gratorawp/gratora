<?php

declare(strict_types=1);

namespace GiveFlow\Tests\Integration;

use GiveFlow\Foundation\Plugin;
use GiveFlow\Settings\SettingsService;
use WP_REST_Request;

/**
 * The Currency tab offered a Locale select under the words "Dates on receipts
 * and exports". Nothing read it: a receipt is rendered in the donor's own
 * locale, on purpose, and exports use the site's date format. The setting is
 * gone rather than left there answering for a behaviour it never had.
 */
final class CurrencyLocaleSettingRemovedTest extends IntegrationTestCase
{
    private function settings(): SettingsService
    {
        return Plugin::instance()->container->get(SettingsService::class);
    }

    public function test_the_group_has_no_locale_of_its_own(): void
    {
        $this->assertArrayNotHasKey('locale', $this->settings()->get('currency-locale'));
    }

    public function test_writing_a_locale_stores_nothing(): void
    {
        $saved = $this->settings()->update('currency-locale', ['locale' => 'de_DE']);

        $this->assertArrayNotHasKey('locale', $saved);
        $this->assertArrayNotHasKey('locale', $this->settings()->get('currency-locale'));
    }

    public function test_the_settings_route_offers_no_locale(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        $data = (array) rest_do_request(new WP_REST_Request('GET', '/giveflow/v1/admin/settings/currency-locale'))->get_data();

        $this->assertArrayHasKey('default_currency', $data, 'the rest of the group is untouched');
        $this->assertArrayNotHasKey('locale', $data);
    }
}
