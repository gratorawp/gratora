<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Analytics\Event;
use Gratora\Donations\Donation;
use Gratora\Donors\Donor;
use Gratora\Donors\DonorRepository;
use Gratora\Donors\DonorRetention;
use Gratora\Donors\DonorService;
use Gratora\Exports\DonorExporter;
use Gratora\Foundation\Plugin;
use InvalidArgumentException;

/**
 * A donor taken off the working list, reversibly.
 *
 * The bin is for the donor a donation cascade cannot reach: a scraped signup,
 * an importer artefact, an abandoned checkout whose donation was already
 * deleted. Nothing about the person changes, so every money figure reads the
 * same either side of it and only the screens that list people move.
 */
final class DonorTrashTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    private function donors(): DonorService
    {
        return Plugin::instance()->container->get(DonorService::class);
    }

    private function repo(): DonorRepository
    {
        return Plugin::instance()->container->get(DonorRepository::class);
    }

    /** A donor with nothing against their name, which is what the bin is for. */
    private function signup(string $label): Donor
    {
        return $this->donors()->findOrCreate(
            $label . '-' . uniqid() . '@example.test',
            ['first_name' => 'Bin', 'last_name' => 'Probe']
        );
    }

    private function paidDonation(int $donorId): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $d   = Donation::make();
        $d->donor_id          = $donorId;
        $d->reference         = 'BIN-' . bin2hex(random_bytes(4));
        $d->amount_cents      = 2500;
        $d->base_amount_cents = 2500;
        $d->currency          = 'USD';
        $d->base_currency     = 'USD';
        $d->status            = 'paid';
        $d->gateway           = 'offline';
        $d->frequency         = 'one_time';
        $d->kind              = 'donation';
        $d->is_test           = false;
        $d->paid_at           = $now;
        $d->created_at        = $now;
        $d->updated_at        = $now;
        $d->save();
    }

    /** @return list<int> */
    private function listedIds(array $args = []): array
    {
        return array_map(
            static fn ($d): int => (int) $d->id,
            $this->repo()->listAdmin($args + ['per_page' => 100])['items']
        );
    }

    public function test_a_trashed_donor_leaves_the_list_and_the_headcount_goes_with_them(): void
    {
        $kept    = $this->signup('kept');
        $trashed = $this->signup('gone');

        $before = count($this->listedIds());
        $this->donors()->trash($trashed);

        $listed = $this->listedIds();
        $this->assertContains((int) $kept->id, $listed);
        $this->assertNotContains((int) $trashed->id, $listed);
        $this->assertCount($before - 1, $listed);

        // The strip and the rows read one population. A total that disagrees
        // with what is underneath it reads as a broken screen.
        $this->assertSame(
            count($listed),
            (int) $this->repo()->aggregateAdmin()['total_count'],
            'the headcount moved with the list'
        );
    }

    public function test_the_bin_shows_the_trashed_and_nothing_else(): void
    {
        $kept    = $this->signup('bin-kept');
        $trashed = $this->signup('bin-gone');
        $this->donors()->trash($trashed);

        $bin = $this->listedIds(['trashed' => 'only']);

        $this->assertSame([(int) $trashed->id], $bin);
        $this->assertNotContains((int) $kept->id, $bin);
        $this->assertSame(1, (int) $this->repo()->countTrashed());
    }

    public function test_restoring_puts_them_back(): void
    {
        $donor = $this->signup('restored');
        $this->donors()->trash($donor);
        $this->assertNotContains((int) $donor->id, $this->listedIds());

        $this->donors()->restore($donor);

        $this->assertContains((int) $donor->id, $this->listedIds());
        $this->assertSame([], $this->listedIds(['trashed' => 'only']));
    }

    /**
     * The bin stages a delete, so it accepts exactly who the delete accepts.
     * A donor whose money is on the books is Redact's problem, not the bin's.
     */
    public function test_a_donor_the_delete_gate_keeps_cannot_be_binned(): void
    {
        $donor = $this->signup('has-money');
        $this->paidDonation((int) $donor->id);

        try {
            $this->donors()->trash($donor);
            $this->fail('a donor who has given must not be binnable');
        } catch (InvalidArgumentException $e) {
            $this->assertNotSame('', $e->getMessage(), 'and it says why');
        }

        $this->assertContains((int) $donor->id, $this->listedIds());
    }

    /**
     * The one place hiding a trashed donor is unambiguously right: the file
     * goes to a fulfillment house and cannot carry a badge.
     */
    public function test_a_binned_donor_does_not_travel_in_the_csv(): void
    {
        $kept    = $this->signup('csv-kept');
        $trashed = $this->signup('csv-gone');
        $this->donors()->trash($trashed);

        $csv = Plugin::instance()->container
            ->get(DonorExporter::class)
            ->toCsv(['columns' => ['email', 'donor_id']]);

        $this->assertStringContainsString('csv-kept', $csv);
        $this->assertStringNotContainsString('csv-gone', $csv);
    }

    /**
     * Retention redacts rather than deletes, and the purge that follows severs
     * the handle, so a sweep over the bin turns a reversible state into a row
     * that is nobody. The preview has to agree with the sweep or the admin
     * authorises it on a count that includes rows it will not take.
     */
    public function test_retention_does_not_count_a_binned_donor(): void
    {
        $donor = $this->signup('retention');
        $old   = gmdate('Y-m-d H:i:s', time() - (3 * 365 * 86400));
        $donor->updateColumns(['created_at' => $old, 'last_donation_at' => null]);

        $retention = Plugin::instance()->container->get(DonorRetention::class);
        $before    = (int) $retention->preview(30, 1)['eligible_now'];

        $this->donors()->trash($donor);

        $this->assertSame(
            $before - 1,
            (int) $retention->preview(30, 1)['eligible_now'],
            'the nightly sweep must not reach into the bin'
        );
    }

    public function test_binning_and_restoring_are_both_on_the_record(): void
    {
        $donor = $this->signup('audited');

        $this->donors()->trash($donor);
        $this->donors()->restore($donor);

        $types = array_map(
            static fn ($e): string => (string) $e->type,
            Event::query()->where('donor_id', (int) $donor->id)->getAll()
        );

        $this->assertContains('donor.trashed', $types);
        $this->assertContains('donor.restored', $types);
    }

    /** A sweep or a CLI run has no user behind it, and 0 is not a user id. */
    public function test_a_bin_with_nobody_signed_in_records_no_user(): void
    {
        $donor = $this->signup('nobody');
        wp_set_current_user(0);

        $this->donors()->trash($donor);

        $this->assertNull(Donor::query()->find('id', (int) $donor->id)->trashed_by);
    }
}
