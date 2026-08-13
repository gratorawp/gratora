<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Campaigns\Campaign;
use WP_REST_Request;

/**
 * How the cover image is set and cleared over REST.
 *
 * The campaign-image block edits this field from the page editor, so the two
 * values its buttons send have to keep meaning what they mean: an attachment id
 * to set one, and null to take it away. The schema is integer-or-null with a
 * minimum of 1, which quietly makes 0 the wrong way to clear it, and a Remove
 * button that sends 0 fails with a validation error nobody sees.
 */
final class CampaignImageFieldTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    private function campaign(): Campaign
    {
        $req = new WP_REST_Request('POST', '/dono/v1/admin/campaigns');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode(['title' => 'Cover image campaign']));

        $data = (array) rest_do_request($req)->get_data();
        $this->assertArrayHasKey('id', $data, (string) wp_json_encode($data));

        return Campaign::query()->find('id', (int) $data['id']);
    }

    private function patch(int $id, mixed $value): \WP_REST_Response|\WP_Error
    {
        $req = new WP_REST_Request('POST', "/dono/v1/admin/campaigns/{$id}");
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode(['image_attachment_id' => $value]));

        return rest_do_request($req);
    }

    public function test_an_attachment_id_is_stored(): void
    {
        $campaign = $this->campaign();
        // An attachment post is enough: the field stores an id and the shape
        // asks WordPress for its url. Uploading a real file would only test
        // WordPress.
        $attachment = self::factory()->attachment->create_object([
            'file'           => 'cover.png',
            'post_mime_type' => 'image/png',
        ]);

        $res = $this->patch((int) $campaign->id, (int) $attachment);
        $this->assertSame(200, $res->get_status(), (string) wp_json_encode($res->get_data()));

        $show = (array) rest_do_request(
            new WP_REST_Request('GET', '/dono/v1/admin/campaigns/' . (int) $campaign->id)
        )->get_data();

        $this->assertSame((int) $attachment, (int) $show['image_attachment_id']);
    }

    public function test_null_clears_the_image(): void
    {
        $campaign = $this->campaign();

        $res = $this->patch((int) $campaign->id, null);

        $this->assertSame(200, $res->get_status(), (string) wp_json_encode($res->get_data()));
        $this->assertNull(Campaign::query()->find('id', (int) $campaign->id)->image_attachment_id);
    }

    public function test_zero_is_refused_rather_than_treated_as_clearing_it(): void
    {
        $campaign = $this->campaign();

        // If this ever starts passing, the Remove button could send 0 and the
        // difference would stop mattering. While it fails, null is the only way
        // to clear the field and the block has to keep sending it.
        $this->assertGreaterThanOrEqual(400, $this->patch((int) $campaign->id, 0)->get_status());
    }
}
