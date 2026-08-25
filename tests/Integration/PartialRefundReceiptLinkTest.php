<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donations\Donation;
use Dono\Donations\DonationService;
use Dono\Donors\Donor;
use Dono\Foundation\Helpers\Money;
use Dono\Foundation\Helpers\View;
use Dono\Foundation\Plugin;
use Dono\Foundation\Upgrade\RestoreReceiptsRetainingMoney;
use Dono\Receipts\Receipt;
use Dono\Receipts\ReceiptContext;
use Dono\Receipts\ReceiptIssuer;
use Dono\Receipts\ReceiptRenderer;
use RuntimeException;
use WP_REST_Request;

/**
 * The emailed receipt link, after the org sends part of the money back.
 *
 * The donor is holding a link that promises a re-download for thirty days, and
 * a refund of part of what they gave does not stop them having given the rest.
 */
final class PartialRefundReceiptLinkTest extends IntegrationTestCase
{
    public function test_partial_refund_still_renders_the_donors_receipt(): void
    {
        [$donation, $receiptId, $token] = $this->driveDonationToEmailedReceipt();

        $this->donationService()->refund($donation, 2000, 'one item returned');

        $rendered = $this->renderThroughTheEmailedLink($receiptId, $token);

        $this->assertNotNull($rendered, 'the emailed link still produces the donor a document');
        $this->assertSame('partial_refund', (string) $rendered->donation->status);
        $this->assertSame(5000, (int) $rendered->donation->amount_cents);
    }

    public function test_full_refund_link_explains_itself_rather_than_denying_the_receipt(): void
    {
        [$donation, $receiptId, $token] = $this->driveDonationToEmailedReceipt();

        $this->donationService()->refund($donation, 5000, 'donor requested');

        $res = $this->requestDownload($receiptId, $token);

        $this->assertSame(410, $res->get_status());
        $this->assertSame('dono_receipt_voided', $res->get_data()['code']);
        $this->assertStringContainsString('refunded', (string) $res->get_data()['message']);
    }

    public function test_partial_refund_donation_can_still_be_re_receipted(): void
    {
        [$donation] = $this->driveDonationToEmailedReceipt();

        $this->donationService()->refund($donation, 2000, 'one item returned');

        $mails  = $this->captureMails();
        $issuer = Plugin::instance()->container->get(ReceiptIssuer::class);

        $this->assertTrue(
            $issuer->requeueForDonation((int) $donation->id),
            'an admin can send the donor their receipt again after a partial refund'
        );

        $this->runPendingAsyncJobs();

        $withPdf = [];
        foreach ($mails as $mail) {
            if (! empty($mail['attachments'])) {
                $withPdf[] = $mail;
            }
        }
        $this->assertCount(1, $withPdf, 'the donor is sent the receipt document, not just told it was queued');
    }

    public function test_upgrade_restores_a_receipt_an_earlier_partial_refund_withdrew(): void
    {
        [$donation, $receiptId] = $this->driveDonationToEmailedReceipt();
        $this->donationService()->refund($donation, 2000, 'one item returned');

        // The state such a site is already in.
        Receipt::query()
            ->where('id', $receiptId)
            ->update(['voided' => 1, 'voided_at' => gmdate('Y-m-d H:i:s')]);

        $this->assertTrue((new RestoreReceiptsRetainingMoney())->step());

        $receipt = Receipt::query()->where('id', $receiptId)->get();
        $this->assertFalse((bool) $receipt->voided);
        $this->assertNull($receipt->voided_at);
    }

    public function test_upgrade_leaves_a_fully_refunded_donations_receipt_withdrawn(): void
    {
        [$donation, $receiptId] = $this->driveDonationToEmailedReceipt();
        $this->donationService()->refund($donation, 5000, 'donor requested');

        $this->assertTrue((new RestoreReceiptsRetainingMoney())->step());

        $receipt = Receipt::query()->where('id', $receiptId)->get();
        $this->assertTrue((bool) $receipt->voided);
    }

    public function test_a_partially_refunded_receipt_states_the_amount_retained(): void
    {
        $html = $this->renderReceiptView(5000, 2000);

        $this->assertStringContainsString('Part of this donation has been refunded', $html);
        $this->assertStringNotContainsString('This donation has been refunded (', $html);
        $this->assertStringContainsString(Money::format(3000, 'USD'), $html);
    }

    public function test_a_fully_refunded_receipt_says_the_whole_donation_went_back(): void
    {
        $html = $this->renderReceiptView(5000, 5000);

        $this->assertStringContainsString('This donation has been refunded (', $html);
        $this->assertStringNotContainsString('Part of this donation has been refunded', $html);
    }

