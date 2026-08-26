<?php

declare(strict_types=1);

namespace GiveFlow\Tests\Integration;

use GiveFlow\Settings\SettingsService;

/**
 * An add-on registers an email template through giveflow.settings.groups, and the
 * admin then has to be able to find and edit it. The settings editor lists what
 * it is told about, so a template with no metadata is stored, sent, and
 * invisible to the person whose name is on it.
 */
final class EmailTemplateMetaTest extends IntegrationTestCase
{
    public function test_an_addon_describes_its_template_for_the_settings_editor(): void
    {
        add_filter('giveflow.email.template_meta', static function (array $meta): array {
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
        add_filter('giveflow.email.template_meta', static function (array $meta): array {
            $meta[] = ['id' => 'addon_thing', 'label' => 'Addon thing'];

            return $meta;
        });

        $_GET['page'] = 'giveflow-settings';
        set_current_screen('giveflow_page_giveflow-settings');
        wp_set_current_user(1);

        // The payload rides an enqueued src-less handle, so it is observed
        // where WordPress serves it: the inline script attached to the handle.
        wp_deregister_script('giveflow-admin-globals');
        (new \GiveFlow\Admin\AdminGlobals(
            \GiveFlow\Foundation\Plugin::instance()->container->get(\GiveFlow\Foundation\License\LicenseService::class)
        ))->inject();
        $data    = wp_scripts()->get_data('giveflow-admin-globals', 'after');
        $printed = is_array($data) ? implode('', array_filter($data)) : '';

        unset($_GET['page']);

        $this->assertStringContainsString('email_template_meta', $printed);
        $this->assertStringContainsString('addon_thing', $printed);
    }
}
