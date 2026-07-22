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

    public function test_state_survives_the_unauthenticated_callback(): void
    {
        wp_set_current_user(1); // an admin initiates the connect
        $url = $this->authorizeUrl();
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        $state = (string) ($q['state'] ?? '');
        $this->assertNotSame('', $state);

        // The broker redirects the browser to a PUBLIC callback where no user is
        // authenticated (id 0). The state transient must still be findable, so
        // it can't be keyed by the current user.
        wp_set_current_user(0);
        $this->assertNotFalse(
            get_transient('dono_stripe_oauth_state_' . hash('sha256', $state)),
            'state transient is keyed by the state, not the current user'
        );
    }
}
