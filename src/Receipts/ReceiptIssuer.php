<?php

declare(strict_types=1);

namespace Dono\Receipts;

use Dono\Analytics\EventRecorder;
use Dono\Async\AsyncDispatcher;
use Dono\Campaigns\Campaign;
use Dono\Donations\Donation;
use Dono\Donations\DonationRepository;
use Dono\Donors\Donor;
use Dono\Donors\DonorRepository;
use Dono\Donors\DonorService;
use Dono\Donors\MagicLinkService;
use Dono\Foundation\Crypto\Crypto;
use Dono\Foundation\Helpers\Money;
use Dono\Foundation\Helpers\View;
use Dono\Foundation\References\ReferenceGenerator;
use Dono\Foundation\Time\Clock;
use Dono\Forms\Blocks\CustomFieldLabels;
use Dono\Forms\Form;
use Dono\Mail\Mailer;
use Dono\Settings\SettingsService;
use Dono\Vendor\Queryable\DB;

/**
 * Issues and emails receipts when donations are paid.
 *
 * On `dono.donation.completed` an async job runs each applicable renderer,
 * persists a Receipt row, renders the PDF in memory, and emails the donor.
 * No file storage; re-sends regenerate from the same context.
 *
 * @since 1.0.0
 */
final class ReceiptIssuer
{
    private const HOOK = 'dono.async.issue_receipt';

    /** @since 1.0.0 */
    public function __construct(
        private DonationRepository $donations,
        private DonorRepository $donors,
        private DonorService $donorService,
        private ReceiptRepository $receipts,
        private ReferenceGenerator $references,
        private EventRecorder $events,
        private AsyncDispatcher $async,
        private MagicLinkService $magicLinks,
        private Clock $clock,
        private Mailer $mailer,
        private SettingsService $settings,
        // Crypto for decrypting donation.custom_data_encrypted directly here
        // so we don't have to drag the full DonationService (which would risk
        // a circular dep through DonationService -> AggregateSyncer chain).
        private Crypto $crypto,
    ) {
    }

    /** @since 1.0.0 */
    public function register(): void
    {
        add_action('dono.donation.completed', [$this, 'onDonationCompleted']);
        add_action(self::HOOK, [$this, 'issueForDonation']);
    }

    /** @since 1.0.0 */
    public function onDonationCompleted(Donation $donation): void
    {
        // Ticket orders ride the donations table but are a purchase, not a
        // donation. Issuing a donation receipt for one misstates what the
        // payer received, and that receipt feeds the tax-deductible statement.
        // Add-ons that sell things issue their own confirmation.
        //
        // The filter exists because "no receipt" is the safe answer, not the
        // right one: a gala ticket is a deductible contribution minus the
        // value of the meal, and an add-on that can state that value should be
        // able to turn issuance back on. The default is unchanged.
        $shouldIssue = (string) ($donation->kind ?? 'donation') === 'donation';
        if (! apply_filters('dono.receipt.should_issue', $shouldIssue, $donation)) {
            return;
        }

        $this->async->enqueue(self::HOOK, ['donation_id' => $donation->id]);
    }

    /**
     * Admin "resend receipt": clear sent_to_email_at and re-queue the issuer.
     *
     * @since 1.0.0
     */
    public function requeueForDonation(int $donationId): bool
    {
        $donation = $this->donations->findById($donationId);
        if (! $donation || $donation->status !== 'paid') {
            return false;
        }

        $existing = $this->receipts->forDonation($donationId);
        foreach ($existing as $r) {
            if ($r->voided) continue;
            $r->sent_to_email_at = null;
            $r->save();
        }

        $this->async->enqueue(self::HOOK, ['donation_id' => $donationId]);
        return true;
    }

    /**
     * @param array{donation_id:int}|int $args
     *
     * @since 1.0.0
     */
    public function issueForDonation(mixed $args): void
    {
        $donationId = is_array($args) ? (int) ($args['donation_id'] ?? 0) : (int) $args;
        if ($donationId <= 0) return;

        $donation = $this->donations->findById($donationId);
        if (! $donation || $donation->status !== 'paid') return;

        $donor = $this->donors->findById($donation->donor_id);
        if (! $donor) return;

        $email = $this->donorService->decryptEmail($donor);

        $ctx = new ReceiptContext(
            donation:      $donation,
            donor:         $donor,
            locale:        $donation->locale ?: 'en',
            org:           $this->loadOrgProfile(),
            donor_email:   $email,
            donor_address: $this->donorService->decryptAddress($donor),
            donor_name:    $this->resolveDonorName($donation, $donor),
            campaign:      $this->loadCampaign($donation),
        );

        $ctx = apply_filters('dono.receipt.context', $ctx);

        foreach ($this->collectRenderers() as $renderer) {
            if (! $renderer->appliesTo($ctx)) continue;
            $this->processRenderer($renderer, $ctx);
        }
    }

