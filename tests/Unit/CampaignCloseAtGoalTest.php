<?php

declare(strict_types=1);

namespace Gratora\Tests\Unit;

use Gratora\Campaigns\Campaign;
use PHPUnit\Framework\TestCase;

/**
 * Closing on the goal shares one path with closing on the end date, so the
 * donation form, the REST endpoint and the donate button all honour it without
 * knowing it exists. That makes the guard around what counts as a goal the only
 * thing standing between a campaign and closing itself by accident.
 */
final class CampaignCloseAtGoalTest extends TestCase
{
    private function campaign(array $props = []): Campaign
    {
        $c = new Campaign();
        $c->status = 'published';
        $c->goal_type = 'amount';

        foreach ($props as $k => $v) {
            $c->$k = $v;
        }

        return $c;
    }

    public function test_a_campaign_under_its_goal_keeps_taking_donations(): void
    {
        $c = $this->campaign(['close_at_goal' => true, 'goal_cents' => 100000, 'raised_cents' => 99999]);

        $this->assertFalse($c->goalMet());
        $this->assertTrue($c->acceptsDonations());
    }

    public function test_a_campaign_that_reached_its_goal_stops(): void
    {
        $c = $this->campaign(['close_at_goal' => true, 'goal_cents' => 100000, 'raised_cents' => 100000]);

        $this->assertTrue($c->goalMet());
        $this->assertFalse($c->acceptsDonations());
        $this->assertSame('goal_met', $c->notAcceptingReason());
    }

    public function test_overshooting_the_goal_still_closes(): void
    {
        // The final donation is rarely exact, and a > check would leave the
        // campaign open forever on an amount that lands past the target.
        $c = $this->campaign(['close_at_goal' => true, 'goal_cents' => 100000, 'raised_cents' => 250000]);

        $this->assertSame('goal_met', $c->notAcceptingReason());
    }

    public function test_the_setting_off_leaves_a_met_goal_open(): void
    {
        $c = $this->campaign(['close_at_goal' => false, 'goal_cents' => 100000, 'raised_cents' => 500000]);

        $this->assertTrue($c->goalMet());
        $this->assertTrue($c->acceptsDonations());
    }

    /**
     * Every campaign without a goal holds null or zero. If either read as met,
     * turning the setting on would close the campaign before its first donation.
     */
    public function test_no_goal_is_never_a_met_goal(): void
    {
        foreach ([null, 0] as $goal) {
            $c = $this->campaign(['close_at_goal' => true, 'goal_cents' => $goal, 'raised_cents' => 0]);
            $this->assertFalse($c->goalMet(), var_export($goal, true) . ' cents should not be a reachable goal');
            $this->assertTrue($c->acceptsDonations(), var_export($goal, true) . ' cents should leave the campaign open');
        }

        foreach ([null, 0] as $goal) {
            $c = $this->campaign(['close_at_goal' => true, 'goal_type' => 'donations', 'goal_count' => $goal]);
            $this->assertFalse($c->goalMet(), var_export($goal, true) . ' donations should not be a reachable goal');
        }
    }

    public function test_a_donations_goal_counts_donations(): void
    {
        $c = $this->campaign([
            'close_at_goal' => true, 'goal_type' => 'donations', 'goal_count' => 10,
            'donations_count' => 10, 'donors_count' => 1, 'raised_cents' => 0,
        ]);

        $this->assertSame('goal_met', $c->notAcceptingReason());
    }

    public function test_a_donors_goal_counts_donors_not_donations(): void
    {
        // Ten donations from three people is not thirty donors, and reading the
        // wrong column closes the campaign at a third of the target.
        $c = $this->campaign([
            'close_at_goal' => true, 'goal_type' => 'donors', 'goal_count' => 10,
            'donations_count' => 30, 'donors_count' => 3,
        ]);

        $this->assertFalse($c->goalMet());
        $this->assertTrue($c->acceptsDonations());
    }

    public function test_an_amount_goal_ignores_the_counts(): void
    {
        $c = $this->campaign([
            'close_at_goal' => true, 'goal_type' => 'amount', 'goal_cents' => 100000,
            'raised_cents' => 5000, 'donations_count' => 999, 'donors_count' => 999,
        ]);

        $this->assertFalse($c->goalMet());
    }

    public function test_an_unpublished_campaign_reports_its_own_state_first(): void
    {
        // A draft that happens to satisfy its goal is still a draft, and saying
        // "goal met" would read as a campaign that ran rather than one that
        // never opened.
        $c = $this->campaign(['status' => 'draft', 'close_at_goal' => true, 'goal_cents' => 100, 'raised_cents' => 500]);

        $this->assertSame('draft', $c->notAcceptingReason());
    }

    public function test_the_end_date_is_reported_before_the_goal(): void
    {
        $c = $this->campaign([
            'close_at_goal' => true, 'goal_cents' => 100, 'raised_cents' => 500,
            'ends_at' => '2020-01-01 00:00:00',
        ]);

        $this->assertSame('ended', $c->notAcceptingReason());
    }
}
