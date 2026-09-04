<?php

declare(strict_types=1);

namespace FundKit\Tests\Integration;

use FundKit\Foundation\Plugin;
use FundKit\Vendor\Queryable\Model;
use FundKit\Vendor\Queryable\Schema\Table;
use ReflectionProperty;

/**
 * A schema change that nobody declares never reaches an existing install.
 *
 * Activation hooks do not fire on a plugin update, so the only thing that
 * migrates an already-installed site is the boot-time gate, and that gate only
 * fires when FUNDKIT_DB_VERSION changes. Add a column and forget the bump and the
 * table is silently missing it: every query touching that column dies with
 * "unknown column", and it looks fine on any machine that reactivated.
 *
 * That has happened, so the rule is pinned here rather than left to memory.
 * When this fails, you changed a schema: bump FUNDKIT_DB_VERSION in fundkit.php and
 * put the new fingerprint below.
 *
 * The bump is semver, and it tracks the schema rather than the release: patch
 * for an additive change a plain migration handles, minor when the change needs
 * an UpgradeRoutine to move data.
 */
final class SchemaVersionTest extends IntegrationTestCase
{
    /**
     * sha256 of every registered model's compiled CREATE TABLE, in class order.
     * Update it in the same commit as the FUNDKIT_DB_VERSION bump.
     *
     * Dropping a model is the one schema change that needs no bump: migrate()
     * iterates registered models, and dbDelta never drops a table, so there is
     * nothing for a migration to do and the orphaned table is inert either way.
     */
    private const FINGERPRINT = '133cd4687fd2d07b4ed45c05ab33434e0e91e5b6a43693cc04d9d02c7a470cdb';

    public function test_the_schema_matches_the_declared_db_version(): void
    {
        $actual = self::fingerprint();

        $this->assertSame(
            self::FINGERPRINT,
            $actual,
            "A model's schema changed. Bump FUNDKIT_DB_VERSION in fundkit.php so existing"
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
            FUNDKIT_DB_VERSION,
            'FUNDKIT_DB_VERSION is major.minor.patch'
        );
    }

    /** Activation and the boot gate must stamp the same thing the gate reads. */
    public function test_activation_stamps_the_db_version_the_gate_compares(): void
    {
        Plugin::onActivation();

        $this->assertSame(FUNDKIT_DB_VERSION, get_option('fundkit_db_version'));
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
