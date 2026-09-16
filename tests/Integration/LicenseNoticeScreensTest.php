<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Foundation\Container\Container;
use Gratora\Foundation\License\LicenseNotice;
use Gratora\Foundation\License\LicenseService;
use Gratora\Foundation\Modules\GratoraModule;
use Gratora\Foundation\Modules\ModuleManager;

/**
 * A license notice is about Gratora, so it is said on Gratora's screens and
 * nowhere else in wp-admin. The page is the one wp-admin/admin.php resolved,
 * which is what a real request hands the notice.
 */
final class LicenseNoticeScreensTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        add_filter('gratora.pro.product_status', static fn (): string => 'revoked');
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['plugin_page']);
        remove_all_filters('gratora.pro.product_status');

        parent::tearDown();
    }

    public function test_a_refused_license_is_announced_on_gratora_screens_and_nowhere_else(): void
    {
        foreach ([null, 'wc-settings', 'metropolis'] as $page) {
            $this->assertSame('', $this->renderedOn($page), var_export($page, true) . ' is not a Gratora screen');
        }

        foreach (['gratora', 'gratora-donations'] as $page) {
            $this->assertStringContainsString('gratora-admin-notice', $this->renderedOn($page), "{$page} is a Gratora screen");
        }

        $this->assertSame('', $this->renderedOn('gratora-settings'), 'the settings screen says it itself');
    }

    public function test_a_lapsing_license_follows_the_same_rule(): void
    {
        remove_all_filters('gratora.pro.product_status');
        add_filter('gratora.pro.product_status', static fn (): string => 'expired');

        $this->assertSame('', $this->renderedOn('wc-settings'));
        $this->assertStringContainsString('Fake Add-on', $this->renderedOn('gratora-campaigns'));
    }

    private function renderedOn(?string $page): string
    {
        if ($page === null) {
            unset($GLOBALS['plugin_page']);
        } else {
            $GLOBALS['plugin_page'] = $page;
        }

        ob_start();
        (new LicenseNotice($this->serviceWithAddon()))->render();

        return (string) ob_get_clean();
    }

    private function serviceWithAddon(): LicenseService
    {
        $module = new class () implements GratoraModule {
            public function id(): string { return 'fake'; }
            public function name(): string { return 'Fake Add-on'; }
            public function version(): string { return '1.0.0'; }
            public function requires(): array { return []; }
            public function isLicensed(): bool { return true; }
            public function tier(): string { return GratoraModule::TIER_PRO; }
            public function boot(Container $container): void {}
            public function migrations(): array { return []; }
        };

        $modules = new ModuleManager(new Container());
        $modules->register($module);
        $modules->bootAll();

        return new LicenseService($modules);
    }
}
