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
 * A donor row is written for every donation, so rehearsing in test mode mints
 * donor records nobody ever gave to, and those stay out of the list and its
 * totals. Signing up in the portal writes a donor row too, and that one is a
 * real person who handed over a real address: they belong on the screen, with
 * nothing against their name, or the only way to find out they exist is to
 * read the database.
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

    public function test_a_donor_minted_by_a_test_donation_is_not_listed(): void
    {
        $id = $this->donor('test-mode-only@example.com');
        $this->seedDonation($id, true);

        $this->assertNotContains($id, $this->listedIds());
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
        $this->assertNotContains($testOnly, $this->listedIds());
        // Counted as a person, not as money: averages divide by this, and a
        // signup with nothing against their name would drag every one to zero.
        $this->assertSame(1, (int) $stats['with_donations']);
    }

    /** The export is started from the screen, so it carries the screen's rows. */
    public function test_the_export_carries_the_same_people(): void
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
