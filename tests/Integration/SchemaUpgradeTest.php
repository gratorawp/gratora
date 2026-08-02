<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

/**
 * What an update does to a site that already has data.
 *
 * Each test installs the current schema, degrades it to look like an older
 * release, seeds rows, then runs the real update path and asks two questions:
 * did the shape come back, and did the data survive.
 *
 * The matrix here is the honest one. dbDelta adds and widens; it never drops,
 * and it does not change nullability. Tests that assert the misses are as
 * valuable as the ones that assert the hits: they are what tells the next
 * person that a drop needs a hand-written routine rather than a schema edit.
 */
final class SchemaUpgradeTest extends UpgradeTestCase
{
    private const TABLE = 'dono_donations';

    private function seedDonation(string $reference): void
    {
        global $wpdb;

        $now = gmdate('Y-m-d H:i:s');
        $wpdb->insert($this->scratch(self::TABLE), [
            'reference'    => $reference,
            'donor_id'     => 1,
            'amount_cents' => 2500,
            'net_cents'    => 2500,
            'currency'     => 'USD',
            'gateway'      => 'offline',
            'status'       => 'paid',
            'is_test'      => 0,
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);
    }

    private function assertDonationSurvived(string $reference): void
    {
        global $wpdb;

        $found = $wpdb->get_var($wpdb->prepare(
            'SELECT reference FROM `' . $this->scratch(self::TABLE) . '` WHERE reference = %s',
            $reference
        ));

        $this->assertSame($reference, $found, 'the donation is still there after the update');
    }

    public function test_a_missing_column_is_added_and_the_rows_survive(): void
    {
        $this->installCurrentSchema();
        $this->seedDonation('DONO-UP-COL');

        // An older release did not have this column.
        $this->alterScratch('ALTER TABLE `' . $this->scratch(self::TABLE) . '` DROP COLUMN `base_amount_cents`');
        $this->assertNotContains('base_amount_cents', $this->columns(self::TABLE));

        $this->runTheRealUpdate();

        $this->assertContains('base_amount_cents', $this->columns(self::TABLE), 'the column is added on update');
        $this->assertDonationSurvived('DONO-UP-COL');
    }

    public function test_a_missing_index_is_added(): void
    {
        $this->installCurrentSchema();

        $this->alterScratch('ALTER TABLE `' . $this->scratch(self::TABLE) . '` DROP INDEX `idx_is_test_created_at`');
        $this->assertNotContains('idx_is_test_created_at', $this->indexes(self::TABLE));

        $this->runTheRealUpdate();

        $this->assertContains(
            'idx_is_test_created_at',
            $this->indexes(self::TABLE),
            'indexes do reach existing installs, contrary to what was assumed'
        );
    }

    public function test_a_narrower_column_is_widened_without_truncating(): void
    {
        $this->installCurrentSchema();

        $this->alterScratch('ALTER TABLE `' . $this->scratch(self::TABLE) . '` MODIFY `gateway` VARCHAR(8) NOT NULL');
        $this->seedDonation('DONO-UP-WIDE');

        $this->runTheRealUpdate();

        $this->assertStringContainsString('32', $this->columnType(self::TABLE, 'gateway'), 'widened to the declared size');
        $this->assertDonationSurvived('DONO-UP-WIDE');
    }

    public function test_a_dropped_table_is_rebuilt(): void
    {
        $this->installCurrentSchema();

        $this->alterScratch('DROP TABLE `' . $this->scratch(self::TABLE) . '`');

        $this->runTheRealUpdate();

        $this->assertContains('reference', $this->columns(self::TABLE), 'a table added in a later release is created');
    }

    /**
     * The harness has to be able to fail, or every assertion above is
     * decoration. Degrades the table and does NOT run the update.
     */
    public function test_the_harness_sees_a_broken_schema_when_no_update_runs(): void
    {
        $this->installCurrentSchema();

        $this->alterScratch('ALTER TABLE `' . $this->scratch(self::TABLE) . '` DROP COLUMN `base_amount_cents`');

        $this->assertNotContains(
            'base_amount_cents',
            $this->columns(self::TABLE),
            'without an update the column stays missing, so the passing tests above mean something'
        );
    }

    /**
     * The misses. These assert current behaviour so that the day someone needs
     * one of them, the test says where the work goes rather than the change
     * silently doing nothing on every existing site.
     */
    public function test_a_column_the_schema_no_longer_declares_is_left_alone(): void
    {
        $this->installCurrentSchema();

        $this->alterScratch('ALTER TABLE `' . $this->scratch(self::TABLE) . '` ADD COLUMN `zz_legacy` VARCHAR(10) NULL');

        $this->runTheRealUpdate();

        $this->assertContains(
            'zz_legacy',
            $this->columns(self::TABLE),
            'dbDelta never drops: removing a column from the schema needs a hand-written routine'
        );
    }

    public function test_nullability_is_not_changed_on_an_existing_column(): void
    {
        $this->installCurrentSchema();

        // paid_at is nullable in the schema; an older install had it NOT NULL.
        $this->alterScratch(
            'ALTER TABLE `' . $this->scratch(self::TABLE) . '` MODIFY `paid_at` DATETIME NOT NULL DEFAULT \'2026-01-01 00:00:00\''
        );

        $this->runTheRealUpdate();

        $this->assertStringContainsString(
            'NOT NULL',
            $this->columnType(self::TABLE, 'paid_at'),
            'dbDelta leaves nullability alone: a change here needs a hand-written routine'
        );
    }
}
