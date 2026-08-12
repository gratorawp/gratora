<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Foundation\Container\Container;
use Dono\Foundation\License\LicenseService;
use Dono\Foundation\Modules\DonoModule;
use Dono\Foundation\Modules\ModuleManager;

/**
 * What entitlement means when nothing has checked.
 *
 * The distinction these pin is "nobody asked" against "the server refused". A
 * seam that reported the first as entitled would hand out paid features to
 * anyone; one that reported it as refused would switch off a customer's add-ons
 * the moment the licensing client was absent. Both are wrong in opposite
 * directions, so unknown is its own answer.
 *
 * These were written against a REST controller that only the licensing screen
 * called. The screen was never built and the routes went with it; the rules
 * belong to LicenseService, so they are asserted there.
 */
final class LicenseEntitlementTest extends IntegrationTestCase
{
    protected function tearDown(): void
    {
        remove_all_filters('dono.pro.product_status');
        parent::tearDown();
    }

    private function serviceWithAddon(): LicenseService
    {
        $module = new class () implements DonoModule {
            public function id(): string { return 'fake'; }
            public function name(): string { return 'Fake Add-on'; }
            public function version(): string { return '1.0.0'; }
            public function requires(): array { return []; }
            public function isLicensed(): bool { return true; }
            public function tier(): string { return DonoModule::TIER_PRO; }
            public function boot(Container $container): void {}
            public function migrations(): array { return []; }
        };

        $modules = new ModuleManager(new Container());
        $modules->register($module);
        $modules->bootAll();

        return new LicenseService($modules);
    }

    public function test_without_a_licensing_client_nothing_claims_to_be_checked(): void
    {
        $addons = $this->serviceWithAddon()->entitlements();

        $this->assertNotEmpty($addons);
        foreach ($addons as $addon) {
            $this->assertSame('unknown', $addon['status']);
            $this->assertFalse($addon['entitled'], 'an unchecked add-on must not read as entitled');
        }
    }

    public function test_a_revoked_product_is_not_entitled(): void
    {
        add_filter('dono.pro.product_status', static fn (): string => 'revoked');

        $addons = $this->serviceWithAddon()->entitlements();

        $this->assertSame('revoked', $addons[0]['status']);
        $this->assertFalse($addons[0]['entitled']);
    }

    /** Expired and grace still run: only revoked drops entitlement. */
    public function test_an_expired_product_is_still_entitled(): void
    {
        add_filter('dono.pro.product_status', static fn (): string => 'expired');

        $addons = $this->serviceWithAddon()->entitlements();

        $this->assertSame('expired', $addons[0]['status']);
        $this->assertTrue(
            $addons[0]['entitled'],
            'a lapsed licence stops updates, it does not switch off what someone paid for'
        );
    }

    public function test_grace_is_entitled_too(): void
    {
        add_filter('dono.pro.product_status', static fn (): string => 'grace');

        $this->assertTrue($this->serviceWithAddon()->entitlements()[0]['entitled']);
    }
}
