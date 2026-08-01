<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donations\Donation;
use WP_REST_Request;

/**
 * The donations list is live-only unless asked otherwise, which is right, but
 * it excluded test rows in total silence. An admin who donates while the org is
 * in test mode watched their donation vanish: the row exists, correctly flagged
 * and correctly pending for an offline gateway, and nothing on the screen said
 * where it had gone.
 */
final class AdminDonationsTestHiddenTest extends IntegrationTestCase
{
    private function makeDonation(bool $isTest, string $status = 'paid'): Donation
    {
        $d = Donation::make();
        $d->reference    = 'REF-' . uniqid();
        $d->status       = $status;
        $d->gateway      = 'offline';
        $d->kind         = 'donation';
        $d->amount_cents = 1000;
        $d->currency     = 'EUR';
        $d->is_test      = $isTest;
        $d->created_at   = gmdate('Y-m-d H:i:s');
        $d->save();

        return $d;
    }

    private function request(array $params = []): \WP_REST_Response
    {
        $req = new WP_REST_Request('GET', '/dono/v1/admin/donations');
        $req->set_query_params(array_merge(['page' => 1, 'per_page' => 25], $params));

        return rest_do_request($req);
    }

    public function test_the_default_view_reports_how_many_test_rows_it_is_hiding(): void
    {
        $this->makeDonation(false);
        $this->makeDonation(true);
        $this->makeDonation(true);

        $res = $this->request();

        $this->assertSame('2', $res->get_headers()['X-Dono-Test-Hidden'] ?? null);
    }

    public function test_it_says_nothing_when_there_are_no_test_rows(): void
    {
        $this->makeDonation(false);

        $res = $this->request();

        $this->assertSame('0', $res->get_headers()['X-Dono-Test-Hidden'] ?? null);
    }

    public function test_asking_for_test_rows_needs_no_notice(): void
    {
        $this->makeDonation(true);

        $res = $this->request(['is_test' => 1]);

        $this->assertArrayNotHasKey('X-Dono-Test-Hidden', $res->get_headers());
    }

    public function test_the_count_follows_the_filters_in_use(): void
    {
        // Two test rows, but only one is pending: an admin filtered to pending
        // should be told about that one, not about both.
        $this->makeDonation(true, 'pending');
        $this->makeDonation(true, 'paid');

        $res = $this->request(['status' => 'pending']);

        $this->assertSame('1', $res->get_headers()['X-Dono-Test-Hidden'] ?? null);
    }

    public function test_an_offline_test_donation_is_reachable_once_asked_for(): void
    {
        // Exactly the reported case: anonymous, offline, test mode on.
        $d = $this->makeDonation(true, 'pending');

        $refs = array_column((array) $this->request(['is_test' => 1])->get_data(), 'reference');

        $this->assertContains($d->reference, $refs);
    }
}
