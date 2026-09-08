<?php

declare(strict_types=1);

namespace FundKit\Tests\Integration;

use FundKit\Analytics\Event;
use FundKit\Foundation\Plugin;
use FundKit\Receipts\Receipt;
use FundKit\Receipts\ReceiptContext;
use FundKit\Receipts\ReceiptIssuer;
use FundKit\Receipts\ReceiptRenderer;
use RuntimeException;
use WP_REST_Request;

/** Answers to the id core's generic receipts are stamped with, and cannot render. */
final class ThrowingReceiptRenderer implements ReceiptRenderer
{
    public function id(): string
    {
        return 'generic.v1';
    }

    public function label(): string
    {
        return 'Throwing';
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
        throw new RuntimeException('dompdf: font cache is not writable');
    }
}

/**
 * A receipt that will not render is a state the org has to be able to act on.
 * The donor's own download link answered with a WordPress critical error, and
 * the admin's Download button asserted a cause the code has no evidence for,
 * while the exception text existed nowhere at all.
 */
final class ReceiptRenderFailureTest extends IntegrationTestCase
{
    /** @var callable|null */
    private $throwing = null;

    protected function tearDown(): void
    {
        if ($this->throwing !== null) {
            remove_filter('fundkit.receipt.renderers', $this->throwing, 1);
            $this->throwing = null;
        }

        parent::tearDown();
    }

    /** Registered at priority 1, so findRendererById's first match is this one. */
    private function breakTheRenderer(): void
    {
        $this->throwing = static fn (array $rs): array => array_merge([new ThrowingReceiptRenderer()], $rs);
        add_filter('fundkit.receipt.renderers', $this->throwing, 1);
    }

    /** @return array{0:int,1:string} the receipt id and its raw download token */
    private function issueReceipt(): array
    {
        $mails = $this->captureMails();

        $create = new WP_REST_Request('POST', '/fundkit/v1/donations');
        $create->set_header('content-type', 'application/json');
        $create->set_body((string) wp_json_encode([
            'email'        => 'sarah@example.com',
            'amount_cents' => 5000,
            'currency'     => 'USD',
            'gateway'      => 'offline',
            'profile'      => ['first_name' => 'Sarah', 'country' => 'US'],
        ]));
        $reference = rest_do_request($create)->get_data()['reference'];

        $confirm = new WP_REST_Request('POST', "/fundkit/v1/donations/{$reference}/confirm");
        $confirm->set_header('content-type', 'application/json');
        $confirm->set_body('{}');
        rest_do_request($confirm);

        $this->runPendingAsyncJobs();

        foreach ($mails as $m) {
            if (empty($m['attachments'])) continue;
            if (preg_match('#/fundkit/v1/receipts/(\d+)/download\?token=([a-f0-9]+)#', (string) $m['message'], $hit)) {
                return [(int) $hit[1], (string) $hit[2]];
            }
        }

        $this->fail('no receipt email carrying a download link was sent');
    }

    private function latestRenderError(string $type): ?Event
    {
        return Event::query()->where('type', $type)->orderBy('id', 'DESC')->get();
    }

    public function test_the_donor_link_answers_instead_of_fataling(): void
    {
        [$receiptId, $token] = $this->issueReceipt();
        $this->breakTheRenderer();

        $req = new WP_REST_Request('GET', "/fundkit/v1/receipts/{$receiptId}/download");
        $req->set_query_params(['token' => $token]);
        $res = rest_do_request($req);

        $this->assertSame(500, $res->get_status());
        $this->assertSame('fundkit_receipt_render_failed', $res->get_data()['code']);
    }

    public function test_the_org_can_read_why_a_download_failed(): void
    {
        [$receiptId, $token] = $this->issueReceipt();
        $this->breakTheRenderer();

        $req = new WP_REST_Request('GET', "/fundkit/v1/receipts/{$receiptId}/download");
        $req->set_query_params(['token' => $token]);
        rest_do_request($req);

        $row = $this->latestRenderError('error.receipt.download');

        $this->assertNotNull($row);
        $this->assertStringContainsString('font cache is not writable', (string) ($row->payload['message'] ?? ''));
    }

    public function test_a_regenerate_failure_leaves_the_reason_where_the_org_reads_it(): void
    {
        [$receiptId] = $this->issueReceipt();
        $this->breakTheRenderer();

        $this->assertNull(Plugin::instance()->container->get(ReceiptIssuer::class)->renderReceiptPdf($receiptId));

        $row = $this->latestRenderError('error.receipt.render');

        $this->assertNotNull($row);
        $this->assertStringContainsString('font cache is not writable', (string) ($row->payload['message'] ?? ''));
    }

    public function test_a_missing_renderer_says_so_rather_than_looking_like_a_crash(): void
    {
        [$receiptId] = $this->issueReceipt();

        $receipt = Receipt::query()->where('id', $receiptId)->get();
        $receipt->renderer_id = 'gone.v1';
        $receipt->save();

        $this->assertNull(Plugin::instance()->container->get(ReceiptIssuer::class)->renderReceiptPdf($receiptId));

        $row = $this->latestRenderError('error.receipt.render');

        $this->assertNotNull($row);
        $this->assertStringContainsString('gone.v1', (string) ($row->payload['message'] ?? ''));
    }
}
