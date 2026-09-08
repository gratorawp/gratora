<?php

declare(strict_types=1);

namespace FundKit\Tests\Integration;

use FundKit\Campaigns\Blocks\CampaignGridBlock;
use FundKit\Campaigns\Campaign;
use FundKit\Campaigns\Blocks\SupporterWallBlock;
use FundKit\Campaigns\CampaignRepository;
use FundKit\Campaigns\CampaignService;
use FundKit\Donations\Donation;
use FundKit\Donors\DonorService;
use FundKit\Foundation\Plugin;

/**
 * Two public-facing blocks that showed a slice of the truth: a grid card that
 * could only read a money goal, and a wall that listed the most recent
 * donations rather than the campaign's supporters.
 */
final class CampaignBlockCoverageTest extends IntegrationTestCase
{
    private function campaigns(): CampaignService
    {
        return Plugin::instance()->container->get(CampaignService::class);
    }

    private function wall(): SupporterWallBlock
    {
        $c = Plugin::instance()->container;

        return new SupporterWallBlock(
            $c->get(CampaignRepository::class),
            $c->get(\FundKit\Donors\DonorAvatars::class),
        );
    }

    private function grid(): CampaignGridBlock
    {
        $c = Plugin::instance()->container;

        return new CampaignGridBlock(
            $c->get(CampaignRepository::class),
        );
    }

    private function donate(int $campaignId, string $first, string $last, string $paidAt, int $cents = 5000): void
    {
        $donor = Plugin::instance()->container->get(DonorService::class)
            ->findOrCreate(strtolower($first . $last) . '-' . uniqid() . '@example.test', [
                'first_name' => $first,
                'last_name'  => $last,
            ]);

        $d = Donation::make();
        $d->reference         = 'WALL-' . uniqid();
        $d->donor_id          = (int) $donor->id;
        $d->campaign_id       = $campaignId;
        $d->kind              = 'donation';
        $d->amount_cents      = $cents;
        $d->net_cents         = $cents;
        $d->currency          = 'USD';
        $d->base_amount_cents = $cents;
        $d->base_currency     = 'USD';
        $d->fx_rate           = '1.00000000';
        $d->gateway           = 'offline';
        $d->status            = 'paid';
        $d->is_test           = false;
        $d->is_anonymous      = false;
        $d->donor_first_name  = $first;
        $d->donor_last_name   = $last;
        $d->paid_at           = $paidAt;
        $d->created_at        = $paidAt;
        $d->updated_at        = $paidAt;
        $d->save();
    }

    /**
     * The wall read a slice of recent donations and collapsed it, so a
     * supporter whose giving is older than that slice never appeared.
     */
    public function test_an_early_supporter_is_on_the_wall_behind_a_wall_of_recent_ones(): void
    {
        $campaign = $this->campaigns()->create(['title' => 'Long ' . uniqid(), 'status' => 'published']);
        $id = (int) $campaign->id;

        $this->donate($id, 'Aaron', 'Early', gmdate('Y-m-d H:i:s', time() - (400 * 86400)));

        for ($i = 0; $i < 30; $i++) {
            $this->donate($id, 'Recent' . $i, 'Giver', gmdate('Y-m-d H:i:s', time() - ($i * 60)));
        }

        $html = $this->wall()->render(['campaignId' => $id, 'columns' => 'auto', 'limit' => 500], '');

        $this->assertStringContainsString('Aaron', $html, 'an early supporter is still a supporter');
    }

    public function test_alphabetical_is_alphabetical_across_the_whole_campaign(): void
    {
        $campaign = $this->campaigns()->create(['title' => 'Sorted ' . uniqid(), 'status' => 'published']);
        $id = (int) $campaign->id;

        $this->donate($id, 'Aaron', 'Early', gmdate('Y-m-d H:i:s', time() - (400 * 86400)));
        $this->donate($id, 'Zoe', 'Latest', gmdate('Y-m-d H:i:s', time() - 60));

        $html = $this->wall()->render([
            'campaignId' => $id,
            'columns'    => 'auto',
            'sort'       => 'alphabetical',
            'limit'      => 5,
        ], '');

        $this->assertLessThan(
            strpos($html, 'Zoe'),
            strpos($html, 'Aaron'),
            'A comes before Z whenever they gave'
        );
    }