    /** @since 1.0.0 */
    private function loadCampaign(Donation $donation): ?Campaign
    {
        $cid = (int) ($donation->campaign_id ?? 0);
        return $cid > 0 ? Campaign::query()->where('id', $cid)->get() : null;
    }

    /**
     * Decrypted custom_data answers for the donation. Empty array when none.
     *
     * @return array<string,mixed>
     *
     * @since 1.0.0
     */
    private function decryptCustomData(Donation $donation): array
    {
        if ($donation->custom_data_encrypted === null || $donation->custom_data_encrypted === '') {
            return [];
        }
        $plain = $this->crypto->decrypt($donation->custom_data_encrypted);
        if ($plain === null) return [];
        $data = json_decode($plain, true);
        return is_array($data) ? $data : [];
    }

    /**
     * Field labels keyed by custom_data key. Pulled from the form's block
     * markup so the receipt PDF can render "Question -> Answer" instead of
     * raw machine-keyed JSON.
     *
     * @return array<string,string>
     *
     * @since 1.0.0
     */
    private function loadCustomFieldLabels(Donation $donation): array
    {
        $formId = (int) ($donation->form_id ?? 0);
        if ($formId <= 0) return [];
        $form = Form::query()->where('id', $formId)->get();
        if (! $form) return [];
        return CustomFieldLabels::forBlocks((string) $form->blocks);
    }

    /** @since 1.0.0 */
    private function processRenderer(ReceiptRenderer $renderer, ReceiptContext $ctx): void
    {
        $existing = $this->receipts->findFor($ctx->donation->id, $renderer->id());

        if ($existing && $existing->sent_to_email_at !== null) {
            return;
        }

        if ($existing) {
            $receipt = $existing;
            $created = false;
        } else {
            [$receipt, $created] = $this->createReceiptRecord($renderer, $ctx);
        }

        // Surface the receipt number to the renderer's merge-tag expander so
        // {receipt_number} resolves on the PDF and not just on the email subject.
        $ctx = $ctx->with('receipt_number', (string) $receipt->receipt_number);

        // The PDF renders these as "Question - Answer" rows.
        $custom = $this->decryptCustomData($ctx->donation);
        if ($custom !== []) {
            $ctx = $ctx->with('custom_data', $custom);
            $ctx = $ctx->with('custom_field_labels', $this->loadCustomFieldLabels($ctx->donation));
        }

        // Switch WP locale to the donor's for the duration of render + email
        // so __() resolves in their language, then restore.
        $switched = $this->switchLocale($ctx->locale);
        try {
            $pdfBytes = $renderer->render($ctx);

            // Only the runner that actually inserted the row announces issuance;
            // a concurrent issue that found the existing row must not re-fire.
            if ($created) {
                do_action('dono.receipt.issued', $receipt, $ctx);
                // Campaign and amount come from the donation being receipted.
                // Without them the donor timeline shows a receipt against no
                // campaign and no figure.
                $this->events->record('receipt.issued', [
                    'donor_id'     => $ctx->donor->id,
                    'donation_id'  => $ctx->donation->id,
                    'receipt_id'   => $receipt->id,
                    'campaign_id'  => $ctx->donation->campaign_id,
                    'form_id'      => $ctx->donation->form_id,
                    'amount_cents' => $ctx->donation->amount_cents,
                    'currency'     => $ctx->donation->currency,
                    'payload'      => ['renderer_id' => $renderer->id()],
                ]);
            }

            if ($ctx->donor_email !== null && $ctx->donor_email !== '') {
                // Atomic single-sender claim: flip sent_to_email_at from NULL in
                // one UPDATE so only one of two racing runners sends the email.
                // Released back to NULL on a soft failure so a retry can resend.
                $now     = $this->clock->now()->format('Y-m-d H:i:s');
                $claimed = Receipt::query()
                    ->where('id', $receipt->id)
                    ->whereNull('sent_to_email_at')
                    ->update(['sent_to_email_at' => $now])
                    ->affectedRows;

                if ($claimed > 0) {
                    $sent = $this->sendEmail($receipt, $ctx, $pdfBytes);
                    if ($sent) {
                        $receipt->sent_to_email_at = $now;
                        do_action('dono.receipt.email_sent', $receipt);
                    } else {
                        Receipt::query()
                            ->where('id', $receipt->id)
                            ->update(['sent_to_email_at' => null]);
                        $receipt->sent_to_email_at = null;
                        do_action('dono.receipt.email_failed', $receipt);
                    }
                }
            }
        } finally {
            if ($switched) {
                restore_previous_locale();
            }
        }
    }

