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
        remove_all_filters('gratora.license.manage_url');

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

    /**
     * The licensing client registers its screen under Gratora's menu, so it
     * reads as a Gratora screen. It lists every add-on's status itself, and a
     * notice there would only link to the page it sits on.
     */
    public function test_the_license_screen_the_client_names_is_not_told_what_it_shows(): void
    {
        add_filter('gratora.license.manage_url', static fn (): string => admin_url('admin.php?page=gratora-pro-license'));

        $this->assertSame('', $this->renderedOn('gratora-pro-license'));

        $elsewhere = $this->renderedOn('gratora-donations');
        $this->assertStringContainsString('Manage licenses', $elsewhere);
        $this->assertStringContainsString('page=gratora-pro-license', $elsewhere);
    }

    /** With no key stored nothing is ever checked, and that is not a pass. */
    public function test_add_ons_with_no_license_key_are_announced(): void
    {
        remove_all_filters('gratora.pro.product_status');

        $this->assertSame('', $this->renderedOn('wc-settings'));
        $this->assertStringContainsString('not linked to a license key', $this->renderedOn('gratora-campaigns'));
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
