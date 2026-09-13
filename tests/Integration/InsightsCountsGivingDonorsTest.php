<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Donors\Donor;
use Gratora\Donors\DonorMetricsService;
use Gratora\Foundation\Plugin;

/**
 * Insights counts the donors it can analyse, which is not every donor.
 *
 * Both views of this page used to head their first figure "Total donors" over
 * different populations: the list over every row, this over the ones with a
 * donation on record. The numbers were each right and the page contradicted
 * itself. The label says which one this is; this pins the population under it,
 * so it cannot drift back to the other without a test saying so.
 */
final class InsightsCountsGivingDonorsTest extends IntegrationTestCase
{
    private function metrics(): DonorMetricsService
    {
        return Plugin::instance()->container->get(DonorMetricsService::class);
    }

    private function donor(string $name, int $donationsCount, ?string $lastDonationAt): int
    {
        $now = gmdate('Y-m-d H:i:s');

        $d = Donor::make();
        $d->email_hash          = hash('sha256', $name . uniqid());
        $d->first_name          = $name;
        $d->last_name           = 'Tester';
        $d->donations_count     = $donationsCount;
        $d->total_donated_cents = $donationsCount > 0 ? 1000 : 0;
        $d->last_donation_at    = $lastDonationAt;
        $d->created_at          = $now;
        $d->updated_at          = $now;
        $d->save();

        return (int) $d->id;
    }

    public function test_a_donor_who_never_gave_is_not_counted(): void
    {
        $this->donor('Gave', 1, gmdate('Y-m-d H:i:s'));
        $this->donor('Never', 0, null);

        $kpi = $this->metrics()->insights()['kpi'];

        $this->assertSame(1, (int) $kpi['total'], 'the one who gave, not both rows');
        $this->assertSame(2, (int) Donor::query()->count(), 'and the other donor is still there');
    }

    /**
     * The segments are cut on the date of a donation, so a donor with none
     * sits in the total and in no segment. The four add up to less than the
     * total on purpose, and the segment table carries the remainder.
     */
    public function test_the_segments_need_not_add_up_to_the_total(): void
    {
        $this->donor('Recent', 2, gmdate('Y-m-d H:i:s'));
        $this->donor('Counted but undated', 1, null);

        $kpi      = $this->metrics()->insights()['kpi'];
        $segments = (int) $kpi['active'] + (int) $kpi['at_risk'] + (int) $kpi['lapsed'] + (int) $kpi['lost'];

        $this->assertSame(2, (int) $kpi['total']);
        $this->assertSame(1, $segments, 'the undated donor is in the total and in no segment');
    }
}
