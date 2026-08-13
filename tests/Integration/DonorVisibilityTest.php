<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donations\Donation;
use Dono\Donors\DonorAggregateSyncer;
use Dono\Donors\DonorRepository;
use Dono\Donors\DonorService;
use Dono\Exports\DonorExporter;
use Dono\Foundation\Plugin;

/**
 * Who counts as a donor on the Donors screen.
 *
 * Every donor row belongs on the screen, and the screen says which is which. A
 * rehearsal in test mode mints a donor nobody gave to, and hiding them meant
 * the operator who made them could not open, check or erase them. They are
 * listed and badged instead, and they reach no money figure: the counters that
 * feed those are live-only by construction.
 *
 * The CSV is the exception. A badge cannot travel in a file mailed to a
 * fulfillment house, so that keeps the narrower population.
 */
final class DonorVisibilityTest extends IntegrationTestCase
{
    private function donor(string $email): int
    {
        return (int) Plugin::instance()->container
            ->get(DonorService::class)
            ->findOrCreate($email, ['first_name' => 'Vis', 'last_name' => 'Probe'])
            ->id;
    }

    private function seedDonation(int $donorId, bool $isTest): void
    {
        $d = Donation::make();
        $d->donor_id     = $donorId;
        $d->campaign_id  = 1;
        $d->reference    = 'VIS-' . bin2hex(random_bytes(4));
        $d->amount_cents = 5000;
        $d->currency     = 'USD';
        $d->status       = 'paid';
        $d->gateway      = 'offline';
        $d->is_test      = $isTest;
        $d->created_at   = '2026-01-01 00:00:00';
        $d->updated_at   = '2026-01-01 00:00:00';
        $d->save();

        // The screen reads the donor's own counters, so a fixture that skips
        // the resync would describe a donor no live install can produce.
        (new DonorAggregateSyncer())->syncForDonor($donorId);
    }

    /** @return list<int> */
    private function listedIds(): array
    {
        $result = Plugin::instance()->container->get(DonorRepository::class)->listAdmin(['per_page' => 100]);

        return array_map(static fn ($d) => (int) $d->id, $result['items']);
    }

    public function test_a_donor_who_only_signed_up_is_listed(): void
    {
        $id = $this->donor('signed-up-only@example.com');

        $this->assertContains($id, $this->listedIds());
    }

    public function test_a_donor_minted_by_a_test_donation_is_listed_and_badged(): void
    {
        $id = $this->donor('test-mode-only@example.com');
        $this->seedDonation($id, true);

        $this->assertContains($id, $this->listedIds());
        $this->assertArrayHasKey($id, DonorRepository::testOnlyIdsAmong([$id]));
    }

    public function test_the_tab_badge_and_the_card_footer_count_the_same_rows(): void
    {
        // They label the same list, so they have to agree. The footer read
        // lifetime.count (live paid only) while the badge counts every row, so
        // one donation that was pending or failed split them by one.
        $id = $this->donor('tab-vs-footer@example.com');
        $this->seedDonation($id, false);
        $this->seedDonation($id, true);

        $profile = Plugin::instance()->container
            ->get(\Dono\Donors\DonorMetricsService::class)
            ->profile($id);

        $this->assertSame(2, (int) $profile['donations_total'], 'both read this');
        $this->assertNotSame(
            (int) $profile['lifetime']['count'],
            (int) $profile['donations_total'],
            'and it is deliberately not the money count'
        );
    }

    public function test_the_donations_tab_badge_counts_test_donations_too(): void
    {
        $id = $this->donor('tab-count-test-only@example.com');
        $this->seedDonation($id, true);
        $this->seedDonation($id, true);

        $profile = Plugin::instance()->container
            ->get(\Dono\Donors\DonorMetricsService::class)
            ->profile($id);

        // The tab lists these rows, so the badge above it has to agree.
        // donations_count is live-only and reads zero here.
        $this->assertSame(2, (int) $profile['donations_total']);
        $this->assertSame(0, (int) $profile['lifetime']['count'], 'and the money figure stays clean');
    }

