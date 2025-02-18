<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use WP_REST_Request;

final class AdminFormsTest extends IntegrationTestCase
{
    private int $campaignId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->campaignId = $this->createCampaign();
    }

    public function test_index_returns_the_auto_created_default_form(): void
    {
        // setUp() created one campaign, which auto-creates one default form.
        $res = $this->get('/dono/v1/admin/forms');
        $this->assertSame(200, $res->get_status());
        $this->assertCount(1, $res->get_data());
        $this->assertSame('1', $res->get_headers()['X-WP-Total'] ?? '0');
    }

    public function test_campaigns_endpoint_returns_pickable_list(): void
    {
        $res = $this->get('/dono/v1/admin/forms/campaigns');
        $this->assertSame(200, $res->get_status());

        $list = $res->get_data();
        $this->assertNotEmpty($list);
        $slugs = array_column($list, 'slug');
        $this->assertContains('campaign-1', $slugs);
    }

    public function test_create_form_rejects_when_campaign_id_missing(): void
    {
        $res = $this->post('/dono/v1/admin/forms', [
            'title' => 'No campaign',
        ]);
        // FormSchemas::create() marks campaign_id required → caught at the
        // REST boundary before the service layer runs.
        $this->assertSame(400, $res->get_status());
        $this->assertSame('rest_missing_callback_param', $res->get_data()['code']);
    }

    public function test_create_form_with_explicit_campaign_id(): void
    {
        $res = $this->post('/dono/v1/admin/forms', [
            'title'       => 'Linked',
            'campaign_id' => $this->campaignId,
        ]);
        $this->assertSame(201, $res->get_status());
        $this->assertSame($this->campaignId, $res->get_data()['campaign_id']);
    }

    public function test_create_form_collides_slug_with_existing_one_and_suffixes(): void
    {
        $this->post('/dono/v1/admin/forms', ['title' => 'My form', 'campaign_id' => $this->campaignId]);
        $second = $this->post('/dono/v1/admin/forms', ['title' => 'My form', 'campaign_id' => $this->campaignId])->get_data();

        $this->assertSame('my-form-2', $second['slug']);
    }

    public function test_create_form_rejects_unknown_campaign_id(): void
    {
        $res = $this->post('/dono/v1/admin/forms', [
            'title'       => 'Bad ref',
            'campaign_id' => 99999,
        ]);
        $this->assertSame(422, $res->get_status());
        $this->assertSame('dono_invalid_input', $res->get_data()['code']);
    }

    public function test_show_returns_full_form_including_blocks(): void
    {
        $created = $this->post('/dono/v1/admin/forms', [
            'title'       => 'With blocks',
            'campaign_id' => $this->campaignId,
            'blocks'      => '<!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph -->',
        ])->get_data();

        $res = $this->get("/dono/v1/admin/forms/{$created['id']}");
        $this->assertSame(200, $res->get_status());
        $this->assertSame($created['id'], $res->get_data()['id']);
        $this->assertStringContainsString('wp:paragraph', $res->get_data()['blocks']);
    }

    public function test_show_404s_unknown_form(): void
    {
        $res = $this->get('/dono/v1/admin/forms/9999');
        $this->assertSame(404, $res->get_status());
    }

    public function test_update_form_partial_only_touches_supplied_fields(): void
    {
        $created = $this->post('/dono/v1/admin/forms', [
            'title'       => 'Initial',
            'campaign_id' => $this->campaignId,
            'blocks'      => 'OLD',
        ])->get_data();

        $res = $this->put("/dono/v1/admin/forms/{$created['id']}", [
            'title' => 'Renamed',
        ]);
        $this->assertSame(200, $res->get_status());

        $reloaded = $this->get("/dono/v1/admin/forms/{$created['id']}")->get_data();
        $this->assertSame('Renamed', $reloaded['title']);
        $this->assertSame('OLD',     $reloaded['blocks'], 'Blocks untouched when not in payload');
    }

    public function test_update_form_publishes_and_stamps_published_at(): void
    {
        // A published form must carry the required blocks (Amount + Name + Email);
        // FormService rejects publishing an empty form with 422.
        $created = $this->post('/dono/v1/admin/forms', [
            'title'       => 'Draft',
            'campaign_id' => $this->campaignId,
            'blocks'      => '<!-- wp:dono/donation-amount /--><!-- wp:dono/name /--><!-- wp:dono/email /-->',
        ])->get_data();
        $this->assertNull($created['published_at']);

        $res = $this->put("/dono/v1/admin/forms/{$created['id']}", ['status' => 'published']);
        $this->assertSame(200, $res->get_status());
        $this->assertSame('published', $res->get_data()['status']);
        $this->assertNotNull($res->get_data()['published_at']);
    }

    public function test_update_rejects_slug_collision_with_a_different_form(): void
    {
        $a = $this->post('/dono/v1/admin/forms', ['title' => 'Alpha form', 'campaign_id' => $this->campaignId])->get_data();
        $b = $this->post('/dono/v1/admin/forms', ['title' => 'Beta form',  'campaign_id' => $this->campaignId])->get_data();

        $res = $this->put("/dono/v1/admin/forms/{$b['id']}", ['slug' => $a['slug']]);
        $this->assertSame(422, $res->get_status());
        $this->assertSame('dono_invalid_input', $res->get_data()['code']);
    }

    public function test_delete_removes_a_form(): void
    {
        $created = $this->post('/dono/v1/admin/forms', ['title' => 'Disposable', 'campaign_id' => $this->campaignId])->get_data();

        $res = $this->deleteReq("/dono/v1/admin/forms/{$created['id']}");
        $this->assertSame(200, $res->get_status());
        $this->assertTrue($res->get_data()['deleted']);

        $this->assertSame(404, $this->get("/dono/v1/admin/forms/{$created['id']}")->get_status());
    }

    public function test_delete_blocks_the_campaign_default_form(): void
    {
        // The campaign was created via REST in setUp, which auto-creates a
        // default form. Find it and try to delete - the service must refuse.
        $campaign = \Dono\Campaigns\Campaign::query()->find('id', $this->campaignId);
        $this->assertNotNull($campaign);
        $defaultFormId = (int) $campaign->default_form_id;
        $this->assertGreaterThan(0, $defaultFormId, 'campaign auto-creates a default form');

        $res = $this->deleteReq("/dono/v1/admin/forms/{$defaultFormId}");
        $this->assertSame(422, $res->get_status());
        $this->assertSame('dono_form_delete_blocked', $res->get_data()['code']);

        // Form still exists.
        $this->assertSame(200, $this->get("/dono/v1/admin/forms/{$defaultFormId}")->get_status());
    }

    public function test_search_filters_by_title_substring(): void
    {
        $this->post('/dono/v1/admin/forms', ['title' => 'Summer appeal',     'campaign_id' => $this->campaignId]);
        $this->post('/dono/v1/admin/forms', ['title' => 'Winter appeal',     'campaign_id' => $this->campaignId]);
        $this->post('/dono/v1/admin/forms', ['title' => 'Spring fundraiser', 'campaign_id' => $this->campaignId]);

        $res = $this->get('/dono/v1/admin/forms', ['search' => 'appeal']);
        $titles = array_column($res->get_data(), 'title');
        $this->assertContains('Summer appeal', $titles);
        $this->assertContains('Winter appeal', $titles);
        $this->assertNotContains('Spring fundraiser', $titles);
    }

    public function test_preview_endpoint_renders_blocks_into_full_html_doc(): void
    {
        $blocks = '<!-- wp:dono/donation-amount {"presets":[500,1500],"currency":"USD"} /-->'
                . '<!-- wp:dono/submit-button {"label":"Send"} /-->';

        $res = $this->post('/dono/v1/admin/forms/preview', ['blocks' => $blocks]);
        $this->assertSame(200, $res->get_status());

        $html = $res->get_data()['html'];
        $this->assertStringStartsWith('<!DOCTYPE html>', $html);
        $this->assertStringContainsString('dono-donation-form--blocks', $html);
        $this->assertStringContainsString('data-cents="500"',  $html);
        $this->assertStringContainsString('data-cents="1500"', $html);
        $this->assertStringContainsString('Send', $html);
        $this->assertMatchesRegularExpression(
            '/<link rel="stylesheet" href="[^"]+build\/donation-form\/runtime\.css/',
            $html
        );
    }

    public function test_preview_endpoint_with_empty_body_returns_empty_form(): void
    {
        $res = $this->post('/dono/v1/admin/forms/preview', []);
        $this->assertSame(200, $res->get_status());
        $this->assertStringContainsString('dono-donation-form--blocks', $res->get_data()['html']);
    }

    // helpers

    private function createCampaign(): int
    {
        $res = $this->post('/dono/v1/admin/campaigns', ['title' => 'Campaign 1', 'status' => 'published']);
        return (int) $res->get_data()['id'];
    }

    private function get(string $path, array $params = []): \WP_REST_Response
    {
        $req = new WP_REST_Request('GET', $path);
        if (! empty($params)) $req->set_query_params($params);
        return rest_do_request($req);
    }

    private function post(string $path, array $body): \WP_REST_Response
    {
        $req = new WP_REST_Request('POST', $path);
        $req->set_header('content-type', 'application/json');
        $req->set_body(json_encode($body));
        return rest_do_request($req);
    }

    private function put(string $path, array $body): \WP_REST_Response
    {
        $req = new WP_REST_Request('PUT', $path);
        $req->set_header('content-type', 'application/json');
        $req->set_body(json_encode($body));
        return rest_do_request($req);
    }

    private function deleteReq(string $path): \WP_REST_Response
    {
        $req = new WP_REST_Request('DELETE', $path);
        return rest_do_request($req);
    }
}
