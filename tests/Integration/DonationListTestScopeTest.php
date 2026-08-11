<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Campaigns\Campaign;
use Dono\Donations\Donation;
use Dono\Donations\DonationRepository;
use Dono\Donors\DonorService;
use Dono\Foundation\Plugin;

/**
 * The donations list has three scopes, not two.
 *
 * `is_test` filters to one kind or the other, so between them there was no way
 * to see a run of donations as it actually happened: live-only hid the test
 * rows and "Test only" hid everything else. `include_test` is the third answer.
 */
final class DonationListTestScopeTest extends IntegrationTestCase
{
    private int $campaignId = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $campaign = Campaign::make();
        $campaign->title      = 'Test scope';
        $campaign->slug       = 'ts-' . uniqid();
        $campaign->status     = 'published';
        $campaign->created_at = gmdate('Y-m-d H:i:s');
        $campaign->updated_at = $campaign->created_at;
        $campaign->save();

        $this->campaignId = (int) $campaign->id;

        $this->seed('DONO-TS-LIVE-1', false, 5000);
        $this->seed('DONO-TS-LIVE-2', false, 3000);
        $this->seed('DONO-TS-TEST-1', true, 9900);
    }

    public function test_live_only_by_default(): void
    {
        $refs = $this->references([]);

        $this->assertSame(['DONO-TS-LIVE-1', 'DONO-TS-LIVE-2'], $refs);
    }

    public function test_include_test_shows_both_kinds(): void
    {
        $refs = $this->references(['include_test' => true]);

        $this->assertSame(
            ['DONO-TS-LIVE-1', 'DONO-TS-LIVE-2', 'DONO-TS-TEST-1'],
            $refs,
            'the whole run, in one list'
        );
    }

    public function test_an_explicit_filter_still_wins_over_the_scope(): void
    {
        $this->assertSame(
            ['DONO-TS-TEST-1'],
            $this->references(['include_test' => true, 'is_test' => true]),
            'Test only means only test, whatever the scope is set to'
        );

        $this->assertSame(
            ['DONO-TS-LIVE-1', 'DONO-TS-LIVE-2'],
            $this->references(['include_test' => true, 'is_test' => false]),
            'and Live only means only live'
        );
    }

    public function test_the_money_kpis_count_real_money_only_by_default(): void
    {
        $stats = (new DonationRepository())->aggregateAdmin([
            'campaign_id' => $this->campaignId,
        ]);

        // The state a site sits in unless somebody asks otherwise, and the one
        // a figure gets quoted from. The 99.00 rehearsal donation is not in it.
        $this->assertSame(2, (int) $stats['paid_count']);
        $this->assertSame(8000, (int) $stats['raised_cents']);
    }

    public function test_asking_for_test_rows_puts_them_in_the_money_kpis_too(): void
    {
        $stats = (new DonationRepository())->aggregateAdmin([
            'campaign_id'  => $this->campaignId,
            'include_test' => true,
        ]);

        // The strip's total is a view count and mirrors the rows on screen.
        $this->assertSame(3, (int) $stats['total_count']);

        // A screen that lists a donation and leaves it out of the figure above
        // it cannot be read at all, and during setup the figures are the thing
        // being checked. The card says it is including test data while it does.
        $this->assertSame(3, (int) $stats['paid_count']);
        $this->assertSame(17900, (int) $stats['raised_cents']);
    }

    public function test_the_hidden_count_reports_test_rows_whatever_the_scope(): void
    {
        $repo = new DonationRepository();

        $this->assertSame(1, $repo->countTestHidden(['campaign_id' => $this->campaignId]));
        $this->assertSame(
            1,
            $repo->countTestHidden(['campaign_id' => $this->campaignId, 'include_test' => true]),
            'countTestHidden asks how many test rows match, not how many are being hidden right now'
        );
    }

    /**
     * @param array<string,mixed> $args
     * @return list<string>
     */
    private function references(array $args): array
    {
        $result = (new DonationRepository())->listAdmin($args + [
            'campaign_id' => $this->campaignId,
            'per_page'    => 50,
        ]);

        $refs = array_map(static fn ($d): string => (string) $d->reference, $result['items']);
        sort($refs);

        return $refs;
    }

    private function seed(string $reference, bool $isTest, int $cents): void
    {
        $donor = Plugin::instance()->container->get(DonorService::class)
            ->findOrCreate('ts-' . uniqid() . '@example.com', ['first_name' => 'Ts', 'last_name' => 'Test']);

        $now = gmdate('Y-m-d H:i:s');

        $d = Donation::make();
        $d->reference         = $reference;
        $d->donor_id          = (int) $donor->id;
        $d->campaign_id       = $this->campaignId;
        $d->amount_cents      = $cents;
        $d->net_cents         = $cents;
        $d->currency          = 'USD';
        $d->base_amount_cents = $cents;
        $d->base_currency     = 'USD';
        $d->fx_rate           = '1.00000000';
        $d->gateway           = 'offline';
        $d->status            = 'paid';
        $d->is_test           = $isTest;
        $d->paid_at           = $now;
        $d->created_at        = $now;
        $d->updated_at        = $now;
        $d->save();
    }
}
