<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use Dono\Campaigns\Campaign;
use Dono\Campaigns\CampaignMetricsService;
use Dono\Dashboard\DashboardMetricsService;
use Dono\Donations\Donation;
use Dono\Donations\DonationRepository;
use Dono\Donors\DonorRepository;
use Dono\Foundation\Plugin;
use Dono\Foundation\Time\FrozenClock;
use Dono\Recurring\RecurringPlanRepository;

/**
 * Every bucket a donation can be counted into is the org's, not UTC.
 *
 * The windows these reports run over are the org's calendar days. A bucket left
 * on the UTC clock therefore files a donation outside the window its own money
 * was counted in: west of UTC the evening's donations fall off the end of the
 * series while the total printed beside it still includes them, and a weekday
 * or hour grid is wrong by the whole offset, which east of UTC is most of a day.
 *
 * One donation, every bucketing, one expected answer, so a bucket that goes
 * back to UTC on its own cannot pass here while the rest stay local.
 */
final class PaidBucketTimezoneTest extends IntegrationTestCase
{
    /** 19:00 on 15 January in Los Angeles, stamped the 16th in UTC. */
    private const EVENING_UTC = '2026-01-16 03:00:00';
    private const LOCAL_DAY   = '2026-01-15';

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

    public function test_the_daily_series_files_an_evening_donation_on_the_org_s_day(): void
    {
        update_option('timezone_string', 'America/Los_Angeles');
        $campaign = $this->campaign();
        $this->paid(self::EVENING_UTC, 5000, (int) $campaign->id);

        $rows = $this->repository()->dailyPaidBetween(self::LOCAL_DAY, self::LOCAL_DAY);

        $this->assertSame(
            [self::LOCAL_DAY => 5000],
            array_column($rows, 'amount_cents', 'day'),
            'the day the org gave it, not the day UTC stamped it'
        );
    }

    public function test_the_per_campaign_daily_series_files_it_on_the_same_day(): void
    {
        update_option('timezone_string', 'America/Los_Angeles');
        $campaign = $this->campaign();
        $this->paid(self::EVENING_UTC, 5000, (int) $campaign->id);

        $rows = $this->repository()->dailyPaidForCampaignBetween(
            (int) $campaign->id,
            self::LOCAL_DAY,
            self::LOCAL_DAY
        );

        $this->assertSame(
            [self::LOCAL_DAY => 5000],
            array_column($rows, 'amount_cents', 'day')
        );
    }

    public function test_the_batched_sparkline_series_files_it_on_the_same_day(): void
    {
        update_option('timezone_string', 'America/Los_Angeles');
        $campaign = $this->campaign();
        $this->paid(self::EVENING_UTC, 5000, (int) $campaign->id);

        $byCampaign = $this->repository()->dailyPaidByCampaignsBetween(
            [(int) $campaign->id],
            self::LOCAL_DAY,
            self::LOCAL_DAY
        );

        $this->assertSame(
            [self::LOCAL_DAY => 5000],
            $byCampaign[(int) $campaign->id] ?? []
        );
    }

    /**
     * The sparkline is drawn by walking the org's days, so a UTC bucket key
     * misses the cursor entirely and the row's chart reads flat while the
     * figure printed next to it counts the money.
     */
    public function test_the_top_campaign_sparkline_adds_up_to_the_figure_beside_it(): void
    {
        update_option('timezone_string', 'America/Los_Angeles');
        $campaign = $this->campaign();
        $this->paid(self::EVENING_UTC, 5000, (int) $campaign->id);

        // 19:30 on 15 January in Los Angeles: the org's today is the 15th.
        $rows = $this->dashboardAt('2026-01-16 03:30:00')->topCampaigns('last-30');
        $row  = $rows[0] ?? [];

        $this->assertSame(5000, $row['amount_cents'] ?? 0, 'the money is in range either way');
        $this->assertSame(
            5000,
            array_sum(array_column($row['sparkline'] ?? [], 'amount_cents')),
            'the chart of a range sums to the total of that range'
        );
        $this->assertSame(
            self::LOCAL_DAY,
            (string) (end($row['sparkline'])['date'] ?? ''),
            'and it ends on the org\'s today'
        );
    }

