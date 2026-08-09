<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donations\Donation;
use Dono\Donors\DonorService;
use Dono\Foundation\Plugin;
use WP_REST_Request;

/**
 * Exercises the admin write-actions on a single donation + the export endpoint:
 *
 *   GET    /admin/donations/{reference}           - full detail incl. receipts + refunds
 *   POST   /admin/donations/{reference}/refund    - full + partial paths
 *   POST   /admin/donations/{reference}/resend-receipt
 *   GET    /admin/donations/export.csv            - streamed CSV
 *
 * Auth: rest_do_request runs as user 1 (administrator) thanks to
 * IntegrationTestCase::setUp().
 */
final class AdminDonationActionsTest extends IntegrationTestCase
{
    public function test_show_returns_full_detail_with_donor_receipts_and_refunds(): void
    {
        $reference = $this->driveDonationToPaidAndIssueReceipt();

        $res = $this->get("/dono/v1/admin/donations/{$reference}");
        $this->assertSame(200, $res->get_status());

        $data = $res->get_data();
        $this->assertSame($reference, $data['donation']['reference']);
        $this->assertSame('paid',     $data['donation']['status']);
        $this->assertSame(5000,       (int) $data['donation']['amount_cents']);
        $this->assertSame(5000,       (int) $data['donation']['refundable_cents']);
        $this->assertSame(0,          (int) $data['donation']['refunded_cents']);
        $this->assertSame('Sarah Doe', $data['donation']['donor']['name']);

        $this->assertCount(1, $data['receipts'], 'Generic renderer should have issued exactly one receipt');
        $this->assertSame('generic.v1', $data['receipts'][0]['renderer_id']);
        $this->assertFalse($data['receipts'][0]['voided']);

        $this->assertSame([], $data['refunds']);
    }

    public function test_show_404s_unknown_reference(): void
    {
        $res = $this->get('/dono/v1/admin/donations/DONO-1999-99999');
        $this->assertSame(404, $res->get_status());
        $this->assertSame('dono_not_found', $res->get_data()['code']);
    }

    public function test_refund_full_amount_returns_refund_payload_and_flips_status(): void
    {
        $reference = $this->driveDonationToPaidAndIssueReceipt();

        $res = $this->post("/dono/v1/admin/donations/{$reference}/refund", [
            'amount_cents' => 5000,
            'reason'       => 'donor requested',
        ]);

        $this->assertSame(200, $res->get_status());
        $data = $res->get_data();
        $this->assertSame(5000, $data['refund']['amount_cents']);
        $this->assertSame('succeeded', $data['refund']['status']);
        $this->assertStringStartsWith('offline_refund_', $data['refund']['gateway_refund_id']);
        $this->assertSame('refunded', $data['donation_status']);
        $this->assertNotEmpty($data['refunded_at']);

        // Detail endpoint now reflects the refund.
        $detail = $this->get("/dono/v1/admin/donations/{$reference}")->get_data();
        $this->assertCount(1, $detail['refunds']);
        $this->assertTrue($detail['receipts'][0]['voided'], 'Receipt should be voided after full refund');
        $this->assertSame(0, (int) $detail['donation']['refundable_cents']);
        $this->assertSame(5000, (int) $detail['donation']['refunded_cents']);
    }

    public function test_refund_partial_amount_leaves_status_partial_refund(): void
    {
        $reference = $this->driveDonationToPaidAndIssueReceipt();

        $res = $this->post("/dono/v1/admin/donations/{$reference}/refund", [
            'amount_cents' => 2000,
        ]);
        $this->assertSame(200, $res->get_status());
        $this->assertSame('partial_refund', $res->get_data()['donation_status']);

        $detail = $this->get("/dono/v1/admin/donations/{$reference}")->get_data();
        $this->assertSame(3000, (int) $detail['donation']['refundable_cents'],
            'Remaining refundable is amount - refunded');
    }

    public function test_refund_defaults_to_full_amount_when_amount_cents_omitted(): void
    {
        $reference = $this->driveDonationToPaidAndIssueReceipt();

        $res = $this->post("/dono/v1/admin/donations/{$reference}/refund", []);

        $this->assertSame(200, $res->get_status());
        $this->assertSame(5000, $res->get_data()['refund']['amount_cents']);
        $this->assertSame('refunded', $res->get_data()['donation_status']);
    }

    public function test_refund_returns_422_when_donation_not_paid(): void
    {
        // Pending donation
        $reference = $this->postDonation([
            'email' => 'pending@example.com', 'amount_cents' => 5000, 'currency' => 'EUR',
            'gateway' => 'offline',
        ])->get_data()['reference'];

        $res = $this->post("/dono/v1/admin/donations/{$reference}/refund", ['amount_cents' => 1000]);

        $this->assertSame(422, $res->get_status());
        $this->assertSame('dono_refund_failed', $res->get_data()['code']);
    }

