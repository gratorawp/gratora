<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Core\Activator;
use Gratora\Foundation\Container\Container;
use Gratora\Foundation\Modules\GratoraModule;
use Gratora\Foundation\Modules\ModuleManager;
use Gratora\Foundation\Plugin;
use Gratora\Foundation\Upgrade\SchemaGuard;
use Gratora\Vendor\Queryable\Model;
use ReflectionProperty;

/**
 * Who a missing table stops.
 *
 * The stamp is what disarms the wp_loaded gate, so withholding it over a table
 * core does not own means every request re-runs a full dbDelta over every model
 * and the activation work never finishes. An add-on the host will not let
 * create its table has to fail alone.
 *
 * The probe models reproduce a migrator that returned without complaint and a
 * table that does not exist, without touching the shared test database:
 * WP_UnitTestCase rewrites DROP TABLE to its TEMPORARY form, so a real table
 * cannot be removed and put back from inside a test. The core case swaps the
 * module registered under 'core' for one that declares an extra model, which is
 * the only way to make a core-owned table absent.
 */
final class SchemaGuardTest extends IntegrationTestCase
{
    private ?GratoraModule $coreBefore = null;

    protected function tearDown(): void
    {
        $this->restoreCoreModule();
        $this->unregisterAddOn();
        update_option(SchemaGuard::OPTION, GRATORA_DB_VERSION, false);
        // dbDelta can commit past the harness transaction, so anything the
        // activation path writes has to be put back by hand.
        update_option(Activator::OPT_ACTIVATED_AT, gmdate('c'), false);
        parent::tearDown();
    }

    public function test_a_missing_add_on_table_does_not_block_the_core_stamp(): void
    {
        $this->registerAddOn();
        delete_option(SchemaGuard::OPTION);

        $this->assertTrue(SchemaGuard::stampWhenComplete());
        $this->assertSame(GRATORA_DB_VERSION, get_option(SchemaGuard::OPTION));
    }

    public function test_a_missing_core_table_blocks_the_stamp(): void
    {
        $this->replaceCoreModule();
        delete_option(SchemaGuard::OPTION);

        $this->assertFalse(SchemaGuard::stampWhenComplete());
        $this->assertFalse(
            get_option(SchemaGuard::OPTION),
            'the schema version must stay unset so the wp_loaded gate migrates again next request'
        );
    }

    public function test_an_add_on_table_is_not_counted_against_core(): void
    {
        $this->registerAddOn();

        $this->assertSame([], SchemaGuard::missingTables());
    }

    public function test_a_missing_core_table_is_counted_against_core(): void
    {
        $this->replaceCoreModule();

        $this->assertContains(UncreatedCoreTable::TABLE, SchemaGuard::missingTables());
    }

    public function test_the_missing_add_on_table_is_reported_against_its_plugin(): void
    {
        $this->registerAddOn();

        $this->assertSame(
            [UncreatedAddOnTable::TABLE => AddOnTableProbeModule::NAME],
            SchemaGuard::missingAddOnTables()
        );
    }

    /**
     * The stamp is written while the add-on table is still missing, so a notice
     * that returns early on a matching stamp would never say anything at all.
     */
    public function test_the_notice_names_the_plugin_that_owns_the_missing_table(): void
    {
        $this->registerAddOn();
        update_option(SchemaGuard::OPTION, GRATORA_DB_VERSION, false);

        ob_start();
        SchemaGuard::renderNotice();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString(AddOnTableProbeModule::NAME, $html);
        $this->assertStringContainsString(self::$prefix . UncreatedAddOnTable::TABLE, $html);
    }

    public function test_the_notice_names_a_missing_core_table(): void
    {
        $this->replaceCoreModule();
        delete_option(SchemaGuard::OPTION);

        ob_start();
        SchemaGuard::renderNotice();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('notice-error', $html);
        $this->assertStringContainsString(self::$prefix . UncreatedCoreTable::TABLE, $html);
    }

