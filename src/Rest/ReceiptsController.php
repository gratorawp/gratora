<?php

declare(strict_types=1);

namespace Gratora\Rest;

use Gratora\Analytics\ErrorLog;
use Gratora\Campaigns\Campaign;
use Gratora\Donations\AntiSpamGuard;
use Gratora\Donations\Donation;
use Gratora\Donations\DonationRepository;
use Gratora\Donors\Donor;
use Gratora\Donors\DonorRepository;
use Gratora\Donors\DonorService;
use Gratora\Donors\MagicLinkService;
use Gratora\Receipts\OrgProfile;
use Gratora\Receipts\ReceiptContext;
use Gratora\Receipts\ReceiptIssuer;
use Gratora\Receipts\ReceiptRenderer;
use Gratora\Receipts\ReceiptRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

/**
 * Receipt tokens allow repeated downloads until expiry.
 *
 * @since 1.0.0
 */
final class ReceiptsController
{
    private const NAMESPACE = 'gratora/v1';

    /**
     * Renders per address per window.
     *
     * The token is the only auth here, it is good for thirty days, it is
     * multi-use, and it travels as a query parameter in an emailed URL. The
     * magic-link limiter counts misses, so a caller holding a working token is
     * never counted at all, and every hit is two decryptions and a full PDF
     * render that nothing caches. Being a GET, an <img src> on any page, a link
     * prefetcher or a mail-security scanner fires it, so one forwarded receipt
     * is an unbounded CPU amplifier pointed at this site.
     *
     * Well clear of a donor who saves their receipt a few times, or of several
     * donors behind one office address.
     */
    private const RENDER_MAX    = 20;
    private const RENDER_WINDOW = 900;

    /** @since 1.0.0 */
    public function __construct(
        private ReceiptRepository $receipts,
        private DonationRepository $donations,
        private DonorRepository $donors,
        private DonorService $donorService,
        private MagicLinkService $magicLinks,
        private AntiSpamGuard $spam,
        private ReceiptIssuer $issuer,
    ) {
    }