    /**
     * Switch WP to the donor's locale if it differs from the current one and
     * is a known locale. Returns true when an actual switch happened (caller
     * must restore_previous_locale()), false otherwise.
     *
     * @since 1.0.0
     */
    private function switchLocale(string $locale): bool
    {
        if ($locale === '' || $locale === get_locale()) {
            return false;
        }
        return (bool) switch_to_locale($locale);
    }

    /**
     * @return array{0: Receipt, 1: bool} The receipt and whether THIS call
     *   created it (false when a concurrent issue won the insert race).
     *
     * @since 1.0.0
     */
    private function createReceiptRecord(ReceiptRenderer $renderer, ReceiptContext $ctx): array
    {
        $receipt = Receipt::make();
        $receipt->donation_id    = $ctx->donation->id;
        $receipt->donor_id       = $ctx->donor->id;
        $receipt->renderer_id    = $renderer->id();
        $receipt->country        = $ctx->donation->country;
        $receipt->locale         = $ctx->locale;
        $receipt->voided         = false;
        $receipt->issued_at      = $this->clock->now()->format('Y-m-d H:i:s');
        try {
            // Allocate the gap-free number and insert in one transaction: if the
            // insert loses the UNIQUE(donation_id, renderer_id) race, the counter
            // increment rolls back too, so the tax sequence never skips a number.
            DB::transaction(function () use ($receipt, $renderer): void {
                $receipt->receipt_number = $this->references->next($renderer->referenceScope());
                $receipt->save();
            });
        } catch (\Throwable $e) {
            // A concurrent issue job already inserted the row (DB unique on
            // donation_id + renderer_id). Use the existing receipt rather than
            // duplicating it (a dup would also send a second receipt email).
            $existing = $this->receipts->findFor($ctx->donation->id, $renderer->id());
            if ($existing) return [$existing, false];
            throw $e;
        }
        return [$receipt, true];
    }

