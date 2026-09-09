<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Admin\AdminMenu;
use Gratora\Admin\Pages\DonationsPage;
use Gratora\Admin\Pages\DonorsPage;
use Gratora\Admin\Pages\SettingsPage;
use Gratora\Admin\Pages\ToolsPage;
use Gratora\Foundation\Auth\Capabilities;
use WP_REST_Request;

/**
 * A role scoped to one area of Gratora should be shown the part of it that role
 * can actually use, and nothing whose every request will be refused.
 */
final class ScopedRoleReachTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        delete_option('gratora_roles');
        Capabilities::applyMapping([]);

        // CoreModule registers these behind is_admin(), which is false here,
        // and the menu is nothing but what they contribute.
        (new DonationsPage())->register();
        (new DonorsPage())->register();
        (new ToolsPage())->register();
        (new SettingsPage())->register();
    }

    protected function tearDown(): void
    {
        Capabilities::applyMapping([]);
        remove_role('bookkeeper');
        parent::tearDown();
    }

    private function asRoleWith(string $slug, array $caps): int
    {
        Capabilities::applyMapping([$slug => $caps]);
        $user = self::factory()->user->create(['role' => $slug]);
        wp_set_current_user($user);

        return $user;
    }

    /** @return array<string, list<string>> menu slug => the capability it was registered under */
    private function renderedMenu(): array
    {
        global $menu, $submenu;
        $menu = [];
        $submenu = [];

        (new AdminMenu())->registerMenu();

        return $submenu['gratora'] ?? [];
    }

    public function test_the_dashboard_is_not_offered_to_a_role_that_cannot_read_reports(): void
    {
        $this->asRoleWith('editor', ['gratora_view_donations']);

        $slugs = array_column($this->renderedMenu(), 2);

        $this->assertNotContains('gratora', $slugs, 'the dashboard needs report access');
        $this->assertContains('gratora-donations', $slugs);
    }

    public function test_the_dashboard_is_offered_to_a_role_that_can_read_reports(): void
    {
        $this->asRoleWith('editor', ['gratora_view_reports']);

        $this->assertContains('gratora', array_column($this->renderedMenu(), 2));
    }

    public function test_tools_is_not_offered_to_a_role_its_routes_refuse(): void
    {
        $this->asRoleWith('editor', ['gratora_view_donors']);
        $slugs = array_column($this->renderedMenu(), 2);
        $this->assertNotContains('gratora-tools', $slugs);

        $res = rest_do_request(new WP_REST_Request('GET', '/gratora/v1/admin/tools/info'));
        $this->assertSame(403, $res->get_status(), 'the routes already refused this role');
    }

    public function test_tools_is_offered_to_a_role_its_routes_accept(): void
    {
        $this->asRoleWith('editor', ['gratora_manage_settings']);

        $this->assertContains('gratora-tools', array_column($this->renderedMenu(), 2));
        $this->assertSame(
            200,
            rest_do_request(new WP_REST_Request('GET', '/gratora/v1/admin/tools/info'))->get_status()
        );
    }

    public function test_a_role_outside_the_screen_keeps_capabilities_the_mapping_never_mentions(): void
    {
        add_role('bookkeeper', 'Bookkeeper', ['read' => true]);
        get_role('bookkeeper')->add_cap('gratora_view_donations');

        // Someone saves the Roles screen, which describes the five core roles.
        Capabilities::applyMapping(['editor' => ['gratora_view_donors']]);

        $this->assertTrue(
            get_role('bookkeeper')->has_cap('gratora_view_donations'),
            'a role the screen cannot see is a role the screen does not speak for'
        );
        $this->assertTrue(get_role('editor')->has_cap('gratora_view_donors'));
    }

    public function test_a_role_the_mapping_names_is_still_revoked_by_it(): void
    {
        add_role('bookkeeper', 'Bookkeeper', ['read' => true]);
        Capabilities::applyMapping(['bookkeeper' => ['gratora_view_donations']]);
        $this->assertTrue(get_role('bookkeeper')->has_cap('gratora_view_donations'));

        Capabilities::applyMapping(['bookkeeper' => []]);
        $this->assertFalse(get_role('bookkeeper')->has_cap('gratora_view_donations'));
    }

    public function test_a_core_role_is_still_revoked_by_its_absence(): void
    {
        Capabilities::applyMapping(['editor' => ['gratora_view_donations']]);
        $this->assertTrue(get_role('editor')->has_cap('gratora_view_donations'));

        Capabilities::applyMapping([]);
        $this->assertFalse(get_role('editor')->has_cap('gratora_view_donations'));
    }
}
