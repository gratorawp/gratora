<?php

declare(strict_types=1);

namespace FundKit\Tests\Integration;

use FundKit\Donors\Portal\PortalPage;
use FundKit\Forms\FormReadinessService;
use FundKit\Forms\FormRepository;
use FundKit\Foundation\Container\Container;
use FundKit\Foundation\Crypto\Crypto;
use FundKit\Foundation\License\LicenseService;
use FundKit\Foundation\Modules\FundKitModule;
use FundKit\Foundation\Modules\ModuleManager;
use FundKit\Gateways\GatewayManager;
use FundKit\Gateways\PayPal\PayPalAccount;
use FundKit\Gateways\Stripe\ApplePayDomain;
use FundKit\Gateways\Stripe\StripeAccount;
use FundKit\Gateways\Stripe\StripeApi;
use FundKit\Gateways\TestMode;
use FundKit\Settings\ReadinessService;
use FundKit\Settings\SettingsService;

/**
 * The licences row on Setup sent the operator to a settings tab that does not
 * exist: the Settings body has no panel keyed 'licenses', so the click emptied
 * the page. Licence keys belong to the licensing client vendored into each Pro
 * add-on, which owns its own admin page, so the row asks the client where to
 * go and offers nothing when nothing answers.
 */
final class LicenseRowActionTest extends IntegrationTestCase
{
    private function service(): ReadinessService
    {
        $settings = new SettingsService();
        $crypto   = new Crypto();
        $stripe   = new StripeAccount($crypto);
        $api      = new StripeApi($stripe);

        $modules = new ModuleManager(new Container());
        $modules->register($this->proModule('fundkit-p2p', 'Peer to peer'));
        $modules->bootAll();

        return new ReadinessService(
            $settings,
            new FormReadinessService($settings, new GatewayManager(), $stripe, new TestMode(new FormRepository()), \FundKit\Foundation\Plugin::instance()->container->get(\FundKit\Donors\ConsentService::class)),
            $stripe,
            $api,
            new ApplePayDomain($api, $stripe),
            new PayPalAccount($crypto),
            new GatewayManager(),
            new PortalPage(),
            new LicenseService($modules),
        );
    }

    /** @return array<string,mixed> */
    private function licenseRow(): array
    {
        foreach ($this->service()->check() as $row) {
            if ($row['id'] === 'licenses') {
                return $row;
            }
        }

        $this->fail('an installed paid add-on should produce a licences row');
    }

    public function test_the_row_offers_no_link_when_nothing_can_manage_licences(): void
    {
        $row = $this->licenseRow();

        $this->assertSame('Your add-ons are not linked to a license key', $row['label']);
        $this->assertArrayNotHasKey('action_url', $row, 'core has no licences page to send anyone to');
        $this->assertArrayNotHasKey('action_label', $row);
    }

    public function test_the_row_links_where_the_licensing_client_says(): void
    {
        add_filter('fundkit.license.manage_url', static fn (): string => admin_url('admin.php?page=fundkit-licenses'));

        $row = $this->licenseRow();

        $this->assertSame(admin_url('admin.php?page=fundkit-licenses'), $row['action_url']);
        $this->assertSame('Add a key', $row['action_label']);
    }

    private function proModule(string $id, string $name): FundKitModule
    {
        return new class($id, $name) implements FundKitModule {
            public function __construct(private string $id, private string $name)
            {
            }

            public function id(): string
            {
                return $this->id;
            }

            public function name(): string
            {
                return $this->name;
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
                return FundKitModule::TIER_PRO;
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
