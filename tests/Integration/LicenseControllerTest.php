<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Foundation\Container\Container;
use Dono\Foundation\License\LicenseService;
use Dono\Foundation\Modules\DonoModule;
use Dono\Foundation\Modules\ModuleManager;
use Dono\Rest\Admin\LicenseController;
use WP_REST_Request;

/**
 * The screen has to tell "nobody checked this key" apart from "the server
 * refused it", or a rejected key looks activated.
 */
final class LicenseControllerTest extends IntegrationTestCase
{
    private const OPTION = 'dono_pro_license_key';

    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        delete_option(self::OPTION);
    }

    protected function tearDown(): void
    {
        delete_option(self::OPTION);
        remove_all_filters('dono.pro.product_status');
        parent::tearDown();
    }

    /** A booted Pro module, so the payload has an add-on to report on. */
    private function controllerWithAddon(): LicenseController
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

        return new LicenseController(new LicenseService($modules));
    }

    /** @return array<string,mixed> */
    private function payloadWithAddon(): array
    {
        return (array) $this->controllerWithAddon()->show()->get_data();
    }

    private function get(string $route, string $method = 'GET', array $body = []): array
    {
        $request = new WP_REST_Request($method, '/dono/v1' . $route);
        foreach ($body as $k => $v) {
            $request->set_param($k, $v);
        }

        return (array) rest_get_server()->dispatch($request)->get_data();
    }

    public function test_an_empty_key_is_refused_rather_than_stored(): void
    {
        $data = $this->get('/admin/license', 'POST', ['key' => '']);

        $this->assertSame('dono_license_empty', $data['code'] ?? null);
        $this->assertFalse(get_option(self::OPTION));
    }

    public function test_a_key_is_stored_and_reported_masked(): void
    {
        $this->get('/admin/license', 'POST', ['key' => 'dono-live-abcdefgh12345678']);

        $data = $this->get('/admin/license');
        $this->assertTrue($data['has_key']);
        $this->assertStringNotContainsString('12345678', $data['key_masked']);
    }

    /**
     * With no licensing client the status filter passes the default back, and
     * the screen must say so instead of claiming the add-on is licensed.
     */
    public function test_without_a_licensing_client_nothing_claims_to_be_checked(): void
    {
        $this->get('/admin/license', 'POST', ['key' => 'dono-live-abcdefgh12345678']);

        $data = $this->get('/admin/license');
        $this->assertFalse($data['checked']);
        foreach ($data['addons'] as $addon) {
            $this->assertSame('unknown', $addon['status']);
            $this->assertFalse($addon['entitled']);
        }
    }

    public function test_a_revoked_product_is_reported_as_not_entitled(): void
    {
        add_filter('dono.pro.product_status', static fn (): string => 'revoked');

        $data = $this->payloadWithAddon();

        $this->assertNotEmpty($data['addons']);
        $this->assertTrue($data['checked']);
        $this->assertSame('revoked', $data['addons'][0]['status']);
        $this->assertFalse($data['addons'][0]['entitled']);
        $this->assertFalse($data['any_entitled']);
    }

    /** Expired and grace still run: only revoked drops entitlement. */
    public function test_an_expired_product_is_still_entitled(): void
    {
        add_filter('dono.pro.product_status', static fn (): string => 'expired');

        $data = $this->payloadWithAddon();

        $this->assertSame('expired', $data['addons'][0]['status']);
        $this->assertTrue($data['addons'][0]['entitled']);
        $this->assertTrue($data['any_entitled']);
    }

    /** No client means no verdict, which must not read as licensed. */
    public function test_an_unchecked_addon_is_not_entitled(): void
    {
        $data = $this->payloadWithAddon();

        $this->assertSame('unknown', $data['addons'][0]['status']);
        $this->assertFalse($data['addons'][0]['entitled']);
        $this->assertFalse($data['checked']);
    }

    public function test_recheck_asks_the_client_to_revalidate(): void
    {
        $fired = 0;
        add_action('dono_licensing_recheck', static function () use (&$fired): void {
            $fired++;
        });

        $this->get('/admin/license/recheck', 'POST');

        $this->assertSame(1, $fired);
    }

    public function test_deactivating_forgets_the_key(): void
    {
        $this->get('/admin/license', 'POST', ['key' => 'dono-live-abcdefgh12345678']);
        $this->get('/admin/license', 'DELETE');

        $this->assertFalse(get_option(self::OPTION));
        $this->assertFalse($this->get('/admin/license')['has_key']);
    }
}
