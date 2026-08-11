<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donations\Donation;
use Dono\Donations\DonationNote;
use Dono\Donors\Donor;
use Dono\Donors\DonorNote;
use Dono\Donors\DonorNoteRepository;
use Dono\Donors\DonorService;
use Dono\Foundation\Crypto\Crypto;
use Dono\Foundation\Identity\IdentityHasher;
use Dono\Foundation\Plugin;
use Dono\Foundation\Transfer\DataExporter;
use Dono\Foundation\Transfer\DataImporter;
use Dono\Vendor\Queryable\DB;

/**
 * The sealed columns that are not a donor's.
 *
 * A staff note leaves the site as body and a donation's custom field answers
 * as custom_data, because the key that sealed them stays behind. No table has
 * a column by either name, so unless the importer seals them again under the
 * name the schema knows, the whole restore dies on the first note.
 */
final class DataRoundTripEncryptedTest extends IntegrationTestCase
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
        return (new DataImporter(
            Plugin::instance()->container->get(Crypto::class),
            Plugin::instance()->container->get(IdentityHasher::class),
        ))->import($export);
    }

    private function wipe(): void
    {
        $prefix = DB::getPrefix();
        foreach (['dono_donation_notes', 'dono_donor_notes', 'dono_donations', 'dono_donors'] as $table) {
            DB::raw("DELETE FROM {$prefix}{$table}");
        }
    }

    private function seedDonor(string $email, string $first, string $last): Donor
    {
        return Plugin::instance()->container->get(DonorService::class)
            ->findOrCreate($email, ['first_name' => $first, 'last_name' => $last]);
    }

    private function seedDonation(int $donorId, string $reference, ?string $customData): Donation
    {
        $now = gmdate('Y-m-d H:i:s');

        $d = Donation::make();
        $d->donor_id     = $donorId;
        $d->reference    = $reference;
        $d->amount_cents = 4200;
        $d->net_cents    = 4200;
        $d->currency     = 'USD';
        $d->gateway      = 'manual';
        $d->status       = 'paid';
        if ($customData !== null) {
            $d->custom_data_encrypted = Plugin::instance()->container->get(Crypto::class)->encrypt($customData);
        }
        $d->paid_at    = $now;
        $d->created_at = $now;
        $d->updated_at = $now;
        $d->save();

        return $d;
    }

    public function test_a_staff_note_on_a_donor_survives_the_round_trip(): void
    {
        $donor = $this->seedDonor('noted@example.test', 'Noted', 'Donor');
        $notes = Plugin::instance()->container->get(DonorNoteRepository::class);
        $notes->create((int) $donor->id, 'Prefers a call before the annual ask.', null);

        $export = $this->export();
        $this->wipe();

        $this->import($export);

        $restored = Donor::query()->where('first_name', 'Noted')->get();
        $this->assertNotNull($restored, 'precondition: the donor came back');

        $restoredNotes = $notes->listForDonor((int) $restored->id);
        $this->assertCount(1, $restoredNotes, 'the note came back with them');
        $this->assertSame(
            'Prefers a call before the annual ask.',
            $restoredNotes[0]['body'],
            'and reads back under this site key'
        );
    }

    public function test_a_note_on_a_donation_survives_the_round_trip(): void
    {
        $donor     = $this->seedDonor('donationnote@example.test', 'Donation', 'Noted');
        $reference = 'RT-DN-' . uniqid();
        $donation  = $this->seedDonation((int) $donor->id, $reference, null);
        $crypto    = Plugin::instance()->container->get(Crypto::class);

        $now = gmdate('Y-m-d H:i:s');
        $n = DonationNote::make();
        $n->donation_id    = (int) $donation->id;
        $n->body_encrypted = $crypto->encrypt('Payer called to confirm the address.');
        $n->created_at     = $now;
        $n->updated_at     = $now;
        $n->save();

        $export = $this->export();
        $this->wipe();

        $this->import($export);

        $restored = Donation::query()->where('reference', $reference)->get();
        $this->assertNotNull($restored, 'precondition: the donation came back');

        $note = DonationNote::query()->where('donation_id', (int) $restored->id)->get();
        $this->assertNotNull($note, 'the note came back with it');
        $this->assertSame(
            'Payer called to confirm the address.',
            $crypto->decrypt((string) $note->body_encrypted),
            'and reads back under this site key'
        );
    }

    public function test_custom_field_answers_on_a_donation_survive_the_round_trip(): void
    {
        $donor     = $this->seedDonor('customdata@example.test', 'Custom', 'Answers');
        $payload   = (string) wp_json_encode(['tribute_name' => 'Ada Lovelace']);
        $reference = 'RT-CD-' . uniqid();
        $this->seedDonation((int) $donor->id, $reference, $payload);

        $export = $this->export();
        $this->wipe();

        $this->import($export);

        $restored = Donation::query()->where('reference', $reference)->get();
        $this->assertNotNull($restored, 'precondition: the donation came back');
        $this->assertNotNull($restored->custom_data_encrypted, 'the answers came back sealed');
        $this->assertSame(
            $payload,
            Plugin::instance()->container->get(Crypto::class)->decrypt((string) $restored->custom_data_encrypted),
            'and read back under this site key'
        );
    }

    /**
     * body_encrypted is NOT NULL. A note whose body did not survive the export
     * (the key that sealed it was lost) still has to land, or one unreadable
     * row stops the restore for everything behind it.
     */
    public function test_a_note_whose_body_did_not_survive_the_export_still_lands(): void
    {
        $this->wipe();

        $now    = gmdate('Y-m-d H:i:s');
        $export = [
            'tables' => [
                'dono_donors' => [[
                    'id'         => 1,
                    'email'      => 'bodyless@example.test',
                    'first_name' => 'Bodyless',
                    'last_name'  => 'Note',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]],
                'dono_donor_notes' => [[
                    'id'             => 7,
                    'donor_id'       => 1,
                    'author_user_id' => 3,
                    'created_at'     => $now,
                    'updated_at'     => $now,
                ]],
            ],
        ];

        $result = $this->import($export);

        $this->assertSame(1, $result['created']['dono_donor_notes'] ?? 0, 'the note was inserted');

        $donor = Donor::query()->where('first_name', 'Bodyless')->get();
        $this->assertNotNull($donor);

        $note = DonorNote::query()->where('donor_id', (int) $donor->id)->get();
        $this->assertNotNull($note, 'and belongs to the donor it named');
        // Null and empty both mean "this note has no body"; which one the
        // column holds is not what the round trip is about.
        $this->assertSame(
            '',
            (string) Plugin::instance()->container->get(Crypto::class)->decrypt((string) $note->body_encrypted),
            'with an empty body rather than none'
        );
    }
}
