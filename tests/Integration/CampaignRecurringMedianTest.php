<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donations\Donation;
use Dono\Donations\DonationRepository;
use Dono\Foundation\Plugin;

/**
 * Two campaign-detail metrics that used to count the wrong set:
 *  - "Recurring donors" counted donation rows, so one donor's monthly renewals
 *    inflated it; it must count distinct donors.
 *  - the amount median ordered over paid-only rows while its offset came from a
 *    paid+partial_refund histogram total, so it overshot the middle.
 */
final class CampaignRecurringMedianTest extends IntegrationTestCase
{
    private const CAMPAIGN = 4242;

    public function test_recurring_donors_counts_distinct_donors_not_rows(): void
    {
        // Donor 101: three monthly renewals + a one-time gift. Donor 202: one
        // monthly gift. Recurring donors = 2 (101, 202), not 4 rows.
        $this->seed(101, 'monthly',  2000, 'r1a');
        $this->seed(101, 'monthly',  2000, 'r1b');
        $this->seed(101, 'monthly',  2000, 'r1c');
        $this->seed(101, 'one_time', 5000, 'ot1');
        $this->seed(202, 'monthly',  3000, 'r2a');

        $this->assertSame(2, $this->repo()->countActiveRecurringForCampaign(self::CAMPAIGN));
    }

    public function test_median_uses_the_same_status_set_as_the_histogram(): void
    {
        // Amounts 1000..5000; the 3000 one is partial_refund. The histogram
        // counts all five, so the median offset (2) must land on 3000 - which
        // only happens if the median query also includes partial_refund.
        $this->seed(301, 'one_time', 1000, 'm1');
        $this->seed(302, 'one_time', 2000, 'm2');
        $this->seed(303, 'one_time', 3000, 'm3', 'partial_refund');
        $this->seed(304, 'one_time', 4000, 'm4');
        $this->seed(305, 'one_time', 5000, 'm5');

        $this->assertSame(3000, $this->repo()->medianPaidAmount(null, null, self::CAMPAIGN, 5));
    }

    public function test_median_uses_base_currency_not_donor_currency(): void
    {
        // Donor-currency amounts and org-base amounts diverge sharply. The
        // median must describe the base values so foreign gifts rank against
        // org-currency ones consistently.
        $this->seed(101, 'one_time', 30000, 'fx1', 'paid', 3000);
        $this->seed(102, 'one_time', 20000, 'fx2', 'paid', 2000);
        $this->seed(103, 'one_time', 10000, 'fx3', 'paid', 1000);

        // offset floor(3/2)=1 over base-sorted [1000,2000,3000] -> 2000, not 20000.
        $this->assertSame(2000, $this->repo()->medianPaidAmount(null, null, self::CAMPAIGN, 3));
    }

    private function repo(): DonationRepository
    {
        return Plugin::instance()->container->get(DonationRepository::class);
    }

    private function seed(int $donorId, string $freq, int $cents, string $ref, string $status = 'paid', ?int $base = null): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $d = Donation::make();
        $d->reference         = 'DONO-RM-' . $ref;
        $d->donor_id          = $donorId;
        $d->campaign_id       = self::CAMPAIGN;
        $d->amount_cents      = $cents;
        $d->net_cents         = $cents;
        $d->currency          = 'USD';
        $d->base_amount_cents = $base ?? $cents;
        $d->base_currency     = 'EUR';
        $d->fx_rate           = '1.00000000';
        $d->gateway           = 'offline';
        $d->status            = $status;
        $d->frequency         = $freq;
        $d->is_test           = false;
        $d->paid_at           = $now;
        $d->created_at        = $now;
        $d->updated_at        = $now;
        $d->save();
    }
}
