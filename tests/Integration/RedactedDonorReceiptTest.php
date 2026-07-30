<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donors\Donor;
use Dono\Donors\DonorService;
use Dono\Foundation\Plugin;
use Dono\Receipts\Receipt;
use WP_REST_Request;

/**
 * Erasure removes the address, so a receipt cannot be re-sent. The endpoint has
 * to say so rather than report one on its way, and it must not clear the
 * sent_to_email_at stamp that records a send which really happened.
 */
final class RedactedDonorReceiptTest extends IntegrationTestCase
{
    public function test_resend_is_refused_for_an_erased_donor(): void
    {
        $reference = $this->paidDonation('erased-receipt@example.test');
        $this->redactDonorFor($reference);

        $res = rest_do_request(
            new WP_REST_Request('POST', "/dono/v1/admin/donations/{$reference}/resend-receipt")
        );

        $this->assertSame(422, $res->get_status(), 'the API refuses instead of reporting a send');
        $this->assertSame('dono_donor_redacted', $res->as_error()->get_error_code());
    }

    public function test_a_refused_resend_leaves_the_sent_stamp_intact(): void
    {
        $reference = $this->paidDonation('erased-stamp@example.test');
        $donation  = \Dono\Donations\Donation::query()->find('reference', $reference);

        // A receipt that really was emailed at the time. Issuance is async, so
        // stand one up directly rather than waiting on the queue.
        $receipt = Receipt::make();
        $receipt->donation_id       = (int) $donation->id;
        $receipt->donor_id          = (int) $donation->donor_id;
        $receipt->receipt_number    = 'RCPT-REDACT-1';
        $receipt->renderer_id       = 'classic';
        $receipt->issued_at         = '2026-07-01 09:00:00';
        $receipt->sent_to_email_at  = '2026-07-01 09:00:00';
        $receipt->save();

        $this->redactDonorFor($reference);

        rest_do_request(new WP_REST_Request('POST', "/dono/v1/admin/donations/{$reference}/resend-receipt"));

        $fresh = Receipt::query()->find('id', (int) $receipt->id);
        $this->assertSame(
            '2026-07-01 09:00:00',
            (string) $fresh->sent_to_email_at,
            'the record of a real send survives a refused resend'
        );
    }

    public function test_the_detail_payload_marks_the_donor_erased(): void
    {
        $reference = $this->paidDonation('erased-flag@example.test');
        $this->redactDonorFor($reference);

        $res = rest_do_request(new WP_REST_Request('GET', "/dono/v1/admin/donations/{$reference}"));
        $this->assertSame(200, $res->get_status());

        $donor = (array) $res->get_data()['donor'];
        $this->assertTrue($donor['redacted'], 'the UI can hide the resend button without guessing');
        $this->assertNull($donor['email'], 'and no address comes back');
    }

    private function redactDonorFor(string $reference): void
    {
        $donation = \Dono\Donations\Donation::query()->find('reference', $reference);
        $donor    = Donor::query()->find('id', (int) $donation->donor_id);
        Plugin::instance()->container->get(DonorService::class)->redact($donor);
    }

    private function paidDonation(string $email): string
    {
        $create = new WP_REST_Request('POST', '/dono/v1/donations');
        $create->set_header('content-type', 'application/json');
        $create->set_body((string) wp_json_encode([
            'email'        => $email,
            'amount_cents' => 2500,
            'currency'     => 'USD',
            'gateway'      => 'offline',
            'profile'      => ['first_name' => 'Gone', 'last_name' => 'Donor'],
        ]));
        $reference = (string) rest_do_request($create)->get_data()['reference'];

        $confirm = new WP_REST_Request('POST', "/dono/v1/donations/{$reference}/confirm");
        $confirm->set_header('content-type', 'application/json');
        $confirm->set_body('{}');
        rest_do_request($confirm);

        return $reference;
    }
}