    /** @since 1.0.0 */
    public function registerRoutes(): void
    {
        register_rest_route(self::NAMESPACE, '/receipts/(?P<receipt_id>\d+)/download', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'download'],
            'permission_callback' => '__return_true',  // auth is the magic-link token
            'args'                => [
                'receipt_id' => ['type' => 'integer', 'required' => true],
                'token'      => ['type' => 'string', 'required' => true],
            ],
        ]);
    }

    /** @since 1.0.0 */
    public function download(WP_REST_Request $request): WP_Error|null
    {
        // Spent before the token is read, because a valid token is exactly what
        // this bounds: an invalid one is already counted by the magic-link
        // limiter, and a valid one was never counted anywhere.
        if ($err = $this->spam->consumeIpBudget('gratora_receipt', self::RENDER_MAX, self::RENDER_WINDOW)) {
            return $err;
        }

        $receiptId = (int) $request['receipt_id'];
        $rawToken  = (string) ($request['token'] ?? '');

        $valid = $this->magicLinks->validate($rawToken, 'download_receipt', $receiptId);
        if (! $valid) {
            return new WP_Error('gratora_invalid_token', __('Link is invalid or expired.', 'gratora'), ['status' => 403]);
        }

        $receipt = $this->receipts->findById($receiptId);
        if (! $receipt) {
            return new WP_Error('gratora_receipt_not_found', __('Receipt not found.', 'gratora'), ['status' => 404]);
        }

        if ($receipt->voided) {
            // 410, not 404: the donor is holding a link that worked, and
            // "not found" reads as a broken one. A voided receipt means the
            // donation was refunded in full, so say that instead.
            return new WP_Error(
                'gratora_receipt_voided',
                __('This receipt was withdrawn because the donation it covers was refunded in full. If that is not what you expected, please contact the organization.', 'gratora'),
                ['status' => 410]
            );
        }

        // Defense-in-depth: token must belong to the same donor as the receipt.
        if ($valid->donor_id !== $receipt->donor_id) {
            return new WP_Error('gratora_invalid_token', __('Link is invalid.', 'gratora'), ['status' => 403]);
        }

        $donation = $this->donations->findById($receipt->donation_id);
        $donor    = $this->donors->findById($receipt->donor_id);
        if (! $donation || ! $donor) {
            return new WP_Error('gratora_receipt_data_missing', __('Receipt data is no longer available.', 'gratora'), ['status' => 410]);
        }

        $ctx = new ReceiptContext(
            donation:      $donation,
            donor:         $donor,
            locale:        $receipt->locale,
            org:           $this->loadOrgProfile(),
            donor_email:   $this->donorService->decryptEmail($donor),
            donor_address: $this->donorService->decryptAddress($donor),
            donor_name:    $this->resolveDonorName($donation, $donor),
            campaign:      $this->loadCampaign($donation),
        );
        $ctx = $ctx->with('receipt_number', (string) $receipt->receipt_number);
        // The same answers the attached copy carried: without them the emailed
        // link handed the donor a different document under the same number.
        $ctx = $this->issuer->withCustomFields($ctx, $donation);
        $ctx = apply_filters('gratora.receipt.context', $ctx);

        $renderer = $this->findRendererById($receipt->renderer_id);
        if (! $renderer) {
            // 410, not 500: nothing is broken, the extension that produced
            // this document is no longer active. A donor following an emailed
            // link gets an explanation rather than an error, and the operator
            // gets a reason instead of a stack trace.
            //
            // Falling back to the generic renderer would be worse than either:
            // it would hand the donor a different, non-compliant document
            // under the same receipt number.
            return new WP_Error(
                'gratora_renderer_missing',
                __('This receipt was produced by an extension that is no longer active. Please contact the organization.', 'gratora'),
                ['status' => 410, 'renderer_id' => (string) $receipt->renderer_id]
            );
        }

        // Render in the donor's locale so re-downloads stay in the issued language.
        $switched = ($receipt->locale !== '' && $receipt->locale !== get_locale())
            ? (bool) switch_to_locale($receipt->locale)
            : false;
        try {
            $pdfBytes = $renderer->render($ctx);
        } catch (\Throwable $e) {
            // The donor is following a link from their own email. A fatal-error
            // page tells them nothing, and tells the org nothing either.
            ErrorLog::record('receipt.download', $e->getMessage(), [
                'donation_id' => (int) $receipt->donation_id,
                'donor_id'    => (int) $receipt->donor_id,
            ]);

            return new WP_Error(
                'gratora_receipt_render_failed',
                __('We could not produce this receipt right now. Please try again shortly, or contact the organization.', 'gratora'),
                ['status' => 500]
            );
        } finally {
            if ($switched) restore_previous_locale();
        }

        $this->stream($pdfBytes, $receipt->receipt_number);
        return null;
    }

    /** @since 1.0.0 */
    private function findRendererById(string $id): ?ReceiptRenderer
    {
        foreach ((array) apply_filters('gratora.receipt.renderers', []) as $r) {
            if ($r instanceof ReceiptRenderer && $r->id() === $id) return $r;
        }
        return null;
    }

    /** @since 1.0.0 */
    private function stream(string $bytes, string $filenameBase): void
    {
        $filename = preg_replace('/[^A-Za-z0-9_\-]/', '', $filenameBase) ?: 'receipt';
        nocache_headers();
        header('Content-Type: application/pdf');
        header('Content-Length: ' . strlen($bytes));
        header('Content-Disposition: attachment; filename="receipt-' . $filename . '.pdf"');
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $bytes is the binary PDF returned by ReceiptRenderer::render(), sent under its own application/pdf header; escaping it would corrupt the document.
        echo $bytes;
        exit;
    }

    /**
     * @return array<string,mixed>
     *
     * @since 1.0.0
     */
    private function loadOrgProfile(): array
    {
        return OrgProfile::load();
    }

    /** @since 1.0.0 */
    private function resolveDonorName(Donation $donation, Donor $donor): string
    {
        $first = $donation->donor_first_name;
        $last  = $donation->donor_last_name;
        if (($first ?? '') === '' && ($last ?? '') === '') {
            $first = $donor->first_name;
            $last  = $donor->last_name;
        }
        return trim((string) $first . ' ' . (string) $last);
    }

    /** @since 1.0.0 */
    private function loadCampaign(Donation $donation): ?Campaign
    {
        $cid = (int) ($donation->campaign_id ?? 0);
        return $cid > 0 ? Campaign::query()->where('id', $cid)->get() : null;
    }
}
