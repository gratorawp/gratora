<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Campaigns\Campaign;
use Gratora\Campaigns\CampaignService;
use Gratora\Campaigns\LiveCampaigns;
use Gratora\Campaigns\StarterCampaign;
use Gratora\Forms\Form;
use Gratora\Foundation\Plugin;
use WP_REST_Request;
use WP_REST_Response;

final class TheWizardCanMakeTheFirstDonationPageTest extends IntegrationTestCase
{
    private function press(): WP_REST_Response
    {
        return rest_do_request(new WP_REST_Request('POST', '/gratora/v1/admin/onboarding/starter-campaign'));
    }

    private function campaigns(): CampaignService
    {
        return Plugin::instance()->container->get(CampaignService::class);
    }

    private function titleOfWhatItMakes(): string
    {
        $made = Campaign::query()->find('id', (int) $this->press()->get_data()['campaign_id']);

        return (string) $made->title;
    }

    public function test_it_makes_a_page_that_can_take_a_donation_at_once(): void
    {
        $res = $this->press();

        $this->assertSame(200, $res->get_status());

        $campaign = Campaign::query()->find('id', (int) $res->get_data()['campaign_id']);
        $this->assertSame('published', (string) $campaign->status);
        $this->assertSame('published', (string) Form::query()->find('id', (int) $campaign->default_form_id)->status);
        $this->assertSame('publish', get_post_status((int) $campaign->page_id));
        $this->assertSame(get_permalink((int) $campaign->page_id), $res->get_data()['page_url']);
        $this->assertCount(1, (new LiveCampaigns())->all());
    }

    public function test_the_page_is_named_after_the_organization(): void
    {
        update_option('gratora_org_profile', ['name' => 'Riverside Food Bank']);

        $this->assertSame('Support Riverside Food Bank', $this->titleOfWhatItMakes());
    }

    public function test_with_no_organization_name_it_takes_the_site_s_name_as_written(): void
    {
        delete_option('gratora_org_profile');
        update_option('blogname', 'Cats &amp; Dogs Rescue');

        $this->assertSame('Support Cats & Dogs Rescue', $this->titleOfWhatItMakes());
    }

    public function test_a_site_with_no_name_at_all_gets_a_page_called_donate(): void
    {
        delete_option('gratora_org_profile');
        update_option('blogname', '');

        $this->assertSame('Donate', $this->titleOfWhatItMakes());
    }

    public function test_pressing_twice_makes_one_campaign(): void
    {
        $first  = $this->press();
        $second = $this->press();

        $this->assertSame(200, $second->get_status());
        $this->assertSame($first->get_data()['campaign_id'], $second->get_data()['campaign_id']);
        $this->assertSame($first->get_data()['page_url'], $second->get_data()['page_url']);
        $this->assertCount(1, Campaign::query()->getAll());
    }

    public function test_a_site_that_already_has_a_campaign_is_refused(): void
    {
        $this->campaigns()->create(['title' => 'Made by hand', 'status' => 'draft']);

        $res = $this->press();

        $this->assertSame(409, $res->get_status());
        $this->assertSame('gratora_starter_campaign_refused', $res->get_data()['code']);
        $this->assertCount(1, Campaign::query()->getAll());
    }

    public function test_after_its_campaign_is_deleted_it_makes_a_new_one(): void
    {
        $first = Campaign::query()->find('id', (int) $this->press()->get_data()['campaign_id']);
        $this->campaigns()->delete($first);

        $res = $this->press();

        $this->assertSame(200, $res->get_status());
        $this->assertNotSame((int) $first->id, (int) $res->get_data()['campaign_id']);
        $this->assertCount(1, Campaign::query()->getAll());
    }

    public function test_a_request_that_is_making_the_page_right_now_is_not_doubled(): void
    {
        update_option(StarterCampaign::OPTION, 'creating.' . time(), false);

        $res = $this->press();

        $this->assertSame(409, $res->get_status());
        $this->assertCount(0, Campaign::query()->getAll());
    }

    public function test_a_claim_nobody_finished_does_not_block_the_button_for_good(): void
    {
        update_option(StarterCampaign::OPTION, 'creating.' . (time() - HOUR_IN_SECONDS), false);

        $res = $this->press();

        $this->assertSame(200, $res->get_status());
        $this->assertCount(1, Campaign::query()->getAll());
    }

    public function test_a_page_that_could_not_be_made_leaves_the_button_working(): void
    {
        add_filter('wp_insert_post_empty_content', '__return_true');
        $failed = $this->press();
        remove_filter('wp_insert_post_empty_content', '__return_true');

        $this->assertSame(500, $failed->get_status());
        $this->assertCount(0, Campaign::query()->getAll());

        $this->assertSame(200, $this->press()->get_status());
    }

    public function test_someone_who_may_not_create_campaigns_is_refused(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));

        $res = $this->press();

        $this->assertSame(403, $res->get_status());
        $this->assertCount(0, Campaign::query()->getAll());
    }
}
