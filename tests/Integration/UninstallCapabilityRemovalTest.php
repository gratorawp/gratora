<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Foundation\Auth\Capabilities;
use Dono\Foundation\Uninstall\DataEraser;

/**
 * "Delete all Dono data" has to take back what the plugin granted, and only
 * that. The roles screen hands the same capabilities to editor and below as it
 * does to the administrator, and a capability nobody took back outlives the
 * plugin in wp_user_roles, on a site that has removed it.
 *
 * The other side of the same rule is that nothing else is touched: the roles
 * themselves belong to WordPress or to whoever added them, and an add-on's
 * capabilities are its own to remove.
 */
final class UninstallCapabilityRemovalTest extends IntegrationTestCase
{
    protected function tearDown(): void
    {
        // Roles live in an option that WP caches in memory for the process,
        // so the transaction rollback alone would leave the next test reading
        // whatever this one granted.
        Capabilities::applyMapping(Capabilities::currentMapping());
        $GLOBALS['wp_roles'] = null;

        parent::tearDown();
    }

    public function test_capabilities_granted_to_a_non_administrator_are_taken_back(): void
    {
        Capabilities::applyMapping([
            'editor' => ['dono_view_donors', 'dono_export_donors'],
        ]);

        $editor = get_role('editor');
        $this->assertTrue($editor->has_cap('dono_view_donors'), 'precondition: the grant happened');
        $this->assertTrue($editor->has_cap(Capabilities::MANAGE), 'precondition: the umbrella came with it');

        (new DataEraser())->removeCapabilities();

        $editor = get_role('editor');
        $this->assertFalse($editor->has_cap('dono_view_donors'));
        $this->assertFalse($editor->has_cap('dono_export_donors'));
        $this->assertFalse($editor->has_cap(Capabilities::MANAGE));
    }

    public function test_the_administrator_is_stripped_too(): void
    {
        Capabilities::applyMapping(['administrator' => Capabilities::ALL]);

        $this->assertTrue(get_role('administrator')->has_cap('dono_refund_donations'), 'precondition');

        (new DataEraser())->removeCapabilities();

        $admin = get_role('administrator');
        foreach ([...Capabilities::ALL, Capabilities::MANAGE] as $cap) {
            $this->assertFalse($admin->has_cap($cap), "{$cap} survived on the administrator");
        }
    }

    /**
     * dono_manage_fundraisers is registered by the peer-to-peer plugin. Taking
     * it here breaks a site that keeps that plugin, which is worse than any
     * capability left behind.
     */
    public function test_an_add_on_capability_survives(): void
    {
        get_role('editor')->add_cap('dono_manage_fundraisers');

        (new DataEraser())->removeCapabilities();

        $this->assertTrue(get_role('editor')->has_cap('dono_manage_fundraisers'));

        get_role('editor')->remove_cap('dono_manage_fundraisers');
    }

    /**
     * Core adds no role, so there is no role of ours to remove, and every role
     * on the site is somebody else's.
     */
    public function test_no_role_is_removed(): void
    {
        $before = array_keys(wp_roles()->role_objects);

        (new DataEraser())->removeCapabilities();

        $this->assertSame($before, array_keys(wp_roles()->role_objects));
        $this->assertNotNull(get_role('editor'));
        $this->assertNotNull(get_role('subscriber'));
    }

    /** WordPress's own capabilities are not the plugin's to take. */
    public function test_core_wordpress_capabilities_are_left_alone(): void
    {
        (new DataEraser())->removeCapabilities();

        $this->assertTrue(get_role('editor')->has_cap('edit_pages'));
        $this->assertTrue(get_role('administrator')->has_cap('manage_options'));
    }
}
