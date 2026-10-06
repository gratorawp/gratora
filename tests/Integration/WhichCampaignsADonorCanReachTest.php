<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Campaigns\Campaign;
use Gratora\Campaigns\CampaignService;
use Gratora\Campaigns\LiveCampaigns;
use Gratora\Foundation\Plugin;

final class WhichCampaignsADonorCanReachTest extends IntegrationTestCase
{
    /** @param array<string,mixed> $input */
    private function campaign(array $input): Campaign
    {
        return Plugin::instance()->container->get(CampaignService::class)->create($input);
    }

    /** @return list<string> */
    private function liveTitles(): array
    {
        return array_map(static fn (Campaign $c): string => (string) $c->title, (new LiveCampaigns())->all());
    }

    public function test_a_site_with_no_campaign_has_none(): void
    {
        $this->assertSame([], (new LiveCampaigns())->all());
        $this->assertNull((new LiveCampaigns())->firstOpen());
    }

    public function test_a_draft_campaign_is_not_one(): void
    {
        $this->campaign(['title' => 'Still a draft', 'status' => 'draft']);

        $this->assertSame([], $this->liveTitles());
    }

    public function test_a_published_campaign_whose_form_is_a_draft_is_not_one(): void
    {
        $this->campaign(['title' => 'No form yet', 'status' => 'published', 'skip_template' => true]);

        $this->assertSame([], $this->liveTitles());
    }

    public function test_a_published_campaign_with_a_published_form_is_one(): void
    {
        $this->campaign(['title' => 'Winter food drive', 'status' => 'published']);

        $this->assertSame(['Winter food drive'], $this->liveTitles());
    }

    public function test_they_come_back_oldest_first_and_the_others_are_left_out(): void
    {
        $this->campaign(['title' => 'First', 'status' => 'published']);
        $this->campaign(['title' => 'A draft between them', 'status' => 'draft']);
        $this->campaign(['title' => 'Second', 'status' => 'published']);

        $this->assertSame(['First', 'Second'], $this->liveTitles());
        $this->assertSame('First', (string) (new LiveCampaigns())->firstOpen()->title);
    }

    /** @return array<string, array{0: array<string,mixed>}> */
    public function campaignsThatArePublishedAndStillShut(): array
    {
        return [
            'one that ended last year'   => [['ends_at' => '2025-01-31']],
            'one that opens next year'   => [['starts_at' => '2027-06-01']],
        ];
    }

    /**
     * A published campaign with a published form can still turn a donor away.
     * The page offered for a first donation has to be one that will take it.
     *
     * @dataProvider campaignsThatArePublishedAndStillShut
     *
     * @param array<string,mixed> $dates
     */
    public function test_the_first_open_one_passes_over_a_campaign_that_is_shut(array $dates): void
    {
        $this->campaign(['title' => 'Shut', 'status' => 'published'] + $dates);
        $this->assertNull((new LiveCampaigns())->firstOpen());

        $this->campaign(['title' => 'Open', 'status' => 'published']);
        $this->assertSame('Open', (string) (new LiveCampaigns())->firstOpen()->title);
    }

    public function test_the_first_open_one_passes_over_a_campaign_with_no_page_to_send_anyone_to(): void
    {
        $pageless = $this->campaign(['title' => 'No page', 'status' => 'published']);
        $pageless->page_id = null;
        $pageless->save();

        $this->assertNull((new LiveCampaigns())->firstOpen());
    }
}
