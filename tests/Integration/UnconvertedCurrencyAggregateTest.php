<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Campaigns\Campaign;
use Dono\Donations\AggregateSyncer;
use Dono\Donations\Donation;
use Dono\Donations\DonationRepository;
use Dono\Donors\DonorService;
use Dono\Foundation\Plugin;

/**
 * A foreign-currency donation with no FX rate has base_amount_cents NULL (no
 * known base value). It must contribute 0 to base-currency aggregates - never
 * its raw foreign cents, which would corrupt every campaign / fund / donor /
 * dashboard total for a multi-currency org.
 */
final class UnconvertedCurrencyAggregateTest extends IntegrationTestCase
{
    public function test_unconverted_foreign_donation_contributes_zero_to_base_totals(): void
    {
        $donor = Plugin::instance()->container->get(DonorService::class)
            ->findOrCreate('fx@example.com', ['first_name' => 'Fx', 'last_name' => 'Test']);

        $campaign = Campaign::make();
        $campaign->title  = 'FX';
        $campaign->slug   = 'fx-' . uniqid();
        $campaign->status = 'published';
        $campaign->created_at = gmdate('Y-m-d H:i:s');
        $campaign->updated_at = $campaign->created_at;
        $campaign->save();

        $now = gmdate('Y-m-d H:i:s');
        // Base-currency donation: 50.00 base.
        $this->seedPaid((int) $donor->id, (int) $campaign->id, 'DONO-FX-BASE', 5000, 'USD', 5000, '1.00000000', $now);
        // Foreign donation we could not convert: base + fx_rate are NULL.
        $this->seedPaid((int) $donor->id, (int) $campaign->id, 'DONO-FX-NORATE', 9999, 'EUR', null, null, $now);

        (new AggregateSyncer())->syncCampaign((int) $campaign->id);

        $reloaded = Campaign::query()->where('id', (int) $campaign->id)->get();
        $this->assertSame(5000, (int) $reloaded->raised_cents,
            'un-converted foreign donation must not fold its raw cents into base raised_cents');

        // The list/dashboard aggregate path agrees, and both donations still
        // count toward volume (only the unreportable money is excluded).
        $agg = (new DonationRepository())->aggregatePaidBetween(null, null, (int) $campaign->id);
        $this->assertSame(5000, (int) $agg['amount_cents'], 'repository aggregate excludes the unconvertible amount');
        $this->assertSame(2, (int) $agg['donations_count'], 'both donations still count toward volume');
    }

    private function seedPaid(int $donorId, int $campaignId, string $ref, int $cents, string $cur, ?int $baseCents, ?string $fx, string $now): void
    {
        $d = Donation::make();
        $d->reference         = $ref;
        $d->donor_id          = $donorId;
        $d->campaign_id       = $campaignId;
        $d->amount_cents      = $cents;
        $d->net_cents         = $cents;
        $d->currency          = $cur;
        $d->base_amount_cents = $baseCents;
        $d->base_currency     = $baseCents !== null ? 'USD' : null;
        $d->fx_rate           = $fx;
        $d->gateway           = 'offline';
        $d->status            = 'paid';
        $d->is_test           = false;
        $d->paid_at           = $now;
        $d->created_at        = $now;
        $d->updated_at        = $now;
        $d->save();
    }
}
