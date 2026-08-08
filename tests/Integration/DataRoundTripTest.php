<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Campaigns\Campaign;
use Dono\Donations\Donation;
use Dono\Donors\Donor;
use Dono\Donors\DonorService;
use Dono\Foundation\Plugin;
use Dono\Foundation\Transfer\DataExporter;
use Dono\Foundation\Transfer\DataImporter;
use Dono\Vendor\Queryable\DB;

/**
 * Export then import, which is the only way to know either works.
 *
 * The interesting cases are not the happy path. They are: running it twice must
 * not duplicate, an address must survive being re-sealed with a different key,
 * and a donor erased here must not be brought back by a file that still
 * remembers them.
 */
final class DataRoundTripTest extends IntegrationTestCase
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

    /**
     * @param  array<string,mixed> $export
     * @return array{created:array<string,int>, existing:array<string,int>, skipped:array<string,int>}
     */
    private function import(array $export): array
    {
        // A fresh importer per run: the id map belongs to one import, and
        // reusing it would hide a failure to match on the natural key.
        return (new DataImporter(
            Plugin::instance()->container->get(\Dono\Foundation\Crypto\Crypto::class),
            Plugin::instance()->container->get(\Dono\Foundation\Identity\IdentityHasher::class),
        ))->import($export);
    }

    private function wipeDonors(): void
    {
        $prefix = DB::getPrefix();
        DB::raw("DELETE FROM {$prefix}dono_donations");
        DB::raw("DELETE FROM {$prefix}dono_donors");
    }

    private function seedDonor(string $email, string $first = 'Round', string $last = 'Trip'): Donor
    {
        return Plugin::instance()->container->get(DonorService::class)
            ->findOrCreate($email, ['first_name' => $first, 'last_name' => $last]);
    }

    public function test_a_donor_survives_the_round_trip_with_their_address(): void
    {
        $this->seedDonor('roundtrip@example.test', 'Ada', 'Lovelace');
        $export = $this->export();

        $this->wipeDonors();
        $this->assertSame(0, Donor::query()->count(), 'precondition: the donor is gone');

        $this->import($export);

        $restored = Donor::query()->where('first_name', 'Ada')->get();
        $this->assertNotNull($restored, 'the donor came back');

        $email = Plugin::instance()->container->get(DonorService::class)->decryptEmail($restored);
        $this->assertSame(
            'roundtrip@example.test',
            $email,
            'the address was re-sealed with this site key and reads back'
        );
    }

    /**
     * The property that makes a half-finished import safe to start again, and
     * an export safe to apply to a site that already has some of it.
     */
    public function test_importing_the_same_file_twice_creates_nothing_the_second_time(): void
    {
        $this->seedDonor('twice@example.test');
        $export = $this->export();

        $this->wipeDonors();

        $first  = $this->import($export);
        $before = Donor::query()->count();

        $second = $this->import($export);
        $after  = Donor::query()->count();

        $this->assertGreaterThan(0, $first['created']['dono_donors'] ?? 0, 'the first run creates');
        $this->assertSame(0, $second['created']['dono_donors'] ?? 0, 'the second creates nothing');
        $this->assertGreaterThan(0, $second['existing']['dono_donors'] ?? 0, 'and reports them as already here');
        $this->assertSame($before, $after, 'no duplicate rows');
    }

    /**
     * Someone exercised their right to be forgotten on this site. A file from
     * before that must not undo it.
     */
    public function test_an_erased_donor_is_not_resurrected(): void
    {
        $donor  = $this->seedDonor('erased@example.test', 'Gone', 'Away');
        $export = $this->export();

        Plugin::instance()->container->get(DonorService::class)->redact($donor);
        $this->assertNotNull(Donor::query()->where('id', (int) $donor->id)->get()->redacted_at);

        $this->import($export);

        $after = Donor::query()->where('id', (int) $donor->id)->get();
        $this->assertNotNull($after->redacted_at, 'still erased');
        $this->assertNull($after->first_name, 'and their name did not come back');
    }

    /** Ids are rewritten, so a donation must still point at its own donor. */
    public function test_a_donation_still_belongs_to_the_right_donor(): void
    {
        $donor = $this->seedDonor('owner@example.test', 'Owner', 'Person');

        $d = Donation::make();
        $d->donor_id     = (int) $donor->id;
        $d->reference    = 'RT-' . uniqid();
        $d->amount_cents = 4200;
        $d->currency     = 'USD';
        $d->status       = 'paid';
        $d->created_at   = gmdate('Y-m-d H:i:s');
        $d->updated_at   = $d->created_at;
        $d->save();
        $reference = $d->reference;

        $export = $this->export();
        $this->wipeDonors();
        $this->import($export);

        $donation = Donation::query()->where('reference', $reference)->get();
        $this->assertNotNull($donation, 'the donation came back');

        $owner = Donor::query()->where('id', (int) $donation->donor_id)->get();
        $this->assertNotNull($owner, 'its donor id points at a real donor');
        $this->assertSame('Owner', $owner->first_name, 'and it is the right one');
    }

    /** A campaign keeps its slug, which is what the import matches it by. */
    public function test_a_campaign_is_matched_by_slug_not_by_id(): void
    {
        $c = Campaign::make();
        $c->title      = 'Slug Match';
        $c->slug       = 'slug-match-' . uniqid();
        $c->status     = 'published';
        $c->currency   = 'USD';
        $c->created_at = gmdate('Y-m-d H:i:s');
        $c->updated_at = $c->created_at;
        $c->save();

        $export = $this->export();
        $result = $this->import($export);

        $this->assertGreaterThan(
            0,
            $result['existing']['dono_campaigns'] ?? 0,
            'the campaign is already here, matched on its slug'
        );
        $this->assertSame(
            1,
            Campaign::query()->where('slug', $c->slug)->count(),
            'and was not duplicated under a new id'
        );
    }
}
