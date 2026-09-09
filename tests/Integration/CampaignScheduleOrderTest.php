<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use WP_REST_Request;

/**
 * A campaign whose end precedes its start refuses every donation and says only
 * "has not started yet" or "has ended", never that the two dates contradict
 * each other. The timeline's drag handles already refuse the crossing; the
 * date pickers wrote it straight through.
 */
final class CampaignScheduleOrderTest extends IntegrationTestCase
{
    private int $campaignId;

    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        $req = new WP_REST_Request('POST', '/gratora/v1/admin/campaigns');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode(['title' => 'Schedule campaign', 'status' => 'published']));
        $this->campaignId = (int) rest_do_request($req)->get_data()['id'];
    }

    /** @param array<string,mixed> $data */
    private function update(array $data): \WP_REST_Response
    {
        $req = new WP_REST_Request('PUT', '/gratora/v1/admin/campaigns/' . $this->campaignId);
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode($data));

        return rest_do_request($req);
    }

    public function test_an_end_before_the_start_is_refused(): void
    {
        $res = $this->update([
            'starts_at' => '2026-06-01 00:00:00',
            'ends_at'   => '2026-05-01 00:00:00',
        ]);

        $this->assertGreaterThanOrEqual(400, $res->get_status(), 'the pair contradicts itself');
    }

    public function test_the_right_way_round_is_accepted(): void
    {
        $res = $this->update([
            'starts_at' => '2026-05-01 00:00:00',
            'ends_at'   => '2026-06-01 00:00:00',
        ]);

        $this->assertSame(200, $res->get_status());
    }

    public function test_the_same_moment_is_accepted(): void
    {
        $res = $this->update([
            'starts_at' => '2026-05-01 09:00:00',
            'ends_at'   => '2026-05-01 09:00:00',
        ]);

        $this->assertSame(200, $res->get_status(), 'an instant window is odd but not contradictory');
    }

    public function test_only_one_date_set_is_still_fine(): void
    {
        $this->assertSame(200, $this->update(['ends_at' => '2026-01-01 00:00:00'])->get_status());
        $this->assertSame(200, $this->update(['starts_at' => '2027-01-01 00:00:00', 'ends_at' => null])->get_status());
    }
}
