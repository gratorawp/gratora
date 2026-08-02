<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Campaigns\Campaign;
use Dono\Donations\AggregateSyncer;
use Dono\Donations\Donation;
use Dono\Donations\DonationRepository;
use Dono\Donations\Refund;
use Dono\Donors\DonorService;
use Dono\Foundation\Plugin;

/**
 * A foreign donation's base value is rounded once, from the whole amount. Its
 * refunds have to be netted the same way, or refunding in instalments gives
 * back base the donation never took in.
 *
 * 200.00 at 0.5107 is worth 10214. Refund it in two 50.00 parts and each is
 * worth 2553.5: rounded separately they come to 5108, but the 100.00 they add
 * up to is worth 5107. One phantom cent, in every total the donation feeds.
 */
final class RefundRoundingTest extends IntegrationTestCase
{
    private const RATE = '0.51070000';

    public function test_instalment_refunds_net_the_same_as_one_refund_of_the_total(): void
    {
        $campaign = $this->campaign();

        $split = $this->paidDonation($campaign, 'DONO-RR-SPLIT', 20000);
        $this->refund($split, 5000);
        $this->refund($split, 5000);

        $whole = $this->campaign();
        $once  = $this->paidDonation($whole, 'DONO-RR-WHOLE', 20000);
        $this->refund($once, 10000);

        $this->assertSame(
            $this->raised($whole),
            $this->raised($campaign),
            'the same 100.00 refunded, in one part or two, leaves the same amount raised'
        );
    }

    public function test_the_netted_refund_is_what_the_refunded_total_is_worth(): void
    {
        $campaign = $this->campaign();
        $donation = $this->paidDonation($campaign, 'DONO-RR-EXACT', 20000);

        $this->refund($donation, 5000);
        $this->refund($donation, 5000);

        // 20000 * 0.5107 = 10214 base in. 10000 refunded is worth 5107 back.
        $this->assertSame(10214 - 5107, $this->raised($campaign));

        $agg = (new DonationRepository())->aggregatePaidBetween(null, null, (int) $campaign->id);
        $this->assertSame(
            10214 - 5107,
            (int) $agg['amount_cents'],
            'the reporting path agrees with the stored campaign total'
        );
    }

    public function test_a_refund_on_an_unconvertible_donation_still_nets_to_nothing(): void
    {
        $campaign = $this->campaign();

        // No rate, so no known base value: the donation contributes nothing and
        // its refunds must not claw back anything either.
        $donation = $this->paidDonation($campaign, 'DONO-RR-NORATE', 20000, null, null);
        $this->refund($donation, 5000);
        $this->refund($donation, 5000);

        $this->assertSame(0, $this->raised($campaign));
    }

    private function raised(Campaign $campaign): int
    {
        (new AggregateSyncer())->syncCampaign((int) $campaign->id);

        return (int) Campaign::query()->where('id', (int) $campaign->id)->get()->raised_cents;
    }

    private function campaign(): Campaign
    {
        $campaign = Campaign::make();
        $campaign->title      = 'Refund rounding';
        $campaign->slug       = 'rr-' . uniqid();
        $campaign->status     = 'published';
        $campaign->created_at = gmdate('Y-m-d H:i:s');
        $campaign->updated_at = $campaign->created_at;
        $campaign->save();

        return $campaign;
    }

    private function paidDonation(
        Campaign $campaign,
        string $reference,
        int $cents,
        ?int $baseCents = null,
        ?string $rate = self::RATE
    ): Donation {
        $donor = Plugin::instance()->container->get(DonorService::class)
            ->findOrCreate('rr-' . uniqid() . '@example.com', ['first_name' => 'Rr', 'last_name' => 'Test']);

        $now = gmdate('Y-m-d H:i:s');

        $d = Donation::make();
        $d->reference         = $reference;
        $d->donor_id          = (int) $donor->id;
        $d->campaign_id       = (int) $campaign->id;
        $d->amount_cents      = $cents;
        $d->net_cents         = $cents;
        $d->currency          = 'EUR';
        $d->base_amount_cents = $rate === null
            ? null
            : ($baseCents ?? (int) round($cents * (float) $rate));
        $d->base_currency     = $rate === null ? null : 'USD';
        $d->fx_rate           = $rate;
        $d->gateway           = 'offline';
        $d->status            = 'paid';
        $d->is_test           = false;
        $d->paid_at           = $now;
        $d->created_at        = $now;
        $d->updated_at        = $now;
        $d->save();

        return $d;
    }

    private function refund(Donation $donation, int $cents): void
    {
        $now = gmdate('Y-m-d H:i:s');

        $refund = Refund::make();
        $refund->donation_id  = (int) $donation->id;
        $refund->amount_cents = $cents;
        $refund->currency     = $donation->currency;
        $refund->status       = 'succeeded';
        $refund->initiated_by = 'admin';
        $refund->occurred_at  = $now;
        $refund->save();

        $donation->refunded_cents = (int) $donation->refunded_cents + $cents;
        $donation->status         = $donation->refunded_cents >= (int) $donation->amount_cents
            ? 'refunded'
            : 'partial_refund';
        $donation->refunded_at    = $now;
        $donation->updated_at     = $now;
        $donation->save();
    }
}
