<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Campaigns\Styling\StylePresets;
use Gratora\Foundation\Plugin;
use Gratora\Settings\SettingsService;
use InvalidArgumentException;
use WP_REST_Request;

/**
 * Settings the panel could write but nothing could take back.
 */
final class SettingsPanelTruthTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    private function settings(): SettingsService
    {
        return Plugin::instance()->container->get(SettingsService::class);
    }


    public function test_a_base_currency_that_is_not_a_code_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->settings()->update('currency-locale', ['default_currency' => 'dollars']);
    }

    public function test_an_accepted_currency_that_is_not_a_code_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->settings()->update('currency-locale', ['supported_currencies' => ['USD', 'Euros']]);
    }

    public function test_a_real_code_still_saves(): void
    {
        $saved = $this->settings()->update('currency-locale', [
            'default_currency'     => 'EUR',
            'supported_currencies' => ['EUR'],
        ]);

        $this->assertSame('EUR', (string) $saved['default_currency']);
    }


    private function saveCurrency(): array
    {
        $req = new WP_REST_Request('PUT', '/gratora/v1/admin/settings/currency-locale');
        $req->set_param('group', 'currency-locale');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode(['format' => ['decimal_places' => 2]]));

        $res = rest_do_request($req);
        $this->assertSame(200, $res->get_status(), (string) wp_json_encode($res->get_data()));

        return (array) $res->get_data();
    }

    public function test_the_save_reply_still_says_whether_the_base_is_locked(): void
    {
        $saved = $this->saveCurrency();

        $this->assertArrayHasKey(
            'base_currency_locked',
            $saved,
            'the client replaces its record with this reply, so the picker unlocked itself on a locked site'
        );
    }


    public function test_a_form_pointing_at_a_deleted_preset_falls_back_to_the_org_default(): void
    {
        $this->settings()->update('org-brand', [
            'presets'    => [
                ['id' => 'house', 'name' => 'House', 'tokens' => ['gratora-accent' => '#123456']],
            ],
            'default_id' => 'house',
        ]);

        $gone = StylePresets::tokensFor('a-preset-nobody-kept');

        $this->assertSame(
            '#123456',
            (string) ($gone['gratora-accent'] ?? ''),
            'a deleted preset dropped every form that used it to the bare catalogue defaults'
        );
    }

    public function test_a_preset_that_exists_is_unaffected(): void
    {
        $this->settings()->update('org-brand', [
            'presets'    => [
                ['id' => 'house', 'name' => 'House', 'tokens' => ['gratora-accent' => '#123456']],
                ['id' => 'other', 'name' => 'Other', 'tokens' => ['gratora-accent' => '#abcdef']],
            ],
            'default_id' => 'house',
        ]);

        $this->assertSame('#abcdef', (string) (StylePresets::tokensFor('other')['gratora-accent'] ?? ''));
    }


    public function test_saving_the_brand_panel_does_not_store_the_builtins_it_was_shown(): void
    {
        // What the panel posts: the whole seeded list, untouched.
        $this->settings()->update('org-brand', [
            'presets'    => StylePresets::builtinsWithTheme(),
            'default_id' => 'classic',
        ]);

        $stored = (array) get_option('gratora_org_brand', []);

        $this->assertSame(
            [],
            (array) ($stored['presets'] ?? []),
            'a snapshot of the theme-derived tokens was frozen into the option and then won over the live theme forever'
        );
    }

    public function test_a_builtin_the_admin_actually_edited_is_kept(): void
    {
        $edited = StylePresets::builtinsWithTheme();
        $edited[0]['tokens']['gratora-accent'] = '#ff0000';

        $this->settings()->update('org-brand', ['presets' => $edited, 'default_id' => 'classic']);

        $stored = (array) get_option('gratora_org_brand', []);
        $ids    = array_map(static fn ($p): string => (string) ($p['id'] ?? ''), (array) $stored['presets']);

        $this->assertSame([ (string) $edited[0]['id'] ], $ids);
    }

    public function test_a_custom_preset_is_always_kept(): void
    {
        $this->settings()->update('org-brand', [
            'presets'    => array_merge(StylePresets::builtinsWithTheme(), [
                ['id' => 'house', 'name' => 'House', 'tokens' => ['gratora-accent' => '#123456']],
            ]),
            'default_id' => 'house',
        ]);

        $stored = (array) get_option('gratora_org_brand', []);
        $ids    = array_map(static fn ($p): string => (string) ($p['id'] ?? ''), (array) $stored['presets']);

        $this->assertSame(['house'], $ids);
    }
}
