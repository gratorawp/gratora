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
 * The token this command mints is a bearer credential that opens a full donor
 * session: history, address, and the power to cancel or re-price a plan. The
 * lifetime a caller asks for therefore cannot exceed the longest one the
 * product issues anywhere else, which is the staff-issued link.
 */
final class MagicLinkCommandTtlTest extends IntegrationTestCase
{
    public function test_an_oversized_ttl_is_capped_at_the_staff_link_lifetime(): void
    {
        $donorId = $this->donorId();
        $this->actAsAdmin();

        $res = $this->issue($donorId, 315_360_000);

        $this->assertSame(200, $res->get_status(), (string) wp_json_encode($res->get_data()));

        $expires = strtotime((string) $this->token($donorId)['expires_at']);
        $this->assertLessThanOrEqual(
            time() + DonorMetricsService::STAFF_LINK_TTL + 5,
            $expires,
            'a ten-year request must not outlive the staff-issued link'
        );
    }

    public function test_a_shorter_ttl_is_honoured_rather_than_raised_to_the_cap(): void
    {
        $donorId = $this->donorId();
        $this->actAsAdmin();

        $res = $this->issue($donorId, HOUR_IN_SECONDS);

        $this->assertSame(200, $res->get_status(), (string) wp_json_encode($res->get_data()));

        $expires = strtotime((string) $this->token($donorId)['expires_at']);
        $this->assertLessThanOrEqual(
            time() + HOUR_IN_SECONDS + 5,
            $expires,
            'a caller asking for an hour keeps the hour'
        );
        $this->assertGreaterThan(time() + HOUR_IN_SECONDS - 60, $expires);
    }

    private function issue(int $donorId, int $ttl): \WP_REST_Response
    {
        $req = new WP_REST_Request('POST', '/dono/v1/admin/commands/donor.magic_link.issue');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode([
            'input' => [
                'donor_id'    => $donorId,
                'purpose'     => PortalSession::PORTAL_PURPOSE,
                'ttl_seconds' => $ttl,
            ],
        ]));

        return rest_do_request($req);
    }

    /** @return array<string,mixed> */
    private function token(int $donorId): array
    {
        $row = DB::table('dono_magic_link_tokens')->where('donor_id', $donorId)->get();
        $this->assertNotEmpty($row, 'the command must have written a token row');

        return (array) $row;
    }

    private function actAsAdmin(): void
    {
        get_role('administrator')->add_cap('dono_edit_donors');
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    private function donorId(): int
    {
        return (int) Plugin::instance()->container->get(DonorService::class)
            ->findOrCreate('ttl-' . uniqid() . '@example.test')->id;
    }
}
