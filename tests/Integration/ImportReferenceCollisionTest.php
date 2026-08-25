<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donations\Donation;
use Dono\Donors\DonorService;
use Dono\Foundation\Plugin;
use Dono\Foundation\Transfer\DataExporter;
use Dono\Foundation\Transfer\DataImporter;
use Dono\Vendor\Queryable\DB;

/**
 * A reference identifies a donation within ONE site. The counter behind it
 * starts at one on every install and the default prefix is the same
 * everywhere, so DONO-2026-00007 exists on most of them and belongs to a
 * different person on each.
 *
 * DataImporter::hashOfDonorBehind() already refuses to trust a reference on its
 * own and says so at length. findExisting() matched on it alone, so any target
 * that had taken donations of its own, an org that set Dono up on a new host
 * and took a few before restoring their history, two chapters merging, a
 * production site topped up from staging, counted the file's donation as
 * already present and discarded it, then mapped its children onto a stranger's
 * row.
 */
final class ImportReferenceCollisionTest extends IntegrationTestCase
{
    /** @return array<string,mixed> */
    private function export(): array
    {
        $out = fopen('php://temp', 'r+');
        Plugin::instance()->container->get(DataExporter::class)->writeJson($out);
        rewind($out);
        $decoded = json_decode((string) stream_get_contents($out), true);
        fclose($out);

        $this->assertIsArray($decoded);

        return $decoded;
    }

    /** @param array<string,mixed> $export */
    private function import(array $export): array
    {
        return (new DataImporter(
            Plugin::instance()->container->get(\Dono\Foundation\Crypto\Crypto::class),
            Plugin::instance()->container->get(\Dono\Foundation\Identity\IdentityHasher::class),
        ))->import($export);
    }

    private function seedDonation(string $email, string $reference, int $cents): Donation
    {
        $donor = Plugin::instance()->container->get(DonorService::class)
            ->findOrCreate($email, ['first_name' => 'Seed']);

        $now = gmdate('Y-m-d H:i:s');

        $d = Donation::make();
        $d->reference    = $reference;
        $d->donor_id     = (int) $donor->id;
        $d->amount_cents = $cents;
        $d->currency     = 'USD';
        $d->status       = 'paid';
        $d->gateway      = 'offline';
        $d->kind         = 'donation';
        $d->paid_at      = $now;
        $d->created_at   = $now;
        $d->updated_at   = $now;
        $d->save();

        return $d;
    }

    public function test_two_unrelated_donations_sharing_a_reference_are_not_merged(): void
    {
        $reference = 'DONO-2026-09' . random_int(100, 999);

        // The source site: Jane gave 500.
        $this->seedDonation('jane-' . uniqid() . '@example.test', $reference, 50000);
        $export = $this->export();

        // The target site: the same number belongs to Bob, who gave 25.
        $prefix = DB::getPrefix();
        DB::raw("DELETE FROM {$prefix}dono_donations");
        DB::raw("DELETE FROM {$prefix}dono_donors");
        $bob = $this->seedDonation('bob-' . uniqid() . '@example.test', $reference, 2500);

        $result = $this->import($export);

        // Bob is untouched, and Jane's donation is not silently folded into him.
        $here = Donation::query()->where('reference', $reference)->getAll();
        $this->assertCount(1, $here, 'the unique reference still holds one row');
        $this->assertSame(2500, (int) $here[0]->amount_cents, "Bob's donation is not overwritten");
        $this->assertSame((int) $bob->id, (int) $here[0]->id);

        $this->assertSame(
            0,
            (int) ($result['existing']['dono_donations'] ?? 0),
            'a different donation with the same number is not "already there"'
        );
        $this->assertSame(
            1,
            (int) ($result['dropped']['dono_donations']['reference_collision'] ?? 0),
            'it is reported to the operator instead of vanishing into the existing count'
        );
    }

    /** The same donation really being present still counts as present. */
    public function test_a_genuine_re_run_still_recognises_its_own_rows(): void
    {
        $this->seedDonation('rerun-' . uniqid() . '@example.test', 'DONO-2026-08' . random_int(100, 999), 1500);

        $export = $this->export();
        $result = $this->import($export);

        $this->assertGreaterThan(
            0,
            (int) ($result['existing']['dono_donations'] ?? 0),
            'importing a file onto the site it came from finds its own donations'
        );
        $this->assertSame(
            0,
            (int) ($result['dropped']['dono_donations']['reference_collision'] ?? 0),
            'and nothing is mistaken for a collision'
        );
    }
}
