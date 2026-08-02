<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Foundation\Plugin;

/**
 * Base for tests that exercise an upgrade rather than a fresh install.
 *
 * Every other integration test builds the current schema from scratch, which is
 * why nothing here was ever visible: the question these ask is not "is the
 * schema right" but "does a site that already has data and an older shape end
 * up right after an update". Those are different questions and only the second
 * one is what a released plugin does.
 *
 * Two things make this awkward and both are handled here.
 *
 * DDL implicitly commits in MySQL, so a CREATE or ALTER inside WP_UnitTestCase's
 * per-test transaction ends it and the rollback discards nothing. These tests
 * therefore run against their own table prefix and clean up by hand. The real
 * wptests_dono_* tables are never touched, so leaking is impossible rather than
 * merely unlikely.
 *
 * Model::migrate() and DB::getPrefix() both read $wpdb->prefix at call time, so
 * swapping it is enough to point the real models at scratch tables. Nothing is
 * mocked: this is the production migration path, on production model classes.
 */
abstract class UpgradeTestCase extends IntegrationTestCase
{
    /** Distinct enough that it can never collide with a real table. */
    protected const SCRATCH_PREFIX = 'wpupg_';

    private string $realPrefix = '';

    protected function setUp(): void
    {
        parent::setUp();

        global $wpdb;
        $this->realPrefix = $wpdb->prefix;
        $wpdb->set_prefix(self::SCRATCH_PREFIX);

        $this->dropScratchTables();
    }

    protected function tearDown(): void
    {
        $this->dropScratchTables();

        global $wpdb;
        $wpdb->set_prefix($this->realPrefix);

        parent::tearDown();
    }

    /**
     * Build every dono table at its current shape, under the scratch prefix.
     *
     * This is the "already installed" starting point. A test then degrades it to
     * look like an older release and runs the update.
     */
    protected function installCurrentSchema(): void
    {
        $this->runTheRealUpdate();
    }

    /**
     * The update path a real site takes: not a model method, the whole thing
     * Plugin::boot() runs behind the DONO_DB_VERSION gate.
     */
    protected function runTheRealUpdate(): void
    {
        Plugin::migrateSchema();
    }

    /** Columns present on a scratch table, in order. */
    protected function columns(string $table): array
    {
        global $wpdb;

        return array_map(
            static fn ($r): string => (string) $r->Field,
            (array) $wpdb->get_results("SHOW COLUMNS FROM `" . self::SCRATCH_PREFIX . $table . "`")
        );
    }

    /** Index names present on a scratch table. */
    protected function indexes(string $table): array
    {
        global $wpdb;

        $names = [];
        foreach ((array) $wpdb->get_results("SHOW INDEX FROM `" . self::SCRATCH_PREFIX . $table . "`") as $row) {
            $names[(string) $row->Key_name] = true;
        }

        return array_keys($names);
    }

    /** The declared type of one column, including nullability. */
    protected function columnType(string $table, string $column): string
    {
        global $wpdb;

        $row = $wpdb->get_row(
            "SHOW COLUMNS FROM `" . self::SCRATCH_PREFIX . $table . "` LIKE '" . esc_sql($column) . "'"
        );

        return $row ? $row->Type . ($row->Null === 'YES' ? ' NULL' : ' NOT NULL') : 'MISSING';
    }

    protected function rowCount(string $table): int
    {
        global $wpdb;

        return (int) $wpdb->get_var("SELECT COUNT(*) FROM `" . self::SCRATCH_PREFIX . $table . "`");
    }

    /** Raw DDL against a scratch table, for degrading it to an older shape. */
    protected function alterScratch(string $sql): void
    {
        global $wpdb;

        $wpdb->query($sql);
    }

    protected function scratch(string $table): string
    {
        return self::SCRATCH_PREFIX . $table;
    }

    private function dropScratchTables(): void
    {
        global $wpdb;

        $like = $wpdb->esc_like(self::SCRATCH_PREFIX) . '%';
        $tables = (array) $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $like));

        foreach ($tables as $table) {
            $wpdb->query("DROP TABLE IF EXISTS `{$table}`");
        }

        // Model::migrate() short-circuits on a per-table version option, so a
        // dropped table would not be rebuilt without clearing these.
        $wpdb->query(
            "DELETE FROM {$this->realPrefix}options WHERE option_name LIKE 'queryable\\_%\\_version'"
        );
    }
}
