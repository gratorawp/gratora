<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Foundation\Auth\Capabilities;
use Dono\Foundation\Plugin;
use Dono\Settings\SettingsService;

/**
 * The Roles screen reads the settings defaults while capabilities come from the
 * dono_roles option, so the two have to be made to agree: a screen showing a
 * capability as granted is a promise that the role holds it, and an
 * administrator refused a refund by command dispatch has no way to grant it
 * from the screen (the administrator column is not editable).
 */
final class RoleDefaultsAppliedTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // The fresh-install state: no stored mapping, no role holding anything.
        delete_option('dono_roles');
        Capabilities::applyMapping([]);

        // These fire admin_init for real, so every listener on it runs. Three
        // object to a CLI process: core's Referrer-Policy header and the
        // onboarding redirect both send headers, which is fatal once PHPUnit
        // has written a byte, and the privacy-policy helper reports incorrect
        // usage because is_admin() is false here.
        remove_action('admin_init', 'wp_admin_headers');
        update_option('dono_onboarding_status', 'completed');
        $this->setExpectedIncorrectUsage('wp_add_privacy_policy_content');
    }

    protected function tearDown(): void
    {
        // Role capabilities live in a process-wide WP_Roles instance, so put it
        // back the way the suite found it. WP's own case asserts the hook table
        // is unchanged, so core's header action goes back too.
        add_action('admin_init', 'wp_admin_headers');
        Capabilities::applyMapping([]);
        parent::tearDown();
    }

    public function test_the_first_admin_load_grants_the_administrator_what_the_screen_shows(): void
    {
        $this->assertFalse(
            get_role('administrator')->has_cap('dono_refund_donations'),
            'nothing has applied the mapping yet'
        );

        do_action('admin_init');

        $admin = get_role('administrator');
        $this->assertTrue($admin->has_cap('dono_refund_donations'));
        $this->assertTrue($admin->has_cap('dono_resend_receipt'));
        $this->assertTrue($admin->has_cap(Capabilities::MANAGE));
    }

    public function test_every_capability_the_roles_screen_shows_is_actually_held(): void
    {
        do_action('admin_init');

        $mapping = Plugin::instance()->container->get(SettingsService::class)->get('roles')['mapping'];
        $this->assertNotSame([], $mapping);

        foreach ($mapping as $slug => $caps) {
            $role = get_role($slug);
            $this->assertNotNull($role, "role {$slug} exists");
            foreach ($caps as $cap) {
                $this->assertTrue($role->has_cap($cap), "{$slug} holds {$cap}");
            }
        }
    }

    public function test_no_other_role_is_given_donor_access_by_default(): void
    {
        do_action('admin_init');

        foreach (['editor', 'author', 'contributor', 'subscriber'] as $slug) {
            $role = get_role($slug);
            foreach (Capabilities::all() as $cap) {
                $this->assertFalse($role->has_cap($cap), "{$slug} starts without {$cap}");
            }
        }
    }
}
