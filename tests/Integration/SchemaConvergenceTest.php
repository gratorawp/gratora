<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Foundation\Plugin;
use Gratora\Vendor\Queryable\Model;
use Gratora\Vendor\Queryable\Schema\Table;
use ReflectionProperty;

/**
 * Check schema convergence on both engines: dbDelta compares type text, while MySQL and MariaDB
 * report JSON and integer widths differently.
 */
final class SchemaConvergenceTest extends IntegrationTestCase
{
    /**
     * Built the way Model::migrate() builds them, including the charset and the
     * prefixed name, because a comparison against anything else proves nothing.
     *
     * @return array<string, Table> keyed by full table name
     */
    private function registeredTables(): array
    {
        $prop = new ReflectionProperty(Model::class, 'schemas');
        $prop->setAccessible(true);
        $schemas = (array) $prop->getValue();

        $charset = self::$wpdb->charset ?: 'utf8mb4';
        $collate = self::$wpdb->collate ?: 'utf8mb4_unicode_ci';

        $out = [];
        foreach (Plugin::instance()->modules->allMigrations() as $class) {
            if (! isset($schemas[$class])) {
                continue;
            }

            $model = new $class();

            $name = new ReflectionProperty($model, 'table');
            $name->setAccessible(true);

            $meta = new \ReflectionMethod($model, 'meta');
            $meta->setAccessible(true);

            $t = new Table($charset, $collate, $meta->invoke($model));
            $schemas[$class]($t);

            $out[self::$wpdb->prefix . $name->getValue($model)] = $t;
        }

        return $out;
    }

    public function test_every_model_migration_converges(): void
    {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $tables = $this->registeredTables();
        $this->assertNotEmpty($tables, 'models register their schema, or this proves nothing');

        $unconverged = [];

        foreach ($tables as $name => $table) {
            // migrate() has already created these; ask dbDelta what it would
            // still change. A converged schema answers with nothing.
            $changes = dbDelta($table->compile($name), false);

            if ($changes !== []) {
                $unconverged[$name] = $changes;
            }
        }

        $this->assertSame(
            [],
            $unconverged,
            self::$wpdb->get_var('SELECT VERSION()')
            . ": dbDelta still wants to change a table it just migrated, so every"
            . " migration will rebuild it again:\n" . var_export($unconverged, true)
        );
    }

    public function test_json_columns_are_stored_as_longtext(): void
    {
        $found = 0;

        foreach ($this->registeredTables() as $table) {
            foreach ($table->getColumns() as $col) {
                $def = $col->getDefinition();
                if (empty($def['json'])) {
                    continue;
                }

                $found++;
                $this->assertSame(
                    'LONGTEXT',
                    $def['type'],
                    "{$def['name']} is emitted as {$def['type']}; MariaDB reports such a"
                    . ' column back as longtext and dbDelta then rewrites it forever'
                );
            }
        }

        $this->assertGreaterThan(0, $found, 'there are json columns, or this proves nothing');
    }
}
