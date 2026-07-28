<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use WP_REST_Request;

/**
 * Two authenticated-surface P2s from the QA sweep.
 *
 * A huge `page` 500'd every admin list route through an uncaught TypeError, and
 * the licence routes were gated on "can this person see the Dono admin", which
 * is true for anyone holding any single dono_* cap.
 */
final class AdminListHardeningTest extends IntegrationTestCase
{
    /** @return list<string> */
    private function listRoutes(): array
    {
        return [
            '/dono/v1/admin/donations',
            '/dono/v1/admin/donors',
            '/dono/v1/admin/campaigns',
            '/dono/v1/admin/forms',
            '/dono/v1/admin/funds',
        ];
    }

    public function test_an_overflowing_page_does_not_500_any_list_route(): void
    {
        $admin = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin);

        foreach ($this->listRoutes() as $route) {
            $req = new WP_REST_Request('GET', $route);
            $req->set_param('page', '9223372036854775807');
            $status = rest_do_request($req)->get_status();

            $this->assertLessThan(500, $status, "{$route} survived the overflow");
        }
    }

    public function test_a_readonly_donor_viewer_cannot_delete_the_site_licence(): void
    {
        $viewer = self::factory()->user->create(['role' => 'subscriber']);
        $user   = get_user_by('id', $viewer);
        $user->add_cap('dono_view_donors');
        wp_set_current_user($viewer);

        update_option('dono_pro_license_key', 'VICTIM-REAL-KEY');

        $this->assertSame(403, rest_do_request(new WP_REST_Request('DELETE', '/dono/v1/admin/license'))->get_status());

        $plant = new WP_REST_Request('POST', '/dono/v1/admin/license');
        $plant->set_header('content-type', 'application/json');
        $plant->set_body((string) wp_json_encode(['key' => 'ATTACKER-KEY']));
        $this->assertSame(403, rest_do_request($plant)->get_status());

        $this->assertSame('VICTIM-REAL-KEY', get_option('dono_pro_license_key'), 'the real key is untouched');

        delete_option('dono_pro_license_key');
    }

    public function test_settings_managers_keep_their_licence_access(): void
    {
        $manager = self::factory()->user->create(['role' => 'subscriber']);
        get_user_by('id', $manager)->add_cap('dono_manage_settings');
        wp_set_current_user($manager);

        $this->assertSame(200, rest_do_request(new WP_REST_Request('GET', '/dono/v1/admin/license'))->get_status());
    }
}
