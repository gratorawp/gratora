<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Foundation\Plugin;
use Dono\Gateways\Stripe\StripeApi;
use Dono\Gateways\Stripe\StripeConnectAccount;
use Dono\Gateways\TestMode;
use Dono\Rest\Admin\StripeConnectController;

/**
 * The Connect authorize URL carries the mode the org is operating in, so a site
 * in test mode links a Stripe test account through the broker (not just the
 * dev-paste path) and a live site links live.
 */
final class StripeConnectAuthorizeModeTest extends IntegrationTestCase
{
    private function controller(): StripeConnectController
    {
        $c = Plugin::instance()->container;
        return new StripeConnectController(
            $c->get(StripeApi::class),
            $c->get(StripeConnectAccount::class),
            $c->get(TestMode::class),
        );
    }

    private function authorizeUrl(): string
    {
        return (string) ($this->controller()->authorize()->get_data()['url'] ?? '');
    }

    public function test_org_test_mode_authorizes_in_test(): void
    {
        update_option('dono_gateway_config', ['test_mode' => true]);

        $url = $this->authorizeUrl();
        $this->assertStringContainsString('mode=test', $url);
        $this->assertStringNotContainsString('mode=live', $url);
    }

    public function test_live_org_authorizes_in_live(): void
    {
        update_option('dono_gateway_config', ['test_mode' => false]);

        $url = $this->authorizeUrl();
        $this->assertStringContainsString('mode=live', $url);
        $this->assertStringNotContainsString('mode=test', $url);
    }
}
