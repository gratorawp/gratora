<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donations\Donation;
use Dono\Donations\DonationRepository;
use Dono\Donations\DonationService;
use Dono\Donations\Refund;
use Dono\Receipts\Receipt;
use RuntimeException;
use WP_REST_Request;

/**
 * Exercises DonationService::refund() end-to-end against the offline gateway.
 *
 * Offline covers the "no remote API" branch - confirms persistence, status
 * transitions, receipt voiding, and event emission without needing Stripe
 * fixtures. Stripe's refund path is exercised at the unit level via FakeGateway.
 */
final class RefundFlowTest extends IntegrationTestCase
{
    public function test_full_refund_marks_donation_refunded_and_voids_receipts(): void
    {
        $donation = $this->driveDonationToPaidAndIssueReceipt();

        $this->donationService()->refund($donation, $donation->amount_cents, 'donor requested');

        $reloaded = $this->donations()->findByReference($donation->reference);
        $this->assertSame('refunded', $reloaded->status);
        $this->assertNotEmpty($reloaded->refunded_at);

        // Refund row persisted with the gateway's refund-id + matching amount.
        $refund = Refund::query()->where('donation_id', $donation->id)->get();
        $this->assertNotNull($refund);
        $this->assertSame($donation->amount_cents, $refund->amount_cents);
        $this->assertSame('succeeded', $refund->status);
        $this->assertStringStartsWith('offline_refund_', (string) $refund->gateway_refund_id);

        // Receipts voided - never deleted, legal retention.
        $receipts = Receipt::query()->where('donation_id', $donation->id)->getAll();
        $this->assertNotEmpty($receipts, 'A receipt should exist before refund');
        foreach ($receipts as $r) {
            $this->assertTrue((bool) $r->voided, 'Receipt should be voided after refund');
            $this->assertNotEmpty($r->voided_at);
        }

        $eventTypes = array_column(
            self::$wpdb->get_results("SELECT type FROM " . self::$prefix . "dono_events ORDER BY id"),
            'type'
        );
        $this->assertContains('donation.refunded', $eventTypes);
    }

    public function test_full_refund_emails_the_donor(): void
    {
        $donation = $this->driveDonationToPaidAndIssueReceipt();

        // Start capturing after the receipt email so only the refund mail counts.
        $mails = $this->captureMails();
        $this->donationService()->refund($donation, $donation->amount_cents, 'donor requested');

        $refundMails = array_filter(
            iterator_to_array($mails),
            static fn ($m) => stripos((string) ($m['subject'] ?? ''), 'refund') !== false,
        );
        $this->assertCount(1, $refundMails, 'the donor is emailed when their donation is refunded');
    }

    public function test_partial_refund_keeps_donation_in_partial_refund_state(): void
    {
        $donation = $this->driveDonationToPaidAndIssueReceipt();

        $this->donationService()->refund($donation, 2000, 'partial - one item returned');

        $reloaded = $this->donations()->findByReference($donation->reference);
        $this->assertSame('partial_refund', $reloaded->status,
            'Sub-total refund leaves the donation in partial_refund, not refunded');

        // Receipts are still voided - once any money goes back, the receipt is
        // no longer a valid deductibility statement.
        $receipts = Receipt::query()->where('donation_id', $donation->id)->getAll();
        foreach ($receipts as $r) {
            $this->assertTrue((bool) $r->voided);
        }
    }

    public function test_multiple_partial_refunds_summing_to_full_transition_to_refunded(): void
    {
        $donation = $this->driveDonationToPaidAndIssueReceipt();

        $this->donationService()->refund($donation, 2000);
        $reloaded = $this->donations()->findByReference($donation->reference);
        $this->assertSame('partial_refund', $reloaded->status);

        $this->donationService()->refund($reloaded, 3000);
        $reloaded = $this->donations()->findByReference($donation->reference);
        $this->assertSame('refunded', $reloaded->status, '2000 + 3000 = full 5000; status flips to refunded');

        $refundCount = (int) self::$wpdb->get_var(self::$wpdb->prepare(
            "SELECT COUNT(*) FROM " . self::$prefix . "dono_refunds WHERE donation_id = %d",
            $donation->id
        ));
        $this->assertSame(2, $refundCount);
    }

    public function test_refund_rejects_overpayment(): void
    {
        $donation = $this->driveDonationToPaidAndIssueReceipt();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid refund amount');
        $this->donationService()->refund($donation, $donation->amount_cents + 1);
    }

    public function test_refund_rejects_zero_or_negative_amount(): void
    {
        $donation = $this->driveDonationToPaidAndIssueReceipt();

        $this->expectException(RuntimeException::class);
        $this->donationService()->refund($donation, 0);
    }

    public function test_refund_rejects_unpaid_donation(): void
    {
        // Pending donation - not paid yet, cannot refund.
        $donation = $this->createPendingDonation();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/is not refundable/');
        $this->donationService()->refund($donation, 1000);
    }

    public function test_refund_rejects_when_partial_overruns_remaining(): void
    {
        $donation = $this->driveDonationToPaidAndIssueReceipt();
        $this->donationService()->refund($donation, 4000);  // 1000 remaining

        $reloaded = $this->donations()->findByReference($donation->reference);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid refund amount');
        $this->donationService()->refund($reloaded, 1500); // > 1000 left
    }

    /** Drive a fresh donation through paid + async receipt issuance, then return the Donation row. */
    private function driveDonationToPaidAndIssueReceipt(): Donation
    {
        $createReq = new WP_REST_Request('POST', '/dono/v1/donations');
        $createReq->set_header('content-type', 'application/json');
        $createReq->set_body(json_encode([
            'email'        => 'sarah@example.com',
            'amount_cents' => 5000,
            'currency'     => 'USD',  // non-DE/EUR → generic renderer only, one receipt
            'gateway'      => 'offline',
            'profile'      => ['first_name' => 'Sarah', 'country' => 'US'],
        ]));
        $reference = rest_do_request($createReq)->get_data()['reference'];

        $confirmReq = new WP_REST_Request('POST', "/dono/v1/donations/{$reference}/confirm");
        $confirmReq->set_header('content-type', 'application/json');
        $confirmReq->set_body('{}');
        rest_do_request($confirmReq);

        $this->runPendingAsyncJobs();

        return $this->donations()->findByReference($reference);
    }

    private function createPendingDonation(): Donation
    {
        $createReq = new WP_REST_Request('POST', '/dono/v1/donations');
        $createReq->set_header('content-type', 'application/json');
        $createReq->set_body(json_encode([
            'email'        => 'pending@example.com',
            'amount_cents' => 5000,
            'currency'     => 'EUR',
            'gateway'      => 'offline',
            'profile'      => ['first_name' => 'Pen', 'country' => 'DE'],
        ]));
        $reference = rest_do_request($createReq)->get_data()['reference'];
        return $this->donations()->findByReference($reference);
    }

    private function donationService(): DonationService
    {
        return \Dono\Foundation\Plugin::instance()->container->get(DonationService::class);
    }

    private function donations(): DonationRepository
    {
        return \Dono\Foundation\Plugin::instance()->container->get(DonationRepository::class);
    }
}
