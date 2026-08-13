<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donations\Donation;
use Dono\Donors\Donor;
use Dono\Donors\DonorService;
use Dono\Exports\DonorExporter;
use Dono\Foundation\Plugin;

/**
 * The donor CSV carries decrypted PII, so what it contains has to be exactly
 * what was asked for and nothing else.
 *
 * Its date range is the org's calendar days while created_at is stored UTC, so
 * the window is converted and the stamps in the file are written in the same
 * timezone the window was cut in.
 */
final class DonorExportTest extends IntegrationTestCase
{
    private ?string $originalTz = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalTz = get_option('timezone_string');
    }

    protected function tearDown(): void
    {
        update_option('timezone_string', $this->originalTz);
        parent::tearDown();
    }

    private function exporter(): DonorExporter
    {
        return Plugin::instance()->container->get(DonorExporter::class);
    }

    private function donors(): DonorService
    {
        return Plugin::instance()->container->get(DonorService::class);
    }

    /**
     * A donor, meaning someone who gave: the export covers the population the
     * Donors screen counts, so a record with no real donation is not in it.
     * Pass $gave = false for the cases that are about exactly that.
     */
    private function makeDonor(string $email, array $profile = [], bool $gave = true): object
    {
        $donor = $this->donors()->findOrCreate($email, $profile + [
            'first_name' => 'Test',
            'last_name'  => 'Person',
        ]);

        if ($gave) {
            $this->gave((int) $donor->id, gmdate('Y-m-d H:i:s'));
        }

        return $donor;
    }

    private function gave(int $donorId, string $when): Donation
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

    public function test_someone_who_only_ever_test_donated_is_not_a_donor(): void
    {
        // A rehearsal writes a donor row like any other donation does. Nobody
        // gave, so the file the screen hands over must not say they did.
        $this->makeDonor('real@example.test');

        $onlyTest = $this->makeDonor('tester@example.test', [], false);
        $t = $this->gave((int) $onlyTest->id, gmdate('Y-m-d H:i:s'));
        \Dono\Donations\Donation::query()->where('id', (int) $t->id)->update(['is_test' => 1]);

        $csv = $this->exporter()->toCsv(['columns' => ['email']]);

        $this->assertStringContainsString('real@example.test', $csv);
        $this->assertStringNotContainsString('tester@example.test', $csv);
    }

    /**
     * Somebody who signed up in the portal and has not given yet handed over
     * their own address to do it. They are on the screen the export is started
     * from, so leaving them out would make the file disagree with the count
     * above it. See DonorVisibilityTest for the rule itself.
     */
    public function test_someone_who_signed_up_without_giving_is_exported(): void
    {
        $this->makeDonor('never-gave@example.test', [], false);

        $this->assertStringContainsString(
            'never-gave@example.test',
            $this->exporter()->toCsv(['columns' => ['email']])
        );
    }

    public function test_a_redacted_donor_is_left_out(): void
    {
        $donor = $this->makeDonor('erased@example.test');
        $this->donors()->redact($donor);

        $csv = $this->exporter()->toCsv(['columns' => ['email']]);

        $this->assertStringNotContainsString('erased@example.test', $csv);
    }

    public function test_the_dates_match_the_donor_record_not_the_donations(): void
    {
        // A long-standing supporter who gave inside the window is still outside
        // it: the range is about when the record was created.
        $old = $this->makeDonor('long-standing@example.test');
        \Dono\Donors\Donor::query()->where('id', (int) $old->id)
            ->update(['created_at' => '2024-02-01 09:00:00']);
        $this->gave((int) $old->id, '2026-06-15 09:00:00');

        $new = $this->makeDonor('brand-new@example.test');
        \Dono\Donors\Donor::query()->where('id', (int) $new->id)
            ->update(['created_at' => '2026-06-02 09:00:00']);

        $csv = $this->exporter()->toCsv([
            'columns' => ['email'],
            'from'    => '2026-06-01',
            'to'      => '2026-06-30',
        ]);

        $this->assertStringContainsString('brand-new@example.test', $csv);
        $this->assertStringNotContainsString('long-standing@example.test', $csv);
    }

    public function test_a_donor_who_signed_up_on_new_year_s_eve_is_in_that_year_s_file(): void
    {
        update_option('timezone_string', 'America/New_York');

        // 23:30 on 31 December 2026 in New York, stored as 04:30 on 1 January
        // 2027 UTC.
        $donor = $this->makeDonor('new-years-eve@example.test');
        Donor::query()->where('id', (int) $donor->id)
            ->update(['created_at' => '2027-01-01 04:30:00']);

        $csv = $this->exporter()->toCsv([
            'columns' => ['email', 'created_at'],
            'from'    => '2026-12-01',
            'to'      => '2026-12-31',
        ]);

        $this->assertStringContainsString('new-years-eve@example.test', $csv);
        $this->assertStringContainsString('2026-12-31 23:30:00', $csv, 'the stamp is the org day the window was cut in');
    }

    public function test_a_window_with_no_donors_yields_a_header_and_nothing_else(): void
    {
        $this->makeDonor('quiet@example.test');

        $rows = $this->parse($this->exporter()->toCsv([
            'columns' => ['email'],
            'from'    => '1999-01-01',
            'to'      => '1999-12-31',
        ]));

        $this->assertCount(1, $rows, 'the header survives so the file opens as a spreadsheet');
    }

    public function test_a_campaign_filter_narrows_to_that_campaign_s_donors(): void
    {
        $campaign = \Dono\Campaigns\Campaign::make();
        $campaign->title      = 'Winter appeal';
        $campaign->slug       = 'winter-appeal-' . uniqid();
        $campaign->status     = 'published';
        $campaign->currency   = 'USD';
        $campaign->created_at = gmdate('Y-m-d H:i:s');
        $campaign->updated_at = gmdate('Y-m-d H:i:s');
        $campaign->save();

        $backer = $this->makeDonor('backer@example.test');
        $gift   = $this->gave((int) $backer->id, gmdate('Y-m-d H:i:s'));
        \Dono\Donations\Donation::query()->where('id', (int) $gift->id)
            ->update(['campaign_id' => (int) $campaign->id]);

        $stranger = $this->makeDonor('stranger@example.test');
        $this->gave((int) $stranger->id, gmdate('Y-m-d H:i:s'));

        $csv = $this->exporter()->toCsv([
            'columns'     => ['email'],
            'campaign_id' => (int) $campaign->id,
        ]);

        $this->assertStringContainsString('backer@example.test', $csv);
        $this->assertStringNotContainsString('stranger@example.test', $csv);
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

    public function test_a_country_filter_narrows_the_file(): void
    {
        $this->makeDonor('in-ie@example.test', ['country' => 'IE']);
        $this->makeDonor('in-us@example.test', ['country' => 'US']);

        $csv = $this->exporter()->toCsv(['columns' => ['email'], 'country' => 'IE']);

        $this->assertStringContainsString('in-ie@example.test', $csv);
        $this->assertStringNotContainsString('in-us@example.test', $csv);
    }

    public function test_a_donor_type_filter_narrows_the_file(): void
    {
        $this->makeDonor('person@example.test', ['donor_type' => 'individual']);
        $this->makeDonor('charity@example.test', ['donor_type' => 'organization']);

        $csv = $this->exporter()->toCsv(['columns' => ['email'], 'donor_type' => 'organization']);

        $this->assertStringContainsString('charity@example.test', $csv);
        $this->assertStringNotContainsString('person@example.test', $csv);
    }

    public function test_a_search_narrows_the_file(): void
    {
        $this->makeDonor('wanted@example.test', ['first_name' => 'Wilhelmina']);
        $this->makeDonor('other@example.test', ['first_name' => 'Gregor']);

        $csv = $this->exporter()->toCsv(['columns' => ['email'], 'search' => 'Wilhelmina']);

        $this->assertStringContainsString('wanted@example.test', $csv);
        $this->assertStringNotContainsString('other@example.test', $csv);
    }

    /**
     * Two filters have to narrow each other. Widening on the second one hands
     * over rows the operator never asked for, which for PII is the whole point.
     */
    public function test_filters_combine_rather_than_widen(): void
    {
        $this->makeDonor('both@example.test',  ['first_name' => 'Ottoline', 'country' => 'IE']);
        $this->makeDonor('one-of@example.test', ['first_name' => 'Ottoline', 'country' => 'US']);

        $csv = $this->exporter()->toCsv([
            'columns' => ['email'],
            'search'  => 'Ottoline',
            'country' => 'IE',
        ]);

        $this->assertStringContainsString('both@example.test', $csv);
        $this->assertStringNotContainsString('one-of@example.test', $csv);
    }

    public function test_a_search_matching_nobody_exports_no_rows(): void
    {
        $this->makeDonor('somebody@example.test');

        $rows = $this->parse($this->exporter()->toCsv(['columns' => ['email'], 'search' => 'nobodyhasthisname']));

        $this->assertCount(1, $rows, 'header only');
    }
}
