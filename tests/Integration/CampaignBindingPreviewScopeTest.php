<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Campaigns\Campaign;
use WP_REST_Request;

/**
 * The binding preview reads a campaign_id straight off the request, so it has
 * to resolve through the same gate the rendered page does. Without it, anyone
 * the site lets edit a page could read any campaign on the site by id,
 * including drafts and archived ones.
 */
final class CampaignBindingPreviewScopeTest extends IntegrationTestCase
{
    private function campaign(string $status): Campaign
    {
        $c = Campaign::make();
        $c->title      = 'Preview scope';
        $c->slug       = 'ps-' . uniqid();
        $c->status     = $status;
        $c->created_at = gmdate('Y-m-d H:i:s');
        $c->updated_at = $c->created_at;
        $c->save();

        return $c;
    }

    private function preview(int $campaignId): array
    {
        // The route takes the edited post id in the path; campaign_id pins an
        // explicit campaign, which is the parameter that was unchecked.
        $req = new WP_REST_Request('GET', '/dono/v1/campaign-binding-preview/1');
        $req->set_param('id', 1);
        $req->set_param('campaign_id', $campaignId);

        return (array) rest_do_request($req)->get_data();
    }

    public function test_a_contributor_cannot_read_a_draft_campaign(): void
    {
        $draft = $this->campaign('draft');

        wp_set_current_user(self::factory()->user->create(['role' => 'contributor']));

        $data = $this->preview((int) $draft->id);

        $this->assertSame(0, (int) ($data['campaign_id'] ?? -1));
        $this->assertArrayHasKey('campaign', $data);
        $this->assertNull($data['campaign'], 'a draft is not theirs to read');
    }

    public function test_a_contributor_can_still_read_a_published_campaign(): void
    {
        $live = $this->campaign('published');

        wp_set_current_user(self::factory()->user->create(['role' => 'contributor']));

        $data = $this->preview((int) $live->id);

        $this->assertSame(
            (int) $live->id,
            (int) ($data['campaign_id'] ?? 0),
            'a published campaign is public, so preview must keep working'
        );
    }

    public function test_a_campaign_manager_still_sees_the_draft(): void
    {
        $draft = $this->campaign('draft');

        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        $this->assertSame((int) $draft->id, (int) ($this->preview((int) $draft->id)['campaign_id'] ?? 0));
    }
}
