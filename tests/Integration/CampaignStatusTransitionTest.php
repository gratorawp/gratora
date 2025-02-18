<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Campaigns\CampaignRepository;
use Dono\Campaigns\CampaignService;
use Dono\Foundation\Plugin;
use WP_REST_Request;

/**
 * Regression for QA bug #1: a fresh draft campaign had no obvious way to be
 * published from the admin UI. The fix added Publish / Move to draft items
 * to the HeaderMenu wired through the existing PUT /admin/campaigns/{id}
 * endpoint. This locks the server-side contract those items rely on:
 *
 *   draft -> published   (Publish campaign)
 *   published -> draft   (Move to draft)
 *
 * Both directions land the persisted status correctly and don't bleed into
 * the archived/trashed transitions (which have their own menu items).
 */
final class CampaignStatusTransitionTest extends IntegrationTestCase
{
    public function test_put_status_published_publishes_a_draft(): void
    {
        $id = $this->seedDraft();

        $res = $this->put("/dono/v1/admin/campaigns/{$id}", ['status' => 'published']);
        $this->assertSame(200, $res->get_status());
        $this->assertSame('published', $res->get_data()['status'] ?? '');

        $row = (new CampaignRepository())->findById($id);
        $this->assertSame('published', $row->status, 'campaign is persisted as published');
    }

    public function test_put_status_draft_unpublishes_a_published_campaign(): void
    {
        $id = $this->seedDraft();
        $this->put("/dono/v1/admin/campaigns/{$id}", ['status' => 'published']);

        $res = $this->put("/dono/v1/admin/campaigns/{$id}", ['status' => 'draft']);
        $this->assertSame(200, $res->get_status());
        $this->assertSame('draft', $res->get_data()['status'] ?? '');

        $row = (new CampaignRepository())->findById($id);
        $this->assertSame('draft', $row->status, 'campaign is persisted as draft');
    }

    public function test_put_rejects_unknown_status(): void
    {
        $id = $this->seedDraft();

        // 'active' is the UI-facing label; the REST API speaks 'published'.
        // Sending the wrong value must 400, not silently no-op.
        $res = $this->put("/dono/v1/admin/campaigns/{$id}", ['status' => 'active']);
        $this->assertSame(400, $res->get_status(), '"active" is not a valid status enum value');
    }

    private function seedDraft(): int
    {
        $service = Plugin::instance()->container->get(CampaignService::class);
        $campaign = $service->create([
            'title'    => 'Status Transition Test',
            'currency' => 'EUR',
            'status'   => 'draft',
        ]);
        return (int) $campaign->id;
    }

    /** @param array<string,mixed> $body */
    private function put(string $path, array $body): \WP_REST_Response
    {
        $req = new WP_REST_Request('PUT', $path);
        $req->set_header('content-type', 'application/json');
        $req->set_body(json_encode($body));
        return rest_do_request($req);
    }
}
