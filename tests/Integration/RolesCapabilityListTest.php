<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Foundation\Auth\Capabilities;
use WP_REST_Request;

/**
 * The roles screen is the one place capabilities are granted, so it has to know
 * about all of them, including the ones add-ons register through the
 * `gratora.capabilities` filter: `applyMapping()` honours those, and a screen
 * that does not show them gates real routes nobody can be granted.
 *
 * Everything it renders is wording, so it also has to be translatable.
 */
final class RolesCapabilityListTest extends IntegrationTestCase
{
    protected function tearDown(): void
    {
        remove_all_filters('gettext');
        remove_all_filters('gettext_with_context');
        parent::tearDown();
    }

    public function test_a_capability_label_is_translated(): void
    {
        add_filter('gettext', static fn ($translated, $text, $domain) => $domain === 'gratora' && $text === 'View donors'
            ? 'Voir les donateurs'
            : $translated, 10, 3);

        $labels = [];
        foreach ((array) ($this->fetch()['capabilities'] ?? []) as $group) {
            foreach ((array) ($group['caps'] ?? []) as $row) {
                $labels[(string) $row['cap']] = (string) $row['label'];
            }
        }

        $this->assertSame('Voir les donateurs', $labels['gratora_view_donors'] ?? '');
    }

    public function test_a_group_heading_is_translated(): void
    {
        add_filter('gettext', static fn ($translated, $text, $domain) => $domain === 'gratora' && $text === 'Donors'
            ? 'Donateurs'
            : $translated, 10, 3);

        $headings = array_map(
            static fn (array $g): string => (string) ($g['label'] ?? ''),
            (array) ($this->fetch()['capabilities'] ?? [])
        );

        $this->assertContains('Donateurs', $headings);
    }

    public function test_a_role_name_is_translated(): void
    {
        add_filter('gettext_with_context', static fn ($translated, $text, $context) => $context === 'User role' && $text === 'Administrator'
            ? 'Administrateur'
            : $translated, 10, 4);

        $names = [];
        foreach ((array) ($this->fetch()['roles'] ?? []) as $role) {
            $names[(string) ($role['slug'] ?? '')] = (string) ($role['name'] ?? '');
        }

        $this->assertSame('Administrateur', $names['administrator'] ?? '');
    }

    /** @return array<string,mixed> */
    private function fetch(): array
    {
        $admin = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin);

        $res = rest_do_request(new WP_REST_Request('GET', '/gratora/v1/admin/roles'));
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

    public function test_an_add_on_capability_reaches_the_screen(): void
    {
        $register = static function (array $maps): array {
            $maps['all'][]                 = 'gratora_manage_fundraisers';
            $maps['groups']['Fundraising'] = ['gratora_manage_fundraisers'];
            $maps['labels']['gratora_manage_fundraisers'] = 'Manage fundraisers';
            return $maps;
        };
        add_filter('gratora.capabilities', $register);

        try {
            $data = $this->fetch();
            $this->assertContains('gratora_manage_fundraisers', $this->capsIn($data));

            $labels = array_column((array) $data['capabilities'], 'label');
            $this->assertContains('Fundraising', $labels, 'and under its own heading');
        } finally {
            remove_filter('gratora.capabilities', $register);
        }
    }

    /**
     * An add-on that registers a capability without putting it in a group would
     * otherwise vanish between the two lists.
     */
    public function test_an_ungrouped_capability_is_gathered_rather_than_dropped(): void
    {
        $register = static function (array $maps): array {
            $maps['all'][] = 'gratora_loose_cap';
            return $maps;
        };
        add_filter('gratora.capabilities', $register);

        try {
            $this->assertContains('gratora_loose_cap', $this->capsIn($this->fetch()));
        } finally {
            remove_filter('gratora.capabilities', $register);
        }
    }

    public function test_a_non_admin_cannot_read_the_capability_map(): void
    {
        $viewer = self::factory()->user->create(['role' => 'subscriber']);
        get_user_by('id', $viewer)->add_cap('gratora_view_donors');
        wp_set_current_user($viewer);

        $this->assertSame(403, rest_do_request(new WP_REST_Request('GET', '/gratora/v1/admin/roles'))->get_status());
    }
}
