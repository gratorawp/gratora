<?php

declare(strict_types=1);

namespace FundKit\Tests\Unit\Foundation;

use FundKit\Foundation\Container\Container;
use FundKit\Foundation\Modules\FundKitModule;
use FundKit\Foundation\Modules\ModuleManager;
use PHPUnit\Framework\TestCase;

final class ModuleManagerCompatTest extends TestCase
{
    protected function setUp(): void
    {
        if (! defined('FUNDKIT_VERSION')) {
            define('FUNDKIT_VERSION', '0.1.0');
        }
    }

    public function test_module_with_unsatisfied_core_constraint_is_not_booted(): void
    {
        $mm     = new ModuleManager(new Container());
        $booted = false;
        $mm->register($this->module('paid', ['core' => '^99'], function () use (&$booted): void {
            $booted = true;
        }));

        $mm->bootAll();

        $this->assertFalse($booted, 'boot() must not run for an incompatible module');
        $this->assertSame(['0.1.0', '^99'], $mm->incompatible()['paid'] ?? null);
    }

    public function test_module_without_core_key_is_unaffected(): void
    {
        $mm     = new ModuleManager(new Container());
        $booted = false;
        $mm->register($this->module('plain', [], function () use (&$booted): void {
            $booted = true;
        }));

        $mm->bootAll();

        $this->assertTrue($booted);
        $this->assertSame([], $mm->incompatible());
    }

    /** @param array<string,mixed> $requires */
    private function module(string $id, array $requires, \Closure $onBoot): FundKitModule
    {
        return new class($id, $requires, $onBoot) implements FundKitModule {
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
                return FundKitModule::TIER_PRO;
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