    /** @since 1.0.0 */
    private function sendEmail(Receipt $receipt, ReceiptContext $ctx, string $pdfBytes): bool
    {
        $emailCfg = $this->settings->get('email');
        $template = $emailCfg['templates']['donation_receipt'] ?? [];

        // Admin can disable the receipt email entirely from Settings → Email.
        // The receipt row + PDF still exist so they can be downloaded from the
        // admin / donor portal, but no email goes out.
        if (array_key_exists('enabled', $template) && empty($template['enabled'])) {
            return false;
        }

        $orgName = (string) ($ctx->org['name'] ?? get_bloginfo('name'));

        // 30-day magic-link token for the re-download URL.
        $rawToken    = $this->magicLinks->issue($ctx->donor->id, 'download_receipt', $receipt->id);
        $downloadUrl = rest_url("dono/v1/receipts/{$receipt->id}/download")
                     . '?token=' . rawurlencode($rawToken);

        $fullName  = (string) $ctx->donor_name;
        $firstName = $fullName !== '' ? explode(' ', $fullName)[0] : '';
        $tokens = [
            'donor_first_name'  => $firstName,
            'donor_name'        => $fullName,
            'amount'            => Money::format((int) $ctx->donation->amount_cents, (string) $ctx->donation->currency),
            'campaign_title'    => (string) ($ctx->campaign->title ?? ''),
            'receipt_number'    => (string) $receipt->receipt_number,
            'organisation_name' => $orgName,
            'reference'         => (string) $ctx->donation->reference,
            'download_url'      => $downloadUrl,
        ];
        $tags = [];
        foreach ($tokens as $k => $v) {
            $tags['{' . $k . '}'] = (string) $v;
        }

        $subject = (string) ($template['subject'] ?? '');
        $subject = strtr($subject, $tags);
        if (trim($subject) === '') {
            /* translators: %s: donation reference number */
            $subject = sprintf(__('Your donation receipt - %s', 'dono-fundraising-platform'), $ctx->donation->reference);
        }

        // Honor the user-edited body when non-empty; otherwise fall back to
        // the bundled template view so a freshly-installed plugin still emails
        // something sensible.
        $bodyTemplate = (string) ($template['body'] ?? '');
        if (trim($bodyTemplate) !== '') {
            $body = nl2br(esc_html(strtr($bodyTemplate, $tags)));
            // Test-mode banner: never trust the body author to remember the
            // "this was a test" warning, so we always inject it on test-mode
            // donations whatever the body content is.
            if (! empty($ctx->donation->is_test)) {
                $body = '<p style="background:#fef2f2;border:1px solid #b91c1c;color:#b91c1c;font-weight:700;text-align:center;padding:10px;border-radius:6px;margin:0 0 20px;">'
                      . esc_html__('Test donation. No real payment was made.', 'dono-fundraising-platform')
                      . '</p>'
                      . $body;
            }
            // Stitch the download link on the end so admins editing the body
            // don't have to remember to include it. wp_kses_post would strip
            // the anchor target so build it with explicit safe markup.
            $body .= sprintf(
                '<p><a href="%s">%s</a></p>',
                esc_url($downloadUrl),
                esc_html__('Download receipt', 'dono-fundraising-platform')
            );
        } else {
            $body = View::load('Receipts.email', [
                'donor'        => $ctx->donor,
                'donor_name'   => (string) $ctx->donor_name,
                'donation'     => $ctx->donation,
                'org_name'     => $orgName,
                'download_url' => $downloadUrl,
            ]);
        }

        // Temp file for wp_mail attachment API, unlinked after send.
        $tmpPath = $this->writeTempPdf($pdfBytes, $ctx->donation->reference);

        // Mailer::sendRaw already applies email.bcc_admin from settings when
        // no explicit bcc is passed - no extra handling needed here.
        try {
            $sent = $this->mailer->sendRaw($ctx->donor_email, $subject, $body, [
                'html'        => true,
                'attachments' => [$tmpPath],
            ]);
        } finally {
            // finally, not after the call: the file holds the donor's name,
            // address and what they gave, and a mailer that throws would
            // otherwise leave it sitting in the system temp directory.
            @unlink($tmpPath);
        }

        return $sent;
    }

    /**
     * Name for this receipt: the per-donation snapshot, falling back to the
     * donor record.
     *
     * @since 1.0.0
     */
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
    private function writeTempPdf(string $bytes, string $reference): string
    {
        $tmp = get_temp_dir() . 'dono-receipt-' . $reference . '-' . bin2hex(random_bytes(4)) . '.pdf';
        file_put_contents($tmp, $bytes);
        return $tmp;
    }

    /**
     * Re-renders the PDF for a previously-issued receipt.
     *
     * Returns null if the receipt, donation, or renderer cannot be resolved.
     *
     * @since 1.0.0
     */
    public function renderReceiptPdf(int $receiptId): ?string
    {
        $receipt = $this->receipts->findById($receiptId);
        if (! $receipt) return null;

        $donation = $this->donations->findById($receipt->donation_id);
        if (! $donation) return null;

        $donor = $this->donors->findById($donation->donor_id);
        if (! $donor) return null;

        $renderer = null;
        foreach ($this->collectRenderers() as $r) {
            if ($r->id() === $receipt->renderer_id) { $renderer = $r; break; }
        }
        if (! $renderer) return null;

        $ctx = new ReceiptContext(
            donation:      $donation,
            donor:         $donor,
            locale:        (string) $receipt->locale ?: ($donation->locale ?: 'en'),
            org:           $this->loadOrgProfile(),
            donor_email:   $this->donorService->decryptEmail($donor),
            donor_address: $this->donorService->decryptAddress($donor),
            donor_name:    $this->resolveDonorName($donation, $donor),
            campaign:      $this->loadCampaign($donation),
        );
        $ctx = $ctx->with('receipt_number', (string) $receipt->receipt_number);

        $custom = $this->decryptCustomData($donation);
        if ($custom !== []) {
            $ctx = $ctx->with('custom_data', $custom);
            $ctx = $ctx->with('custom_field_labels', $this->loadCustomFieldLabels($donation));
        }

        $ctx = apply_filters('dono.receipt.context', $ctx);

        try {
            return $renderer->render($ctx);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * @return array<ReceiptRenderer>
     *
     * @since 1.0.0
     */
    private function collectRenderers(): array
    {
        return (array) apply_filters('dono.receipt.renderers', []);
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
}
