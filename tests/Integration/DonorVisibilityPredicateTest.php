<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donations\Donation;
use Dono\Donors\DonorRepository;
use Dono\Donors\DonorService;
use Dono\Foundation\Plugin;
use Dono\Vendor\Queryable\DB;

/**
 * Which donors are test-only, and which may leave the site in a CSV.
 *
 * The distinction is not "donations_count is zero". That counter is synced
 * through donationsOnly(), so a donor whose only live donation is a ticket
 * order reads zero as well, and so does one who has never given. The question
 * is whether any row against them is real, so it is asked of the rows.
 *
 * The lifecycle KPI still takes the donations_count shortcut, and the ticket
 * order is what proves the shortcut cannot replace the subquery.
 */
final class DonorVisibilityPredicateTest extends IntegrationTestCase
{
    private function donorId(string $tag): int
    {
        return (int) Plugin::instance()->container->get(DonorService::class)
            ->findOrCreate("vis-{$tag}-" . uniqid() . '@example.test')->id;
    }

    private function donation(int $donorId, bool $isTest, string $kind = 'donation'): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $d = Donation::make();
        $d->reference    = 'VIS-' . strtoupper(uniqid());
        $d->donor_id     = $donorId;
        $d->amount_cents = 1000;
        $d->net_cents    = 1000;
        $d->currency     = 'EUR';
        $d->status       = 'paid';
        $d->kind         = $kind;
        $d->gateway      = 'offline';
        $d->is_test      = $isTest;
        $d->paid_at      = $now;
        $d->created_at   = $now;
        $d->updated_at   = $now;
        $d->save();
    }

    /** @return list<int> */
    private function idsMatching(string $predicate): array
    {
        $rows = DB::table('dono_donors')
            ->selectRaw('id')
            ->whereRaw($predicate)
            ->getAll();

        return array_map(static fn ($r): int => (int) (is_array($r) ? $r['id'] : $r->id), $rows);
    }

    public function test_a_donor_whose_donations_are_all_test_is_test_only(): void
    {
        $id = $this->donorId('test-only');
        $this->donation($id, true);
        $this->donation($id, true);

        $this->assertContains($id, $this->idsMatching(DonorRepository::testOnlyDonorPredicate()));
    }

    public function test_a_donor_who_has_never_given_is_not_test_only(): void
    {
        // No rows at all is a real person who has not given yet.
        $id = $this->donorId('never');

        $this->assertNotContains($id, $this->idsMatching(DonorRepository::testOnlyDonorPredicate()));
    }

    public function test_one_live_donation_among_test_ones_clears_the_badge(): void
    {
        $id = $this->donorId('mixed');
        $this->donation($id, true);
        $this->donation($id, false);

        $this->assertNotContains($id, $this->idsMatching(DonorRepository::testOnlyDonorPredicate()));
    }

    /**
     * A ticket order is live money that donations_count never counts, so a
     * badge built on that counter would call this buyer a rehearsal.
     */
    public function test_a_live_ticket_order_is_not_a_rehearsal(): void
    {
        $id = $this->donorId('order-only');
        $this->donation($id, false, 'order');

        $this->assertNotContains($id, $this->idsMatching(DonorRepository::testOnlyDonorPredicate()));
    }

    public function test_the_two_predicates_partition_the_table(): void
    {
        $live = $this->donorId('part-live');
        $this->donation($live, false);
        $testOnly = $this->donorId('part-test');
        $this->donation($testOnly, true);
        $this->donorId('part-none');

        $testIds = $this->idsMatching(DonorRepository::testOnlyDonorPredicate());
        $mailIds = $this->idsMatching(DonorRepository::mailableDonorPredicate());

        // Every donor is in exactly one, which is what lets the list drop its
        // predicate entirely while the export keeps one.
        $this->assertSame([], array_intersect($testIds, $mailIds));
        $this->assertSame(
            (int) DB::table('dono_donors')->count(),
            count($testIds) + count($mailIds)
        );
        $this->assertContains($testOnly, $testIds);
        $this->assertContains($live, $mailIds);
    }

    /**
     * The lifecycle KPI still takes the donations_count shortcut. A ticket
     * order is a live donation the counter does not count, so the buyer has to
     * survive the fall-through to the subquery.
     */
    public function test_the_kpi_counts_a_ticket_only_buyer_as_having_given(): void
    {
        $id = $this->donorId('kpi-order');
        $this->donation($id, false, 'order');

        $kpi = Plugin::instance()->container->get(\Dono\Donors\DonorRepository::class)
            ->lifecycleKpi(gmdate('Y-m-d'));

        $withOrder = (int) $kpi['total'];

        $hidden = $this->donorId('kpi-test-only');
        $this->donation($hidden, true);

        $after = (int) Plugin::instance()->container->get(\Dono\Donors\DonorRepository::class)
            ->lifecycleKpi(gmdate('Y-m-d'))['total'];

        $this->assertSame($withOrder, $after, 'a test-only donor does not join the KPI');
        $this->assertGreaterThan(0, $withOrder);
    }
}
