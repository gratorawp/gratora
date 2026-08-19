<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donations\Donation;
use Dono\Donations\DonationRepository;
use Dono\Donations\DonationService;
use Dono\Donations\Refund;
use Dono\Receipts\Receipt;
use WP_REST_Request;

/** THROWAWAY PROBE - delete before finishing. */
final class ZzDonorTruthProbeTest extends IntegrationTestCase
{
    public function test_probe_reversed_refund_leaves_receipt_voided(): void
    {
        $donation = $this->paidWithReceipt();

        $refund = $this->svc()->refund($donation, $donation->amount_cents, 'donor requested');
        $gwId   = (string) $refund->gateway_refund_id;

        $reloaded = $this->repo()->findByReference($donation->reference);
        fwrite(STDERR, "\nAFTER REFUND status={$reloaded->status} refunded_cents={$reloaded->refunded_cents}\n");

        // The gateway says the refund never landed (Stripe refund.failed, or a
        // won dispute reinstating the funds).
        $this->svc()->reverseExternalRefund($reloaded, $gwId);

        $after = $this->repo()->findByReference($donation->reference);
        fwrite(STDERR, "AFTER REVERSAL status={$after->status} refunded_cents={$after->refunded_cents}\n");

        $receipts = Receipt::query()->where('donation_id', $donation->id)->getAll();
        foreach ($receipts as $r) {
            fwrite(STDERR, "RECEIPT {$r->receipt_number} voided=" . var_export((bool) $r->voided, true)
                . " voided_at=" . var_export($r->voided_at, true) . "\n");
        }

        // What the donor's year-end statement now says.
        $rows = $this->repo()->paidForDonorInYear((int) $after->donor_id, (int) gmdate('Y'));
        fwrite(STDERR, 'STATEMENT ROWS: ' . json_encode($rows) . "\n");

        // What the emailed re-download link now answers.
        $magic = \Dono\Foundation\Plugin::instance()->container->get(\Dono\Donors\MagicLinkService::class);
        $token = $magic->issue((int) $after->donor_id, 'download_receipt', (int) $receipts[0]->id);
        $req   = new WP_REST_Request('GET', '/dono/v1/receipts/' . (int) $receipts[0]->id . '/download');
        $req->set_param('receipt_id', (int) $receipts[0]->id);
        $req->set_param('token', $token);
        $res = rest_do_request($req);
        fwrite(STDERR, 'DOWNLOAD STATUS: ' . $res->get_status() . ' ' . json_encode($res->get_data()) . "\n");

        // Can an admin re-issue?
        $issuer = \Dono\Foundation\Plugin::instance()->container->get(\Dono\Receipts\ReceiptIssuer::class);
        fwrite(STDERR, 'REQUEUE RETURNED: ' . var_export($issuer->requeueForDonation((int) $after->id), true) . "\n");

        $this->assertTrue(true);
    }

    public function test_probe_partial_refund_leaves_donor_with_no_receipt(): void
    {
        $donation = $this->paidWithReceipt();

        $this->svc()->refund($donation, 1000, 'partial');

        $after = $this->repo()->findByReference($donation->reference);
        fwrite(STDERR, "\nPARTIAL: status={$after->status} amount={$after->amount_cents} refunded={$after->refunded_cents}\n");

        $receipts = Receipt::query()->where('donation_id', $donation->id)->getAll();
        foreach ($receipts as $r) {
            fwrite(STDERR, "RECEIPT {$r->receipt_number} voided=" . var_export((bool) $r->voided, true) . "\n");
        }

        // Re-issue attempt after a partial refund.
        $issuer = \Dono\Foundation\Plugin::instance()->container->get(\Dono\Receipts\ReceiptIssuer::class);
        fwrite(STDERR, 'REQUEUE RETURNED: ' . var_export($issuer->requeueForDonation((int) $after->id), true) . "\n");
        $issuer->issueForDonation(['donation_id' => (int) $after->id]);
        $this->runPendingAsyncJobs();
        $n = Receipt::query()->where('donation_id', $donation->id)->where('voided', 0)->getAll();
        fwrite(STDERR, 'NON-VOIDED RECEIPTS AFTER RE-ISSUE: ' . count($n) . "\n");

        $rows = $this->repo()->paidForDonorInYear((int) $after->donor_id, (int) gmdate('Y'));
        fwrite(STDERR, 'STATEMENT ROWS: ' . json_encode($rows) . "\n");

        $this->assertTrue(true);
    }

    private function paidWithReceipt(): Donation
    {
        $createReq = new WP_REST_Request('POST', '/dono/v1/donations');
        $createReq->set_header('content-type', 'application/json');
        $createReq->set_body(json_encode([
            'email'        => 'probe@example.com',
            'amount_cents' => 5000,
            'currency'     => 'USD',
            'gateway'      => 'offline',
            'profile'      => ['first_name' => 'Probe', 'country' => 'US'],
        ]));
        $reference = rest_do_request($createReq)->get_data()['reference'];

        $confirmReq = new WP_REST_Request('POST', "/dono/v1/donations/{$reference}/confirm");
        $confirmReq->set_header('content-type', 'application/json');
        $confirmReq->set_body('{}');
        rest_do_request($confirmReq);
        $this->runPendingAsyncJobs();

        return $this->repo()->findByReference($reference);
    }

    private function svc(): DonationService
    {
        return \Dono\Foundation\Plugin::instance()->container->get(DonationService::class);
    }

    private function repo(): DonationRepository
    {
        return \Dono\Foundation\Plugin::instance()->container->get(DonationRepository::class);
    }
}
