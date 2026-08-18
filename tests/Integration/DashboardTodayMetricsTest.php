<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Dashboard\DashboardMetricsService;
use Dono\Donations\Donation;
use Dono\Donations\DonationRepository;
use Dono\Donations\Refund;
use Dono\Donors\DonorService;
use Dono\Foundation\Plugin;
use Dono\Foundation\Time\Clock;
use Dono\Recurring\RecurringPlanRepository;

/**
 * Locks the live "today" ribbon's money math: donations are summed in the org
 * base currency, so a foreign-currency refund must be scaled by its fx rate
 * before it is subtracted. Subtracting the raw amount_cents would mix
 * currencies and understate (or overstate) the real net raised.
 */
final class DashboardTodayMetricsTest extends IntegrationTestCase
{
    public function test_today_nets_foreign_currency_refund_in_base_currency(): void
    {
        $donor = Plugin::instance()->container->get(DonorService::class)
            ->findOrCreate('today@example.com', ['first_name' => 'Tess', 'last_name' => 'Day']);
        $now = gmdate('Y-m-d H:i:s');

        // Base-currency donation: 100.00 base.
        $this->seedPaid((int) $donor->id, 'DONO-TODAY-EUR', 10000, 'EUR', 10000, '1.00000000', $now);

        // Foreign donation: 100.00 USD recorded as 50.00 base (fx 0.5).
        $usd = $this->seedPaid((int) $donor->id, 'DONO-TODAY-USD', 10000, 'USD', 5000, '0.50000000', $now);

        // 40.00 USD refunded == 20.00 base.
        $r = Refund::make();
        $r->donation_id  = (int) $usd->id;
        $r->amount_cents = 4000;
        $r->currency     = 'USD';
        $r->initiated_by = 'admin';
        $r->status       = 'succeeded';
        $r->occurred_at  = $now;
        $r->save();

        $c       = Plugin::instance()->container;
        $service = new DashboardMetricsService(
            $c->get(Clock::class),
            $c->get(DonationRepository::class),
            $c->get(RecurringPlanRepository::class),
        );
        $today = $service->today();

        // 10000 (EUR base) + 5000 (USD base) - 2000 (refund scaled to base) = 13000.
        $this->assertSame(13000, (int) $today['amount_raised_cents'],
            'foreign-currency refund is netted in base currency, not raw');
        $this->assertSame(2, (int) $today['donations_count']);
    }

    /**
     * The ribbon is the one money surface that nets refunds itself; the campaign
     * counter, the donor total, the admin aggregate and the revenue export all
     * go through DonationQueries::refundedBaseExpr(), which rounds the summed
     * refund once. Rounding each instalment on its own agrees with that on a
     * single refund and drifts a cent per extra one, so an admin reconciling the
     * headline against the counter beside it finds a difference with nothing
     * explaining it.
     */
    public function test_a_refund_paid_in_instalments_nets_the_same_as_every_other_surface(): void
    {
        $donor = Plugin::instance()->container->get(DonorService::class)
            ->findOrCreate('instalments@example.com', ['first_name' => 'Ivy', 'last_name' => 'Nett']);
        $now = gmdate('Y-m-d H:i:s');

        // 200.00 USD at 0.5107, so 102.14 base, rounded once from the whole.
        $usd = $this->seedPaid((int) $donor->id, 'DONO-TODAY-SPLIT', 20000, 'USD', 10214, '0.51070000', $now);

        // Refunded in two halves. 10000 * 0.5107 = 5107 exactly; each 5000 half
        // is 2553.5, which rounds up twice to 5108.
        foreach ([5000, 5000] as $i => $cents) {
            $r = Refund::make();
            $r->donation_id  = (int) $usd->id;
            $r->amount_cents = $cents;
            $r->currency     = 'USD';
            $r->initiated_by = 'admin';
            $r->status       = 'succeeded';
            $r->occurred_at  = $now;
            $r->save();
        }

        $c       = Plugin::instance()->container;
        $service = new DashboardMetricsService(
            $c->get(Clock::class),
            $c->get(DonationRepository::class),
            $c->get(RecurringPlanRepository::class),
        );

        $this->assertSame(
            5107,
            (int) $service->today()['amount_raised_cents'],
            'one rounding on one product, as refundedBaseExpr does it'
        );
    }

    private function seedPaid(int $donorId, string $ref, int $cents, string $cur, int $baseCents, string $fx, string $now): Donation
    {
        $d = Donation::make();
        $d->reference         = $ref;
        $d->donor_id          = $donorId;
        $d->amount_cents      = $cents;
        $d->net_cents         = $cents;
        $d->currency          = $cur;
        $d->base_amount_cents = $baseCents;
        $d->base_currency     = 'EUR';
        $d->fx_rate           = $fx;
        $d->gateway           = 'offline';
        $d->status            = 'paid';
        $d->is_test           = false;
        $d->paid_at           = $now;
        $d->created_at        = $now;
        $d->updated_at        = $now;
        $d->save();
        return $d;
    }
}
