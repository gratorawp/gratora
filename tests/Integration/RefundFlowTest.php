<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Donations\Donation;
use Gratora\Donations\DonationRepository;
use Gratora\Donations\DonationService;
use Gratora\Donations\Refund;
use Gratora\Donors\DonorService;
use Gratora\Foundation\Time\Clock;
use Gratora\Gateways\GatewayManager;
use Gratora\Gateways\Sandbox\SandboxGateway;
use Gratora\Receipts\OrgProfile;
use Gratora\Receipts\Receipt;
use Gratora\Recurring\RecurringPlanRepository;
use RuntimeException;
use WP_REST_Request;

/**
 * Exercises DonationService::refund() end-to-end against the offline gateway.
 *
 * Offline covers the "no remote API" branch - confirms persistence, status
 * transitions, receipt voiding, and event emission without needing Stripe
 * fixtures. Stripe's refund path is exercised at the unit level via FakeGateway.
 * The sandbox gateway stands in for a payment provider where the refund email
 * has to tell the two apart.
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
            self::$wpdb->get_results("SELECT type FROM " . self::$prefix . "gratora_events ORDER BY id"),
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

    public function test_an_offline_refund_promises_no_card_and_no_wait(): void
    {
        $donation = $this->driveDonationToPaidAndIssueReceipt();

        $mails = $this->captureMails();
        $this->donationService()->refund($donation, $donation->amount_cents, 'handed back in cash');

        $body = $this->onlyRefundMail($mails);
        $this->assertStringNotContainsStringIgnoringCase('card', $body, 'the money goes back by hand, not to a card');
        $this->assertStringNotContainsString('{refund_timing}', $body);
        $this->assertStringNotContainsString(" \n", $body, 'no line ends in the space the empty tag stood after');
        $this->assertStringNotContainsString("\n\n\n", $body, 'and no paragraph is left empty');
    }

    public function test_a_refund_through_a_payment_provider_says_how_long_the_money_takes(): void
    {
        $donation = $this->paidSandboxDonation();

        $mails = $this->captureMails();
        $this->donationService()->refund($donation, $donation->amount_cents, 'donor requested');

        $this->assertStringContainsString(
            'It can take 5 to 10 business days for the money to reach the card or account you paid with.',
            $this->onlyRefundMail($mails)
        );
    }

    public function test_a_refund_of_a_donation_to_no_campaign_names_the_organization(): void
    {
        $donation = $this->driveDonationToPaidAndIssueReceipt();
        $this->assertEmpty($donation->campaign_id, 'the donation was given outside any campaign');

        $mails = $this->captureMails();
        $this->donationService()->refund($donation, $donation->amount_cents, 'donor requested');

        $this->assertStringContainsString(
            'We have refunded your donation of $50.00 to ' . OrgProfile::load()['name'] . '.',
            $this->onlyRefundMail($mails)
        );
    }

    public function test_partial_refund_keeps_donation_in_partial_refund_state(): void
    {
        $donation = $this->driveDonationToPaidAndIssueReceipt();

        $this->donationService()->refund($donation, 2000, 'partial - one item returned');

        $reloaded = $this->donations()->findByReference($donation->reference);
        $this->assertSame('partial_refund', $reloaded->status,
            'Sub-total refund leaves the donation in partial_refund, not refunded');

        // The receipt stands: the org kept 3000 of the 5000, and the document
        // renders from live data, so it states the refund and the net total.
        $receipts = Receipt::query()->where('donation_id', $donation->id)->getAll();
        $this->assertNotEmpty($receipts);
        foreach ($receipts as $r) {
            $this->assertFalse((bool) $r->voided);
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
            "SELECT COUNT(*) FROM " . self::$prefix . "gratora_refunds WHERE donation_id = %d",
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
        $createReq = new WP_REST_Request('POST', '/gratora/v1/donations');
        $createReq->set_header('content-type', 'application/json');
        $createReq->set_body(json_encode([
            'email'        => 'sarah@example.com',
            'amount_cents' => 5000,
            'currency'     => 'USD',  // non-DE/EUR → generic renderer only, one receipt
            'gateway'      => 'offline',
            'profile'      => ['first_name' => 'Sarah', 'country' => 'US'],
        ]));
        $reference = rest_do_request($createReq)->get_data()['reference'];

        $confirmReq = new WP_REST_Request('POST', "/gratora/v1/donations/{$reference}/confirm");
        $confirmReq->set_header('content-type', 'application/json');
        $confirmReq->set_body('{}');
        rest_do_request($confirmReq);

        $this->runPendingAsyncJobs();

        return $this->donations()->findByReference($reference);
    }

    private function paidSandboxDonation(): Donation
    {
        $c       = \Gratora\Foundation\Plugin::instance()->container;
        $manager = $c->get(GatewayManager::class);
        if ($manager->get('sandbox') === null) {
            $manager->register(new SandboxGateway($c->get(Clock::class), $c->get(RecurringPlanRepository::class)));
        }

        $donor = $c->get(DonorService::class)->findOrCreate('card@example.com', ['first_name' => 'Cara', 'last_name' => 'Lind']);
        $now   = gmdate('Y-m-d H:i:s');

        $d = Donation::make();
        $d->reference         = 'SBX-' . strtoupper(bin2hex(random_bytes(4)));
        $d->donor_id          = (int) $donor->id;
        $d->amount_cents      = 5000;
        $d->net_cents         = 5000;
        $d->base_amount_cents = 5000;
        $d->base_currency     = 'USD';
        $d->currency          = 'USD';
        $d->gateway           = 'sandbox';
        $d->gateway_txn_id    = 'sandbox_txn_' . bin2hex(random_bytes(4));
        $d->status            = 'paid';
        $d->is_test           = true;
        $d->paid_at           = $now;
        $d->created_at        = $now;
        $d->updated_at        = $now;
        $d->save();

        return $d;
    }

    private function onlyRefundMail(\ArrayObject $mails): string
    {
        $bodies = [];
        foreach ($mails as $mail) {
            if (stripos((string) ($mail['subject'] ?? ''), 'refund') !== false) {
                $bodies[] = (string) $mail['message'];
            }
        }
        $this->assertCount(1, $bodies, 'exactly one refund email');

        return $bodies[0];
    }

    private function createPendingDonation(): Donation
    {
        $createReq = new WP_REST_Request('POST', '/gratora/v1/donations');
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
        return \Gratora\Foundation\Plugin::instance()->container->get(DonationService::class);
    }

    private function donations(): DonationRepository
    {
        return \Gratora\Foundation\Plugin::instance()->container->get(DonationRepository::class);
    }
}