    public function test_the_limit_counts_supporters_not_donations(): void
    {
        $campaign = $this->campaigns()->create(['title' => 'Repeat ' . uniqid(), 'status' => 'published']);
        $id = (int) $campaign->id;

        // One donor, many donations: a limit of 5 must not be spent on them.
        $donor = Plugin::instance()->container->get(DonorService::class)
            ->findOrCreate('repeat-' . uniqid() . '@example.test', ['first_name' => 'Repeat', 'last_name' => 'Giver']);

        for ($i = 0; $i < 8; $i++) {
            $d = Donation::make();
            $d->reference         = 'REP-' . uniqid();
            $d->donor_id          = (int) $donor->id;
            $d->campaign_id       = $id;
            $d->kind              = 'donation';
            $d->amount_cents      = 1000;
            $d->net_cents         = 1000;
            $d->currency          = 'USD';
            $d->base_amount_cents = 1000;
            $d->base_currency     = 'USD';
            $d->fx_rate           = '1.00000000';
            $d->gateway           = 'offline';
            $d->status            = 'paid';
            $d->is_test           = false;
            $d->is_anonymous      = false;
            $d->paid_at           = gmdate('Y-m-d H:i:s', time() - ($i * 60));
            $d->created_at        = $d->paid_at;
            $d->updated_at        = $d->paid_at;
            $d->save();
        }

        $this->donate($id, 'Second', 'Supporter', gmdate('Y-m-d H:i:s', time() - 100000));

        $html = $this->wall()->render(['campaignId' => $id, 'columns' => 'auto', 'limit' => 5], '');

        $this->assertStringContainsString('Second', $html);
        // And the repeat donor is one card carrying their total, not eight.
        $this->assertSame(1, substr_count($html, 'Repeat'));
    }

    public function test_a_count_goal_campaign_shows_its_progress_on_a_card(): void
    {
        $campaign = $this->campaigns()->create([
            'title'      => 'Counted ' . uniqid(),
            'status'     => 'published',
            'goal_type'  => 'donations',
            'goal_count' => 4,
        ]);
        $id = (int) $campaign->id;

        $this->donate($id, 'One', 'Giver', gmdate('Y-m-d H:i:s'));
        $this->donate($id, 'Two', 'Giver', gmdate('Y-m-d H:i:s'));

        // The counters on the campaign row are maintained by the syncer, not
        // by writing a donation.
        Plugin::instance()->container->get(\FundKit\Donations\AggregateSyncer::class)->syncCampaign($id);

        $other = $this->campaigns()->create(['title' => 'Other ' . uniqid(), 'status' => 'published']);

        $html = $this->grid()->render(['currentCampaignId' => (int) $other->id], '');

        $this->assertStringContainsString('of 4', $html, 'the card names the target it is measured against');
        $this->assertStringContainsString('donations', $html);
        $this->assertStringContainsString('50', $html, 'two of four is half way');
    }

    /**
     * A "Browse our campaigns" page with the grid on it and nothing published
     * told visitors "This is the only campaign running right now", naming
     * something the page does not contain, and invited them to give to it.
     */
    public function test_a_browse_page_with_nothing_to_list_does_not_claim_a_campaign(): void
    {
        $html = $this->grid()->render(['count' => 3], '');

        $this->assertStringNotContainsString('only campaign', $html);
        $this->assertStringNotContainsString('easy choice', $html);
        $this->assertStringContainsString('No campaigns are running right now.', $html);
    }

    /** On a campaign's own page the sentence has something to point at. */
    public function test_a_campaign_page_still_says_it_is_the_only_one(): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $c = Campaign::make();
        $c->title      = 'Only one';
        $c->slug       = 'only-' . uniqid();
        $c->status     = 'published';
        $c->created_at = $now;
        $c->updated_at = $now;
        $c->save();

        $html = $this->grid()->render(['campaignId' => (int) $c->id, 'count' => 3], '');

        $this->assertStringContainsString('only campaign', $html);
    }
}
