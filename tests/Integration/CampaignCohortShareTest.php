<?php

declare(strict_types=1);

namespace FundKit\Tests\Integration;

use FundKit\Campaigns\Campaign;
use FundKit\Campaigns\CampaignMetricsService;
use FundKit\Donations\Donation;
use FundKit\Foundation\Plugin;
use WP_REST_Request;

/**
 * The two cohorts are disjoint, so a share of one measured against the other
 * has no ceiling: two returning donors and one first-timer printed 200%.
 */
final class CampaignCohortShareTest extends IntegrationTestCase
{
    private function metrics(): CampaignMetricsService
    {
        return Plugin::instance()->container->get(CampaignMetricsService::class);
    }

    private function campaign(): Campaign
    {
        $req = new WP_REST_Request('POST', '/fundkit/v1/admin/campaigns');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode(['title' => 'Cohort probe', 'status' => 'published']));

        return Campaign::query()->find('id', (int) rest_do_request($req)->get_data()['id']);
    }

    private function donation(Campaign $c, int $donorId): void
    {
        $now = gmdate('Y-m-d H:i:s');

        $d = Donation::make();
        $d->donor_id          = $donorId;
        $d->campaign_id       = (int) $c->id;
        $d->reference         = 'COH-' . bin2hex(random_bytes(4));
        $d->amount_cents      = 1000;
        $d->base_amount_cents = 1000;
        $d->fx_rate           = '1';
        $d->currency          = (string) $c->currency;
        $d->status            = 'paid';
        $d->gateway           = 'offline';
        $d->is_test           = false;
        $d->paid_at           = $now;
        $d->created_at        = $now;
        $d->updated_at        = $now;
        $d->save();
    }

    public function test_the_share_of_returning_donors_cannot_exceed_everyone(): void
    {
        $c = $this->campaign();
        // Two who came back, one who did not.
        $this->donation($c, 101);
        $this->donation($c, 101);
        $this->donation($c, 102);
        $this->donation($c, 102);
        $this->donation($c, 103);

        $cohort = $this->metrics()->cohort((int) $c->id, 'all-time');

        $this->assertSame(2, $cohort['returning']);
        $this->assertSame(1, $cohort['first_time']);
        $this->assertLessThanOrEqual(100.0, $cohort['conversion_pct']);
        $this->assertEqualsWithDelta(66.7, $cohort['conversion_pct'], 0.05);
    }

    public function test_everyone_coming_back_is_a_hundred_percent(): void
    {
        $c = $this->campaign();
        $this->donation($c, 201);
        $this->donation($c, 201);

        $this->assertEqualsWithDelta(100.0, $this->metrics()->cohort((int) $c->id, 'all-time')['conversion_pct'], 0.05);
    }

    public function test_nobody_coming_back_is_zero(): void
    {
        $c = $this->campaign();
        $this->donation($c, 301);
        $this->donation($c, 302);

        $this->assertEqualsWithDelta(0.0, $this->metrics()->cohort((int) $c->id, 'all-time')['conversion_pct'], 0.05);
    }

    public function test_a_campaign_with_no_donors_says_nothing(): void
    {
        $this->assertNull($this->metrics()->cohort((int) $this->campaign()->id, 'all-time')['conversion_pct']);
    }
}
