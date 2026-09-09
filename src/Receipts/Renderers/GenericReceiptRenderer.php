<?php

declare(strict_types=1);

namespace Gratora\Receipts\Renderers;

use Gratora\Campaigns\Campaign;
use Gratora\Campaigns\Styling\CampaignStyleResolver;
use Gratora\Campaigns\Styling\StylePresets;
use Gratora\Campaigns\Styling\Tokens;
use Gratora\Donations\Refund;
use Gratora\Foundation\Helpers\Money;
use Gratora\Foundation\Helpers\View;
use Gratora\Receipts\PdfBuilder;
use Gratora\Receipts\ReceiptContext;
use Gratora\Receipts\ReceiptRenderer;

/**
 * Generic receipt renderer applied to every paid donation.
 *
 * No legal tax-deduction language; that is handled by country-specific
 * renderers.
 *
 * @since 1.0.0
 */
final class GenericReceiptRenderer implements ReceiptRenderer
{
    /** @since 1.0.0 */
    public function __construct(private PdfBuilder $pdf)
    {
    }

    /** @since 1.0.0 */
    public function id(): string
    {
        return 'generic.v1';
    }

    /** @since 1.0.0 */
    public function label(): string
    {
        return __('Generic Receipt', 'gratora');
    }

    /** @since 1.0.0 */
    public function referenceScope(): string
    {
        return 'receipt';
    }

    /** @since 1.0.0 */
    public function appliesTo(ReceiptContext $ctx): bool
    {
        // A partial refund included: the document prints the refunded line and
        // the net total, so it still states what the org kept.
        return in_array((string) $ctx->donation->status, ['paid', 'partial_refund'], true);
    }

    /** @since 1.0.0 */
    public function render(ReceiptContext $ctx): string
    {
        $template       = $this->loadTemplate($ctx->campaign);
        $amountDisplay  = Money::format($ctx->donation->amount_cents, $ctx->donation->currency);

        // When part of the payment bought something, the prose is about the
        // donation that is left: "your donation of" the whole charge would
        // state the price of a seat as a contribution. The lines below still
        // show what was actually paid.
        $goodsCents = max(0, (int) ($ctx->extras['goods_received_cents'] ?? 0));
        $rendered   = $this->expandMergeTags(
            $template,
            $ctx,
            $goodsCents > 0
                ? Money::format(
                    max(0, (int) $ctx->donation->amount_cents - $goodsCents),
                    $ctx->donation->currency
                )
                : $amountDisplay
        );
        // Donation has no `refunded_amount_cents` column; the source of truth
        // is the Refund table. Sum successful refunds for this donation so the
        // PDF can show a clear refunded line + the refunded amount.
        $refundedCents  = (int) Refund::query()
            ->where('donation_id', (int) $ctx->donation->id)
            ->where('status', 'succeeded')
            ->sum('amount_cents');

        // Key is `receipt_template` not `template` because View::renderFile
        // already has a `$template` parameter (the view file path) and
        // extract(..., EXTR_SKIP) silently drops keys that collide.
        $html = View::load('Receipts.generic', [
            'donation'        => $ctx->donation,
            'donor'           => $ctx->donor,
            'donor_name'      => (string) ($ctx->donor_name ?? ''),
            'donor_address'   => (string) ($ctx->donor_address ?? ''),
            'org'             => $ctx->org,
            'locale'          => $ctx->locale,
            'extras'          => $ctx->extras,
            'amount_display'  => $amountDisplay,
            'receipt_number'  => (string) ($ctx->extras['receipt_number'] ?? ''),
            'receipt_template'=> $rendered,
            'refunded_cents'  => $refundedCents,
            'refunded_display'=> $refundedCents > 0
                ? Money::format($refundedCents, $ctx->donation->currency)
                : '',
            'custom_data'     => is_array($ctx->extras['custom_data'] ?? null)
                ? $ctx->extras['custom_data']
                : [],
            'custom_field_labels' => is_array($ctx->extras['custom_field_labels'] ?? null)
                ? $ctx->extras['custom_field_labels']
                : [],
        ]);

        return $this->pdf->fromHtml($html, [
            /* translators: %s: human-readable donation reference. */
            'title'  => sprintf(__('Donation receipt %s', 'gratora'), $ctx->donation->reference),
            'author' => $ctx->org['name'] ?? 'Gratora',
            'subject' => __('Donation receipt', 'gratora'),
        ]);
    }

