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
use Dono\Vendor\Queryable\DB;
use WP_REST_Request;

/**
 * What the left edge of an all-time chart is allowed to be.
 *
 * It is the earliest thing the org has: the first donation, or the day the
 * campaign opened. Both are stored UTC and read as one of the org's calendar
 * days, so both are converted, and a stamp that is not an instant at all is
 * not a bound. A zero date reaches the same arithmetic as a real one and parses
 * two millennia back: the chart is then asked for a window starting at a
 * datetime the database rejects, and the export picker offers a month from
 * year zero.
 */
final class AllTimeChartBoundTest extends IntegrationTestCase
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

    public function test_a_stamp_that_is_not_an_instant_does_not_anchor_the_chart(): void
    {
        update_option('timezone_string', 'America/Los_Angeles');

        $this->paid('2026-01-15 12:00:00', 5000);
        $this->zeroDated($this->paid('2026-01-10 12:00:00', 100));

        $series = $this->dashboardAt('2026-01-20 12:00:00')->revenueSeries('all-time')['series'];

        // The 365-day fallback, exactly, rather than a shape assertion that any
        // queryable bound would also satisfy. Unfixed, the window starts at a
        // datetime the database rejects and the call aborts before either of
        // these runs, so the picker below is where the bad bound is read back.
        $this->assertSame(
            '2025-01-20',
            (string) ($series[0]['date'] ?? ''),
            'a stamp that bounds nothing leaves the chart on its fallback year'
        );
        $this->assertSame(
            '2026-01-20',
            (string) (end($series)['date'] ?? ''),
            'and it still runs to the org\'s today'
        );
    }

    /**
     * The helper both bounds go through. strtotime is willing to read an empty
     * string as now, which is the one input where "not an instant" would
     * otherwise come back as a date.
     */
    public function test_a_stamp_that_is_not_an_instant_is_not_a_date(): void
    {
        $this->assertNull(DonationRepository::localDateOf(''), 'nothing is not today');
        $this->assertNull(DonationRepository::localDateOf('   '));
        $this->assertNull(DonationRepository::localDateOf('0000-00-00 00:00:00'));
        $this->assertSame('2026-01-15', DonationRepository::localDateOf('2026-01-15 12:00:00'));
    }

    public function test_the_export_picker_is_not_offered_a_month_from_year_zero(): void
    {
        $this->zeroDated($this->paid('2026-01-10 12:00:00', 100));

        $req  = new WP_REST_Request('GET', '/dono/v1/admin/exports/options');
        $opts = rest_do_request($req)->get_data();

        $this->assertSame(
            (string) wp_date('Y-m'),
            $opts['first_month'],
            'a stamp that bounds nothing leaves the picker where a site with no donations is'
        );
    }

    /**
     * The campaign's own opening day is the other lower bound, and it is stored
     * on the same clock as the donations, so it has to be read on the same one:
     * a campaign opened in the evening carries the next UTC date, and the chart
     * then begins after the day the org opened it.
     */
    public function test_the_campaign_chart_begins_the_day_the_org_opened_it(): void
    {
        update_option('timezone_string', 'America/Los_Angeles');

        // 19:00 on 14 January in Los Angeles, stamped 15 January in UTC.
        $campaign = $this->campaignCreatedAt('2026-01-15 03:00:00');
        $this->paid('2026-01-20 20:00:00', 5000, (int) $campaign->id);

        $series = $this->campaignAt('2026-01-25 12:00:00')->revenueSeries((int) $campaign->id, 'all-time');

        $this->assertSame(
            '2026-01-14',
            (string) ($series[0]['date'] ?? ''),
            'the campaign opened on the org 14th, whatever date UTC stamped on it'
        );
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

    private function campaignCreatedAt(string $utc): Campaign
    {
        $c = Campaign::make();
        $c->title      = 'Opened in the evening';
        $c->slug       = 'opened-' . uniqid();
        $c->status     = 'published';
        $c->created_at = $utc;
        $c->updated_at = $utc;
        $c->save();

        return $c;
    }

    /** A row whose paid_at is a zero date, as an import or a hand-edited table leaves it. */
    private function zeroDated(Donation $d): void
    {
        $prefix = DB::getPrefix();
        DB::raw(
            "UPDATE {$prefix}dono_donations SET paid_at = '0000-00-00 00:00:00' WHERE id = %d",
            [(int) $d->id]
        );
    }

    private function paid(string $utc, int $cents, ?int $campaignId = null): Donation
    {
        $d = Donation::make();
        $d->reference         = 'DONO-BOUND-' . uniqid();
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
