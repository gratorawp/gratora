<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Foundation\Plugin;
use Gratora\Vendor\Queryable\Model;
use Gratora\Vendor\Queryable\Schema\Table;
use ReflectionProperty;

/**
 * Schema changes require GRATORA_DB_VERSION and fingerprint updates. Use a patch bump for plain
 * migrations, minor for data-moving UpgradeRoutines.
 */
final class SchemaVersionTest extends IntegrationTestCase
{
    /**
     * sha256 of every registered model's compiled CREATE TABLE, in class order.
     * Update it in the same commit as the GRATORA_DB_VERSION bump.
     *
     * Dropping a model is the one schema change that needs no bump: migrate()
     * iterates registered models, and dbDelta never drops a table, so there is
     * nothing for a migration to do and the orphaned table is inert either way.
     */
    private const FINGERPRINT = '7589e82f96fe1dd8cff1a5325d2e504ff9deaea5af0c558bfe6890812ef68030';

    public function test_the_schema_matches_the_declared_db_version(): void
    {
        $actual = self::fingerprint();

        $this->assertSame(
            self::FINGERPRINT,
            $actual,
            "A model's schema changed. Bump GRATORA_DB_VERSION in gratora.php so existing"
            . " installs migrate on update, then set FINGERPRINT to:\n{$actual}"
        );
    }

    /**
     * Three segments, so version_compare can order it. The gate only tests
     * equality today, but an UpgradeRoutine that wants to say which stored
     * versions it applies to needs the value to already be comparable, and a
     * shape nobody enforces is one absent-minded bump from being a counter.
     */
    public function test_the_db_version_is_semver(): void
    {
        $this->assertMatchesRegularExpression(
            '/^\d+\.\d+\.\d+$/',
            GRATORA_DB_VERSION,
            'GRATORA_DB_VERSION is major.minor.patch'
        );
    }

    /** Activation and the boot gate must stamp the same thing the gate reads. */
    public function test_activation_stamps_the_db_version_the_gate_compares(): void
    {
        Plugin::onActivation();

        $this->assertSame(GRATORA_DB_VERSION, get_option('gratora_db_version'));
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
