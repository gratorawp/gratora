<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Core\Activator;
use Gratora\Core\CoreModule;
use Gratora\Donors\Portal\PortalPage;
use Gratora\Foundation\Plugin;
use Gratora\Foundation\Upgrade\UpgradeRoutine;
use Gratora\Receipts\OrgProfile;

final class ActivationTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        delete_option(Activator::OPT_ACTIVATED_AT);
        delete_option('gratora_org_profile');

        $admin = get_role('administrator');
        if ($admin && $admin->has_cap(Activator::CAP_MANAGE)) {
            $admin->remove_cap(Activator::CAP_MANAGE);
        }
    }

    public function test_activation_seeds_a_default_general_fund(): void
    {
        Plugin::onActivation();

        $fund = self::$wpdb->get_row(
            "SELECT code, name, is_default, is_active FROM " . self::$prefix . "gratora_funds WHERE code = 'general'"
        );

        $this->assertNotNull($fund);
        $this->assertSame('general', $fund->code);
        $this->assertTrue((bool) $fund->is_default);
        $this->assertTrue((bool) $fund->is_active);
    }

    public function test_activation_stamps_the_schema_version(): void
    {
        delete_option('gratora_db_version');
        Plugin::onActivation();
        $this->assertSame(
            GRATORA_DB_VERSION,
            get_option('gratora_db_version'),
            'activation records the schema version so the boot gate skips a redundant migration'
        );
    }

    public function test_re_activation_does_not_duplicate_the_default_fund(): void
    {
        Plugin::onActivation();
        Plugin::onActivation();
        Plugin::onActivation();

        $count = (int) self::$wpdb->get_var(
            "SELECT COUNT(*) FROM " . self::$prefix . "gratora_funds WHERE code = 'general'"
        );
        $this->assertSame(1, $count);
    }

    public function test_administrator_role_gains_manage_gratora_capability(): void
    {
        Plugin::onActivation();

        $admin = get_role('administrator');
        $this->assertTrue($admin->has_cap(Activator::CAP_MANAGE));
    }

    public function test_activation_stores_no_org_identity_of_its_own(): void
    {
        Plugin::onActivation();

        $this->assertFalse(
            get_option('gratora_org_profile', false),
            'a name copied out of the site at activation is a name the org never gave'
        );
    }

    public function test_the_receipt_name_follows_the_site_after_a_rename(): void
    {
        $before = (string) get_bloginfo('name');
        Plugin::onActivation();

        update_option('blogname', 'Renamed Foundation');

        try {
            $this->assertSame('Renamed Foundation', OrgProfile::load()['name']);
        } finally {
            update_option('blogname', $before);
        }
    }

    public function test_re_activation_does_not_overwrite_a_customised_org_profile(): void
    {
        Plugin::onActivation();
        update_option('gratora_org_profile', [
            'name'          => 'Custom Org Name',
            'address_lines' => ['Line 1', 'Line 2'],
            'tax_id'        => 'TAX-123',
            'email'         => 'changed@example.org',
        ]);

        Plugin::onActivation();

        $profile = get_option('gratora_org_profile');
        $this->assertSame('Custom Org Name', $profile['name'],  'Activator must not overwrite an already-customized profile');
        $this->assertSame(['Line 1', 'Line 2'], $profile['address_lines']);
        $this->assertSame('TAX-123', $profile['tax_id']);
    }

    public function test_first_activation_stamps_activated_at(): void
    {
        Plugin::onActivation();
        $this->assertNotEmpty(get_option(Activator::OPT_ACTIVATED_AT));
    }

    public function test_activation_does_not_seed_any_campaigns_or_forms(): void
    {
        Plugin::onActivation();

        $campaignCount = (int) self::$wpdb->get_var(
            "SELECT COUNT(*) FROM " . self::$prefix . "gratora_campaigns"
        );
        $formCount = (int) self::$wpdb->get_var(
            "SELECT COUNT(*) FROM " . self::$prefix . "gratora_forms"
        );

        $this->assertSame(0, $campaignCount, 'No campaigns should be seeded on activation');
        $this->assertSame(0, $formCount,     'No forms should be seeded on activation');
    }

    public function test_activation_creates_a_published_donor_portal_page(): void
    {
        delete_option(PortalPage::OPTION_PAGE_ID);
        delete_option(PortalPage::OPTION_VERSION);

        Plugin::onActivation();

        $id = (int) get_option(PortalPage::OPTION_PAGE_ID);
        $this->assertGreaterThan(0, $id, 'activation stores a portal page id');
        $post = get_post($id);
        $this->assertSame('publish', $post->post_status);
        $this->assertStringContainsString(PortalPage::SHORTCODE, $post->post_content);
        $this->assertSame(GRATORA_VERSION, get_option(PortalPage::OPTION_VERSION));
    }

    /**
     * Activation runs on register_activation_hook, which fires before
     * plugins_loaded and so before any module has booted. Anything it resolves
     * from the container asks for a binding that does not exist yet, and
     * WordPress reports the result as nothing but "Plugin could not be
     * activated because it triggered a fatal error".
     */
    public function test_activation_does_not_need_a_booted_container(): void
    {
        $routines = CoreModule::upgradeRoutines();

        $this->assertIsArray($routines, 'the list is built without resolving anything');
        foreach ($routines as $routine) {
            $this->assertInstanceOf(UpgradeRoutine::class, $routine);
        }
    }
}
