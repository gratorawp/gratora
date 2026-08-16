<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donors\MagicLinkToken;
use Dono\Foundation\Plugin;

/**
 * A column that only exists after a wipe is not shipped. Both paths an install
 * can reach the new token columns by are driven for real: the table built from
 * nothing, and a site sitting on the previous DONO_DB_VERSION with the old
 * table already in place.
 *
 * DDL commits implicitly, so nothing here writes rows: the table is left in the
 * shape the current schema declares either way.
 */
final class ReviewW6SchemaApplyTest extends IntegrationTestCase
{
    /**
     * WP_UnitTestCase rewrites CREATE TABLE and DROP TABLE to their TEMPORARY
     * forms for the duration of a test, so a migration driven under it would
     * build a table nothing else can see and a drop would silently do nothing.
     * The dono_* tables are real, built at bootstrap, so this test drops the
     * rewriting and works on the real one.
     */
    protected function setUp(): void
    {
        parent::setUp();
        remove_filter('query', [$this, '_create_temporary_tables']);
        remove_filter('query', [$this, '_drop_temporary_tables']);
    }

    private function table(): string
    {
        return self::$wpdb->prefix . 'dono_magic_link_tokens';
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

    public function test_a_fresh_install_builds_the_table_with_the_name_columns(): void
    {
        self::$wpdb->query('DROP TABLE IF EXISTS ' . $this->table());
        $this->assertSame([], self::$wpdb->get_col(
            self::$wpdb->prepare('SHOW TABLES LIKE %s', $this->table())
        ), 'the table is gone before the migration runs');

        Plugin::migrateSchema();

        $cols = $this->columns();
        $this->assertArrayHasKey('first_name', $cols);
        $this->assertArrayHasKey('last_name', $cols);
        $this->assertSame('varchar(100)', $cols['first_name']);
        $this->assertSame('varchar(100)', $cols['last_name']);
    }

    /**
     * The path an existing site actually takes. Activation hooks do not fire on
     * an update, so the boot gate is the only thing that migrates, and it only
     * fires when the stored version differs.
     */
    public function test_a_site_on_the_previous_db_version_gains_the_columns_on_update(): void
    {
        self::$wpdb->query('ALTER TABLE ' . $this->table() . ' DROP COLUMN first_name, DROP COLUMN last_name');

        $cols = $this->columns();
        $this->assertArrayNotHasKey('first_name', $cols, 'the 1.0.1 table shape');
        $this->assertArrayNotHasKey('last_name', $cols);

        update_option('dono_db_version', '1.0.1', false);
        $this->fireProductWpLoaded();

        $cols = $this->columns();
        $this->assertArrayHasKey('first_name', $cols, 'the update added the column');
        $this->assertArrayHasKey('last_name', $cols);
        $this->assertSame(DONO_DB_VERSION, get_option('dono_db_version'));
    }

    /**
     * The test bootstrap hangs Plugin::onActivation() on wp_loaded, and that
     * migrates unconditionally, so a plain do_action would pass whether the
     * product's version gate fired or not. Only callbacks declared in the
     * product's own Plugin file are left standing for the duration of the call.
     */
    private function fireProductWpLoaded(): void
    {
        global $wp_filter;

        $product = realpath(dirname(__DIR__, 2) . '/src/Foundation/Plugin.php');
        $removed = [];

        foreach (($wp_filter['wp_loaded']->callbacks ?? []) as $priority => $callbacks) {
            foreach ($callbacks as $cb) {
                $fn = $cb['function'];
                $file = $fn instanceof \Closure
                    ? (new \ReflectionFunction($fn))->getFileName()
                    : null;
                if ($file !== null && realpath((string) $file) === $product) continue;

                $removed[] = [$fn, $priority, $cb['accepted_args']];
                remove_action('wp_loaded', $fn, $priority);
            }
        }

        try {
            do_action('wp_loaded');
        } finally {
            foreach ($removed as [$fn, $priority, $args]) {
                add_action('wp_loaded', $fn, $priority, $args);
            }
        }
    }

    /** The columns the migration builds are the columns the model writes. */
    public function test_the_migrated_table_takes_a_token_carrying_a_name(): void
    {
        $token             = new MagicLinkToken();
        $token->donor_id   = 0;
        $token->token_hash = hash('sha256', 'w6-schema-probe');
        $token->purpose    = 'donor_portal_signup';
        $token->target_id  = 0;
        $token->first_name = 'Alice';
        $token->last_name  = 'Okafor';
        $token->expires_at = gmdate('Y-m-d H:i:s', time() + 3600);
        $token->created_at = gmdate('Y-m-d H:i:s');
        $token->save();

        $read = MagicLinkToken::query()->where('token_hash', hash('sha256', 'w6-schema-probe'))->get();

        $this->assertNotNull($read);
        $this->assertSame('Alice', (string) $read->first_name);
        $this->assertSame('Okafor', (string) $read->last_name);

        MagicLinkToken::query()->where('token_hash', hash('sha256', 'w6-schema-probe'))->delete();
    }
}
