<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Currency\FxRates;
use Dono\Foundation\Helpers\Money;
use Dono\Foundation\Plugin;
use WP_REST_Request;

/**
 * A currency the org offers but the FX feed does not carry.
 *
 * The QA sweep took a real BGN 500.00 donation through the public route: it
 * stored correctly, then counted as zero in every base-currency total. The
 * campaign showed 22 donations raising exactly what 21 raised, and the donor
 * showed 1 donation totalling 0. Nothing validated the currency and nothing
 * warned.
 *
 * Money is still never gated on reporting being configured, so the fix is
 * visibility at both ends: warn before the currency is offered, and report how
 * many rows a total is missing.
 */
final class UnconvertibleCurrencyTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    private function fx(): FxRates
    {
        return Plugin::instance()->container->get(FxRates::class);
    }

    public function test_a_currency_with_no_rate_is_named(): void
    {
        // ZZZ is not a real ISO code, so no feed will ever carry it.
        $missing = $this->fx()->unconvertible([Money::defaultCurrency(), 'ZZZ']);

        $this->assertSame(['ZZZ'], $missing, 'only the one with no rate');
    }

    public function test_the_base_currency_is_always_convertible(): void
    {
        $this->assertSame([], $this->fx()->unconvertible([Money::defaultCurrency()]));
    }

    public function test_blank_and_duplicate_codes_are_ignored(): void
    {
        $this->assertSame(['ZZZ'], $this->fx()->unconvertible(['ZZZ', 'zzz', '', '  ']));
    }

    /**
     * The admin has to be told before they offer the currency, not after a
     * report quietly under-reports.
     */
    public function test_the_fx_status_names_unconvertible_supported_currencies(): void
    {
        $cur = (array) get_option('dono_currency_locale', []);
        $cur['supported_currencies'] = [Money::defaultCurrency(), 'ZZZ'];
        update_option('dono_currency_locale', $cur);

        $data = (array) rest_do_request(new WP_REST_Request('GET', '/dono/v1/admin/currency/fx'))->get_data();

        $this->assertArrayHasKey('unconvertible', $data);
        $this->assertContains('ZZZ', $data['unconvertible']);
        $this->assertNotContains(Money::defaultCurrency(), $data['unconvertible']);
    }

    public function test_a_fully_convertible_setup_reports_nothing_missing(): void
    {
        $cur = (array) get_option('dono_currency_locale', []);
        $cur['supported_currencies'] = [Money::defaultCurrency()];
        update_option('dono_currency_locale', $cur);

        $data = (array) rest_do_request(new WP_REST_Request('GET', '/dono/v1/admin/currency/fx'))->get_data();

        $this->assertSame([], $data['unconvertible']);
    }
}
