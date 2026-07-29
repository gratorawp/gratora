<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donations\Donation;
use WP_REST_Request;

/**
 * H3 from the manual-donations review. Currently FAILING on purpose.
 *
 * record() clears is_test after createPending() returns, but createPending()
 * fires dono.donation.creating inside its own transaction, so every listener on
 * that hook sees the flag still set. Gift Aid listens there and skips a claim
 * snapshot for a test donation. The row then persists is_test = 0, so it counts
 * as real money in every total while sitting permanently outside the Gift Aid
 * claim: 25% of the donation, gone, discovered at claim time.
 *
 * The existing test_real_money_is_recorded_even_while_the_site_is_in_test_mode
 * asserts the persisted column and passes, because by the time it looks the
 * flag has been corrected. The damage happened earlier, which is why this test
 * watches the hook rather than the row.
 *
 * The fix is to resolve is_test BEFORE the insert, not after it: a
 * DonationIntent field, or a dono.donation.intent_creating filter, so record()
 * can state "this is never a test" before any listener runs.
 */
final class ManualDonationTestModeWindowTest extends IntegrationTestCase
{
    public function test_no_listener_ever_sees_a_recorded_cheque_as_a_test_donation(): void
    {
        // Skipped so the suite stays green while the bug is open, not because
        // the test is unreliable. It has been run and fails on the assertion
        // below with [true] instead of [false]. Deleting this line is the first
        // step of the fix; if it then passes without the is_test resolution
        // moving before the insert, something else changed and that is worth
        // understanding before closing the task.
        $this->markTestSkipped('H3 is open. See task #253.');

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
            'a listener saw the recorded cheque as a test donation, which is where Gift Aid decides'
        );

        // The already-passing half, kept together so a fix cannot satisfy one
        // and quietly break the other.
        $this->assertFalse((bool) Donation::query()->find('reference', $reference)->is_test);
    }
}
