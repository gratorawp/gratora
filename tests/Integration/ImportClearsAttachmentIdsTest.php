<?php

declare(strict_types=1);

namespace FundKit\Tests\Integration;

use FundKit\Donors\Donor;
use FundKit\Donors\DonorService;
use FundKit\Foundation\Plugin;
use FundKit\Foundation\Transfer\DataExporter;
use FundKit\Foundation\Transfer\DataImporter;
use FundKit\Vendor\Queryable\DB;

/**
 * An export carries no media, so a WordPress attachment id in it means nothing
 * on the target: it lands on whatever post happens to hold that id there.
 *
 * That is the sharpest case of the rule DataImporter::FOREIGN already states,
 * "a number pointing at the wrong record is worse than no number", and the
 * attachment columns were not on the list. A donor would arrive wearing the
 * charity's logo as their avatar on every supporter wall. Worse, redact() and
 * delete() read the column and run wp_delete_attachment($id, true) after the
 * commit, a force delete with no trash: exercising one donor's erasure right
 * would permanently destroy an unrelated file and every page using it.
 */
final class ImportClearsAttachmentIdsTest extends IntegrationTestCase
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
    private function import(array $export): void
    {
        (new DataImporter(
            Plugin::instance()->container->get(\FundKit\Foundation\Crypto\Crypto::class),
            Plugin::instance()->container->get(\FundKit\Foundation\Identity\IdentityHasher::class),
        ))->import($export);
    }

    public function test_an_imported_donor_does_not_claim_a_media_file_on_this_site(): void
    {
        $email = 'avatar-' . uniqid() . '@example.test';

        $donor = Plugin::instance()->container->get(DonorService::class)
            ->findOrCreate($email, ['first_name' => 'Jane', 'last_name' => 'Doe']);

        // The source site's attachment id. On the target this number is
        // somebody else's file, which is the whole point.
        Donor::query()->where('id', (int) $donor->id)->update(['avatar_attachment_id' => 137]);

        $export = $this->export();

        // Wipe the row so the import creates it rather than matching it.
        $prefix = DB::getPrefix();
        DB::raw("DELETE FROM {$prefix}fundkit_donations");
        DB::raw("DELETE FROM {$prefix}fundkit_donors");

        $this->import($export);

        $restored = Donor::query()->where('email_hash', (string) $donor->email_hash)->get();
        $this->assertNotNull($restored, 'fixture: the donor came back');

        $this->assertNull(
            $restored->avatar_attachment_id,
            'an id from another site must not survive: redact() force-deletes whatever it points at here'
        );
    }

    public function test_every_attachment_column_is_cleared_on_import(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2) . '/src/Foundation/Transfer/DataImporter.php'
        );

        foreach (['avatar_attachment_id', 'image_attachment_id', 'logo_attachment_id'] as $column) {
            $this->assertStringContainsString(
                "'{$column}',",
                $source,
                "{$column} points at media the export does not carry"
            );
        }
    }
}
