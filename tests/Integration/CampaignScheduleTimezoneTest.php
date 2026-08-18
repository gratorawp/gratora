<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use Dono\Campaigns\Campaign;

/**
 * A campaign schedule is the org's calendar, and the gate on the money path has
 * to read it that way.
 *
 * The admin picks dates in a local timeline and the campaign screen reads them
 * back the same way. Comparing those dates against UTC closes a campaign ending
 * 31 December at 19:00 in New York, which is the heaviest giving window of the
 * fundraising year, while the screen still says the campaign runs to the 31st.
 * The mirror east of UTC keeps the form open into the next calendar and tax year.
 */
final class CampaignScheduleTimezoneTest extends IntegrationTestCase
{
    private ?string $originalTz = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalTz = get_option('timezone_string');
    }

    protected function tearDown(): void
    {
        update_option('timezone_string', $this->originalTz);
        parent::tearDown();
    }

    public function test_a_campaign_takes_donations_to_the_last_local_minute_of_its_end_date(): void
    {
        update_option('timezone_string', 'America/New_York');
        $campaign = $this->scheduled('2025-12-01', '2025-12-31');

        foreach (['18:30:00', '19:30:00', '22:00:00', '23:59:00'] as $time) {
            $this->assertNull(
                $campaign->notAcceptingReason($this->utcFor('2025-12-31 ' . $time, 'America/New_York')),
                "open at {$time} local on the end date"
            );
        }
    }

    public function test_a_campaign_closes_when_the_local_day_after_its_end_date_starts(): void
    {
        update_option('timezone_string', 'America/New_York');
        $campaign = $this->scheduled('2025-12-01', '2025-12-31');

        $this->assertSame(
            'ended',
            $campaign->notAcceptingReason($this->utcFor('2026-01-01 00:01:00', 'America/New_York'))
        );
    }

    public function test_a_campaign_does_not_open_before_its_start_date_arrives_locally(): void
    {
        update_option('timezone_string', 'America/New_York');
        $campaign = $this->scheduled('2025-12-01', '2025-12-31');

        $this->assertSame(
            'scheduled',
            $campaign->notAcceptingReason($this->utcFor('2025-11-30 20:00:00', 'America/New_York')),
            'still November where the org is'
        );
        $this->assertNull(
            $campaign->notAcceptingReason($this->utcFor('2025-12-01 00:30:00', 'America/New_York'))
        );
    }

    public function test_a_campaign_ahead_of_utc_stops_when_its_own_new_year_arrives(): void
    {
        update_option('timezone_string', 'Australia/Sydney');
        $campaign = $this->scheduled('2025-12-01', '2025-12-31');

        $this->assertNull(
            $campaign->notAcceptingReason($this->utcFor('2025-12-31 23:00:00', 'Australia/Sydney')),
            'open to the end of the 31st in Sydney'
        );
        $this->assertSame(
            'ended',
            $campaign->notAcceptingReason($this->utcFor('2026-01-01 08:00:00', 'Australia/Sydney')),
            'and not into the next tax year'
        );
    }

    public function test_the_boundaries_are_published_as_utc_for_anything_counting_days_left(): void
    {
        update_option('timezone_string', 'America/New_York');
        $campaign = $this->scheduled('2025-12-01', '2025-12-31');

        $this->assertSame('2025-12-01 05:00:00', $campaign->startsAtUtc());
        $this->assertSame('2026-01-01 04:59:59', $campaign->endsAtUtc());
    }

    public function test_no_schedule_is_no_boundary(): void
    {
        update_option('timezone_string', 'America/New_York');
        $campaign = $this->scheduled(null, null);

        $this->assertNull($campaign->startsAtUtc());
        $this->assertNull($campaign->endsAtUtc());
        $this->assertTrue($campaign->acceptsDonations());
    }

    private function scheduled(?string $startsAt, ?string $endsAt): Campaign
    {
        $campaign            = Campaign::make();
        $campaign->status    = 'published';
        $campaign->starts_at = $startsAt;
        $campaign->ends_at   = $endsAt;

        return $campaign;
    }

    private function utcFor(string $local, string $timezone): string
    {
        return (new DateTimeImmutable($local, new DateTimeZone($timezone)))
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');
    }
}
