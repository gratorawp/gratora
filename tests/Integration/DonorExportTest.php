<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donations\Donation;
use Dono\Donors\DonorService;
use Dono\Exports\DonorExporter;
use Dono\Foundation\Plugin;

/**
 * The donor CSV carries decrypted PII, so what it contains has to be exactly
 * what was asked for and nothing else.
 */
final class DonorExportTest extends IntegrationTestCase
{
    private function exporter(): DonorExporter
    {
        return Plugin::instance()->container->get(DonorExporter::class);
    }

    private function donors(): DonorService
    {
        return Plugin::instance()->container->get(DonorService::class);
    }

    private function makeDonor(string $email, array $profile = []): object
    {
        return $this->donors()->findOrCreate($email, $profile + [
            'first_name' => 'Test',
            'last_name'  => 'Person',
        ]);
    }

    private function gave(int $donorId, string $when, int $formId = 0): Donation
    {
        $d = Donation::make();
        $d->reference    = 'REF-' . uniqid();
        $d->status       = 'paid';
        $d->gateway      = 'offline';
        $d->kind         = 'donation';
        $d->donor_id     = $donorId;
        $d->amount_cents = 5000;
        $d->currency     = 'USD';
        $d->base_amount_cents = 5000;
        $d->is_test      = false;
        if ($formId > 0) {
            $d->form_id = $formId;
        }
        $d->created_at = $when;
        $d->paid_at    = $when;
        $d->save();

        return $d;
    }

    /** @return list<array<int,string>> parsed rows, header included */
    private function parse(string $csv): array
    {
        $csv  = preg_replace('/^\xEF\xBB\xBF/', '', $csv) ?? $csv;
        $fh   = fopen('php://temp', 'r+');
        fwrite($fh, $csv);
        rewind($fh);

        $rows = [];
        while (($row = fgetcsv($fh)) !== false) {
            if ($row !== [null]) {
                $rows[] = $row;
            }
        }
        fclose($fh);

        return $rows;
    }

    public function test_only_the_requested_columns_are_written(): void
    {
        $this->makeDonor('columns@example.test');

        $rows = $this->parse($this->exporter()->toCsv(['columns' => ['email', 'donations_count']]));

        $this->assertSame(['Email', 'Donations'], $rows[0]);
        $this->assertCount(2, $rows[1], 'a row carries exactly the requested columns');
    }

    public function test_column_order_follows_the_schema_not_the_request(): void
    {
        $this->makeDonor('order@example.test');

        $rows = $this->parse($this->exporter()->toCsv([
            'columns' => ['donor_id', 'email', 'first_name'],
        ]));

        $this->assertSame(['First name', 'Email', 'Donor ID'], $rows[0]);
    }

    public function test_a_request_naming_no_valid_column_still_writes_a_usable_file(): void
    {
        $this->makeDonor('fallback@example.test');

        $rows = $this->parse($this->exporter()->toCsv(['columns' => ['nonsense', 'ssn']]));

        // A blank-column file reads as a broken export rather than an empty one.
        $this->assertNotEmpty($rows[0]);
        $this->assertContains('Email', $rows[0]);
    }

    public function test_a_redacted_donor_is_left_out(): void
    {
        $donor = $this->makeDonor('erased@example.test');
        $this->donors()->redact($donor);

        $csv = $this->exporter()->toCsv(['columns' => ['email']]);

        $this->assertStringNotContainsString('erased@example.test', $csv);
    }

    public function test_the_two_date_bases_ask_different_questions(): void
    {
        $old = $this->makeDonor('long-standing@example.test');
        \Dono\Donors\Donor::query()->where('id', (int) $old->id)
            ->update(['created_at' => '2024-02-01 09:00:00']);
        $this->gave((int) $old->id, '2026-06-15 09:00:00');

        $new = $this->makeDonor('brand-new@example.test');
        \Dono\Donors\Donor::query()->where('id', (int) $new->id)
            ->update(['created_at' => '2026-06-02 09:00:00']);

        $gave = $this->exporter()->toCsv([
            'columns' => ['email'],
            'basis'   => 'donation',
            'from'    => '2026-06-01',
            'to'      => '2026-06-30',
        ]);
        $created = $this->exporter()->toCsv([
            'columns' => ['email'],
            'basis'   => 'created',
            'from'    => '2026-06-01',
            'to'      => '2026-06-30',
        ]);

        $this->assertStringContainsString('long-standing@example.test', $gave);
        $this->assertStringNotContainsString('brand-new@example.test', $gave, 'a donor who gave nothing did not give in June');

        $this->assertStringContainsString('brand-new@example.test', $created);
        $this->assertStringNotContainsString('long-standing@example.test', $created, 'a 2024 record was not created in June 2026');
    }

    public function test_a_window_with_no_donations_yields_a_header_and_nothing_else(): void
    {
        $donor = $this->makeDonor('quiet@example.test');
        $this->gave((int) $donor->id, '2026-06-15 09:00:00');

        $rows = $this->parse($this->exporter()->toCsv([
            'columns' => ['email'],
            'basis'   => 'donation',
            'from'    => '1999-01-01',
            'to'      => '1999-12-31',
        ]));

        $this->assertCount(1, $rows, 'the header survives so the file opens as a spreadsheet');
    }

    public function test_a_name_that_looks_like_a_formula_is_neutralised(): void
    {
        // Spreadsheets execute a leading '=' on open, so a donor could put a
        // command in their own name and have staff run it.
        $this->makeDonor('formula@example.test', [
            'first_name' => '=HYPERLINK("http://evil.test")',
            'last_name'  => 'Payload',
        ]);

        $csv = $this->exporter()->toCsv(['columns' => ['first_name']]);

        $this->assertStringContainsString('\'=HYPERLINK', $csv);
    }

    public function test_totals_are_written_as_a_bare_number(): void
    {
        $donor = $this->makeDonor('total@example.test');
        \Dono\Donors\Donor::query()->where('id', (int) $donor->id)
            ->update(['total_donated_cents' => 123456]);

        $rows = $this->parse($this->exporter()->toCsv(['columns' => ['total_donated']]));

        // A currency symbol or a thousands separator makes the column text in
        // every spreadsheet, and the recipient cannot sum it.
        $this->assertSame('1234.56', $rows[1][0]);
    }
}
