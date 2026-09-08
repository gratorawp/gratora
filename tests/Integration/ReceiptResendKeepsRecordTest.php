<?php

declare(strict_types=1);

namespace FundKit\Tests\Integration;

use FundKit\Donations\Donation;
use FundKit\Foundation\Plugin;
use FundKit\Receipts\Receipt;
use FundKit\Receipts\ReceiptIssuer;
use FundKit\Settings\SettingsService;
use WP_REST_Request;

/**
 * sent_to_email_at was doing two jobs: the record of when the donor was
 * emailed, and the lock that keeps two runners from sending twice. A resend
 * released the lock by clearing the record, so a resend that then failed left
 * no trace of the send that really happened: the receipts card dropped the
 * "emailed" line and the donation started being reported as a donor who never
 * got their receipt.
 */
final class ReceiptResendKeepsRecordTest extends IntegrationTestCase
{
    private function paidDonation(): Donation
    {
        $create = new WP_REST_Request('POST', '/fundkit/v1/donations');
        $create->set_header('content-type', 'application/json');
        $create->set_body((string) wp_json_encode([
            'email'        => 'receipt-' . uniqid() . '@example.test',
            'amount_cents' => 5000,
            'currency'     => 'USD',
            'gateway'      => 'offline',
            'profile'      => ['first_name' => 'Ida', 'last_name' => 'Kerr'],
        ]));
        $reference = (string) rest_do_request($create)->get_data()['reference'];

        $confirm = new WP_REST_Request('POST', "/fundkit/v1/donations/{$reference}/confirm");
        $confirm->set_header('content-type', 'application/json');
        $confirm->set_body('{}');
        rest_do_request($confirm);

        $this->runPendingAsyncJobs();

        return Donation::query()->find('reference', $reference);
    }

    private function receiptFor(Donation $d): Receipt
    {
        $r = Receipt::query()->where('donation_id', (int) $d->id)->get();
        $this->assertNotNull($r, 'fixture: the donation was receipted');

        return $r;
    }

    private function disableReceiptEmail(): void
    {
        $settings = Plugin::instance()->container->get(SettingsService::class);
        $email    = (array) $settings->get('email');
        $templates = is_array($email['templates'] ?? null) ? $email['templates'] : [];
        $templates['donation_receipt']['enabled'] = false;
        $settings->update('email', ['templates' => $templates]);
    }

    public function test_a_resend_that_cannot_send_keeps_the_record_of_the_first_send(): void
    {
        $donation = $this->paidDonation();
        $first    = (string) $this->receiptFor($donation)->sent_to_email_at;
        $this->assertNotSame('', $first, 'fixture: the donor was emailed');

        $this->disableReceiptEmail();
        Plugin::instance()->container->get(ReceiptIssuer::class)->requeueForDonation((int) $donation->id);
        $this->runPendingAsyncJobs();

        $this->assertSame(
            $first,
            (string) $this->receiptFor($donation)->sent_to_email_at,
            'the only record of the send that happened was erased by one that could not'
        );
    }

    /** And the donation does not start reading as a donor who never got a receipt. */
    public function test_that_donation_is_not_reported_as_missing_its_receipt(): void
    {
        $donation = $this->paidDonation();
        $this->disableReceiptEmail();
        Plugin::instance()->container->get(ReceiptIssuer::class)->requeueForDonation((int) $donation->id);
        $this->runPendingAsyncJobs();

        $missing = Plugin::instance()->container
            ->get(\FundKit\Donations\DonationRepository::class)
            ->paidWithoutReceipt();

        $ids = array_map(static fn ($d): int => (int) $d->id, (array) $missing['items']);
        $this->assertNotContains((int) $donation->id, $ids);
    }

    public function test_a_resend_that_works_moves_the_record_forward(): void
    {
        $donation = $this->paidDonation();
        $first    = (string) $this->receiptFor($donation)->sent_to_email_at;

        $mails = $this->captureMails();
        Plugin::instance()->container->get(ReceiptIssuer::class)->requeueForDonation((int) $donation->id);
        $this->runPendingAsyncJobs();

        $this->assertGreaterThanOrEqual(1, count($mails), 'the resend actually sent');
        $this->assertNotSame('', (string) $this->receiptFor($donation)->sent_to_email_at);
        $this->assertNotSame('', $first);
    }

    public function test_running_the_issuer_twice_does_not_send_twice(): void
    {
        $donation = $this->paidDonation();

        $mails = $this->captureMails();
        Plugin::instance()->container->get(ReceiptIssuer::class)->issueForDonation((int) $donation->id);
        Plugin::instance()->container->get(ReceiptIssuer::class)->issueForDonation((int) $donation->id);

        $this->assertCount(0, $mails, 'the receipt was already sent, so neither run sends again');
    }
}
