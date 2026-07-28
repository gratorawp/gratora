<?php

declare(strict_types=1);

namespace Dono\Tests\Unit\Campaigns;

use Dono\Campaigns\Campaign;
use PHPUnit\Framework\TestCase;

/**
 * The one rule three separate gates now ask: is this campaign open for money?
 *
 * The boundary maths is the whole reason this lives in one place. Getting the
 * end date wrong by one interpretation costs every scheduled campaign its final
 * day of donations, silently.
 */
final class CampaignAcceptsDonationsTest extends TestCase
{
    private function campaign(?string $startsAt, ?string $endsAt, string $status = 'published'): Campaign
    {
        $c = Campaign::make();
        $c->status    = $status;
        $c->starts_at = $startsAt;
        $c->ends_at   = $endsAt;
        return $c;
    }

    public function test_an_unscheduled_published_campaign_is_open(): void
    {
        $this->assertTrue($this->campaign(null, null)->acceptsDonations('2026-07-28 10:00:00'));
    }

    public function test_status_still_decides_first(): void
    {
        foreach (['draft', 'archived'] as $status) {
            $this->assertFalse(
                $this->campaign(null, null, $status)->acceptsDonations('2026-07-28 10:00:00'),
                "a {$status} campaign takes nothing, schedule or no schedule"
            );
        }
    }

    /**
     * The admin picked a date; the datetime column stored it as midnight. Read
     * literally that closes the campaign 24 hours early.
     */
    public function test_an_end_date_is_inclusive_of_that_whole_day(): void
    {
        $c = $this->campaign(null, '2026-07-28 00:00:00');

        $this->assertTrue($c->acceptsDonations('2026-07-28 00:00:00'), 'open at the stroke of midnight');
        $this->assertTrue($c->acceptsDonations('2026-07-28 10:00:00'), 'open through the working day');
        $this->assertTrue($c->acceptsDonations('2026-07-28 23:59:59'), 'open to the last second');
        $this->assertFalse($c->acceptsDonations('2026-07-29 00:00:00'), 'closed the moment the next day starts');
    }

    public function test_a_campaign_that_ended_yesterday_is_closed(): void
    {
        $this->assertFalse(
            $this->campaign(null, '2026-07-27 00:00:00')->acceptsDonations('2026-07-28 10:00:00')
        );
    }

    /**
     * Midnight is the value we widen, so an end that carries a real time must be
     * taken at face value rather than pushed out to 23:59:59.
     */
    public function test_an_explicit_end_time_is_taken_literally(): void
    {
        $c = $this->campaign(null, '2026-07-28 09:00:00');

        $this->assertTrue($c->acceptsDonations('2026-07-28 08:59:59'));
        $this->assertFalse($c->acceptsDonations('2026-07-28 09:00:01'));
    }

    public function test_a_campaign_that_has_not_started_takes_nothing(): void
    {
        $c = $this->campaign('2026-07-29 00:00:00', null);

        $this->assertFalse($c->acceptsDonations('2026-07-28 23:59:59'));
        $this->assertTrue($c->acceptsDonations('2026-07-29 00:00:00'), 'open from the first instant of the start date');
    }

    public function test_both_ends_are_applied_together(): void
    {
        $c = $this->campaign('2026-07-01 00:00:00', '2026-07-31 00:00:00');

        $this->assertFalse($c->acceptsDonations('2026-06-30 23:59:59'));
        $this->assertTrue($c->acceptsDonations('2026-07-15 12:00:00'));
        $this->assertTrue($c->acceptsDonations('2026-07-31 18:00:00'), 'the last day still counts');
        $this->assertFalse($c->acceptsDonations('2026-08-01 00:00:00'));
    }

    /** Date-only and ISO 'T' forms can reach the model before MySQL normalises them. */
    public function test_unnormalised_stamps_are_understood(): void
    {
        $this->assertTrue($this->campaign(null, '2026-07-28')->acceptsDonations('2026-07-28 22:00:00'));
        $this->assertFalse($this->campaign('2026-07-29', null)->acceptsDonations('2026-07-28 22:00:00'));
        $this->assertTrue($this->campaign(null, '2026-07-28T00:00:00')->acceptsDonations('2026-07-28 22:00:00'));
        $this->assertTrue($this->campaign('  ', '')->acceptsDonations('2026-07-28 22:00:00'), 'blank is no schedule');
    }
}
