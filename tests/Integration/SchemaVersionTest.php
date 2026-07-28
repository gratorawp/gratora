<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Foundation\Plugin;
use Dono\Vendor\Queryable\Model;
use Dono\Vendor\Queryable\Schema\Table;
use ReflectionProperty;

/**
 * A schema change that nobody declares never reaches an existing install.
 *
 * Activation hooks do not fire on a plugin update, so the only thing that
 * migrates an already-installed site is the boot-time gate, and that gate only
 * fires when DONO_DB_VERSION changes. Add a column and forget the bump and the
 * table is silently missing it: every query touching that column dies with
 * "unknown column", and it looks fine on any machine that reactivated.
 *
 * This is exactly what happened to the Gift Aid columns, so the rule is pinned
 * here rather than left to memory. When this fails, you changed a schema:
 * bump DONO_DB_VERSION in dono.php and put the new fingerprint below.
 */
final class SchemaVersionTest extends IntegrationTestCase
{
    /**
     * sha256 of every registered model's compiled CREATE TABLE, in class order.
     * Update it in the same commit as the DONO_DB_VERSION bump.
     */
    private const FINGERPRINT = '757c416b90dd3855b4912f80f428bd3ee5abf9d64b3f687ebdf128caeba52270';

    public function test_the_schema_matches_the_declared_db_version(): void
    {
        $actual = self::fingerprint();

        $this->assertSame(
            self::FINGERPRINT,
            $actual,
            "A model's schema changed. Bump DONO_DB_VERSION in dono.php so existing"
            . " installs migrate on update, then set FINGERPRINT to:\n{$actual}"
        );
    }

    /** Activation and the boot gate must stamp the same thing the gate reads. */
    public function test_activation_stamps_the_db_version_the_gate_compares(): void
    {
        Plugin::onActivation();

        $this->assertSame(DONO_DB_VERSION, get_option('dono_db_version'));
    }

    private static function fingerprint(): string
    {
        $prop = new ReflectionProperty(Model::class, 'schemas');
        $prop->setAccessible(true);
        /** @var array<class-string,callable> $schemas */
        $schemas = $prop->getValue();

        $classes = array_values(array_filter(
            Plugin::instance()->modules->allMigrations(),
            static fn (string $cls): bool => isset($schemas[$cls])
        ));
        sort($classes);

        $sql = '';
        foreach ($classes as $cls) {
            $table = new Table('utf8mb4', 'utf8mb4_unicode_ci', []);
            $schemas[$cls]($table);
            $sql .= $cls . "\n" . $table->compile('t') . "\n";
        }

        return hash('sha256', $sql);
    }
}
