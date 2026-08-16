<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donors\DonorMetricsService;
use Dono\Donors\DonorService;
use Dono\Donors\Portal\PortalSession;
use Dono\Foundation\Plugin;
use Dono\Vendor\Queryable\DB;
use WP_REST_Request;

/**
 * Reading a donor's record must not create a way to sign in as them, and the
 * link the admin does ask for must be the one the screen describes.
 *
 * The profile payload used to carry a freshly minted portal link, so the number
 * of live logins matched the number of times anyone had opened a donor. A rep
 * working through forty donors left forty credentials, each in a response body
 * and on a screen, none of them asked for.
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
            ->where('purpose', PortalSession::PORTAL_PURPOSE)
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

    /**
     * The purpose is what makes the link a portal sign-in rather than a receipt
     * download, and it is what sign out everywhere looks for when it revokes.
     */
    public function test_the_link_is_issued_under_the_purpose_the_portal_redeems(): void
    {
        $this->admin();
        $id = $this->donorId();

        rest_do_request(new WP_REST_Request('POST', "/dono/v1/admin/donors/{$id}/portal-link"));

        $rows = DB::table('dono_magic_link_tokens')->where('donor_id', $id)->getAll();

        $this->assertCount(1, $rows);
        $this->assertSame(PortalSession::PORTAL_PURPOSE, (string) $rows[0]['purpose']);
    }

    /**
     * The screen prints this value, so a response that disagreed with the token
     * would tell the admin a deadline the link does not have.
     */
    public function test_the_response_states_the_expiry_the_token_carries(): void
    {
        $this->admin();
        $id = $this->donorId();

        $data  = rest_do_request(new WP_REST_Request('POST', "/dono/v1/admin/donors/{$id}/portal-link"))->get_data();
        $token = DB::table('dono_magic_link_tokens')->where('donor_id', $id)->get();

        $this->assertNotEmpty($data['expires_at'] ?? null);
        $this->assertLessThanOrEqual(
            5,
            abs(strtotime((string) $data['expires_at']) - strtotime((string) $token['expires_at'])),
            'the stated deadline is the token deadline'
        );
        $this->assertLessThanOrEqual(
            5,
            abs(strtotime((string) $token['expires_at']) - (time() + DonorMetricsService::STAFF_LINK_TTL)),
            'and the deadline is one link lifetime out'
        );
    }

    /**
     * The card tells the admin the donor can revoke the link. Sign out
     * everywhere is where they do it, and it has to reach a link staff minted,
     * not only the ones the portal mailed.
     */
    public function test_sign_out_everywhere_revokes_a_link_staff_issued(): void
    {
        $this->admin();
        $id = $this->donorId();

        rest_do_request(new WP_REST_Request('POST', "/dono/v1/admin/donors/{$id}/portal-link"));
        $this->assertSame(1, $this->tokenCount($id));

        Plugin::instance()->container->get(PortalSession::class)->destroyAllFor($id);

        $this->assertSame(0, $this->tokenCount($id), 'the unclicked staff link is gone');
    }
}
