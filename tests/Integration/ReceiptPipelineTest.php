<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use WP_REST_Request;

final class ReceiptPipelineTest extends IntegrationTestCase
{
    public function test_paid_donation_schedules_receipt_job_and_running_it_produces_receipt_row(): void
    {
        $reference = $this->driveDonationToPaid();

        // After confirm, exactly one issue_receipt job should be pending.
        $pending = self::$wpdb->get_results(
            "SELECT hook FROM " . self::$prefix . "actionscheduler_actions WHERE hook = 'dono.async.issue_receipt' AND status = 'pending'"
        );
        $this->assertCount(1, $pending);

        $this->runPendingAsyncJobs();

        $receipt = self::$wpdb->get_row("SELECT * FROM " . self::$prefix . "dono_receipts");
        $this->assertNotNull($receipt, 'Receipt row should be persisted after async job runs');
        $this->assertSame('generic.v1', $receipt->renderer_id);
        // Generic receipts use the 'receipt' counter scope → 'REC-' prefix.
        $this->assertMatchesRegularExpression('/^REC-\d{4}-\d{5}$/', $receipt->receipt_number);
        $this->assertFalse((bool) $receipt->voided);
    }

    public function test_running_pipeline_calls_wp_mail_with_pdf_attachment(): void
    {
        $mails = $this->captureMails();
        $this->driveDonationToPaid();
        $this->runPendingAsyncJobs();

        // Offline donations now also send an offline_instructions email at
        // intent time; filter to the one with the PDF attachment (the receipt).
        // $mails is an ArrayObject from captureMails(), so iterate manually.
        $receiptMails = [];
        foreach ($mails as $m) {
            if (! empty($m['attachments'])) $receiptMails[] = $m;
        }
        $this->assertCount(1, $receiptMails, 'one receipt email should be sent (with PDF attachment)');
        $mail = $receiptMails[0];
        // Default donation_receipt subject is the friendly
        // "Thank you for your donation to {organisation_name}" template; the
        // donation reference lives in the body, asserted below.
        $this->assertStringContainsStringIgnoringCase('donation', $mail['subject']);
        $this->assertCount(1, $mail['attachments']);
        $attachment = $mail['attachments'][0];

        // Attachment should be a real PDF on disk at send time (a temp file we wrote).
        // We can't read it now (ReceiptIssuer unlinks after wp_mail returns), but the
        // path should at least be string-shaped and the email body should reference the
        // donation + carry a re-download URL.
        $this->assertIsString($attachment);
        $this->assertStringContainsString('DONO-', $mail['message']);
        $this->assertMatchesRegularExpression('#/dono/v1/receipts/\d+/download\?token=[a-f0-9]+#', $mail['message']);
    }

    public function test_receipt_issued_event_fires_with_donor_and_receipt_link(): void
    {
        $this->driveDonationToPaid();
        $this->runPendingAsyncJobs();

        $event = self::$wpdb->get_row("SELECT * FROM " . self::$prefix . "dono_events WHERE type = 'receipt.issued'");
        $this->assertNotNull($event);
        $this->assertNotEmpty($event->donor_id);
        $this->assertNotEmpty($event->donation_id);
        $this->assertNotEmpty($event->receipt_id);
    }

    public function test_pipeline_is_idempotent_on_repeat_invocation(): void
    {
        $this->driveDonationToPaid();
        $this->runPendingAsyncJobs();
        $firstCount = (int) self::$wpdb->get_var("SELECT COUNT(*) FROM " . self::$prefix . "dono_receipts");
        $this->assertSame(1, $firstCount);

        // Re-run the same handler manually - should be a no-op (existing receipt with sent_to_email_at).
        // But wp_mail returns false in CLI, so sent_to_email_at may be null and the handler
        // would attempt re-send. Either way: must not insert a duplicate Receipt row.
        do_action('dono.async.issue_receipt', ['donation_id' => 1]);

        $secondCount = (int) self::$wpdb->get_var("SELECT COUNT(*) FROM " . self::$prefix . "dono_receipts");
        $this->assertSame(1, $secondCount, 'Idempotent - no duplicate receipt row per (donation, renderer)');
    }

    /**
     * Drive a fresh donation to paid status and return its reference.
     * Exercises the generic-receipt pipeline (the only built-in renderer).
     */
    private function driveDonationToPaid(): string
    {
        $createReq = new WP_REST_Request('POST', '/dono/v1/donations');
        $createReq->set_header('content-type', 'application/json');
        $createReq->set_body(json_encode([
            'email'        => 'sarah@example.com',
            'amount_cents' => 5000,
            'currency'     => 'USD',
            'gateway'      => 'offline',
            'profile'      => ['first_name' => 'Sarah', 'last_name' => 'Doe', 'country' => 'US'],
        ]));
        $reference = rest_do_request($createReq)->get_data()['reference'];

        $confirmReq = new WP_REST_Request('POST', "/dono/v1/donations/{$reference}/confirm");
        $confirmReq->set_header('content-type', 'application/json');
        $confirmReq->set_body('{}');
        rest_do_request($confirmReq);

        return $reference;
    }
}
