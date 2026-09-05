<?php

declare(strict_types=1);

namespace FundKit\Tests\Integration;

use FundKit\Admin\AdminMenu;
use FundKit\Admin\Pages\DonationsPage;
use FundKit\Admin\Pages\DonorsPage;
use FundKit\Admin\Pages\SettingsPage;
use FundKit\Admin\Pages\ToolsPage;
use FundKit\Foundation\Auth\Capabilities;
use WP_REST_Request;

/**
 * A role scoped to one area of FundKit should be shown the part of it that role
 * can actually use, and nothing whose every request will be refused.
 */
final class ScopedRoleReachTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        delete_option('fundkit_roles');
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

        return $submenu['fundkit'] ?? [];
    }

    public function test_the_dashboard_is_not_offered_to_a_role_that_cannot_read_reports(): void
    {
        $this->asRoleWith('editor', ['fundkit_view_donations']);

        $slugs = array_column($this->renderedMenu(), 2);

        $this->assertNotContains('fundkit', $slugs, 'the dashboard needs report access');
        $this->assertContains('fundkit-donations', $slugs);
    }

    public function test_the_dashboard_is_offered_to_a_role_that_can_read_reports(): void
    {
        $this->asRoleWith('editor', ['fundkit_view_reports']);

        $this->assertContains('fundkit', array_column($this->renderedMenu(), 2));
    }

    public function test_tools_is_not_offered_to_a_role_its_routes_refuse(): void
    {
        $this->asRoleWith('editor', ['fundkit_view_donors']);
        $slugs = array_column($this->renderedMenu(), 2);
        $this->assertNotContains('fundkit-tools', $slugs);

        $res = rest_do_request(new WP_REST_Request('GET', '/fundkit/v1/admin/tools/info'));
        $this->assertSame(403, $res->get_status(), 'the routes already refused this role');
    }

    public function test_tools_is_offered_to_a_role_its_routes_accept(): void
    {
        $this->asRoleWith('editor', ['fundkit_manage_settings']);

        $this->assertContains('fundkit-tools', array_column($this->renderedMenu(), 2));
        $this->assertSame(
            200,
            rest_do_request(new WP_REST_Request('GET', '/fundkit/v1/admin/tools/info'))->get_status()
        );
    }

    public function test_a_role_outside_the_screen_keeps_capabilities_the_mapping_never_mentions(): void
    {
        add_role('bookkeeper', 'Bookkeeper', ['read' => true]);
        get_role('bookkeeper')->add_cap('fundkit_view_donations');

        // Someone saves the Roles screen, which describes the five core roles.
        Capabilities::applyMapping(['editor' => ['fundkit_view_donors']]);

        $this->assertTrue(
            get_role('bookkeeper')->has_cap('fundkit_view_donations'),
            'a role the screen cannot see is a role the screen does not speak for'
        );
        $this->assertTrue(get_role('editor')->has_cap('fundkit_view_donors'));
    }

    public function test_a_role_the_mapping_names_is_still_revoked_by_it(): void
    {
        add_role('bookkeeper', 'Bookkeeper', ['read' => true]);
        Capabilities::applyMapping(['bookkeeper' => ['fundkit_view_donations']]);
        $this->assertTrue(get_role('bookkeeper')->has_cap('fundkit_view_donations'));

        Capabilities::applyMapping(['bookkeeper' => []]);
        $this->assertFalse(get_role('bookkeeper')->has_cap('fundkit_view_donations'));
    }

    public function test_a_core_role_is_still_revoked_by_its_absence(): void
    {
        Capabilities::applyMapping(['editor' => ['fundkit_view_donations']]);
        $this->assertTrue(get_role('editor')->has_cap('fundkit_view_donations'));

        Capabilities::applyMapping([]);
        $this->assertFalse(get_role('editor')->has_cap('fundkit_view_donations'));
    }
}