    public function test_refund_404s_unknown_reference(): void
    {
        $res = $this->post('/dono/v1/admin/donations/DONO-1999-99999/refund', ['amount_cents' => 1000]);
        $this->assertSame(404, $res->get_status());
    }

    /**
     * The earlier flakiness here was Queryable's DB::transaction() committing
     * through WP_UnitTestCase's wrapping transaction, so the cleared
     * sent_to_email_at was read back inconsistently. IntegrationTestCase now
     * keeps Queryable inside that one transaction, so the resend is observed.
     */
    public function test_resend_receipt_queues_async_job_and_resends_email(): void
    {
        $reference = $this->driveDonationToPaidAndIssueReceipt();

        // First email already captured + sent during driveDonationToPaidAndIssueReceipt; start fresh.
        $mails = $this->captureMails();

        $res = $this->post("/dono/v1/admin/donations/{$reference}/resend-receipt", []);
        $this->assertSame(202, $res->get_status());
        $this->assertTrue($res->get_data()['queued']);

        $this->runPendingAsyncJobs();

        // Same receipt row, second wp_mail call. The default receipt subject
        // is the friendly thank-you template; the reference is in the body.
        $this->assertCount(1, $mails, 'Resend should fire exactly one new email');
        $this->assertStringContainsStringIgnoringCase('donation', $mails[0]['subject']);
        $this->assertStringContainsString($reference, (string) $mails[0]['message']);

        // No duplicate Receipt row was created.
        $receipts = self::$wpdb->get_results(
            "SELECT id FROM " . self::$prefix . "dono_receipts"
        );
        $this->assertCount(1, $receipts);
    }

    public function test_resend_receipt_returns_422_for_unpaid_donation(): void
    {
        $reference = $this->postDonation([
            'email' => 'p@example.com', 'amount_cents' => 5000, 'currency' => 'EUR', 'gateway' => 'offline',
        ])->get_data()['reference'];

        $res = $this->post("/dono/v1/admin/donations/{$reference}/resend-receipt", []);
        $this->assertSame(422, $res->get_status());
        $this->assertSame('dono_resend_unavailable', $res->get_data()['code']);
    }

    public function test_export_csv_includes_header_and_one_row_per_match(): void
    {
        $this->driveDonationToPaidAndIssueReceipt();   // Sarah Doe / USD / paid
        $this->driveDonationToPaidAndIssueReceipt(['email' => 'b@example.com', 'first_name' => 'Bob']);

        $csv = $this->captureCsv('/dono/v1/admin/donations/export.csv');

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv, 'CSV should start with UTF-8 BOM');

