<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use WP_REST_Request;

/**
 * The currency screen warns about currencies the org offers but cannot really
 * take: no exchange rate, or no gateway that charges them. Both are arrays the
 * panel renders only when non-empty, so a missing key disables the warning
 * silently and looks exactly like a healthy site.
 */
final class FxStateWarningsTest extends IntegrationTestCase
{
    private function state(): array
    {
        $res = rest_do_request(new WP_REST_Request('GET', '/dono/v1/admin/currency/fx'));

        return (array) $res->get_data();
    }

    public function test_the_state_reports_both_warning_sets(): void
    {
        $state = $this->state();

        $this->assertArrayHasKey('unconvertible', $state);
        $this->assertArrayHasKey('no_gateway', $state);
        $this->assertIsArray($state['unconvertible']);
        $this->assertIsArray($state['no_gateway']);
    }

    public function test_a_currency_with_no_rate_is_named(): void
    {
        // The suite configures USD base with rates for EUR and GBP only.
        update_option('dono_currency_locale', [
            'default_currency'     => 'USD',
            'supported_currencies' => ['USD', 'EUR', 'BGN'],
        ]);

        $this->assertContains('BGN', $this->state()['unconvertible']);
    }

    public function test_a_healthy_currency_is_not_named(): void
    {
        $state = $this->state();

        $this->assertNotContains('USD', $state['unconvertible']);
        $this->assertNotContains('USD', $state['no_gateway']);
    }
}
