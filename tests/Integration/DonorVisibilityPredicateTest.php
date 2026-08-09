<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donations\Donation;
use Dono\Donors\DonorRepository;
use Dono\Donors\DonorService;
use Dono\Foundation\Plugin;
use Dono\Vendor\Queryable\DB;

/**
 * The donors list hides a donor whose donations are all test-mode, and shows
 * one who has never given. The predicate leads with donations_count because the
 * count companion has no LIMIT and paid a correlated lookup per donor; that is
 * only sound while the shortcut and the subqueries agree, which is what these
 * assert.
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
    private function visibleIds(): array
    {
        $rows = DB::table('dono_donors')
            ->selectRaw('id')
            ->whereRaw(DonorRepository::visibleDonorPredicate())
            ->getAll();

        return array_map(static fn ($r): int => (int) (is_array($r) ? $r['id'] : $r->id), $rows);
    }

    /** The original predicate, kept here so the shortcut has something to agree with. */
    private function visibleIdsTheSlowWay(): array
    {
        $prefix = DB::getPrefix();
        $any    = "SELECT 1 FROM {$prefix}dono_donations d WHERE d.donor_id = {$prefix}dono_donors.id";

        $rows = DB::table('dono_donors')
            ->selectRaw('id')
            ->whereRaw("(EXISTS ({$any} AND d.is_test = 0) OR NOT EXISTS ({$any}))")
            ->getAll();

        return array_map(static fn ($r): int => (int) (is_array($r) ? $r['id'] : $r->id), $rows);
    }

    public function test_a_donor_whose_donations_are_all_test_is_hidden(): void
    {
        $id = $this->donorId('test-only');
        $this->donation($id, true);
        $this->donation($id, true);

        $this->assertNotContains($id, $this->visibleIds());
    }

    public function test_a_donor_who_has_never_given_is_shown(): void
    {
        $this->assertContains($this->donorId('never'), $this->visibleIds());
    }

    public function test_one_live_donation_among_test_ones_is_enough(): void
    {
        $id = $this->donorId('mixed');
        $this->donation($id, true);
        $this->donation($id, false);
        Plugin::instance()->container->get(\Dono\Donations\AggregateSyncer::class)->syncDonor($id);

        $this->assertContains($id, $this->visibleIds());
    }

    /**
     * The shortcut is only a shortcut. A ticket order is live but does not
     * count toward donations_count, so it has to fall through to the subquery
     * rather than vanish from the list.
     */
    public function test_a_live_ticket_order_still_makes_a_donor_visible(): void
    {
        $id = $this->donorId('order-only');
        $this->donation($id, false, 'order');

        $this->assertContains($id, $this->visibleIds());
    }

    public function test_the_shortcut_agrees_with_the_predicate_it_replaced(): void
    {
        $live = $this->donorId('agree-live');
        $this->donation($live, false);
        Plugin::instance()->container->get(\Dono\Donations\AggregateSyncer::class)->syncDonor($live);

        $testOnly = $this->donorId('agree-test');
        $this->donation($testOnly, true);

        $this->donorId('agree-none');

        $fast = $this->visibleIds();
        $slow = $this->visibleIdsTheSlowWay();
        sort($fast);
        sort($slow);

        $this->assertSame($slow, $fast);
    }

    /**
     * The lifecycle KPI counts donors who have given, and takes the same
     * shortcut. A ticket order is a live donation the counter does not count,
     * so the buyer has to survive the fall-through here too.
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
