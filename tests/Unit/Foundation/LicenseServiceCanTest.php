<?php

declare(strict_types=1);

namespace Dono\Tests\Unit\Foundation;

use Dono\Foundation\Container\Container;
use Dono\Foundation\License\LicenseService;
use Dono\Foundation\Modules\DonoModule;
use Dono\Foundation\Modules\ModuleManager;
use PHPUnit\Framework\TestCase;

/**
 * Pro status is derived from booted TIER_PRO modules, not a flippable filter.
 * Guards that contract: free modules never grant pro, and feature ids map to
 * booted paid add-on ids.
 */
final class LicenseServiceCanTest extends TestCase
{
    public function test_default_is_inactive_and_can_is_false(): void
    {
        $svc = new LicenseService($this->managerWith());

        $this->assertFalse($svc->isPro());
        $this->assertFalse($svc->can('p2p'));
        $this->assertSame(
            ['active' => false, 'features' => [], 'status' => 'inactive'],
            $svc->snapshot()
        );
    }

    public function test_pro_module_grants_pro_and_its_feature(): void
    {
        $svc = new LicenseService($this->managerWith(
            $this->module('p2p', DonoModule::TIER_PRO),
            $this->module('crm', DonoModule::TIER_PRO),
        ));

        $this->assertTrue($svc->isPro());
        $this->assertTrue($svc->can('p2p'));
        $this->assertTrue($svc->can('crm'));
        $this->assertFalse($svc->can('tickets'));
        $this->assertSame('active', $svc->status());
    }

    public function test_free_module_does_not_grant_pro(): void
    {
        $svc = new LicenseService($this->managerWith(
            $this->module('free-module', DonoModule::TIER_FREE),
        ));

        $this->assertFalse($svc->isPro(), 'free modules must not grant pro');
        $this->assertFalse($svc->can('free-module'));
    }

    public function test_missing_manager_is_inactive(): void
    {
        $this->assertFalse((new LicenseService())->isPro());
    }

    private function managerWith(DonoModule ...$modules): ModuleManager
    {
        $manager = new ModuleManager(new Container());
        foreach ($modules as $module) {
            $manager->register($module);
        }
        $manager->bootAll();

        return $manager;
    }

    private function module(string $id, string $tier): DonoModule
    {
        return new class($id, $tier) implements DonoModule {
            public function __construct(private string $id, private string $tier)
            {
            }

            public function id(): string
            {
                return $this->id;
            }

            public function name(): string
            {
                return $this->id;
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
                return $this->tier;
            }

            public function boot(Container $c): void
            {
            }

            public function migrations(): array
            {
                return [];
            }
        };
    }
}