    /**
     * The stamp is only half of it: finishActivation() asks the same question
     * again, and a site that never gets past it has no default fund, no
     * capabilities and no donor portal page, on which activation hooks never
     * fire a second time.
     */
    public function test_activation_finishes_while_an_add_on_table_is_missing(): void
    {
        $this->registerAddOn();
        delete_option(SchemaGuard::OPTION);
        delete_option(Activator::OPT_ACTIVATED_AT);

        Plugin::onActivation();

        $this->assertSame(GRATORA_DB_VERSION, get_option(SchemaGuard::OPTION));
        $this->assertNotFalse(
            get_option(Activator::OPT_ACTIVATED_AT, false),
            'core activation must complete when only an add-on table is missing'
        );
    }

    private function registerAddOn(): void
    {
        $modules = Plugin::instance()->modules;

        if (! $modules->get(AddOnTableProbeModule::ID)) {
            $modules->register(new AddOnTableProbeModule());
        }
    }

    /**
     * ModuleManager has no removal path by design, and the Plugin singleton
     * outlives the test, so a probe left registered would fail every later
     * activation in the suite.
     */
    private function unregisterAddOn(): void
    {
        $modules = Plugin::instance()->modules;

        foreach (['modules', 'booted'] as $name) {
            $property = new ReflectionProperty(ModuleManager::class, $name);
            $property->setAccessible(true);
            $value = $property->getValue($modules);
            unset($value[AddOnTableProbeModule::ID]);
            $property->setValue($modules, $value);
        }
    }

    private function replaceCoreModule(): void
    {
        $registry = $this->registry();
        $core     = $registry['core'] ?? null;

        if (! $core instanceof GratoraModule || $core instanceof CoreWithAnUncreatedTable) {
            return;
        }

        $this->coreBefore = $core;
        $registry['core'] = new CoreWithAnUncreatedTable($core);

        $this->writeRegistry($registry);
    }

    private function restoreCoreModule(): void
    {
        if (! $this->coreBefore instanceof GratoraModule) {
            return;
        }

        $registry         = $this->registry();
        $registry['core'] = $this->coreBefore;
        $this->coreBefore = null;

        $this->writeRegistry($registry);
    }

    /** @return array<string, GratoraModule> */
    private function registry(): array
    {
        $property = new ReflectionProperty(ModuleManager::class, 'modules');
        $property->setAccessible(true);

        return $property->getValue(Plugin::instance()->modules);
    }

    /** @param array<string, GratoraModule> $registry */
    private function writeRegistry(array $registry): void
    {
        $property = new ReflectionProperty(ModuleManager::class, 'modules');
        $property->setAccessible(true);
        $property->setValue(Plugin::instance()->modules, $registry);
    }
}

final class UncreatedAddOnTable extends Model
{
    public const TABLE = 'gratora_schema_guard_addon_probe';

    protected string $table = self::TABLE;

    public static function migrate(bool $force = false): void
    {
    }
}

final class UncreatedCoreTable extends Model
{
    public const TABLE = 'gratora_schema_guard_core_probe';

    protected string $table = self::TABLE;

    public static function migrate(bool $force = false): void
    {
    }
}

final class AddOnTableProbeModule implements GratoraModule
{
    public const ID   = 'schema-guard-addon-probe';
    public const NAME = 'Gratora Table Probe';

    public function id(): string
    {
        return self::ID;
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function requires(): array
    {
        return [];
    }

    public function isLicensed(): bool
    {
        return true;
    }

    public function tier(): string
    {
        return GratoraModule::TIER_PRO;
    }

    public function boot(Container $container): void
    {
    }

    public function migrations(): array
    {
        return [UncreatedAddOnTable::class];
    }
}

/**
 * Core, declaring one model whose table was never created.
 */
final class CoreWithAnUncreatedTable implements GratoraModule
{
    public function __construct(private GratoraModule $inner)
    {
    }

    public function id(): string
    {
        return $this->inner->id();
    }

    public function name(): string
    {
        return $this->inner->name();
    }

    public function version(): string
    {
        return $this->inner->version();
    }

    public function requires(): array
    {
        return $this->inner->requires();
    }

    public function isLicensed(): bool
    {
        return $this->inner->isLicensed();
    }

    public function tier(): string
    {
        return $this->inner->tier();
    }

    public function boot(Container $container): void
    {
        $this->inner->boot($container);
    }

    public function migrations(): array
    {
        return [...$this->inner->migrations(), UncreatedCoreTable::class];
    }
}
