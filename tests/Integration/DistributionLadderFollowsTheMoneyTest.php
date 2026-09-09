<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Campaigns\CampaignMetricsService;
use Gratora\Donations\Donation;
use Gratora\Foundation\Plugin;
use WP_REST_Request;

/**
 * Storage is major units times 100 for every currency, so a base whose major
 * unit is worth about a hundredth of a dollar (JPY, HUF, and others the picker
 * offers) puts every donation past a ladder whose top rung is $500. The whole
 * chart becomes one overflow bar, which says nothing about how people give.
 */
final class DistributionLadderFollowsTheMoneyTest extends IntegrationTestCase
{
    private int $campaignId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        $req = new WP_REST_Request('POST', '/gratora/v1/admin/campaigns');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode(['title' => 'Ladder', 'status' => 'published']));
        $this->campaignId = (int) rest_do_request($req)->get_data()['id'];
    }

    private function paid(int $baseCents): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $d   = Donation::make();
        $d->reference         = 'LADDER-' . uniqid();
        $d->donor_id          = 9002;
        $d->campaign_id       = $this->campaignId;
        $d->amount_cents      = $baseCents;
        $d->base_amount_cents = $baseCents;
        $d->currency          = 'USD';
        $d->base_currency     = 'USD';
        $d->status            = 'paid';
        $d->gateway           = 'offline';
        $d->frequency         = 'one_time';
        $d->kind              = 'donation';
        $d->is_test           = false;
        $d->paid_at           = $now;
        $d->created_at        = $now;
        $d->updated_at        = $now;
        $d->save();
    }

    private function metrics(): CampaignMetricsService
    {
        return Plugin::instance()->container->get(CampaignMetricsService::class);
    }

    /** @return array<string,mixed> */
    private function distribution(): array
    {
        return $this->metrics()->distributionBuckets($this->campaignId);
    }

    public function test_a_small_unit_currency_does_not_land_entirely_in_the_overflow(): void
    {
        // 1,000 / 3,000 / 10,000 yen, which is 100000 / 300000 / 1000000 stored.
        foreach ([100000, 300000, 1000000] as $cents) {
            $this->paid($cents);
        }

        $d        = $this->distribution();
        $buckets  = (array) $d['buckets'];
        $overflow = end($buckets);

        $this->assertSame(3, (int) $d['total_count']);
        $this->assertLessThan(
            (int) $d['total_count'],
            (int) $overflow['count'],
            'the ladder dumped every donation past its top rung'
        );
    }

    public function test_a_dollar_scale_campaign_reads_the_same_as_before(): void
    {
        foreach ([2500, 5000, 7500] as $cents) {
            $this->paid($cents);
        }

        $tops = array_map(
            static fn (array $b): ?int => $b['max_cents'],
            (array) $this->distribution()['buckets']
        );

        $this->assertSame([1000, 2500, 5000, 10000, 25000, 50000, null], $tops);
    }

    public function test_an_empty_campaign_keeps_the_shipped_ladder(): void
    {
        $tops = array_map(
            static fn (array $b): ?int => $b['max_cents'],
            (array) $this->distribution()['buckets']
        );

        $this->assertSame([1000, 2500, 5000, 10000, 25000, 50000, null], $tops);
    }
}