    private function renderReceiptView(int $amountCents, int $refundedCents): string
    {
        $donation = Donation::make();
        $donation->reference    = 'DONO-VIEW-' . strtoupper(bin2hex(random_bytes(3)));
        $donation->donor_id     = 0;
        $donation->amount_cents = $amountCents;
        $donation->net_cents    = $amountCents;
        $donation->currency     = 'USD';
        $donation->gateway      = 'offline';
        $donation->status       = $refundedCents >= $amountCents ? 'refunded' : 'partial_refund';
        $donation->frequency    = 'one_time';
        $donation->paid_at      = gmdate('Y-m-d H:i:s');

        return View::load('Receipts.generic', [
            'donation'         => $donation,
            'donor'            => Donor::make(),
            'donor_name'       => 'Sarah Keen',
            'donor_address'    => '',
            'org'              => ['name' => 'Test Org', 'address_lines' => [], 'tax_id' => '', 'email' => ''],
            'locale'           => 'en_US',
            'extras'           => [],
            'amount_display'   => Money::format($amountCents, 'USD'),
            'receipt_number'   => 'REC-2026-00001',
            'receipt_template' => [],
            'refunded_cents'   => $refundedCents,
            'refunded_display' => Money::format($refundedCents, 'USD'),
            'custom_data'      => [],
            'custom_field_labels' => [],
        ]);
    }

    /**
     * Follow the emailed link the way a donor does and capture the context the
     * receipt is rendered from. Null when the route refused to render at all.
     *
     * A stand-in for the stored renderer, because the real one streams the PDF
     * and calls exit(), which would take the test process with it.
     */
    private function renderThroughTheEmailedLink(int $receiptId, string $token): ?ReceiptContext
    {
        $spy = new class implements ReceiptRenderer {
            public ?ReceiptContext $seen = null;

            public function id(): string
            {
                return 'generic.v1';
            }

            public function label(): string
            {
                return 'spy';
            }

            public function referenceScope(): string
            {
                return 'receipt';
            }

            public function appliesTo(ReceiptContext $ctx): bool
            {
                return true;
            }

            public function render(ReceiptContext $ctx): string
            {
                $this->seen = $ctx;
                throw new RuntimeException('rendered');
            }
        };

        $swap = static fn (): array => [$spy];
        add_filter('dono.receipt.renderers', $swap, 99);
        try {
            $this->requestDownload($receiptId, $token);
        } catch (RuntimeException $e) {
            if ($e->getMessage() !== 'rendered') {
                throw $e;
            }
        } finally {
            remove_filter('dono.receipt.renderers', $swap, 99);
        }

        return $spy->seen;
    }

    private function requestDownload(int $receiptId, string $token): \WP_REST_Response
    {
        $req = new WP_REST_Request('GET', "/dono/v1/receipts/{$receiptId}/download");
        $req->set_query_params(['token' => $token]);

        return rest_do_request($req);
    }

    /**
     * @return array{0: Donation, 1: int, 2: string} donation, receipt id, raw token
     */
    private function driveDonationToEmailedReceipt(): array
    {
        $mails = $this->captureMails();

        $createReq = new WP_REST_Request('POST', '/dono/v1/donations');
        $createReq->set_header('content-type', 'application/json');
        $createReq->set_body(json_encode([
            'email'        => 'sarah@example.com',
            'amount_cents' => 5000,
            'currency'     => 'USD',
            'gateway'      => 'offline',
            'profile'      => ['first_name' => 'Sarah', 'country' => 'US'],
        ]));
        $reference = rest_do_request($createReq)->get_data()['reference'];

        $confirmReq = new WP_REST_Request('POST', "/dono/v1/donations/{$reference}/confirm");
        $confirmReq->set_header('content-type', 'application/json');
        $confirmReq->set_body('{}');
        rest_do_request($confirmReq);

        $this->runPendingAsyncJobs();

        $link = null;
        foreach ($mails as $mail) {
            if (preg_match('#/dono/v1/receipts/(\d+)/download\?token=([a-f0-9]+)#', (string) $mail['message'], $m)) {
                $link = $m;
            }
        }
        $this->assertNotNull($link, 'the receipt email carries a download link');

        return [$this->donations()->findByReference($reference), (int) $link[1], (string) $link[2]];
    }

    private function donationService(): DonationService
    {
        return Plugin::instance()->container->get(DonationService::class);
    }

    private function donations(): \Dono\Donations\DonationRepository
    {
        return Plugin::instance()->container->get(\Dono\Donations\DonationRepository::class);
    }
}
