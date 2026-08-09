<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Campaigns\Campaign;
use Dono\Campaigns\CampaignService;
use Dono\Foundation\Plugin;
use WP_REST_Request;

/**
 * Every widget on the campaign metrics screen resolves its own date range, and
 * on the default range they all resolve the same one. Unmemoized that read the
 * campaign row ten times and scanned for the earliest donation nine times, a
 * third of the endpoint's SQL time, and none of it grew with the data so
 * nothing about the timings would ever have pointed at it.
 */
final class CampaignMetricsQueryCountTest extends IntegrationTestCase
{
    private function campaignId(): int
    {
        $campaign = Plugin::instance()->container->get(CampaignService::class)->create([
            'title'         => 'Metrics ' . uniqid(),
            'slug'          => 'metrics-' . uniqid(),
            'status'        => 'published',
            'skip_template' => true,
        ]);

        return (int) $campaign->id;
    }

    /**
     * Counted through the `query` filter rather than SAVEQUERIES, which is a
     * constant: defining it here would leave query logging on for the rest of
     * the suite.
     *
     * @return array<string,int> normalised sql => times run
     */
    private function queryShapes(string $path): array
    {
        rest_do_request(new WP_REST_Request('GET', $path));

        $shapes = [];
        $spy = static function ($sql) use (&$shapes) {
            $shape = preg_replace('/\s+/', ' ', trim((string) $sql));
            // Ids collapse, LIMIT does not: two methods reading the same table
            // in the same order with different limits are different questions,
            // and collapsing both hid that behind a false duplicate.
            $shape = preg_replace('/(?<!LIMIT )\b\d+\b/', 'N', (string) $shape);
            $shapes[$shape] = ($shapes[$shape] ?? 0) + 1;
            return $sql;
        };

        add_filter('query', $spy);
        try {
            $res = rest_do_request(new WP_REST_Request('GET', $path));
            $this->assertSame(200, $res->get_status());
        } finally {
            remove_filter('query', $spy);
        }

        return $shapes;
    }

    public function test_no_query_runs_twice_for_one_metrics_request(): void
    {
        $id     = $this->campaignId();
        $shapes = $this->queryShapes("/dono/v1/admin/campaigns/{$id}/metrics");

        $this->assertNotSame([], $shapes, 'the endpoint should issue queries at all');

        $repeated = array_filter($shapes, static fn (int $n): bool => $n > 1);
        $this->assertSame(
            [],
            array_map(static fn (int $n): int => $n, $repeated),
            'a repeated query with an unchanged id is work the request already did'
        );
    }

    /**
     * The count is set by the widget list, so data volume must not move it.
     * Measured from a campaign that already has donations: going from none to
     * some legitimately adds queries, because widgets with nothing to show skip
     * their work.
     */
    public function test_the_query_count_does_not_grow_with_donations(): void
    {
        $id = $this->campaignId();
        for ($i = 0; $i < 25; $i++) {
            $this->seedDonation($id);
        }
        $before = array_sum($this->queryShapes("/dono/v1/admin/campaigns/{$id}/metrics"));

        for ($i = 0; $i < 75; $i++) {
            $this->seedDonation($id);
        }
        $after = array_sum($this->queryShapes("/dono/v1/admin/campaigns/{$id}/metrics"));

        $this->assertSame($before, $after, 'four times the donations, same number of queries');
    }

    private function seedDonation(int $campaignId): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $d = \Dono\Donations\Donation::make();
        $d->reference         = 'MET-' . strtoupper(uniqid());
        $d->donor_id          = random_int(100000, 999999);
        $d->campaign_id       = $campaignId;
        $d->amount_cents      = 2500;
        $d->net_cents         = 2500;
        $d->base_amount_cents = 2500;
        $d->base_currency     = 'EUR';
        $d->fx_rate           = '1.00000000';
        $d->currency          = 'EUR';
        $d->status            = 'paid';
        $d->gateway           = 'offline';
        $d->is_test           = false;
        $d->paid_at           = $now;
        $d->created_at        = $now;
        $d->updated_at        = $now;
        $d->save();
    }
}
