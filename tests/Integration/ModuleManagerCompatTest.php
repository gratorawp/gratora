<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Foundation\Container\Container;
use Dono\Foundation\Modules\DonoModule;
use Dono\Foundation\Modules\ModuleManager;

/**
 * The compat *state* is unit-tested (ModuleManagerCompatTest in tests/Unit);
 * this exercises the real WP `dono.module.incompatible` action firing, which
 * the unit bootstrap stubs to a no-op by design.
 */
final class ModuleManagerCompatTest extends IntegrationTestCase
{
    public function test_incompatible_action_fires_once_with_version_and_constraint(): void
    {
        $calls = [];
        add_action('dono.module.incompatible', static function (string $id, string $core, string $constraint) use (&$calls): void {
            $calls[] = [$id, $core, $constraint];
        }, 10, 3);

        $mm     = new ModuleManager(new Container());
        $booted = false;
        $mm->register($this->module('paid', ['core' => '^99'], function () use (&$booted): void {
            $booted = true;
        }));

        $mm->bootAll();

        $this->assertFalse($booted);
        $this->assertCount(1, $calls);
        $this->assertSame(['paid', DONO_VERSION, '^99'], $calls[0]);
        $this->assertSame([DONO_VERSION, '^99'], $mm->incompatible()['paid']);

        remove_all_actions('dono.module.incompatible');
    }

    public function test_module_without_core_constraint_still_boots(): void
    {
        $mm     = new ModuleManager(new Container());
        $booted = false;
        $mm->register($this->module('plain', [], function () use (&$booted): void {
            $booted = true;
        }));

        $mm->bootAll();

        $this->assertTrue($booted);
        $this->assertArrayNotHasKey('plain', $mm->incompatible());
    }

    /** @param array<string,mixed> $requires */
    private function module(string $id, array $requires, \Closure $onBoot): DonoModule
    {
        return new class($id, $requires, $onBoot) implements DonoModule {
            /** @param array<string,mixed> $requires */
            public function __construct(
                private string $idValue,
                private array $requiresValue,
                private \Closure $onBoot,
            ) {
            }

            public function id(): string
            {
                return $this->idValue;
            }

            public function name(): string
            {
                return $this->idValue;
            }

            public function version(): string
            {
                return '1.0.0';
            }

            public function requires(): array
            {
                return $this->requiresValue;
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
                ($this->onBoot)();
            }

            public function migrations(): array
            {
                return [];
            }
        };
    }
}