    /**
     * Loads the user-editable template strings from settings, falling back to
     * built-in defaults so a stale option never produces a blank receipt.
     *
     * @return array{header_title:string,signoff:string,footer_note:string,show_tax_id:bool}
     *
     * @since 1.0.0
     */
    private function loadTemplate(?Campaign $campaign = null): array
    {
        $stored = get_option('gratora_receipt_settings', []);
        if (! is_array($stored)) $stored = [];

        $defaults = [
            'header_title'       => __('Donation receipt', 'gratora'),
            'intro'              => '',
            // The wording the Receipts panel shows and the admin believes is in
            // effect. Two default sets for one field disagreed about it.
            'signoff'            => __('Thank you for your support, {donor_name}.', 'gratora'),
            'footer_note'        => __(
                "This is a non-fiscal acknowledgement of receipt. Whether your donation is tax-deductible depends on your local jurisdiction and the recipient organization's status. Keep this receipt for your records.",
                'gratora'
            ),
            'show_tax_id'        => true,
            'show_donor_address' => false,
            'logo_url'           => '',
        ];

        $logoId  = (int) ($stored['logo_attachment_id'] ?? 0);
        $logoUrl = $logoId > 0 ? (string) wp_get_attachment_image_url($logoId, 'medium') : '';

        // The campaign's own accent, so the receipt looks like the page the
        // donation was made on. The org default stands in when there is no
        // campaign behind the donation.
        $accent = (new CampaignStyleResolver())->accentFor($campaign);

        return [
            // Absent means never set, so the default applies; an empty string
            // means the admin cleared the field. Treating the two the same put
            // back text they had deliberately removed, and made one field mean
            // the opposite of what it means on the annual statement.
            'header_title'       => array_key_exists('header_title', $stored) ? (string) $stored['header_title'] : $defaults['header_title'],
            'intro'              => (string) ($stored['intro'] ?? ''),
            'signoff'            => array_key_exists('signoff', $stored)      ? (string) $stored['signoff']      : $defaults['signoff'],
            'footer_note'        => array_key_exists('footer_note', $stored)  ? (string) $stored['footer_note']  : $defaults['footer_note'],
            'show_tax_id'        => array_key_exists('show_tax_id', $stored)        ? (bool) $stored['show_tax_id']        : $defaults['show_tax_id'],
            'show_donor_address' => array_key_exists('show_donor_address', $stored) ? (bool) $stored['show_donor_address'] : $defaults['show_donor_address'],
            'logo_url'           => $logoUrl,
            'accent_color'       => Tokens::printColor($accent, '#211d3f'),
        ];
    }

    /**
     * @param array<string,mixed> $template
     * @return array<string,mixed>
     *
     * @since 1.0.0
     */
    private function expandMergeTags(array $template, ReceiptContext $ctx, string $amountDisplay): array
    {
        $donation = $ctx->donation;
        $donorName = trim((string) ($ctx->donor_name ?? ''));
        if ($donorName === '') $donorName = __('Friend', 'gratora');

        $replacements = [
            '{donor_name}'        => $donorName,
            '{donor_email}'       => (string) ($ctx->donor_email ?? ''),
            '{organisation_name}' => (string) ($ctx->org['name'] ?? ''),
            '{amount}'            => $amountDisplay,
            '{campaign_title}'    => (string) ($ctx->campaign->title ?? ''),
            '{receipt_number}'    => (string) ($ctx->extras['receipt_number'] ?? ''),
            '{date}'              => $donation->paid_at
                ? wp_date(get_option('date_format'), strtotime($donation->paid_at))
                : '',
            '{reference}'         => (string) $donation->reference,
        ];

        $apply = static fn (string $s): string => strtr($s, $replacements);

        return [
            'header_title'       => $apply((string) $template['header_title']),
            'intro'              => $apply((string) $template['intro']),
            'signoff'            => $apply((string) $template['signoff']),
            'footer_note'        => $apply((string) $template['footer_note']),
            'show_tax_id'        => (bool) $template['show_tax_id'],
            'show_donor_address' => (bool) $template['show_donor_address'],
            'logo_url'           => (string) $template['logo_url'],
            'accent_color'       => (string) $template['accent_color'],
        ];
    }
}
