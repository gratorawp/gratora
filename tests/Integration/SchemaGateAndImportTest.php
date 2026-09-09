<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Donations\Donation;
use Gratora\Donors\MagicLinkToken;
use Gratora\Foundation\Plugin;
use Gratora\Foundation\Transfer\CsvImporter;
use Gratora\Foundation\Upgrade\MigrationLock;
use Gratora\Foundation\Upgrade\SchemaGuard;
use Gratora\Funds\Fund;
use Gratora\Funds\FundRepository;

/**
 * Three things that decide whether a site's data is what it says it is: the
 * gate that migrates, the stamp that says it finished, and the importer that
 * reads a file back.
 */
final class SchemaGateAndImportTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        remove_filter('query', [$this, '_create_temporary_tables']);
        remove_filter('query', [$this, '_drop_temporary_tables']);
        MigrationLock::release();
    }

    protected function tearDown(): void
    {
        MigrationLock::release();
        parent::tearDown();
    }

    private function tokenTable(): string
    {
        return self::$wpdb->prefix . 'gratora_magic_link_tokens';
    }

    /**
     * dbDelta reports success for an ALTER it never ran, so a stamp written on
     * table existence alone disarms the gate for good and the column never
     * arrives.
     */
    public function test_a_column_the_migration_did_not_add_holds_the_stamp_back(): void
    {
        self::$wpdb->query('ALTER TABLE ' . $this->tokenTable() . ' DROP COLUMN first_name');

        try {
            $this->assertContains(
                'first_name',
                SchemaGuard::missingColumns()['gratora_magic_link_tokens'] ?? [],
                'the guard notices a column its own schema declares'
            );
            $this->assertFalse(SchemaGuard::stampWhenComplete());
        } finally {
            Plugin::migrateSchema();
        }

        $this->assertSame([], SchemaGuard::missingColumns());
        $this->assertTrue(SchemaGuard::stampWhenComplete());
    }

    public function test_only_one_request_holds_the_migration_lock(): void
    {
        $this->assertTrue(MigrationLock::claim(), 'the first request takes it');
        $this->assertFalse(MigrationLock::claim(), 'a second one arriving mid-pass does not');

        MigrationLock::release();

        $this->assertTrue(MigrationLock::claim(), 'and it is free again once the pass is done');
    }

    public function test_a_lock_left_behind_by_a_dead_request_does_not_wedge_the_site(): void
    {
        update_option(MigrationLock::OPTION, (string) (time() - 3600), false);

        $this->assertTrue(MigrationLock::claim(), 'a stale lock is taken over');
    }

    /**
     * sort_order is 0 on every row until someone reorders, so without a unique
     * tiebreaker MySQL may put one fund on two pages and another on none.
     */
    public function test_the_funds_list_pages_without_repeating_or_losing_a_row(): void
    {
        $now = gmdate('Y-m-d H:i:s');
        for ($i = 0; $i < 9; $i++) {
            $f = Fund::make();
            $f->code       = 'page-' . $i . '-' . uniqid();
            $f->name       = 'Fund ' . $i;
            $f->is_active  = true;
            $f->sort_order = 0;
            $f->created_at = $now;
            $f->updated_at = $now;
            $f->save();
        }

        $repo = Plugin::instance()->container->get(FundRepository::class);

        $seen = [];
        for ($page = 1; $page <= 4; $page++) {
            foreach ($repo->listAdmin(['page' => $page, 'per_page' => 3, 'order' => 'desc'])['items'] as $fund) {
                $seen[] = (int) $fund->id;
            }
        }

        $this->assertSame(
            count($seen),
            count(array_unique($seen)),
            'a fund appears on one page, not two'
        );

        // With every sort_order equal, the tiebreaker is the only thing
        // deciding the sequence, so descending is what descending means.
        $descending = $seen;
        rsort($descending);
        $this->assertSame($descending, $seen, 'the pages are one ordered list, not four guesses');
    }

    /**
     * With every sort_order equal, insertion order is not an order anyone
     * chose. The donor-facing picker breaks that tie by name, so the admin
     * table has to read the same way or the two lists disagree.
     */
    public function test_the_funds_list_reads_the_way_the_donor_picker_does(): void
    {
        $now = gmdate('Y-m-d H:i:s');
        foreach (['Zebra fund', 'Apple fund', 'Mango fund'] as $name) {
            $f = Fund::make();
            $f->code       = 'tie-' . uniqid();
            $f->name       = $name;
            $f->is_active  = true;
            $f->sort_order = 0;
            $f->created_at = $now;
            $f->updated_at = $now;
            $f->save();
        }

        $repo = Plugin::instance()->container->get(FundRepository::class);

        $names = array_map(
            static fn ($fund): string => (string) $fund->name,
            $repo->listAdmin(['per_page' => 100])['items']
        );

        $alphabetical = $names;
        sort($alphabetical);
        $this->assertSame($alphabetical, $names);
    }

    /** @param array<string,string> $row @return array<string,mixed> */
    private function importRow(array $row): array
    {
        $csv = "email,amount,date,status\n"
            . sprintf(
                "%s,%s,%s,%s\n",
                $row['email'],
                $row['amount'] ?? '25.00',
                $row['date'] ?? '2026-01-15',
                $row['status']
            );

        return Plugin::instance()->container->get(CsvImporter::class)->import(
            $csv,
            ['email' => 'email', 'amount' => 'amount', 'date' => 'date', 'status' => 'status'],
            false
        );
    }

    public function test_a_status_this_site_does_not_know_is_refused_not_called_paid(): void
    {
        $email = 'unknown-status-' . uniqid() . '@example.test';

        $result = $this->importRow(['email' => $email, 'status' => 'held_for_review']);

        $this->assertSame(0, $result['donations_imported']);
        $this->assertSame(1, $result['skipped']['unknown_status'] ?? 0);
    }

    public function test_a_status_this_site_exports_imports_back_as_itself(): void
    {
        foreach (['disputed', 'processing', 'partial_refund', 'refunded'] as $status) {
            $email = $status . '-' . uniqid() . '@example.test';

            $result = $this->importRow(['email' => $email, 'status' => $status]);

            $this->assertSame(1, $result['donations_imported'], $status . ' imports');

            $row = Donation::query()->where('gateway', 'imported')->orderBy('id', 'desc')->get();
            $this->assertSame($status, $row['status'], $status . ' arrives as itself');
        }
    }

    public function test_another_systems_word_for_paid_is_understood(): void
    {
        $result = $this->importRow([
            'email'  => 'synonym-' . uniqid() . '@example.test',
            'status' => 'Succeeded',
        ]);

        $this->assertSame(1, $result['donations_imported'], (string) wp_json_encode($result));
        $this->assertSame('paid', Donation::query()->where('gateway', 'imported')->orderBy('id', 'desc')->get()['status']);
    }

    public function test_a_donor_history_import_with_no_status_column_still_works(): void
    {
        $result = $this->importRow(['email' => 'blank-' . uniqid() . '@example.test', 'status' => '']);

        $this->assertSame(1, $result['donations_imported']);
        $this->assertSame('paid', Donation::query()->where('gateway', 'imported')->orderBy('id', 'desc')->get()['status']);
    }

    public function test_an_imported_row_whose_money_moved_carries_its_date(): void
    {
        $this->importRow([
            'email'  => 'dated-' . uniqid() . '@example.test',
            'status' => 'disputed',
            'date'   => '2026-01-15',
        ]);

        $row = Donation::query()->where('gateway', 'imported')->orderBy('id', 'desc')->get();
        $this->assertNotNull($row['paid_at']);
    }
}
