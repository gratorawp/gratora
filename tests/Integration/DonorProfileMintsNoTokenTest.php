<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donors\DonorService;
use Dono\Foundation\Plugin;
use Dono\Vendor\Queryable\DB;
use WP_REST_Request;

/**
 * Reading a donor's record must not create a way to sign in as them.
 *
 * The profile payload used to carry a freshly minted portal link, so the number
 * of live thirty-day logins matched the number of times anyone had opened a
 * donor. A rep working through forty donors left forty credentials, each in a
 * response body and on a screen, none of them asked for and none revocable.
 */
final class DonorProfileMintsNoTokenTest extends IntegrationTestCase
{
    private function admin(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    private function tokenCount(int $donorId): int
    {
        return (int) DB::table('dono_magic_link_tokens')
            ->where('donor_id', $donorId)
            ->where('purpose', 'donor_portal')
            ->count();
    }

    private function donorId(): int
    {
        return (int) Plugin::instance()->container->get(DonorService::class)
            ->findOrCreate('profile-' . uniqid() . '@example.test')->id;
    }

    public function test_opening_a_profile_mints_nothing(): void
    {
        $this->admin();
        $id = $this->donorId();

        rest_do_request(new WP_REST_Request('GET', "/dono/v1/admin/donors/{$id}/profile"));
        rest_do_request(new WP_REST_Request('GET', "/dono/v1/admin/donors/{$id}/profile"));
        rest_do_request(new WP_REST_Request('GET', "/dono/v1/admin/donors/{$id}/profile"));

        $this->assertSame(0, $this->tokenCount($id), 'three reads, no credentials');
    }

    public function test_the_profile_payload_carries_no_link(): void
    {
        $this->admin();
        $id = $this->donorId();

        $data = rest_do_request(new WP_REST_Request('GET', "/dono/v1/admin/donors/{$id}/profile"))->get_data();

        $this->assertNull($data['donor']['magic_link_url'] ?? null);
    }

    public function test_asking_for_a_link_issues_exactly_one(): void
    {
        $this->admin();
        $id = $this->donorId();

        $res = rest_do_request(new WP_REST_Request('POST', "/dono/v1/admin/donors/{$id}/portal-link"));

        $this->assertSame(201, $res->get_status());
        $this->assertStringContainsString('token=', (string) ($res->get_data()['magic_link_url'] ?? ''));
        $this->assertSame(1, $this->tokenCount($id));
    }

    public function test_a_view_only_role_cannot_mint_one(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));
        $id = $this->donorId();

        $res = rest_do_request(new WP_REST_Request('POST', "/dono/v1/admin/donors/{$id}/portal-link"));

        $this->assertGreaterThanOrEqual(400, $res->get_status());
        $this->assertSame(0, $this->tokenCount($id));
    }
}
