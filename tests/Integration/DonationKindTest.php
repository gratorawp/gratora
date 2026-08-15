<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Campaigns\Campaign;
use Dono\Donations\AggregateSyncer;
use Dono\Donations\Donation;
use Dono\Donations\DonationIntent;
use Dono\Donations\DonationService;
use Dono\Donors\Donor;
use Dono\Donors\DonorService;
use Dono\Foundation\Plugin;

/**
 * The donation `kind` discriminator: non-donation money (event ticket orders)
 * rides the payment rails and stays out of every total that means giving.
 */
final class DonationKindTest extends IntegrationTestCase
{
    private function paidDonation(int $donorId, int $cents, string $kind, ?int $campaignId = null): Donation
    {
        $now = gmdate('Y-m-d H:i:s');
        $d = Donation::make();
        $d->reference         = 'DONO-KD-' . substr(md5($kind . $cents . uniqid()), 0, 8);
        $d->donor_id          = $donorId;
        $d->campaign_id       = $campaignId;
        $d->amount_cents      = $cents;
        $d->net_cents         = $cents;
        $d->currency          = 'USD';
        $d->base_amount_cents = $cents;
        $d->base_currency     = 'USD';
        $d->fx_rate           = '1.00000000';
        $d->gateway           = 'stripe';
        $d->status            = 'paid';
        $d->is_test           = false;
        $d->kind              = $kind;
        $d->paid_at           = $now;
        $d->created_at        = $now;
        $d->updated_at        = $now;
        $d->save();
        return $d;
    }

    public function test_intent_kind_is_stored_and_defaults_to_donation(): void
    {
        $service = Plugin::instance()->container->get(DonationService::class);

        $plain = $service->createPending(new DonationIntent(
            email: 'kind-default@example.com',
            amount_cents: 1500,
            currency: 'USD',
            gateway: 'offline',
        ))['donation'];
        $this->assertSame('donation', Donation::query()->where('id', $plain->id)->get()->kind);

        $order = $service->createPending(new DonationIntent(
            email: 'kind-order@example.com',
            amount_cents: 4500,
            currency: 'USD',
            gateway: 'offline',
            kind: 'order',
        ))['donation'];
        $this->assertSame('order', Donation::query()->where('id', $order->id)->get()->kind);
    }

    public function test_donor_rollups_exclude_order_kind(): void
    {
        $donor = Plugin::instance()->container->get(DonorService::class)
            ->findOrCreate('kind-donor@example.com', ['first_name' => 'K', 'last_name' => 'D']);
        $this->paidDonation((int) $donor->id, 2000, 'donation');
        $this->paidDonation((int) $donor->id, 5000, 'order');

        Plugin::instance()->container->get(AggregateSyncer::class)->syncDonor((int) $donor->id);

        $fresh = Donor::query()->where('id', $donor->id)->get();
        $this->assertSame(2000, (int) $fresh->total_donated_cents, 'ticket money stays out of lifetime totals');
        $this->assertSame(1, (int) $fresh->donations_count);
    }

    public function test_campaign_aggregates_exclude_order_kind(): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $c = Campaign::make();
        $c->title      = 'Kind';
        $c->slug       = 'kind-' . uniqid();
        $c->status     = 'published';
        $c->created_at = $now;
        $c->updated_at = $now;
        $c->save();

        $donor = Plugin::instance()->container->get(DonorService::class)
            ->findOrCreate('kind-campaign@example.com', []);
        $this->paidDonation((int) $donor->id, 2000, 'donation', (int) $c->id);
        $this->paidDonation((int) $donor->id, 5000, 'order', (int) $c->id);

        Plugin::instance()->container->get(AggregateSyncer::class)->syncCampaign((int) $c->id);

        // Raised means donations, at every level. A campaign used to count orders
        // while its funds did not, so a campaign disagreed with the sum of its
        // own funds, and a ticket buyer who received something of value was
        // counted as having given.
        $fresh = Campaign::query()->where('id', $c->id)->get();
        $this->assertSame(2000, (int) $fresh->raised_cents, 'the donation, not the ticket');
    }
}
