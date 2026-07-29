<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Settings\SettingsService;

/**
 * An add-on registers an email template through dono.settings.groups, and the
 * admin then has to be able to find and edit it. The settings editor lists what
 * it is told about, so a template with no metadata is stored, sent, and
 * invisible to the person whose name is on it.
 */
final class EmailTemplateMetaTest extends IntegrationTestCase
{
    public function test_an_addon_describes_its_template_for_the_settings_editor(): void
    {
        add_filter('dono.email.template_meta', static function (array $meta): array {
            $meta[] = [
                'id'        => 'addon_thing',
                'label'     => 'Addon thing',
                'desc'      => 'Sent when the thing happens.',
                'recipient' => 'Donor',
            ];

            return $meta;
        });

        $ids = array_column(SettingsService::templateMeta(), 'id');

        $this->assertContains('addon_thing', $ids);
    }

    /** Core describes its own templates in the editor bundle, so this starts empty. */
    public function test_it_is_empty_without_an_addon(): void
    {
        $this->assertSame([], SettingsService::templateMeta());
    }

    public function test_the_admin_bundle_is_handed_the_descriptions(): void
    {
        add_filter('dono.email.template_meta', static function (array $meta): array {
            $meta[] = ['id' => 'addon_thing', 'label' => 'Addon thing'];

            return $meta;
        });

        $_GET['page'] = 'dono-settings';
        set_current_screen('dono_page_dono-settings');
        wp_set_current_user(1);

        ob_start();
        (new \Dono\Admin\AdminGlobals(
            \Dono\Foundation\Plugin::instance()->container->get(\Dono\Foundation\License\LicenseService::class)
        ))->inject();
        $printed = (string) ob_get_clean();

        unset($_GET['page']);

        $this->assertStringContainsString('email_template_meta', $printed);
        $this->assertStringContainsString('addon_thing', $printed);
    }
}