        $lines = preg_split('/\r?\n/', trim($csv));
        $this->assertCount(3, $lines, 'header + 2 rows');
        $this->assertStringContainsString('Reference', $lines[0]);
        $this->assertStringContainsString('Donor email', $lines[0]);
        $this->assertStringContainsString('sarah@example.com', $csv);
        $this->assertStringContainsString('b@example.com', $csv);
    }

    public function test_export_csv_respects_search_filter(): void
    {
        $this->driveDonationToPaidAndIssueReceipt();
        $this->driveDonationToPaidAndIssueReceipt(['email' => 'someone@else.com', 'first_name' => 'Zed']);

        $csv = $this->captureCsv('/dono/v1/admin/donations/export.csv', ['search' => 'sarah@example.com']);

        $lines = preg_split('/\r?\n/', trim($csv));
        $this->assertCount(2, $lines, 'header + only Sarah\'s row');
        $this->assertStringContainsString('sarah@example.com', $csv);
        $this->assertStringNotContainsString('someone@else.com', $csv);
    }

    public function test_export_csv_respects_gateway_and_test_filters(): void
    {
        // One live offline + one paid test (stripe) donation.
        $this->seedPaidDonation(['email' => 'live@example.com',  'gateway' => 'offline', 'is_test' => false]);
        $this->seedPaidDonation(['email' => 'test@example.com',  'gateway' => 'stripe',  'is_test' => true]);

        // gateway filter (was silently dropped by the export before this fix).
        $csv = $this->captureCsv('/dono/v1/admin/donations/export.csv', ['gateway' => 'offline']);
        $this->assertStringContainsString('live@example.com', $csv);
        $this->assertStringNotContainsString('test@example.com', $csv, 'gateway filter must scope the export');

        // is_test=no filter must exclude the test donation.
        $csv = $this->captureCsv('/dono/v1/admin/donations/export.csv', ['is_test' => 'false']);
        $this->assertStringContainsString('live@example.com', $csv);
        $this->assertStringNotContainsString('test@example.com', $csv, 'is_test filter must scope the export');

        // No filter is live-only: the test row is hidden unless explicitly asked for.
        $csv = $this->captureCsv('/dono/v1/admin/donations/export.csv');
        $this->assertStringContainsString('live@example.com', $csv);
        $this->assertStringNotContainsString('test@example.com', $csv);
    }

    public function test_stats_excludes_test_money_from_raised_by_default(): void
    {
        $this->seedPaidDonation(['email' => 'live@example.com', 'gateway' => 'offline', 'is_test' => false, 'amount_cents' => 5000]);
        $this->seedPaidDonation(['email' => 'test@example.com', 'gateway' => 'stripe',  'is_test' => true,  'amount_cents' => 9999]);

        // Default (no is_test filter): live-only across the board, so total,
        // paid and raised all describe real donations.
        $stats = $this->get('/dono/v1/admin/donations/stats')->get_data();
        $this->assertSame(1, (int) $stats['total_count'], 'total is live-only by default');
        $this->assertSame(1, (int) $stats['paid_count'], 'paid count excludes test money');
        $this->assertSame(5000, (int) $stats['raised_cents'], 'test money never inflates Raised');

        // Explicitly viewing test donations surfaces the test totals.
        $stats = $this->get('/dono/v1/admin/donations/stats', ['is_test' => 'true'])->get_data();
        $this->assertSame(9999, (int) $stats['raised_cents'], 'is_test=true shows test totals');
    }

    // helpers

    /**
     * Insert a paid donation row directly so gateway / is_test / amount can be
     * controlled (the public REST path only mints offline/sandbox donations).
     *
     * @param array{email?:string, gateway?:string, is_test?:bool, amount_cents?:int} $args
     */
    private function seedPaidDonation(array $args = []): string
    {
        $email  = $args['email']        ?? 'seed@example.com';
        $amount = (int) ($args['amount_cents'] ?? 5000);
        $donor  = Plugin::instance()->container->get(DonorService::class)
            ->findOrCreate($email, ['first_name' => 'Seed', 'last_name' => 'Donor']);

        $now = gmdate('Y-m-d H:i:s');
        $d = Donation::make();
        $d->reference    = 'DONO-SEED-' . substr(md5($email . $amount), 0, 8);
        $d->donor_id     = $donor->id;
        $d->amount_cents = $amount;
        $d->net_cents    = $amount;
        $d->currency     = 'USD';
        $d->base_amount_cents = $amount;
        $d->base_currency     = 'USD';
        $d->fx_rate           = '1.00000000';
        $d->gateway      = (string) ($args['gateway'] ?? 'offline');
        $d->status       = 'paid';
        $d->is_test      = (bool) ($args['is_test'] ?? false);
        $d->paid_at      = $now;
        $d->created_at   = $now;
        $d->updated_at   = $now;
        $d->save();

        return $d->reference;
    }

    /**
     * @param array{email?:string, first_name?:string, country?:string} $overrides
     */
    private function driveDonationToPaidAndIssueReceipt(array $overrides = []): string
    {
        $email     = $overrides['email']      ?? 'sarah@example.com';
        $firstName = $overrides['first_name'] ?? 'Sarah';
        $country   = $overrides['country']    ?? 'US';   // non-DE → generic renderer only

        $reference = $this->postDonation([
            'email'        => $email,
            'amount_cents' => 5000,
            'currency'     => 'USD',
            'gateway'      => 'offline',
            'profile'      => ['first_name' => $firstName, 'last_name' => 'Doe', 'country' => $country],
        ])->get_data()['reference'];

        $req = new WP_REST_Request('POST', "/dono/v1/donations/{$reference}/confirm");
        $req->set_header('content-type', 'application/json');
        $req->set_body('{}');
        rest_do_request($req);

        $this->runPendingAsyncJobs();
        return $reference;
    }

    private function postDonation(array $body): \WP_REST_Response
    {
        $req = new WP_REST_Request('POST', '/dono/v1/donations');
        $req->set_header('content-type', 'application/json');
        $req->set_body(json_encode($body));
        return rest_do_request($req);
    }

    private function get(string $path, array $params = []): \WP_REST_Response
    {
        $req = new WP_REST_Request('GET', $path);
        if (! empty($params)) {
            $req->set_query_params($params);
        }
        return rest_do_request($req);
    }

    private function post(string $path, array $body): \WP_REST_Response
    {
        $req = new WP_REST_Request('POST', $path);
        $req->set_header('content-type', 'application/json');
        $req->set_body(json_encode($body));
        return rest_do_request($req);
    }

    /**
     * The CSV controller builds its body up-front and returns it as the response
     * data; the streaming hook only fires when WP_REST_Server is actually serving
     * a request (production). Tests inspect get_data() directly.
     */
    private function captureCsv(string $path, array $params = []): string
    {
        return $this->serveBody($path, $params);
    }
}
