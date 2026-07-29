<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donations\Donation;
use Dono\Donations\DonationRepository;
use Dono\Donations\DonationService;
use Dono\Foundation\Plugin;
use RuntimeException;
use WP_REST_Request;

/**
 * The state between authorised and settled.
 *
 * Card money moves in seconds, so a card donation is either paid or it is not.
 * Bank debit does not work that way: SEPA through Stripe and Direct Debit
 * through GoCardless both authorise now and settle days later, and can still
 * fail in between. Calling that `pending` puts it in the same bucket as a donor
 * who closed the tab, which is wrong in both directions. An admin cannot tell
 * expected income from abandoned checkouts, and the donor is told their
 * donation is "still processing" for a week when nothing is wrong with it.
 *
 * `processing` means: the donor has done everything, the money is on its way,
 * and it has not arrived.
 */
final class DonationProcessingTest extends IntegrationTestCase
{
    private function service(): DonationService
    {
        return Plugin::instance()->container->get(DonationService::class);
    }

    private function reload(string $reference): Donation
    {
        return Plugin::instance()->container->get(DonationRepository::class)->findByReference($reference);
    }

    private function driveOfflineDonation(): Donation
    {
        $request = new WP_REST_Request('POST', '/dono/v1/donations');
        $request->set_header('content-type', 'application/json');
        $request->set_body((string) wp_json_encode([
            'email'        => 'sarah@example.com',
            'amount_cents' => 5000,
            'currency'     => 'EUR',
            'gateway'      => 'offline',
            'profile'      => ['first_name' => 'Sarah', 'last_name' => 'Muller'],
        ]));

        return $this->reload((string) rest_do_request($request)->get_data()['reference']);
    }

    public function test_a_pending_donation_can_move_to_processing(): void
    {
        $donation = $this->driveOfflineDonation();

        $this->service()->markProcessing($donation, 'bank_debit_submitted');

        $this->assertSame('processing', $this->reload((string) $donation->reference)->status);
    }

    /** The reason is what an admin reads to know why it is sitting there. */
    public function test_the_reason_is_kept_where_an_admin_can_read_it(): void
    {
        $donation = $this->driveOfflineDonation();

        $this->service()->markProcessing($donation, 'bank_debit_submitted', ['charge_date' => '2026-08-05']);

        $meta = (array) ($this->reload((string) $donation->reference)->gateway_metadata ?? []);
        $this->assertSame('bank_debit_submitted', $meta['processing_reason'] ?? null);
        $this->assertSame('2026-08-05', $meta['charge_date'] ?? null);
    }

    /**
     * Money that has not arrived is not income. Whatever else changes, a
     * processing donation must never be counted as paid.
     */
    public function test_processing_is_not_paid(): void
    {
        $donation = $this->driveOfflineDonation();

        $this->service()->markProcessing($donation, 'bank_debit_submitted');
        $row = $this->reload((string) $donation->reference);

        $this->assertNull($row->paid_at);
        $this->assertNotSame('paid', $row->status);
    }

    /** Settlement is the whole point: the money lands, days later. */
    public function test_a_processing_donation_can_still_be_confirmed(): void
    {
        $donation = $this->driveOfflineDonation();
        $this->service()->markProcessing($donation, 'bank_debit_submitted');

        $this->service()->confirm($this->reload((string) $donation->reference), ['gateway_txn_id' => 'PM123']);

        $row = $this->reload((string) $donation->reference);
        $this->assertSame('paid', $row->status);
        $this->assertSame('PM123', $row->gateway_txn_id);
        $this->assertNotNull($row->paid_at);
    }

    /**
     * A bank debit can bounce after it was submitted. That is the whole reason
     * this state exists rather than marking it paid optimistically.
     */
    public function test_a_processing_donation_can_still_fail(): void
    {
        $donation = $this->driveOfflineDonation();
        $this->service()->markProcessing($donation, 'bank_debit_submitted');

        $this->service()->markFailed($this->reload((string) $donation->reference), 'insufficient_funds');

        $row = $this->reload((string) $donation->reference);
        $this->assertSame('failed', $row->status);
        $this->assertSame('insufficient_funds', $row->failure_reason);
    }

    /** Settled money is settled. A late webhook must not undo it. */
    public function test_a_paid_donation_cannot_be_walked_back_to_processing(): void
    {
        $donation = $this->driveOfflineDonation();
        $this->service()->confirm($donation, ['gateway_txn_id' => 'PM123']);

        $this->service()->markProcessing($this->reload((string) $donation->reference), 'bank_debit_submitted');

        $this->assertSame('paid', $this->reload((string) $donation->reference)->status);
    }

    public function test_a_failed_donation_is_not_moved_to_processing(): void
    {
        $donation = $this->driveOfflineDonation();
        $this->service()->markFailed($donation, 'declined');

        $this->service()->markProcessing($this->reload((string) $donation->reference), 'bank_debit_submitted');

        $this->assertSame('failed', $this->reload((string) $donation->reference)->status);
    }

    /**
     * Webhooks are redelivered. A second identical notification must not fire
     * the event again, or the donor gets a second email about the same debit.
     */
    public function test_a_redelivered_notification_does_not_fire_twice(): void
    {
        $donation = $this->driveOfflineDonation();

        $fired = 0;
        add_action('dono.donation.processing', static function () use (&$fired): void { $fired++; });

        $this->service()->markProcessing($donation, 'bank_debit_submitted');
        $this->service()->markProcessing($this->reload((string) $donation->reference), 'bank_debit_submitted');

        $this->assertSame(1, $fired);
    }

    public function test_moving_to_processing_announces_it(): void
    {
        $donation = $this->driveOfflineDonation();

        $seen = null;
        add_action('dono.donation.processing', static function ($d, $reason) use (&$seen): void {
            $seen = $reason;
        }, 10, 2);

        $this->service()->markProcessing($donation, 'bank_debit_submitted');

        $this->assertSame('bank_debit_submitted', $seen);
    }

    /**
     * Refunds move money back out of the merchant's account. There is nothing
     * to move back until it has arrived.
     */
    public function test_a_processing_donation_cannot_be_refunded(): void
    {
        $donation = $this->driveOfflineDonation();
        $this->service()->markProcessing($donation, 'bank_debit_submitted');

        try {
            $this->service()->refund($this->reload((string) $donation->reference), 1000);
            $this->fail('A donation that has not settled must not be refundable.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('processing', $e->getMessage());
        }

        $this->assertSame('processing', $this->reload((string) $donation->reference)->status);
    }
}
