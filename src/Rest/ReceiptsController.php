<?php

declare(strict_types=1);

namespace Dono\Rest;

use Dono\Campaigns\Campaign;
use Dono\Donations\Donation;
use Dono\Donations\DonationRepository;
use Dono\Donors\Donor;
use Dono\Donors\DonorRepository;
use Dono\Donors\DonorService;
use Dono\Donors\MagicLinkService;
use Dono\Receipts\ReceiptContext;
use Dono\Receipts\ReceiptRenderer;
use Dono\Receipts\ReceiptRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

/**
 * Magic-link receipt re-download. The token is multi-use within its TTL so donors
 * can re-download from any device.
 *
 * @since 1.0.0
 */
final class ReceiptsController
{
    private const NAMESPACE = 'dono/v1';

    /** @since 1.0.0 */
    public function __construct(
        private ReceiptRepository $receipts,
        private DonationRepository $donations,
        private DonorRepository $donors,
        private DonorService $donorService,
        private MagicLinkService $magicLinks,
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
        $receiptId = (int) $request['receipt_id'];
        $rawToken  = (string) ($request['token'] ?? '');

        $valid = $this->magicLinks->validate($rawToken, 'download_receipt', $receiptId);
        if (! $valid) {
            return new WP_Error('dono_invalid_token', __('Link is invalid or expired.', 'dono'), ['status' => 403]);
        }

        $receipt = $this->receipts->findById($receiptId);
        if (! $receipt || $receipt->voided) {
            return new WP_Error('dono_receipt_not_found', __('Receipt not found.', 'dono'), ['status' => 404]);
        }

        // Defense-in-depth: token must belong to the same donor as the receipt.
        if ($valid->donor_id !== $receipt->donor_id) {
            return new WP_Error('dono_invalid_token', __('Link is invalid.', 'dono'), ['status' => 403]);
        }

        $donation = $this->donations->findById($receipt->donation_id);
        $donor    = $this->donors->findById($receipt->donor_id);
        if (! $donation || ! $donor) {
            return new WP_Error('dono_receipt_data_missing', __('Receipt data is no longer available.', 'dono'), ['status' => 410]);
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
        $ctx = apply_filters('dono.receipt.context', $ctx);

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
                'dono_renderer_missing',
                __('This receipt was produced by an extension that is no longer active. Please contact the organization.', 'dono'),
                ['status' => 410, 'renderer_id' => (string) $receipt->renderer_id]
            );
        }

        // Render in the donor's locale so re-downloads stay in the issued language.
        $switched = ($receipt->locale !== '' && $receipt->locale !== get_locale())
            ? (bool) switch_to_locale($receipt->locale)
            : false;
        try {
            $pdfBytes = $renderer->render($ctx);
        } finally {
            if ($switched) restore_previous_locale();
        }

        $this->stream($pdfBytes, $receipt->receipt_number);
        return null;
    }

    /** @since 1.0.0 */
    private function findRendererById(string $id): ?ReceiptRenderer
    {
        foreach ((array) apply_filters('dono.receipt.renderers', []) as $r) {
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
        $defaults = [
            'name'          => get_bloginfo('name'),
            'address_lines' => [],
            'tax_id'        => '',
            'email'         => get_option('admin_email'),
        ];
        $stored = get_option('dono_org_profile', []);
        return is_array($stored) ? array_merge($defaults, $stored) : $defaults;
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
