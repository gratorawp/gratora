<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donations\Donation;
use Dono\Donors\Donor;
use Dono\Donors\DonorRepository;

/**
 * Guards that the at-risk KPI count and the at-risk list/export describe the
 * exact same donors. Before the shared lifecycle window, lifecycleKpi()
 * bucketed at_risk as 90-180 days while listAtRisk() returned 90-365 days, so
 * the headline number never matched the rows beneath it.
 */
final class DonorLifecycleWindowTest extends IntegrationTestCase
{
    private const TODAY = '2026-06-26';

    public function test_at_risk_kpi_count_matches_the_at_risk_list(): void
    {
        $active  = $this->seedDonor('active@example.com',   30);  // active  (<90)
        $atRisk1 = $this->seedDonor('atrisk1@example.com', 100);  // at_risk (90-180)
        $atRisk2 = $this->seedDonor('atrisk2@example.com', 150);  // at_risk (90-180)
        $lapsed  = $this->seedDonor('lapsed@example.com',  250);  // lapsed  (180-365)
        $lost    = $this->seedDonor('lost@example.com',    400);  // lost    (>365)

        $repo = $this->repo();
        $kpi  = $repo->lifecycleKpi(self::TODAY);
        $list = $repo->listAtRisk(self::TODAY);

        $this->assertSame(1, (int) $kpi['active'], 'one donor inside 90 days');
        $this->assertSame(2, (int) $kpi['at_risk'], 'two donors in the 90-180 day at-risk band');
        $this->assertSame(1, (int) $kpi['lapsed'], 'one donor in the 180-365 day lapsed band');
        $this->assertSame(1, (int) $kpi['lost'], 'one donor past 365 days');

        $this->assertSame(
            (int) $kpi['at_risk'],
            (int) $list['total'],
            'the at-risk KPI count and the at-risk list total must agree'
        );

        $ids = array_map(static fn ($r) => (int) $r['id'], $list['rows']);
        sort($ids);
        $expected = [$atRisk1, $atRisk2];
        sort($expected);
        $this->assertSame($expected, $ids, 'the list contains exactly the at-risk donors');
        $this->assertNotContains($lapsed, $ids, 'a lapsed donor is not in the at-risk list');
        $this->assertNotContains($active, $ids, 'an active donor is not in the at-risk list');
        $this->assertNotContains($lost, $ids, 'a lost donor is not in the at-risk list');
    }

    public function test_redacted_donors_are_excluded_from_both(): void
    {
        $this->seedDonor('keep@example.com', 120);
        $gone = $this->seedDonor('gone@example.com', 120);

        Donor::query()->where('id', $gone)->update(['redacted_at' => gmdate('Y-m-d H:i:s')]);

        $repo = $this->repo();
        $this->assertSame(1, (int) $repo->lifecycleKpi(self::TODAY)['at_risk']);
        $this->assertSame(1, (int) $repo->listAtRisk(self::TODAY)['total']);
    }

    private function repo(): DonorRepository
    {
        return \Dono\Foundation\Plugin::instance()->container->get(DonorRepository::class);
    }

    private function seedDonor(string $email, int $lastDonatedDaysAgo): int
    {
        $last = (new \DateTimeImmutable(self::TODAY))
            ->modify("-{$lastDonatedDaysAgo} days")
            ->format('Y-m-d 12:00:00');
        $now  = gmdate('Y-m-d H:i:s');

        $d = Donor::make();
        $d->email_hash          = hash('sha256', $email);
        $d->email_encrypted     = 'enc:' . $email;
        $d->donations_count     = 1;
        $d->total_donated_cents = 5000;
        $d->first_donation_at   = $last;
        $d->last_donation_at    = $last;
        $d->created_at          = $now;
        $d->updated_at          = $now;
        $d->save();

        $donorId = (int) $d->id;

        // A real donor always has at least one live donation backing these
        // counters; lifecycleKpi()/list counts now filter on that, so seed the
        // paid row the denormalized fields imply.
        $don = Donation::make();
        $don->reference         = 'DONO-LW-' . substr(md5($email), 0, 8);
        $don->donor_id          = $donorId;
        $don->amount_cents      = 5000;
        $don->net_cents         = 5000;
        $don->currency          = 'USD';
        $don->base_amount_cents = 5000;
        $don->base_currency     = 'USD';
        $don->fx_rate           = '1.00000000';
        $don->gateway           = 'stripe';
        $don->status            = 'paid';
        $don->is_test           = false;
        $don->paid_at           = $last;
        $don->created_at        = $now;
        $don->updated_at        = $now;
        $don->save();

        return $donorId;
    }
}
