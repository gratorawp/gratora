<?php

declare(strict_types=1);

namespace FundKit\Tests\Unit\Foundation;

use FundKit\Foundation\Container\Container;
use FundKit\Foundation\Modules\FundKitModule;
use FundKit\Foundation\Modules\ModuleManager;
use PHPUnit\Framework\TestCase;

final class ModuleManagerStatusTest extends TestCase
{
    protected function setUp(): void
    {
        if (! defined('FUNDKIT_VERSION')) {
            define('FUNDKIT_VERSION', '0.1.0');
        }
    }

    public function test_status_returns_one_value_per_state(): void
    {
        $mm = new ModuleManager(new Container());

        $mm->register($this->module('booted'));
        $mm->register($this->module('unlicensed', [], false));
        $mm->register($this->module('unmet', ['modules' => ['ghost']]));
        $mm->register($this->module('incompatible', ['core' => '^99']));

        $mm->bootAll();

        $this->assertSame('not-registered', $mm->status('never-added'));
        $this->assertSame('unlicensed', $mm->status('unlicensed'));
        $this->assertSame('unmet-deps', $mm->status('unmet'));
        $this->assertSame('incompatible', $mm->status('incompatible'));
        $this->assertSame('booted', $mm->status('booted'));
    }

    /** @param array<string,mixed> $requires */
    private function module(string $id, array $requires = [], bool $licensed = true): FundKitModule
    {
        return new class($id, $requires, $licensed) implements FundKitModule {
            /** @param array<string,mixed> $requires */
            public function __construct(
                private string $idValue,
                private array $requiresValue,
                private bool $licensed,
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
                return $this->licensed;
            }

            public function tier(): string
            {
                return FundKitModule::TIER_PRO;
            }

            public function boot(Container $container): void
            {
            }

            public function migrations(): array
            {
                return [];
            }
        };
    }
}
