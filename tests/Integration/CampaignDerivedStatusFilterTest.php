<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Campaigns\Campaign;
use Gratora\Campaigns\CampaignRepository;

/**
 * The list badges a campaign by its derived state, so the filter has to derive
 * the same one. If the two disagree, filtering by Ended returns a set whose
 * rows are badged something else, which is worse than having no filter.
 */
final class CampaignDerivedStatusFilterTest extends IntegrationTestCase
{
    private CampaignRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new CampaignRepository();
    }

    private function make(string $title, array $props = []): Campaign
    {
        $c = new Campaign();
        $c->title  = $title;
        $c->slug   = sanitize_title($title) . '-' . wp_generate_password(6, false);
        $c->status = 'published';
        $c->goal_type = 'amount';
        $c->currency = 'USD';

        foreach ($props as $k => $v) {
            $c->$k = $v;
        }

        $c->save();
        return $c;
    }

    /** @return array<string> */
    private function titlesFor(string $status): array
    {
        $items = $this->repo->listAdmin(['status' => $status, 'per_page' => 100])['items'];
        return array_map(static fn (Campaign $c): string => $c->title, $items);
    }

    private function past(): string
    {
        return gmdate('Y-m-d H:i:s', strtotime('-2 days'));
    }

    private function future(): string
    {
        return gmdate('Y-m-d H:i:s', strtotime('+2 days'));
    }

    public function test_ended_finds_a_published_campaign_past_its_end_date(): void
    {
        $this->make('Finished appeal', ['ends_at' => $this->past()]);
        $this->make('Running appeal');

        $titles = $this->titlesFor('ended');

        $this->assertContains('Finished appeal', $titles);
        $this->assertNotContains('Running appeal', $titles);
    }

    public function test_ended_does_not_sweep_up_drafts(): void
    {
        // A draft with a date in the past never ran, and the badge calls it a
        // draft, so the Ended filter must not claim it.
        $this->make('Never published', ['status' => 'draft', 'ends_at' => $this->past()]);

        $this->assertNotContains('Never published', $this->titlesFor('ended'));
    }

    public function test_scheduled_finds_a_campaign_that_has_not_opened(): void
    {
        $this->make('Opens later', ['starts_at' => $this->future()]);
        $this->make('Open now');

        $titles = $this->titlesFor('scheduled');

        $this->assertContains('Opens later', $titles);
        $this->assertNotContains('Open now', $titles);
    }

    public function test_goal_met_finds_a_campaign_that_closes_on_its_goal(): void
    {
        $this->make('Target reached', ['close_at_goal' => true, 'goal_cents' => 1000, 'raised_cents' => 1000]);
        $this->make('Still short', ['close_at_goal' => true, 'goal_cents' => 1000, 'raised_cents' => 999]);

        $titles = $this->titlesFor('goal_met');

        $this->assertContains('Target reached', $titles);
        $this->assertNotContains('Still short', $titles);
    }

    public function test_goal_met_ignores_a_campaign_that_is_not_set_to_close(): void
    {
        $this->make('Past target, stays open', ['close_at_goal' => false, 'goal_cents' => 1000, 'raised_cents' => 5000]);

        $this->assertNotContains('Past target, stays open', $this->titlesFor('goal_met'));
    }

    public function test_a_campaign_that_ended_and_met_its_goal_belongs_only_to_ended(): void
    {
        $this->make('Both', [
            'close_at_goal' => true, 'goal_cents' => 1000, 'raised_cents' => 5000,
            'ends_at' => $this->past(),
        ]);

        $this->assertContains('Both', $this->titlesFor('ended'));
        $this->assertNotContains('Both', $this->titlesFor('goal_met'));
    }

    public function test_a_goal_of_zero_is_never_met(): void
    {
        $this->make('No target', ['close_at_goal' => true, 'goal_cents' => 0, 'raised_cents' => 9999]);
        $this->make('Null target', ['close_at_goal' => true, 'goal_cents' => null, 'raised_cents' => 9999]);

        $titles = $this->titlesFor('goal_met');

        $this->assertNotContains('No target', $titles);
        $this->assertNotContains('Null target', $titles);
    }

    public function test_a_count_goal_reads_its_own_column(): void
    {
        $this->make('Donors reached', [
            'close_at_goal' => true, 'goal_type' => 'donors', 'goal_count' => 3, 'donors_count' => 3,
        ]);
        $this->make('Donations not donors', [
            'close_at_goal' => true, 'goal_type' => 'donors', 'goal_count' => 10,
            'donations_count' => 40, 'donors_count' => 2,
        ]);

        $titles = $this->titlesFor('goal_met');

        $this->assertContains('Donors reached', $titles);
        $this->assertNotContains('Donations not donors', $titles);
    }

    public function test_the_stored_statuses_still_filter(): void
    {
        $this->make('A draft', ['status' => 'draft']);

        $this->assertContains('A draft', $this->titlesFor('draft'));
        $this->assertNotContains('A draft', $this->titlesFor('published'));
    }

    public function test_the_kpi_strip_counts_the_same_set_as_the_list(): void
    {
        $this->make('Ended one', ['ends_at' => $this->past()]);
        $this->make('Ended two', ['ends_at' => $this->past()]);

        $listed = count($this->titlesFor('ended'));
        $counted = $this->repo->aggregateAdmin(['status' => 'ended'])['total_count'];

        $this->assertSame($listed, $counted, 'the strip and the rows disagree about what Ended means');
    }
}