    public function test_the_weekday_and_hour_grid_is_on_the_org_s_clock(): void
    {
        update_option('timezone_string', 'America/Los_Angeles');
        $campaign = $this->campaign();
        $this->paid(self::EVENING_UTC, 5000, (int) $campaign->id);

        $cells = $this->repository()->dowHourGridForPaid(
            self::LOCAL_DAY,
            self::LOCAL_DAY,
            (int) $campaign->id
        );

        // 15 January 2026 is a Thursday: 3 on the 0=Mon..6=Sun scale.
        $this->assertSame(
            [['dow' => 3, 'hour' => 19, 'donations_count' => 1]],
            array_map(
                static fn ($c) => ['dow' => $c['dow'], 'hour' => $c['hour'], 'donations_count' => $c['donations_count']],
                $cells
            ),
            'Thursday evening where the org is, not Friday morning in UTC'
        );
    }

    /**
     * The campaign screen asks for this grid over all time, which names no
     * window at all. The offset still has to come from somewhere, and the rows
     * it is grouping are the only honest source.
     */
    public function test_the_all_time_grid_is_on_the_org_s_clock_too(): void
    {
        update_option('timezone_string', 'America/Los_Angeles');
        $campaign = $this->campaign();
        $this->paid(self::EVENING_UTC, 5000, (int) $campaign->id);

        $grid = $this->campaignAt('2026-01-20 12:00:00')
            ->dowHourGrid((int) $campaign->id, 'all-time')['grid'];

        $this->assertSame(1, $grid[3][19], 'Thursday at 19:00 where the org is');
        $this->assertSame(0, $grid[4][3], 'and nothing on the Friday UTC would have filed it under');
    }

    /**
     * An all-time grid spans every transition its rows do, and the offset in
     * force is not the same on either side of one. A single offset for the
     * whole span moves one of these two donations by an hour, which near
     * midnight is a different weekday.
     */
    public function test_each_donation_is_gridded_under_the_offset_in_force_when_it_was_given(): void
    {
        update_option('timezone_string', 'America/New_York');
        $campaign = $this->campaign();

        // 23:30 on Saturday 28 February, on standard time.
        $this->paid('2026-03-01 04:30:00', 1000, (int) $campaign->id);
        // 00:30 on Sunday 1 November, on the last night of daylight saving.
        $this->paid('2026-11-01 04:30:00', 2000, (int) $campaign->id);

        $grid = $this->campaignAt('2026-12-01 12:00:00')
            ->dowHourGrid((int) $campaign->id, 'all-time')['grid'];

        $this->assertSame(1, $grid[5][23], 'Saturday night, since New York was five hours back then');
        $this->assertSame(1, $grid[6][0], 'Sunday small hours, since it was four hours back on that one');
        $this->assertSame(2, array_sum(array_map('array_sum', $grid)), 'and nothing landed anywhere else');
    }

    private function repository(): DonationRepository
    {
        return Plugin::instance()->container->get(DonationRepository::class);
    }

    private function dashboardAt(string $utc): DashboardMetricsService
    {
        $c = Plugin::instance()->container;

        return new DashboardMetricsService(
            new FrozenClock(new DateTimeImmutable($utc, new DateTimeZone('UTC'))),
            $c->get(DonationRepository::class),
            $c->get(RecurringPlanRepository::class),
        );
    }

    private function campaignAt(string $utc): CampaignMetricsService
    {
        $c = Plugin::instance()->container;

        return new CampaignMetricsService(
            new FrozenClock(new DateTimeImmutable($utc, new DateTimeZone('UTC'))),
            $c->get(DonationRepository::class),
            $c->get(DonorRepository::class),
        );
    }

    private function campaign(): Campaign
    {
        $c = Campaign::make();
        $c->title      = 'Bucket probe';
        $c->slug       = 'bucket-' . uniqid();
        $c->status     = 'published';
        $c->created_at = '2026-01-01 00:00:00';
        $c->updated_at = '2026-01-01 00:00:00';
        $c->save();

        return $c;
    }

    private function paid(string $utc, int $cents, int $campaignId): Donation
    {
        $d = Donation::make();
        $d->reference         = 'DONO-BUCKET-' . uniqid();
        $d->campaign_id       = $campaignId;
        $d->status            = 'paid';
        $d->gateway           = 'offline';
        $d->kind              = 'donation';
        $d->amount_cents      = $cents;
        $d->net_cents         = $cents;
        $d->base_amount_cents = $cents;
        $d->currency          = 'USD';
        $d->base_currency     = 'USD';
        $d->fx_rate           = '1.00000000';
        $d->is_test           = false;
        $d->paid_at           = $utc;
        $d->created_at        = $utc;
        $d->updated_at        = $utc;
        $d->save();

        return $d;
    }
}
