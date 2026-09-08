<?php

declare(strict_types=1);

namespace FundKit\Tests\Integration;

use FundKit\Donations\Donation;
use WP_REST_Request;

/**
 * A recorded donation is backdated to the day the money arrived, so it lands in
 * the right month's totals. The time of day is not something anybody wrote
 * down, and standing noon in for it put a donation recorded this morning after
 * its own receipt in the history, which reads as though the receipt came first.
 */
final class RecordedDonationHistoryOrderTest extends IntegrationTestCase
{
    private function record(string $receivedAt): Donation
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        $req = new WP_REST_Request('POST', '/fundkit/v1/admin/donations');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) json_encode([
            'email'          => 'cash-' . uniqid() . '@example.test',
            'first_name'     => 'Sam',
            'last_name'      => 'Reilly',
            'amount_cents'   => 4000,
            'currency'       => 'USD',
            'payment_method' => 'cash',
            'received_at'    => $receivedAt,
            'send_receipt'   => false,
        ]));

        $res = rest_do_request($req);
        $this->assertSame(201, $res->get_status(), (string) wp_json_encode($res->get_data()));

        $donation = Donation::query()->where('reference', (string) $res->get_data()['reference'])->get();
        $this->assertNotNull($donation);

        return $donation;
    }

    /**
     * Money handed over this morning and entered this morning is stamped now,
     * so everything the recording sets off comes after it.
     */
    public function test_a_donation_recorded_today_is_not_stamped_in_its_own_future(): void
    {
        $donation = $this->record(current_time('Y-m-d'));

        $this->assertLessThanOrEqual(
            strtotime((string) current_time('mysql', true)) + 5,
            strtotime((string) $donation->created_at),
            'a donation recorded today is dated later than the moment it was recorded'
        );
    }

    public function test_a_donation_received_earlier_still_lands_on_that_day(): void
    {
        $when     = gmdate('Y-m-d', strtotime('-9 days'));
        $donation = $this->record($when);

        $this->assertSame($when, substr((string) $donation->created_at, 0, 10));
        $this->assertSame($when, substr((string) $donation->paid_at, 0, 10));
    }

    /**
     * The ordering this exists to protect: whatever the recording sets off has
     * to sort after the donation itself, or the history tells the story
     * backwards.
     */
    public function test_what_the_recording_sets_off_comes_after_it(): void
    {
        $donation = $this->record(current_time('Y-m-d'));

        $req = new WP_REST_Request('POST', '/fundkit/v1/admin/donations/' . $donation->reference . '/notes');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) json_encode(['body' => 'Received as cash at the door.']));
        $res = rest_do_request($req);

        $this->assertContains($res->get_status(), [200, 201], (string) wp_json_encode($res->get_data()));

        $note = (array) $res->get_data();
        $this->assertGreaterThanOrEqual(
            strtotime((string) $donation->created_at),
            strtotime((string) ($note['created_at'] ?? $note['note']['created_at'] ?? 'now')),
            'the note is dated before the donation it was added to'
        );
    }
}
