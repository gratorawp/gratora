<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Settings\SettingsService;

/**
 * An add-on registers an email template through gratora.settings.groups, and the
 * admin then has to be able to find and edit it. The settings editor lists what
 * it is told about, so a template with no metadata is stored, sent, and
 * invisible to the person whose name is on it.
 */
final class EmailTemplateMetaTest extends IntegrationTestCase
{
    public function test_an_addon_describes_its_template_for_the_settings_editor(): void
    {
        add_filter('gratora.email.template_meta', static function (array $meta): array {
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

    public function test_it_is_empty_without_an_addon(): void
    {
        $this->assertSame([], SettingsService::templateMeta());
    }

    public function test_the_admin_bundle_is_handed_the_descriptions(): void
    {
        add_filter('gratora.email.template_meta', static function (array $meta): array {
            $meta[] = ['id' => 'addon_thing', 'label' => 'Addon thing'];

            return $meta;
        });

        $_GET['page'] = 'gratora-settings';
        set_current_screen('gratora_page_gratora-settings');
        wp_set_current_user(1);

        // The payload rides an enqueued src-less handle, so it is observed
        // where WordPress serves it: the inline script attached to the handle.
        wp_deregister_script('gratora-admin-globals');
        (new \Gratora\Admin\AdminGlobals(
            \Gratora\Foundation\Plugin::instance()->container->get(\Gratora\Foundation\License\LicenseService::class)
        ))->inject();
        $data    = wp_scripts()->get_data('gratora-admin-globals', 'after');
        $printed = is_array($data) ? implode('', array_filter($data)) : '';

        unset($_GET['page']);

        $this->assertStringContainsString('email_template_meta', $printed);
        $this->assertStringContainsString('addon_thing', $printed);
    }
}
