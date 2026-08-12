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
}
