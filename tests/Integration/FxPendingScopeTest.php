<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Currency\FxBackfill;
use Dono\Donations\Donation;
use Dono\Foundation\Helpers\Money;
use WP_REST_Request;

/**
 * "Donations missing from your totals" is a claim about the totals, and the
 * totals are donationsOnly() rows in a paid state. Counting every row with a
 * null base amount counts abandoned checkouts, failed attempts, test-mode data
 * and ticket orders, none of which any total was going to include, so a healthy
 * site is told real money is stranded and an admin goes looking for it.
 */
final class FxPendingScopeTest extends IntegrationTestCase
{
    private function donation(array $attrs): Donation
    {
        $d = Donation::make();
        $d->reference         = 'REF-' . uniqid();
        $d->status            = 'paid';
        $d->gateway           = 'offline';
        $d->kind              = 'donation';
        $d->amount_cents      = 1000;
        $d->currency          = 'BGN';
        $d->base_amount_cents = null;
        $d->base_currency     = null;
        $d->fx_rate           = null;
        $d->is_test           = false;
        $d->created_at        = gmdate('Y-m-d H:i:s');

        foreach ($attrs as $key => $value) {
            $d->{$key} = $value;
        }
        $d->save();

        return $d;
    }

    /** @return array<string,array{currency:string,count:int,amount_cents:int,needs_rate:bool}> */
    private function byCurrency(): array
    {
        $out = [];
        foreach (FxBackfill::pending() as $row) {
            $out[$row['currency']] = $row;
        }

        return $out;
    }

    public function test_an_abandoned_checkout_is_not_missing_money(): void
    {
        $this->donation(['status' => 'pending']);

        $this->assertSame([], FxBackfill::pending(), 'nothing was taken, so nothing is missing');
    }

    public function test_a_failed_donation_is_not_missing_money(): void
    {
        $this->donation(['status' => 'failed']);

        $this->assertSame([], FxBackfill::pending());
    }

    public function test_test_mode_rows_are_not_missing_money(): void
    {
        $this->donation(['is_test' => true]);

        $this->assertSame([], FxBackfill::pending(), 'no total counts test rows either way');
    }

    public function test_a_ticket_order_is_not_a_donation(): void
    {
        $this->donation(['kind' => 'order']);

        $this->assertSame([], FxBackfill::pending());
    }

    public function test_a_paid_donation_is_still_reported(): void
    {
        $this->donation(['amount_cents' => 50000]);
        $this->donation(['amount_cents' => 2500, 'status' => 'partial_refund']);
        // Noise that no total counts, mixed into the same currency.
        $this->donation(['amount_cents' => 999999, 'status' => 'pending']);
        $this->donation(['amount_cents' => 888888, 'is_test' => true]);

        $rows = $this->byCurrency();

        $this->assertCount(1, $rows);
        $this->assertSame(2, $rows['BGN']['count']);
        $this->assertSame(52500, $rows['BGN']['amount_cents'], 'the figure is what the totals are short by');
    }

    public function test_a_base_currency_row_is_not_waiting_on_a_rate(): void
    {
        $base = strtoupper(Money::defaultCurrency());
        $this->donation(['currency' => $base, 'amount_cents' => 4000]);
        $this->donation(['currency' => 'BGN', 'amount_cents' => 3000]);

        $rows = $this->byCurrency();

        $this->assertFalse($rows[$base]['needs_rate'], 'a currency is worth one of itself; there is no rate to add');
        $this->assertTrue($rows['BGN']['needs_rate']);
    }

    public function test_the_tools_screen_serves_the_same_scope(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $this->donation(['status' => 'pending', 'amount_cents' => 777777]);

        $data = (array) rest_do_request(new WP_REST_Request('GET', '/dono/v1/admin/tools/info'))->get_data();

        $this->assertSame([], $data['unconverted_donations'], 'the card must not alarm over an abandoned checkout');
    }
}
