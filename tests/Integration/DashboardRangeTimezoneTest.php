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
use WP_REST_Request;

/**
 * The dashboard ranges are named in the org's days, so they have to be resolved
 * in the org's timezone.
 *
 * paid_at is stored UTC while "today", "last 7" and "all time" mean calendar
 * days where the org is. Resolving them against a UTC clock moves every window
 * by the offset: east of UTC the morning's donations have not happened yet, and
 * west of UTC the evening's are already tomorrow. The all-time chart has the
 * same fault at its lower bound, and there it drops the very donation that set
 * the bound rather than merely shifting a figure.
 *
 * This pins the range arithmetic itself rather than any one screen, because the
 * KPI ribbon, the revenue series and the comparison periods all cut their window
 * here.
 */
final class DashboardRangeTimezoneTest extends IntegrationTestCase
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

    /**
     * Auckland runs 13 hours ahead in January, so the org's day starts at 11:00
     * UTC the day before. 22:00 UTC on the 14th is already mid-morning on the
     * 15th where the org is.
     */
    private function inAuckland(): DashboardMetricsService
    {
        update_option('timezone_string', 'Pacific/Auckland');

        return $this->metricsAt('2026-01-14 22:00:00');
    }

    private function metricsAt(string $utc): DashboardMetricsService
    {
        $c = Plugin::instance()->container;

        return new DashboardMetricsService(
            new FrozenClock(new DateTimeImmutable($utc, new DateTimeZone('UTC'))),
            $c->get(DonationRepository::class),
            $c->get(RecurringPlanRepository::class),
        );
    }

    public function test_a_donation_given_this_morning_is_in_todays_figures(): void
    {
        // 10:00 on 15 January in Auckland, which is still the 14th in UTC.
        $this->paid('2026-01-14 21:00:00', 5000);

        $kpi = $this->inAuckland()->kpi('today');

        $this->assertSame(1, (int) $kpi['donations_count'], 'the org gave this one today');
        $this->assertSame(5000, (int) $kpi['amount_raised_cents']);
    }

    public function test_a_donation_given_last_night_is_not_in_todays_figures(): void
    {
        // 23:00 on 14 January in Auckland: yesterday there, today in UTC.
        $this->paid('2026-01-14 10:00:00', 5000);

        $kpi = $this->inAuckland()->kpi('today');

        $this->assertSame(0, (int) $kpi['donations_count'], 'yesterday is not today');
    }

    /**
     * The mirror image, west of UTC: Los Angeles runs 8 hours behind, so an
     * evening donation carries the next day's UTC date. Anchoring the chart on
     * that date starts it after the donation that set it.
     *
     * The series, not the KPI: an all-time KPI drops both bounds and sums
     * everything, so only the chart reads the lower bound.
     */
    public function test_the_all_time_chart_starts_on_the_day_the_first_donation_was_given(): void
    {
        update_option('timezone_string', 'America/Los_Angeles');

        // 19:00 on 14 January in Los Angeles, stamped 15 January in UTC.
        $this->paid('2026-01-15 03:00:00', 5000);

        $series = $this->metricsAt('2026-01-20 12:00:00')->revenueSeries('all-time')['series'];

        $this->assertSame(
            '2026-01-14',
            $series[0]['date'] ?? null,
            'the chart begins the day the org gave it, not the day UTC stamped it'
        );
        $this->assertSame(
            5000,
            (int) ($series[0]['amount_cents'] ?? 0),
            'the earliest donation cannot fall outside a series that begins with it'
        );
    }

    /**
     * A campaign page answers the same range names from its own service, so the
     * two have to cut the day in the same place or one screen contradicts the
     * other about the same donation.
     */
    public function test_a_campaign_reads_the_same_today_as_the_dashboard(): void
    {
        update_option('timezone_string', 'Pacific/Auckland');

        $campaign = $this->campaign();
        $this->paid('2026-01-14 21:00:00', 5000, (int) $campaign->id);

        $c       = Plugin::instance()->container;
        $metrics = new CampaignMetricsService(
            new FrozenClock(new DateTimeImmutable('2026-01-14 22:00:00', new DateTimeZone('UTC'))),
            $c->get(DonationRepository::class),
            $c->get(DonorRepository::class),
        );

        $this->assertSame(
            1,
            (int) $metrics->summary((int) $campaign->id, 'today')['donations_count'],
            'the campaign gave this one today, same as the dashboard says'
        );
    }

    private function campaign(): Campaign
    {
        $req = new WP_REST_Request('POST', '/dono/v1/admin/campaigns');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode(['title' => 'Range probe', 'status' => 'published']));

        return Campaign::query()->find('id', (int) rest_do_request($req)->get_data()['id']);
    }

    private function paid(string $utc, int $cents, ?int $campaignId = null): Donation
    {
        $d = Donation::make();
        $d->reference         = 'DONO-TZ-' . uniqid();
        if ($campaignId !== null) {
            $d->campaign_id = $campaignId;
        }
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
