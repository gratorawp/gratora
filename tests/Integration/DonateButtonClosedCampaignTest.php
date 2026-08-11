<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Campaigns\Campaign;
use WP_REST_Request;

/**
 * The donate button opens a modal that the view only emits alongside the
 * form HTML. The form gate returns nothing once a campaign is outside its
 * schedule, so a button rendered in that state is a click that does nothing
 * at all: no message, no navigation, no error.
 */
final class DonateButtonClosedCampaignTest extends IntegrationTestCase
{
    private int $campaignId;

    protected function setUp(): void
    {
        parent::setUp();

        $req = new WP_REST_Request('POST', '/dono/v1/admin/campaigns');
        $req->set_header('content-type', 'application/json');
        $req->set_body(json_encode(['title' => 'Button gate campaign', 'status' => 'published']));
        $this->campaignId = (int) rest_do_request($req)->get_data()['id'];
    }

    public function test_an_open_campaign_still_renders_the_button_and_its_modal(): void
    {
        $html = $this->renderButton();

        $this->assertStringContainsString('dono-donate-button', $html);
        $this->assertStringContainsString('dono-donate-modal', $html);
        $this->assertStringNotContainsString('dono-block__empty', $html);
    }

    public function test_an_ended_campaign_explains_itself_instead_of_rendering_a_dead_button(): void
    {
        $this->schedule(null, gmdate('Y-m-d', strtotime('-1 day')));

        $html = $this->renderButton();

        $this->assertStringNotContainsString('dono-donate-button', $html);
        $this->assertStringContainsString('This campaign has finished accepting donations.', $html);
    }

    /**
     * The sharper case: a pre-launch landing page whose button would be dead
     * for every visitor through the whole anticipation window.
     */
    public function test_a_scheduled_campaign_explains_itself_instead_of_rendering_a_dead_button(): void
    {
        $this->schedule(gmdate('Y-m-d', strtotime('+2 days')), null);

        $html = $this->renderButton();

        $this->assertStringNotContainsString('dono-donate-button', $html);
        $this->assertStringContainsString('Donations are not open for this campaign yet.', $html);
    }

    public function test_an_editor_is_told_which_setting_to_look_at(): void
    {
        $this->schedule(null, gmdate('Y-m-d', strtotime('-1 day')));

        $html = $this->renderButton();

        $this->assertStringContainsString('dono-block-notice', $html);
        $this->assertStringContainsString('check its schedule', $html);
    }

    public function test_a_visitor_gets_the_explanation_without_the_editor_notice(): void
    {
        $this->schedule(null, gmdate('Y-m-d', strtotime('-1 day')));
        wp_set_current_user(0);

        $html = $this->renderButton();

        $this->assertStringContainsString('This campaign has finished accepting donations.', $html);
        $this->assertStringNotContainsString('dono-block-notice', $html);
    }

    private function schedule(?string $startsAt, ?string $endsAt): void
    {
        $campaign = Campaign::query()->find('id', $this->campaignId);
        $campaign->starts_at = $startsAt;
        $campaign->ends_at   = $endsAt;
        $campaign->save();
    }

    private function renderButton(): string
    {
        $pageId = wp_insert_post([
            'post_title'   => 'Donate button page',
            'post_status'  => 'publish',
            'post_type'    => 'page',
            'post_content' => sprintf(
                '<!-- wp:dono/donate-button {"campaignId":%d} /-->',
                $this->campaignId
            ),
            'meta_input'   => ['_dono_campaign_id' => $this->campaignId],
        ]);

        global $post;
        $post = get_post((int) $pageId);
        setup_postdata($post);
        try {
            return do_blocks($post->post_content);
        } finally {
            wp_reset_postdata();
        }
    }
}
