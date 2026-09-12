<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Foundation\Upgrade\MigrationLock;

/**
 * The donor bin selects on trashed_at and reads newest first, so the column
 * carries its own index.
 *
 * SchemaGuard compares columns only and stamps the version once they are
 * present, so a KEY that dbDelta declines to add on an existing table is
 * invisible: the site reports a complete schema and every bin query is a
 * filesort. Both paths an install can take are driven for real.
 *
 * DDL commits implicitly, so nothing here writes rows and each test leaves the
 * table in the shape the schema declares.
 */
final class DonorTrashSchemaTest extends IntegrationTestCase
{
    /**
     * WP_UnitTestCase rewrites CREATE TABLE and DROP TABLE to their TEMPORARY
     * forms, so a migration driven under it builds a table nothing else sees.
     * The gratora_* tables are real, built at bootstrap.
     */
    protected function setUp(): void
    {
        parent::setUp();
        remove_filter('query', [$this, '_create_temporary_tables']);
        remove_filter('query', [$this, '_drop_temporary_tables']);
    }

    /**
     * An ALTER commits the transaction each test is normally rolled back
     * inside, so anything written after one outlives this test. The version
     * stamp and the migration lock both decide whether the next test's gate
     * runs at all, so they are put back by hand.
     */
    protected function tearDown(): void
    {
        MigrationLock::release();
        update_option('gratora_db_version', GRATORA_DB_VERSION, false);

        parent::tearDown();
    }

    private function table(): string
    {
        return self::$wpdb->prefix . 'gratora_donors';
    }

    /** @return array<string,string> column name => column type */
    private function columns(): array
    {
        $out = [];
        foreach (self::$wpdb->get_results('DESCRIBE ' . $this->table()) as $col) {
            $out[(string) $col->Field] = (string) $col->Type;
        }

        return $out;
    }

    /** @return list<string> names of indexes whose first column is $column */
    private function indexesLedBy(string $column): array
    {
        $found = [];
        foreach (self::$wpdb->get_results('SHOW INDEX FROM `' . $this->table() . '`') as $row) {
            if ((int) $row->Seq_in_index === 1 && (string) $row->Column_name === $column) {
                $found[] = (string) $row->Key_name;
            }
        }

        return $found;
    }

    public function test_the_trash_columns_and_their_index_exist(): void
    {
        $cols = $this->columns();

        $this->assertArrayHasKey('trashed_at', $cols);
        $this->assertArrayHasKey('trashed_by', $cols);
        $this->assertNotSame(
            [],
            $this->indexesLedBy('trashed_at'),
            'the bin filters and orders on this column, so an index has to start with it'
        );
    }

    /**
     * The path an existing site actually takes. Activation hooks do not fire on
     * an update, so the boot gate is the only thing that migrates, and it only
     * fires when the stored version differs from the declared one.
     */
    public function test_a_site_on_the_previous_db_version_gains_the_columns_and_the_index(): void
    {
        self::$wpdb->query('ALTER TABLE ' . $this->table() . ' DROP COLUMN trashed_at, DROP COLUMN trashed_by');

        $cols = $this->columns();
        $this->assertArrayNotHasKey('trashed_at', $cols, 'the table shape before the update');
        $this->assertArrayNotHasKey('trashed_by', $cols);
        $this->assertSame([], $this->indexesLedBy('trashed_at'), 'and the index went with the column');

        update_option('gratora_db_version', '1.0.1', false);
        $this->fireProductWpLoaded();

        $cols = $this->columns();
        $this->assertArrayHasKey('trashed_at', $cols, 'the update added the column');
        $this->assertArrayHasKey('trashed_by', $cols);
        $this->assertSame(GRATORA_DB_VERSION, get_option('gratora_db_version'));
        $this->assertNotSame(
            [],
            $this->indexesLedBy('trashed_at'),
            'the column arrived without the index, so every bin query on an upgraded site is a filesort'
        );
    }

    /**
     * The bootstrap hangs Plugin::onActivation() on wp_loaded and that migrates
     * unconditionally, so a plain do_action would pass whether the version gate
     * fired or not. Only callbacks declared in the product's own Plugin file
     * are left standing for the duration of the call.
     */
    private function fireProductWpLoaded(): void
    {
        global $wp_filter;

        $product = realpath(dirname(__DIR__, 2) . '/src/Foundation/Plugin.php');
        $removed = [];

        foreach (($wp_filter['wp_loaded']->callbacks ?? []) as $priority => $callbacks) {
            foreach ($callbacks as $cb) {
                $fn   = $cb['function'];
                $file = $fn instanceof \Closure
                    ? (new \ReflectionFunction($fn))->getFileName()
                    : null;
                if ($file !== null && realpath((string) $file) === $product) {
                    continue;
                }

                $removed[] = [$fn, $priority, $cb['accepted_args']];
                remove_action('wp_loaded', $fn, $priority);
            }
        }

        // A previous test's gate run can leave this claimed: dbDelta's DDL
        // commits the row, while the release that follows is rolled back with
        // the test. A held lock makes the gate return without migrating.
        MigrationLock::release();

        try {
            do_action('wp_loaded');
        } finally {
            foreach ($removed as [$fn, $priority, $args]) {
                add_action('wp_loaded', $fn, $priority, $args);
            }
        }
    }
}
