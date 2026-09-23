<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Campaigns\Blocks\CampaignGridBlock;
use Gratora\Campaigns\Blocks\CampaignProgressBlock;
use Gratora\Campaigns\Campaign;
use Gratora\Campaigns\CampaignRepository;
use Gratora\Campaigns\CampaignService;
use Gratora\Campaigns\CampaignStatMetrics;
use Gratora\Foundation\Plugin;

/**
 * A bar cannot overflow its track, so the fill is capped. The figure printed
 * beside it is a different question, and every donor-facing surface answered
 * both with the same number: a campaign standing at 112 per cent told a
 * visitor it had reached exactly 100, like one that had just arrived.
 *
 * The admin settled this first, in aGoalPastItsTargetSaysSo.test.js.
 */
final class AGoalPastItsTargetSaysSoTest extends IntegrationTestCase
{
    private function campaigns(): CampaignService
    {
        return Plugin::instance()->container->get(CampaignService::class);
    }

    /** A campaign that raised 8,940 against a goal of 8,000: 112 per cent. */
    private function pastItsGoal(): Campaign
    {
        $campaign = $this->campaigns()->create([
            'title'      => 'Past it ' . uniqid(),
            'status'     => 'published',
            'goal_type'  => 'amount',
            'goal_cents' => 800000,
        ]);

        $campaign->raised_cents = 894000;
        $campaign->save();

        return $campaign;
    }

    private function progress(): CampaignProgressBlock
    {
        return new CampaignProgressBlock(Plugin::instance()->container->get(CampaignRepository::class));
    }

    /** The registered block always arrives with its defaults filled in. */
    private function renderProgress(Campaign $campaign): string
    {
        return $this->progress()->render([
            'campaignId' => (int) $campaign->id,
            'showLabels' => true,
            'align'      => 'left',
        ], '');
    }

    private function grid(): CampaignGridBlock
    {
        return new CampaignGridBlock(Plugin::instance()->container->get(CampaignRepository::class));
    }

    public function test_the_progress_block_prints_the_real_figure(): void
    {
        $campaign = $this->pastItsGoal();

        $html = $this->renderProgress($campaign);

        $this->assertStringContainsString('112%', $html);
        $this->assertStringNotContainsString('100% of', $html);
    }

    public function test_the_progress_bar_stops_at_the_end_of_its_track(): void
    {
        $campaign = $this->pastItsGoal();

        $html = $this->renderProgress($campaign);

        $this->assertStringContainsString('width: 100%', $html);
        $this->assertStringNotContainsString('width: 112%', $html);
    }

    /**
     * aria-valuemax is pinned at 100, so a valuenow above it is out of range
     * and assistive tech is free to announce anything it likes.
     */
    public function test_the_progress_bar_does_not_report_a_value_past_its_maximum(): void
    {
        $campaign = $this->pastItsGoal();

        $html = $this->renderProgress($campaign);

        $this->assertStringContainsString('aria-valuenow="100"', $html);
        $this->assertStringNotContainsString('aria-valuenow="112"', $html);
    }

    public function test_a_grid_card_prints_the_real_figure_and_caps_its_bar(): void
    {
        $campaign = $this->pastItsGoal();
        $other    = $this->campaigns()->create(['title' => 'Other ' . uniqid(), 'status' => 'published']);

        $html = $this->grid()->render(['currentCampaignId' => (int) $other->id], '');

        $this->assertStringContainsString('112%', $html);
        $this->assertStringContainsString('width: 100%', $html);
        $this->assertStringContainsString('aria-valuenow="100"', $html);
        $this->assertStringNotContainsString('width: 112%', $html);
    }

    public function test_the_stat_block_prints_the_real_figure(): void
    {
        $campaign = $this->pastItsGoal();
        $metrics  = Plugin::instance()->container->get(CampaignStatMetrics::class);

        $this->assertSame('111%', $metrics->value($campaign, 'percent'));
    }
}
