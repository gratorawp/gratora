<?php

declare(strict_types=1);

namespace FundKit\Tests\Integration;

use FundKit\Donations\Donation;
use WP_REST_Request;

/**
 * A currency with no exchange rate leaves its donations out of every total.
 * Reported inside the counts bag, the screen read it back as a success line:
 * "Still_unconvertible: 2 synced", about rows nothing had synced.
 */
final class RecalculateUnconvertibleTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        delete_option('fundkit_recalculate_cursor');

        update_option('fundkit_currency_locale', [
            'default_currency'     => 'USD',
            'supported_currencies' => ['USD', 'JPY'],
        ]);
        // No rate for JPY, which is what makes those donations unconvertible.
        update_option('fundkit_fx_rates', ['base' => 'USD', 'rates' => []]);
    }

    protected function tearDown(): void
    {
        delete_option('fundkit_recalculate_cursor');
        parent::tearDown();
    }

    private function strandedDonation(): void
    {
        $now = gmdate('Y-m-d H:i:s');

        $d = Donation::make();
        $d->donor_id          = 1;
        $d->reference         = 'UNC-' . bin2hex(random_bytes(4));
        $d->amount_cents      = 100000;
        $d->base_amount_cents = null;
        $d->currency          = 'JPY';
        $d->status            = 'paid';
        $d->gateway           = 'offline';
        $d->is_test           = false;
        $d->paid_at           = $now;
        $d->created_at        = $now;
        $d->updated_at        = $now;
        $d->save();
    }

    /** @return array<string,mixed> */
    private function recalculate(): array
    {
        $req = new WP_REST_Request('POST', '/fundkit/v1/admin/tools/recalculate');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode(['scope' => 'all']));

        return (array) rest_do_request($req)->get_data();
    }

    public function test_what_could_not_be_converted_is_not_reported_as_synced(): void
    {
        $this->strandedDonation();

        $res = $this->recalculate();

        $this->assertArrayNotHasKey(
            'still_unconvertible',
            $res['counts'],
            'counts are things that were synced; this is the opposite'
        );
        $this->assertSame(1, $res['still_unconvertible']);
        $this->assertSame(['JPY'], $res['unconvertible_currencies']);
    }

    public function test_every_stranded_donation_is_counted_not_every_currency(): void
    {
        $this->strandedDonation();
        $this->strandedDonation();
        $this->strandedDonation();

        $res = $this->recalculate();

        $this->assertSame(3, $res['still_unconvertible']);
        $this->assertSame(['JPY'], $res['unconvertible_currencies'], 'and the currencies are still named once each');
    }

    public function test_a_site_with_nothing_stranded_says_so(): void
    {
        $res = $this->recalculate();

        $this->assertSame(0, $res['still_unconvertible']);
        $this->assertSame([], $res['unconvertible_currencies']);
    }
}
