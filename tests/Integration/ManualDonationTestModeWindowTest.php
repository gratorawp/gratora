<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donations\Donation;
use WP_REST_Request;

/**
 * H3 from the manual-donations review.
 *
 * record() used to clear is_test after createPending() returned, but createPending()
 * fires dono.donation.creating inside its own transaction, so every listener on
 * that hook saw the flag still set. Gift Aid listens there and skips a claim
 * snapshot for a test donation. The row then persists is_test = 0, so it counts
 * as real money in every total while sitting permanently outside the Gift Aid
 * claim: 25% of the donation, gone, discovered at claim time.
 *
 * The existing test_real_money_is_recorded_even_while_the_site_is_in_test_mode
 * asserts the persisted column and passes, because by the time it looks the
 * flag has been corrected. The damage happened earlier, which is why this test
 * watches the hook rather than the row.
 *
 * FIXED: is_test is now resolved before the insert, via a
 * DonationIntent field record() sets to false, so no listener ever sees a
 * hand-recorded check as a test donation.
 */
final class ManualDonationTestModeWindowTest extends IntegrationTestCase
{
    public function test_no_listener_ever_sees_a_recorded_cheque_as_a_test_donation(): void
    {
        update_option('dono_gateway_config', array_merge(
            (array) get_option('dono_gateway_config', []),
            ['test_mode' => true]
        ));

        $seen = [];
        add_action('dono.donation.creating', static function ($donation) use (&$seen): void {
            $seen[] = (bool) $donation->is_test;
        }, 1);

        $request = new WP_REST_Request('POST', '/dono/v1/admin/donations');
        $request->set_header('content-type', 'application/json');
        $request->set_body((string) wp_json_encode([
            'email'          => 'margit@example.com',
            'first_name'     => 'Margit',
            'last_name'      => 'Halvorsen',
            'amount_cents'   => 25000,
            'currency'       => 'USD',
            'payment_method' => 'cheque',
            'received_at'    => '2026-06-14',
        ]));

        $reference = (string) rest_do_request($request)->get_data()['reference'];

        $this->assertNotSame([], $seen, 'the hook never fired, so this proves nothing');
        $this->assertSame(
            [false],
            $seen,
            'a listener saw the recorded check as a test donation, which is where Gift Aid decides'
        );

        // The already-passing half, kept together so a fix cannot satisfy one
        // and quietly break the other.
        $this->assertFalse((bool) Donation::query()->find('reference', $reference)->is_test);
    }
}
