<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Foundation\Auth\Capabilities;
use WP_REST_Request;

/**
 * The roles screen is the one place capabilities are granted, so it has to know
 * about all of them.
 *
 * It used to render from a list hardcoded in its own JSX while add-ons register
 * theirs through the `dono.capabilities` filter. `applyMapping()` honoured
 * those; the screen never showed them. dono-p2p's `dono_manage_fundraisers`
 * gated real routes and could not be granted to anyone through the UI.
 */
final class RolesCapabilityListTest extends IntegrationTestCase
{
    /** @return array<string,mixed> */
    private function fetch(): array
    {
        $admin = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin);

        $res = rest_do_request(new WP_REST_Request('GET', '/dono/v1/admin/roles'));
        $this->assertSame(200, $res->get_status());

        return (array) $res->get_data();
    }

    /** @param array<string,mixed> $data @return list<string> */
    private function capsIn(array $data): array
    {
        $caps = [];
        foreach ((array) ($data['capabilities'] ?? []) as $group) {
            foreach ((array) ($group['caps'] ?? []) as $row) {
                $caps[] = (string) $row['cap'];
            }
        }
        return $caps;
    }

    public function test_the_endpoint_serves_the_roles_and_the_capabilities(): void
    {
        $data = $this->fetch();

        $this->assertArrayHasKey('roles', $data);
        $this->assertArrayHasKey('capabilities', $data);

        $slugs = array_column((array) $data['roles'], 'slug');
        $this->assertContains('administrator', $slugs);
        $this->assertContains('editor', $slugs);
    }

    public function test_every_core_capability_is_grantable(): void
    {
        $served = $this->capsIn($this->fetch());

        foreach (Capabilities::all() as $cap) {
            $this->assertContains($cap, $served, "{$cap} is enforced but cannot be granted");
        }
    }

    /** The whole point: a capability an add-on registers has to show up. */
    public function test_an_add_on_capability_reaches_the_screen(): void
    {
        $register = static function (array $maps): array {
            $maps['all'][]                 = 'dono_manage_fundraisers';
            $maps['groups']['Fundraising'] = ['dono_manage_fundraisers'];
            $maps['labels']['dono_manage_fundraisers'] = 'Manage fundraisers';
            return $maps;
        };
        add_filter('dono.capabilities', $register);

        try {
            $data = $this->fetch();
            $this->assertContains('dono_manage_fundraisers', $this->capsIn($data));

            $labels = array_column((array) $data['capabilities'], 'label');
            $this->assertContains('Fundraising', $labels, 'and under its own heading');
        } finally {
            remove_filter('dono.capabilities', $register);
        }
    }

    /**
     * An add-on that registers a capability without putting it in a group would
     * otherwise vanish between the two lists.
     */
    public function test_an_ungrouped_capability_is_gathered_rather_than_dropped(): void
    {
        $register = static function (array $maps): array {
            $maps['all'][] = 'dono_loose_cap';
            return $maps;
        };
        add_filter('dono.capabilities', $register);

        try {
            $this->assertContains('dono_loose_cap', $this->capsIn($this->fetch()));
        } finally {
            remove_filter('dono.capabilities', $register);
        }
    }

    public function test_a_non_admin_cannot_read_the_capability_map(): void
    {
        $viewer = self::factory()->user->create(['role' => 'subscriber']);
        get_user_by('id', $viewer)->add_cap('dono_view_donors');
        wp_set_current_user($viewer);

        $this->assertSame(403, rest_do_request(new WP_REST_Request('GET', '/dono/v1/admin/roles'))->get_status());
    }
}
