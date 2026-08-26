<?php

declare(strict_types=1);

namespace GiveFlow\Tests\Integration;

use GiveFlow\Donations\Donation;
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
        $res = rest_do_request(new WP_REST_Request('GET', '/giveflow/v1/admin/currency/fx'));

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
        update_option('giveflow_currency_locale', [
            'default_currency'     => 'USD',
            'supported_currencies' => ['USD', 'EUR', 'BGN'],
        ]);

        $this->assertContains('BGN', $this->state()['unconvertible']);
    }

    /**
     * Tools tells the operator to add a rate on this screen for a currency that
     * was recorded without one, and a currency donations arrived in is not
     * necessarily one the org still accepts: a CSV import takes the code
     * straight from the file, and a currency can be de-listed after the fact.
     * With no row there is no input, so the instruction has nowhere to be
     * carried out.
     */
    public function test_a_currency_donations_were_taken_in_gets_a_row_to_type_a_rate_into(): void
    {
        update_option('giveflow_currency_locale', [
            'default_currency'     => 'USD',
            'supported_currencies' => ['USD'],
        ]);

        $this->strandedDonation('BGN');

        $codes = array_column($this->state()['rows'], 'code');

        $this->assertContains('BGN', $codes, 'Tools sends the operator to a screen that cannot show the currency');
        $this->assertSame('USD', $codes[0], 'the base currency is still first');
    }

    /** A site that only ever took its own currency keeps its single-row table. */
    public function test_a_site_with_nothing_stranded_gains_no_rows(): void
    {
        update_option('giveflow_currency_locale', [
            'default_currency'     => 'USD',
            'supported_currencies' => ['USD'],
        ]);

        $this->assertSame(['USD'], array_column($this->state()['rows'], 'code'));
    }

    /** Recorded in its own currency with no base amount, which is what strands it. */
    private function strandedDonation(string $currency): void
    {
        $d = Donation::make();
        $d->reference         = 'FX-' . uniqid();
        $d->status            = 'paid';
        $d->gateway           = 'offline';
        $d->kind              = 'donation';
        $d->amount_cents      = 1000;
        $d->currency          = $currency;
        $d->base_amount_cents = null;
        $d->base_currency     = null;
        $d->fx_rate           = null;
        $d->is_test           = false;
        $d->created_at        = gmdate('Y-m-d H:i:s');
        $d->save();
    }

    public function test_a_healthy_currency_is_not_named(): void
    {
        $state = $this->state();

        $this->assertNotContains('USD', $state['unconvertible']);
        $this->assertNotContains('USD', $state['no_gateway']);
    }
}
