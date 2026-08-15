<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donations\Donation;
use Dono\Donors\DonorRepository;
use Dono\Donors\DonorService;
use Dono\Foundation\Plugin;

/**
 * The cohort-retention matrix used to anchor cohorts on the denormalized
 * first_donation_at while counting only status='paid'. A donor whose first
 * donation wasn't a plain paid row missed offset 0, so the cohort size
 * undercounted and later offsets could exceed 100%. Cohorts now anchor on each
 * donor's own MIN(paid_at) over the same status set, so everyone lands at
 * offset 0.
 */
final class DonorCohortRetentionTest extends IntegrationTestCase
{
    public function test_cohort_size_includes_partial_refund_first_gifts(): void
    {
        $m0 = gmdate('Y-m-15 12:00:00', strtotime('-3 months'));
        $m2 = gmdate('Y-m-15 12:00:00', strtotime('-1 month'));

        $a = $this->donor('a@example.com');
        $b = $this->donor('b@example.com');

        // Donor A's first donation is partially refunded, then gives again two
        // months on. Donor B gives once in the same cohort month.
        $this->donation($a, 5000, $m0, 'partial_refund');
        $this->donation($a, 5000, $m2, 'paid');
        $this->donation($b, 3000, $m0, 'paid');

        $cohortMonth = gmdate('Y-m', strtotime('-3 months'));
        $cohort      = null;
        foreach ($this->repo()->donorCohortRetention()['cohorts'] as $c) {
            if ($c['month'] === $cohortMonth) {
                $cohort = $c;
                break;
            }
        }

        $this->assertNotNull($cohort, 'the -3mo cohort exists');
        $this->assertSame(2, $cohort['size'],
            'both donors anchor at offset 0, even the partial_refund first donation');
        $this->assertSame(100.0, $cohort['retention'][0]['pct']);
        $this->assertSame(1, $cohort['retention'][2]['count'], 'only donor A returns at +2 months');
        $this->assertSame(50.0, $cohort['retention'][2]['pct'], 'retention never exceeds 100%');
    }

    private function repo(): DonorRepository
    {
        return Plugin::instance()->container->get(DonorRepository::class);
    }

    private function donor(string $email): int
    {
        $d = Plugin::instance()->container->get(DonorService::class)
            ->findOrCreate($email, ['first_name' => 'T', 'last_name' => 'D']);
        return (int) $d->id;
    }

    private function donation(int $donorId, int $cents, string $paidAt, string $status): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $d = Donation::make();
        $d->reference         = 'DONO-CH-' . bin2hex(random_bytes(4));
        $d->donor_id          = $donorId;
        $d->amount_cents      = $cents;
        $d->net_cents         = $cents;
        $d->currency          = 'USD';
        $d->base_amount_cents = $cents;
        $d->base_currency     = 'USD';
        $d->fx_rate           = '1.00000000';
        $d->gateway           = 'offline';
        $d->status            = $status;
        $d->is_test           = false;
        $d->paid_at           = $paidAt;
        $d->created_at        = $now;
        $d->updated_at        = $now;
        $d->save();
    }
}
