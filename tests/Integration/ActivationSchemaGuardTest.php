<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Core\Activator;
use Dono\Foundation\Container\Container;
use Dono\Foundation\Modules\DonoModule;
use Dono\Foundation\Modules\ModuleManager;
use Dono\Foundation\Plugin;
use Dono\Foundation\Upgrade\SchemaGuard;
use Dono\Vendor\Queryable\Model;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Activation on a host that will not create tables.
 *
 * Restricted grants, a table-count quota or a row-size limit all end the same
 * way: dbDelta says nothing and the table is not there. Stamping the schema
 * version on top of that disarms the wp_loaded gate, which is the only thing
 * that would ever migrate again, and the install can never recover.
 *
 * The probe model reproduces exactly that state, a migrator that returned
 * without complaint and a table that does not exist, without touching the
 * shared test database: WP_UnitTestCase rewrites DROP TABLE to its TEMPORARY
 * form, so a real table cannot be removed and put back from inside a test.
 */
final class ActivationSchemaGuardTest extends IntegrationTestCase
{
    protected function tearDown(): void
    {
        $this->unregisterProbeModule();
        update_option(SchemaGuard::OPTION, DONO_DB_VERSION, false);
        // dbDelta can commit past the harness transaction, so anything the
        // activation path writes has to be put back by hand.
        update_option(Activator::OPT_ACTIVATED_AT, gmdate('Y-m-d H:i:s'), false);
        parent::tearDown();
    }

    public function test_activation_withholds_the_stamp_when_a_table_is_missing(): void
    {
        $this->registerProbeModule();
        delete_option(SchemaGuard::OPTION);

        Plugin::onActivation();

        $this->assertFalse(
            get_option(SchemaGuard::OPTION),
            'the schema version must stay unset so the wp_loaded gate migrates again next request'
        );
    }

    public function test_activation_stamps_the_version_once_every_table_exists(): void
    {
        delete_option(SchemaGuard::OPTION);

        Plugin::onActivation();

        $this->assertSame(DONO_DB_VERSION, get_option(SchemaGuard::OPTION));
    }

    public function test_the_missing_table_is_named(): void
    {
        $this->registerProbeModule();

        $this->assertContains(UnmigratedProbe::TABLE, SchemaGuard::missingTables());
    }

    public function test_the_admin_notice_names_the_missing_table(): void
    {
        $this->registerProbeModule();
        delete_option(SchemaGuard::OPTION);

        ob_start();
        SchemaGuard::renderNotice();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('notice-error', $html);
        $this->assertStringContainsString(self::$prefix . UnmigratedProbe::TABLE, $html);
    }

    public function test_no_notice_while_the_schema_is_whole(): void
    {
        update_option(SchemaGuard::OPTION, DONO_DB_VERSION, false);

        ob_start();
        SchemaGuard::renderNotice();

        $this->assertSame('', (string) ob_get_clean());
    }

    /**
     * The stamp and the activation record are written at different points, and
     * a throw between them is not the same failure as a refused CREATE: the
     * tables are there and the version says so, but the default fund, the
     * capabilities and the portal page are not. Activation hooks never fire
     * again, so anything that reads only the stamp leaves that site broken for
     * good and says nothing.
     *
     * Reached by reflection rather than through wp_loaded, because the
     * integration bootstrap hooks a full activation onto that same action at
     * priority 1: firing it in a test proves what the harness does, not what
     * the gate decides.
     */
    public function test_an_activation_that_died_after_the_stamp_is_finished_later(): void
    {
        update_option(SchemaGuard::OPTION, DONO_DB_VERSION, false);
        delete_option(Activator::OPT_ACTIVATED_AT);

        $this->finishActivation();

        $this->assertNotFalse(
            get_option(Activator::OPT_ACTIVATED_AT, false),
            'a matching stamp is not evidence that the activation finished'
        );
    }

    /** Costs one option read on a healthy site and does nothing else. */
    public function test_a_finished_activation_is_not_run_again(): void
    {
        update_option(SchemaGuard::OPTION, DONO_DB_VERSION, false);
        update_option(Activator::OPT_ACTIVATED_AT, '2020-01-01T00:00:00+00:00', false);

        $this->finishActivation();

        $this->assertSame('2020-01-01T00:00:00+00:00', get_option(Activator::OPT_ACTIVATED_AT));
    }

    private function finishActivation(): void
    {
        $method = new ReflectionMethod(Plugin::class, 'finishActivation');
        $method->setAccessible(true);
        $method->invoke(null, null);
    }

    private function registerProbeModule(): void
    {
        $modules = Plugin::instance()->modules;

        if (! $modules->get(UnmigratedProbeModule::ID)) {
            $modules->register(new UnmigratedProbeModule());
        }
    }

    /**
     * ModuleManager has no removal path by design, and the Plugin singleton
     * outlives the test, so a probe left registered would fail every later
     * activation in the suite.
     */
    private function unregisterProbeModule(): void
    {
        $modules = Plugin::instance()->modules;

        foreach (['modules', 'booted'] as $name) {
            $property = new ReflectionProperty(ModuleManager::class, $name);
            $property->setAccessible(true);
            $value = $property->getValue($modules);
            unset($value[UnmigratedProbeModule::ID]);
            $property->setValue($modules, $value);
        }
    }
}

final class UnmigratedProbe extends Model
{
    public const TABLE = 'dono_schema_guard_probe';

    protected string $table = self::TABLE;

    public static function migrate(bool $force = false): void
    {
    }
}

final class UnmigratedProbeModule implements DonoModule
{
    public const ID = 'schema-guard-probe';

    public function id(): string
    {
        return self::ID;
    }

    public function name(): string
    {
        return 'Schema guard probe';
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
        return DonoModule::TIER_PRO;
    }

    public function boot(Container $container): void
    {
    }

    public function migrations(): array
    {
        return [UnmigratedProbe::class];
    }
}
