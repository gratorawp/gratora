<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donors\DonorRetention;
use WP_REST_Request;

/**
 * Importing legacy history pushes the first retention sweep out.
 *
 * This is the pairing that costs an org everything it just migrated: erasure is
 * switched on, ten years of donations arrive with their real dates, and the
 * sweep that night reads every one of them as a donor who has not given in a
 * decade. The deferral is the only thing standing between the two, so it is
 * asserted against the importer's actual return rather than a key someone
 * expected it to have.
 */
final class ImportDefersErasureTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        delete_option(DonorRetention::STARTS_AT_OPTION);
    }

    private function csv(): string
    {
        return "Email,Amount,Date,First,Last\n"
            . "ancient@example.test,25.00,2016-02-03,Ancient,Donor\n"
            . "older@example.test,40.00,2015-11-20,Older,Donor\n";
    }

    /** @param array<string,string> $mapping */
    private function importCsv(bool $dryRun, ?array $mapping = null): array
    {
        $req = new WP_REST_Request('POST', '/dono/v1/admin/tools/csv-import');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode([
            'csv'     => $this->csv(),
            'dry_run' => $dryRun,
            'mapping' => $mapping ?? [
                'email'      => 'Email',
                'amount'     => 'Amount',
                'date'       => 'Date',
                'first_name' => 'First',
                'last_name'  => 'Last',
            ],
        ]));

        $res = rest_do_request($req);
        $this->assertSame(200, $res->get_status(), (string) wp_json_encode($res->get_data()));

        return (array) $res->get_data();
    }

    public function test_a_real_import_holds_the_first_sweep_back(): void
    {
        $data = $this->importCsv(false);

        $this->assertGreaterThan(
            0,
            (int) ($data['donations_imported'] ?? 0),
            'nothing was imported, so this test proves nothing about deferral'
        );
        $this->assertGreaterThan(
            time(),
            DonorRetention::startsAt(),
            'the sweep would run tonight, on history imported today'
        );
    }

    public function test_a_dry_run_changes_nothing(): void
    {
        $this->importCsv(true);

        // A preview that moved the schedule would be a preview with a side
        // effect, which is the one thing it promises not to have.
        $this->assertSame(0, (int) get_option(DonorRetention::STARTS_AT_OPTION, 0));
    }

    public function test_an_import_that_lands_nothing_does_not_move_the_schedule(): void
    {
        // Mapped to columns the file does not have, so every row is skipped.
        $data = $this->importCsv(false, ['email' => 'Nope', 'amount' => 'Missing']);

        $this->assertSame(0, (int) ($data['donations_imported'] ?? 0));
        $this->assertSame(
            0,
            (int) get_option(DonorRetention::STARTS_AT_OPTION, 0),
            'nothing arrived, so there is no history to protect'
        );
    }
}
