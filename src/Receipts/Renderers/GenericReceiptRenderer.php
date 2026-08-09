<?php

declare(strict_types=1);

namespace Dono\Receipts\Renderers;

use Dono\Campaigns\Styling\StylePresets;
use Dono\Donations\Refund;
use Dono\Foundation\Helpers\Money;
use Dono\Foundation\Helpers\View;
use Dono\Receipts\PdfBuilder;
use Dono\Receipts\ReceiptContext;
use Dono\Receipts\ReceiptRenderer;

/**
 * Generic receipt renderer applied to every paid donation.
 *
 * No legal tax-deduction language; that is handled by country-specific
 * renderers.
 *
 * @version 1.0.0
 */
final class GenericReceiptRenderer implements ReceiptRenderer
{
    public function __construct(private PdfBuilder $pdf)
    {
    }

    public function id(): string
    {
        return 'generic.v1';
    }

    public function label(): string
    {
        return __('Generic Receipt', 'dono');
    }

    public function referenceScope(): string
    {
        return 'receipt';
    }

    public function appliesTo(ReceiptContext $ctx): bool
    {
        return $ctx->donation->status === 'paid';
    }

    public function render(ReceiptContext $ctx): string
    {
        $template       = $this->loadTemplate();
        $amountDisplay  = Money::format($ctx->donation->amount_cents, $ctx->donation->currency);
        $rendered       = $this->expandMergeTags($template, $ctx, $amountDisplay);
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
            'title'  => sprintf(__('Donation receipt %s', 'dono'), $ctx->donation->reference),
            'author' => $ctx->org['name'] ?? 'Dono',
            'subject' => __('Donation receipt', 'dono'),
        ]);
    }

    /**
     * Loads the user-editable template strings from settings, falling back to
     * built-in defaults so a stale option never produces a blank receipt.
     *
     * @return array{header_title:string,signoff:string,footer_note:string,show_tax_id:bool}
     */
    private function loadTemplate(): array
    {
        $stored = get_option('dono_receipt_settings', []);
        if (! is_array($stored)) $stored = [];

        $defaults = [
            'header_title'       => __('Donation receipt', 'dono'),
            'intro'              => '',
            'signoff'            => __('Thank you for your support.', 'dono'),
            'footer_note'        => __(
                "This is a non-fiscal acknowledgement of receipt. Whether your donation is tax-deductible depends on your local jurisdiction and the recipient organization's status. Keep this receipt for your records.",
                'dono'
            ),
            'show_tax_id'        => true,
            'show_donor_address' => false,
            'logo_url'           => '',
        ];

        $logoId  = (int) ($stored['logo_attachment_id'] ?? 0);
        $logoUrl = $logoId > 0 ? (string) wp_get_attachment_image_url($logoId, 'medium') : '';

        // Accent color from the org's default brand preset.
        $brandTokens = StylePresets::tokensFor(StylePresets::defaultId());
        $accent      = (string) ($brandTokens['dono-accent'] ?? '#1e8a4e');

        return [
            'header_title'       => trim((string) ($stored['header_title'] ?? '')) !== '' ? (string) $stored['header_title'] : $defaults['header_title'],
            'intro'              => (string) ($stored['intro'] ?? ''),
            'signoff'            => trim((string) ($stored['signoff']      ?? '')) !== '' ? (string) $stored['signoff']      : $defaults['signoff'],
            'footer_note'        => trim((string) ($stored['footer_note']  ?? '')) !== '' ? (string) $stored['footer_note']  : $defaults['footer_note'],
            'show_tax_id'        => array_key_exists('show_tax_id', $stored)        ? (bool) $stored['show_tax_id']        : $defaults['show_tax_id'],
            'show_donor_address' => array_key_exists('show_donor_address', $stored) ? (bool) $stored['show_donor_address'] : $defaults['show_donor_address'],
            'logo_url'           => $logoUrl,
            'accent_color'       => preg_match('/^#[0-9a-fA-F]{3,8}$/', $accent) ? $accent : '#1e8a4e',
        ];
    }

    /**
     * Substitutes merge tags in the template strings.
     *
     * @param array<string,mixed> $template
     * @return array<string,mixed>
     */
    private function expandMergeTags(array $template, ReceiptContext $ctx, string $amountDisplay): array
    {
        $donation = $ctx->donation;
        $donorName = trim((string) ($ctx->donor_name ?? ''));
        if ($donorName === '') $donorName = __('Friend', 'dono');

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
