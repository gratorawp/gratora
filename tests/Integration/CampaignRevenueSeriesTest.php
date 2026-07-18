<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Campaigns\Campaign;
use Dono\Campaigns\CampaignMetricsService;
use Dono\Donations\Donation;
use Dono\Donors\DonorService;
use Dono\Foundation\Plugin;

/**
 * The all-time revenue series must reach back to the earliest paid donation,
 * even when it predates the campaign's start/created date (imports, backfills,
 * a start set after early gifts). Regression: the range started at created_at,
 * so the series query excluded every earlier donation and the chart read a flat
 * $0 line while the KPI showed the real total.
 */
final class CampaignRevenueSeriesTest extends IntegrationTestCase
{
    public function test_all_time_series_includes_donations_before_the_campaign_start(): void
    {
        $metrics = Plugin::instance()->container->get(CampaignMetricsService::class);
        $donor   = Plugin::instance()->container->get(DonorService::class)
            ->findOrCreate('early@example.com', ['first_name' => 'E', 'last_name' => 'G']);

        $now         = time();
        $campCreated = gmdate('Y-m-d H:i:s', $now - 30 * 86400);
        $giftTime    = gmdate('Y-m-d H:i:s', $now - 90 * 86400);
        $giftDay     = gmdate('Y-m-d',       $now - 90 * 86400);

        $c = Campaign::make();
        $c->title      = 'Backdated';
        $c->slug       = 'backdated-' . substr(md5(uniqid('', true)), 0, 8);
        $c->status     = 'published';
        $c->goal_cents = 100000;
        $c->created_at = $campCreated;
        $c->updated_at = $campCreated;
        $c->save();

        // A paid gift 90 days ago, i.e. 60 days BEFORE the campaign was created.
        $d = Donation::make();
        $d->reference         = 'DONO-BK-' . substr(md5(uniqid('', true)), 0, 8);
        $d->donor_id          = (int) $donor->id;
        $d->campaign_id       = (int) $c->id;
        $d->amount_cents      = 5000;
        $d->net_cents         = 5000;
        $d->base_amount_cents = 5000;
        $d->currency          = 'USD';
        $d->base_currency     = 'USD';
        $d->fx_rate           = '1.00000000';
        $d->gateway           = 'offline';
        $d->status            = 'paid';
        $d->is_test           = false;
        $d->paid_at           = $giftTime;
        $d->created_at        = $giftTime;
        $d->updated_at        = $giftTime;
        $d->save();

        $series = $metrics->revenueSeries((int) $c->id, 'all-time');

        $this->assertNotEmpty($series);
        $this->assertLessThanOrEqual(
            $giftDay,
            $series[0]['date'],
            'the all-time series starts at or before the earliest donation, not the campaign start'
        );

        $day = null;
        foreach ($series as $point) {
            if ($point['date'] === $giftDay) {
                $day = $point;
                break;
            }
        }
        $this->assertNotNull($day, 'the pre-start donation day is present in the series');
        $this->assertSame(5000, (int) $day['amount_cents'], 'the pre-start donation contributes its revenue');
    }
}
