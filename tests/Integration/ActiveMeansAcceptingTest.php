<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Campaigns\Campaign;
use Gratora\Campaigns\CampaignRepository;

/**
 * The campaigns strip counts how many are active, over a table whose own
 * badge derives that from the schedule and the goal.
 *
 * The count asked the stored status alone, so a campaign that ended in June
 * was one of five Active above a row reading Ended. The model already answers
 * this: notAcceptingReason names archived, draft, scheduled, ended and goal
 * met, the badge uses it and so does the filter.
 */
final class ActiveMeansAcceptingTest extends IntegrationTestCase
{
    private function campaign(array $overrides = []): Campaign
    {
        $now = gmdate('Y-m-d H:i:s');

        $c = Campaign::make();
        $c->title      = 'Campaign ' . bin2hex(random_bytes(3));
        $c->slug       = 'c-' . bin2hex(random_bytes(4));
        $c->status     = 'published';
        $c->goal_type  = 'amount';
        $c->created_at = $now;
        $c->updated_at = $now;

        foreach ($overrides as $column => $value) {
            $c->{$column} = $value;
        }

        $c->save();

        return $c;
    }

    private function activeCount(): int
    {
        return (int) (new CampaignRepository())->aggregateAdmin()['active_count'];
    }

    private const PAST   = '2020-01-01 00:00:00';
    private const FUTURE = '2099-01-01 00:00:00';

    public function test_a_campaign_open_today_is_active(): void
    {
        $this->campaign(['starts_at' => self::PAST]);

        $this->assertSame(1, $this->activeCount());
    }

    public function test_one_that_has_ended_is_not(): void
    {
        $this->campaign(['ends_at' => self::PAST]);

        $this->assertSame(0, $this->activeCount());
    }

    public function test_one_that_has_not_started_is_not(): void
    {
        $this->campaign(['starts_at' => self::FUTURE]);

        $this->assertSame(0, $this->activeCount());
    }

    public function test_one_closed_on_a_goal_it_reached_is_not(): void
    {
        $this->campaign([
            'close_at_goal' => true,
            'goal_type'     => 'amount',
            'goal_cents'    => 10000,
            'raised_cents'  => 10000,
        ]);

        $this->assertSame(0, $this->activeCount());
    }

    /** A goal reached by a campaign that does not close on one changes nothing. */
    public function test_one_past_a_goal_it_does_not_close_on_is_active(): void
    {
        $this->campaign([
            'close_at_goal' => false,
            'goal_type'     => 'amount',
            'goal_cents'    => 10000,
            'raised_cents'  => 99999,
        ]);

        $this->assertSame(1, $this->activeCount());
    }

    /** @dataProvider notPublished */
    public function test_a_campaign_that_is_not_published_is_not_active(string $status): void
    {
        $this->campaign(['status' => $status]);

        $this->assertSame(0, $this->activeCount());
    }

    /** @return array<string, array{0:string}> */
    public static function notPublished(): array
    {
        return [
            'draft'    => ['draft'],
            'archived' => ['archived'],
        ];
    }

    /**
     * The strip and the filter answer the same question, so a campaign in
     * neither set, or in both, is one they disagree about.
     */
    public function test_the_strip_agrees_with_the_filters_beside_it(): void
    {
        $this->campaign(['starts_at' => self::PAST]);
        $this->campaign(['ends_at' => self::PAST]);
        $this->campaign(['starts_at' => self::FUTURE]);
        $this->campaign(['status' => 'draft']);
        $this->campaign(['status' => 'archived']);

        $repo  = new CampaignRepository();
        $count = static fn (string $status): int => (int) $repo->listAdmin(['status' => $status, 'per_page' => 100])['total'];

        $this->assertSame(
            (int) $repo->aggregateAdmin()['total_count'],
            $this->activeCount() + $count('ended') + $count('scheduled') + $count('goal_met') + $count('draft') + $count('archived')
        );
    }
}