    public function test_insights_says_how_many_donors_it_left_out(): void
    {
        $id = $this->donor('insights-test-only@example.com');
        $this->seedDonation($id, true);

        $insights = Plugin::instance()->container
            ->get(\Dono\Donors\DonorMetricsService::class)
            ->insights();

        // Insights reads the donor rollup columns, which are live-only by
        // construction, so there is no test-inclusive version of lifetime
        // value or retention to offer. Naming the gap is the whole fix.
        $this->assertSame(1, (int) $insights['test']['test_only_donors']);
        $this->assertSame(0, (int) $insights['kpi']['total'], 'and the analysis itself does not move');
    }

    public function test_a_donor_who_gave_for_real_is_not_badged_as_test(): void
    {
        $id = $this->donor('badge-real@example.com');
        $this->seedDonation($id, true);
        $this->seedDonation($id, false);

        $this->assertSame([], DonorRepository::testOnlyIdsAmong([$id]));
    }

    public function test_a_donor_who_never_gave_is_not_badged_as_test(): void
    {
        // No donations at all is a real person who has not given yet, not a
        // rehearsal. The badge asks whether any row is real, not whether the
        // live counter is zero.
        $id = $this->donor('badge-never-gave@example.com');

        $this->assertSame([], DonorRepository::testOnlyIdsAmong([$id]));
    }

    public function test_a_real_donation_puts_a_test_minted_donor_back_on_the_list(): void
    {
        $id = $this->donor('test-then-real@example.com');
        $this->seedDonation($id, true);
        $this->seedDonation($id, false);

        $this->assertContains($id, $this->listedIds());
    }

    /**
     * The strip sits directly above the list, so a total that disagrees with the
     * rows under it reads as a broken screen.
     */
    public function test_the_kpi_strip_counts_the_same_people_the_list_shows(): void
    {
        $signedUp = $this->donor('strip-signed-up@example.com');
        $gave     = $this->donor('strip-gave@example.com');
        $this->seedDonation($gave, false);
        $testOnly = $this->donor('strip-test-only@example.com');
        $this->seedDonation($testOnly, true);

        $repo  = Plugin::instance()->container->get(DonorRepository::class);
        $stats = $repo->aggregateAdmin();

        $this->assertSame(count($this->listedIds()), (int) $stats['total_count']);
        $this->assertContains($signedUp, $this->listedIds());
        $this->assertContains($testOnly, $this->listedIds());
        // Counted as a person, not as money: averages divide by this, and a
        // signup with nothing against their name would drag every one to zero.
        $this->assertSame(1, (int) $stats['with_donations']);
    }

    /**
     * The CSV is narrower than the screen on purpose: its columns are opt-in
     * and none of them can say "not a real person".
     */
    public function test_the_export_leaves_out_test_only_donors(): void
    {
        $signedUp = $this->donor('export-signed-up@example.com');
        $testOnly = $this->donor('export-test-only@example.com');
        $this->seedDonation($testOnly, true);

        $csv = Plugin::instance()->container
            ->get(DonorExporter::class)
            ->toCsv(['columns' => ['email', 'donor_id']]);

        $this->assertStringContainsString('export-signed-up@example.com', $csv);
        $this->assertStringNotContainsString('export-test-only@example.com', $csv);
    }

    /**
     * Lifecycle stages are stages of giving. Somebody who has never given has
     * none, and counting them would leave every share on the insights screen
     * describing a smaller slice of the total than it claims to.
     */
    public function test_a_signup_is_left_out_of_the_lifecycle_rollup(): void
    {
        $this->donor('lifecycle-signed-up@example.com');
        $gave = $this->donor('lifecycle-gave@example.com');
        $this->seedDonation($gave, false);

        $kpi = Plugin::instance()->container
            ->get(DonorRepository::class)
            ->lifecycleKpi('2026-01-15');

        $this->assertSame(1, (int) $kpi['total']);
    }
}
