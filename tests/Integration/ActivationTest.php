<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Core\Activator;
use Dono\Donors\Portal\PortalPage;
use Dono\Foundation\Plugin;

final class ActivationTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        delete_option(Activator::OPT_ACTIVATED_AT);
        delete_option(Activator::OPT_ORG_PROFILE);

        $admin = get_role('administrator');
        if ($admin && $admin->has_cap(Activator::CAP_MANAGE)) {
            $admin->remove_cap(Activator::CAP_MANAGE);
        }
    }

    public function test_activation_seeds_a_default_general_fund(): void
    {
        Plugin::onActivation();

        $fund = self::$wpdb->get_row(
            "SELECT code, name, is_default, is_active FROM " . self::$prefix . "dono_funds WHERE code = 'general'"
        );

        $this->assertNotNull($fund);
        $this->assertSame('general', $fund->code);
        $this->assertTrue((bool) $fund->is_default);
        $this->assertTrue((bool) $fund->is_active);
    }

    public function test_activation_stamps_the_schema_version(): void
    {
        delete_option('dono_db_version');
        Plugin::onActivation();
        $this->assertSame(
            DONO_DB_VERSION,
            get_option('dono_db_version'),
            'activation records the schema version so the boot gate skips a redundant migration'
        );
    }

    public function test_re_activation_does_not_duplicate_the_default_fund(): void
    {
        Plugin::onActivation();
        Plugin::onActivation();
        Plugin::onActivation();

        $count = (int) self::$wpdb->get_var(
            "SELECT COUNT(*) FROM " . self::$prefix . "dono_funds WHERE code = 'general'"
        );
        $this->assertSame(1, $count);
    }

    public function test_administrator_role_gains_manage_dono_capability(): void
    {
        Plugin::onActivation();

        $admin = get_role('administrator');
        $this->assertTrue($admin->has_cap(Activator::CAP_MANAGE));
    }

    public function test_org_profile_is_seeded_with_site_defaults(): void
    {
        Plugin::onActivation();

        $profile = get_option(Activator::OPT_ORG_PROFILE);
        $this->assertIsArray($profile);
        $this->assertSame(get_bloginfo('name'),     $profile['name']);
        $this->assertSame(get_option('admin_email'), $profile['email']);
        $this->assertIsArray($profile['address_lines']);
    }

    public function test_re_activation_does_not_overwrite_a_customised_org_profile(): void
    {
        Plugin::onActivation();
        update_option(Activator::OPT_ORG_PROFILE, [
            'name'          => 'Custom Org Name',
            'address_lines' => ['Line 1', 'Line 2'],
            'tax_id'        => 'TAX-123',
            'email'         => 'changed@example.org',
        ]);

        Plugin::onActivation();

        $profile = get_option(Activator::OPT_ORG_PROFILE);
        $this->assertSame('Custom Org Name', $profile['name'],  'Activator must not overwrite an already-customised profile');
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
            "SELECT COUNT(*) FROM " . self::$prefix . "dono_campaigns"
        );
        $formCount = (int) self::$wpdb->get_var(
            "SELECT COUNT(*) FROM " . self::$prefix . "dono_forms"
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
        $this->assertSame(DONO_VERSION, get_option(PortalPage::OPTION_VERSION));
    }
}
