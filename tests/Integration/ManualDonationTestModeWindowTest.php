<?php

declare(strict_types=1);

namespace FundKit\Tests\Integration;

use FundKit\Donations\Donation;
use WP_REST_Request;

/**
 * Assert is_test inside fundkit.donation.creating, before listeners create donation-dependent
 * records.
 */
final class ManualDonationTestModeWindowTest extends IntegrationTestCase
{
    public function test_no_listener_ever_sees_a_recorded_cheque_as_a_test_donation(): void
    {
        update_option('fundkit_gateway_config', array_merge(
            (array) get_option('fundkit_gateway_config', []),
            ['test_mode' => true]
        ));

        $seen = [];
        add_action('fundkit.donation.creating', static function ($donation) use (&$seen): void {
            $seen[] = (bool) $donation->is_test;
        }, 1);

        $request = new WP_REST_Request('POST', '/fundkit/v1/admin/donations');
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
