<?php

declare(strict_types=1);

namespace Gratora\Donations;

use Gratora\Campaigns\CampaignRepository;
use Gratora\Donors\DonorRepository;
use Gratora\Donors\DonorService;
use Gratora\Donors\Portal\PortalPage;
use Gratora\Foundation\Helpers\Money;
use Gratora\Foundation\Hooks\HookProvider;
use Gratora\Mail\Mailer;
use Gratora\Receipts\OrgProfile;
use Gratora\Recurring\FrequencyMap;
use Gratora\Recurring\RecurringPlan;
use Gratora\Recurring\RecurringPlanChange;
use Gratora\Settings\SettingsService;

/**
 * Wires the non-receipt donation email templates (offline instructions, refund
 * notice, pending notice). Each fires via Mailer::sendTemplate, so the
 * `enabled` toggle and the user-edited subject/body are both honored.
 *
 * @since 1.0.0
 */
final class DonationEmails extends HookProvider
{
    /** @since 1.0.0 */
    public function __construct(
        private Mailer $mailer,
        private DonorRepository $donors,
        private DonorService $donorService,
        private SettingsService $settings,
        private CampaignRepository $campaigns,
    ) {
    }

    /** @since 1.0.0 */
    protected function actions(): array
    {
        return [
            'gratora.donation.intent_created' => 'onIntentCreated',
            'gratora.donation.pending'        => ['onPending', 10, 3],
            'gratora.donation.refunded'       => ['onRefunded', 10, 2],
            'gratora.recurring.renewed'       => ['onRecurringRenewed', 10, 2],
            'gratora.recurring.cancelled'     => ['onRecurringCancelled', 10, 2],
            'gratora.recurring.renewal_failed' => ['onRecurringFailed', 10, 2],
            'gratora.donation.completed'      => 'onDonationCompleted',
            // Fires for every plan change, donor-made or admin-made; the
            // handler decides whether to send.
            'gratora.recurring.plan_changed'  => ['onPlanChanged', 10, 2],
        ];
    }

    /**
     * Runs a send in the locale the donor was recorded in.
     *
     * Wraps the token building, not just the send: a frequency label and a
     * formatted date are __() and wp_date() calls made while the array is
     * built, so a switch that starts later mails a French donor a French
     * sentence containing "every month".
     *
     * @since 1.0.0
     */
    private function inDonorLocale(string $locale, callable $send): void
    {
        $switched = $locale !== '' && $locale !== get_locale() && switch_to_locale($locale);

        try {
            $send();
        } finally {
            if ($switched) restore_previous_locale();
        }
    }

    /** @since 1.0.0 */
    public function onIntentCreated(Donation $donation): void
    {
        if ($donation->gateway !== 'offline') return;

        // A hand-recorded donation is money already banked. It rides the
        // offline gateway because that is what it is, but sending the bank
        // details asks the donor to pay a check they posted six weeks ago.
        if (ChannelClassifier::classify((array) ($donation->source_attribution ?? [])) === 'manual') return;

        $email = $this->resolveDonorEmail($donation);
        if ($email === null) return;

        $gateways = $this->settings->get('gateways');
        $offline  = is_array($gateways['offline'] ?? null) ? $gateways['offline'] : [];

        $donorName = $this->donorName($donation);
        $amount    = Money::format((int) $donation->amount_cents, (string) $donation->currency);
        $reference = (string) $donation->reference;

        // The settings UI lets admins use these placeholders inside the
        // instructions / bank-details text; fill them before the email's own
        // single interpolation pass (which can't reach nested placeholders).
        $fill = static fn (string $s): string => strtr($s, [
            '{amount}'     => $amount,
            '{reference}'  => $reference,
            '{donor_name}' => $donorName,
        ]);

        $this->inDonorLocale((string) ($donation->locale ?? ''), fn (): bool => $this->mailer->sendTemplate($this->templateFor('offline_instructions', $donation), $email, [
            'donor_name'        => $donorName,
            // Advertised on this template by templateTags, and interpolate
            // replaces only what it is handed: unfilled it reached the donor as
            // literal braces in the one email that tells them how to pay.
            'donor_first_name'  => $this->donorFirstName($donation),
            'organisation_name' => OrgProfile::load()['name'],
            'campaign_title'    => $this->campaignTitle($donation),
            'amount'            => $amount,
            'reference'         => $reference,
            'instructions'      => $fill((string) ($offline['instructions'] ?? '')),
            'bank_details'      => $fill((string) ($offline['bank_details'] ?? '')),
        ]));
    }

    /** @since 1.0.0 */
    public function onPending(Donation $donation, string $reason, array $metadata): void
    {
        $email = $this->resolveDonorEmail($donation);
        if ($email === null) return;

        $this->inDonorLocale((string) ($donation->locale ?? ''), fn (): bool => $this->mailer->sendTemplate($this->templateFor('donation_pending', $donation), $email, [
            'donor_first_name'  => $this->donorFirstName($donation),
            'donor_name'        => $this->donorName($donation),
            'organisation_name' => OrgProfile::load()['name'],
            'amount'            => Money::format((int) $donation->amount_cents, (string) $donation->currency),
            'campaign_title'    => $this->campaignTitle($donation),
            'reference'         => (string) $donation->reference,
        ]));
    }

    /** @since 1.0.0 */
    public function onRecurringRenewed(Donation $donation, RecurringPlan $plan): void
    {
        $email = $this->resolveDonorEmail($donation);
        if ($email === null) return;

        // No receipt number here: the receipt row is issued asynchronously and
        // does not exist yet. The receipt email carries it, and a notice that
        // needs it can be sent from gratora.async.receipt_issued instead.
        $this->inDonorLocale((string) ($donation->locale ?? ''), fn (): bool => $this->mailer->sendTemplate('recurring_renewal', $email, [
            'donor_first_name'  => $this->donorFirstName($donation),
            'donor_name'        => $this->donorName($donation),
            'organisation_name' => OrgProfile::load()['name'],
            'amount'            => Money::format((int) $donation->amount_cents, (string) $donation->currency),
            'campaign_title'    => $this->campaignTitle($donation),
            'reference'         => (string) $donation->reference,
        ]));
    }

    /** @since 1.0.0 */
    public function onRecurringCancelled(RecurringPlan $plan, ?string $reason = null): void
    {
        $donor = $this->donors->findById((int) $plan->donor_id);
        if (! $donor) return;
        $email = $this->donorService->decryptEmail($donor);
        if ($email === null || $email === '') return;

        $name = trim(($donor->first_name ?? '') . ' ' . ($donor->last_name ?? ''));
        $first = trim((string) ($donor->first_name ?? ''));

        $this->inDonorLocale((string) ($donor->locale ?? ''), fn (): bool => $this->mailer->sendTemplate('subscription_cancelled', $email, [
            'donor_first_name'  => $first,
            'donor_name'        => $name,
            'organisation_name' => OrgProfile::load()['name'],
            'amount'            => Money::format((int) $plan->amount_cents, (string) $plan->currency),
            'campaign_title'    => $plan->campaign_id
                ? (($c = $this->campaigns->findById((int) $plan->campaign_id)) ? (string) $c->title : '')
                : '',
        ]));
    }

    /**
     * A plan someone changed. Cancellation already has its own notice through
     * the canceller, so it is not repeated here.
     *
     * Only sends when the change asked for it, which is admin-initiated
     * changes by default: a donor who just used the portal does not need an
     * email telling them what they did a second ago, but someone whose monthly
     * amount was altered for them has no other way of finding out.
     *
     * @since 1.0.0
     */
    public function onPlanChanged(RecurringPlan $plan, RecurringPlanChange $change): void
    {
        if (! $change->notifyDonor) return;

        $template = match ($change->action) {
            'change_amount'   => 'recurring_amount_changed',
            'change_interval' => 'recurring_interval_changed',
            'pause'         => 'recurring_paused',
            'resume'        => 'recurring_resumed',
            'skip_next'     => 'recurring_skipped',
            default         => null,
        };
        if ($template === null) return;

        $donor = $this->donors->findById((int) $plan->donor_id);
        if (! $donor) return;
        $email = $this->donorService->decryptEmail($donor);
        if ($email === null || $email === '') return;

        $currency = (string) $plan->currency;
        $oldCents = isset($change->detail['from_cents']) ? (int) $change->detail['from_cents'] : null;

        $this->inDonorLocale((string) ($donor->locale ?? ''), fn (): bool => $this->mailer->sendTemplate($template, $email, [
            'donor_first_name'  => trim((string) ($donor->first_name ?? '')),
            'donor_name'        => trim(($donor->first_name ?? '') . ' ' . ($donor->last_name ?? '')),
            'organisation_name' => OrgProfile::load()['name'],
            'amount'            => Money::format((int) $plan->amount_cents, $currency),
            'old_amount'        => $oldCents !== null ? Money::format($oldCents, $currency) : '',
            'frequency'         => FrequencyMap::label(
                FrequencyMap::fromInterval((string) $plan->interval_unit, (int) $plan->interval_count)
                    ?? ''
            ),
            'old_frequency'     => FrequencyMap::label((string) ($change->detail['from'] ?? '')),
            'resumes_at'        => $this->onDate($plan->resume_at),
            'next_payment_at'   => $this->onDate($plan->next_payment_at),
            'portal_url'        => (new PortalPage())->url(),
            'campaign_title'    => $plan->campaign_id
                ? (($c = $this->campaigns->findById((int) $plan->campaign_id)) ? (string) $c->title : '')
                : '',
        ]));
    }

    /**
     * A stored UTC timestamp as the site would write the date.
     *
     * @since 1.0.0
     */
    private function onDate(?string $timestamp): string
    {
        if ($timestamp === null || $timestamp === '') {
            return '';
        }
        $ts = strtotime($timestamp);

        return $ts ? wp_date((string) get_option('date_format', 'Y-m-d'), $ts) : '';
    }

    /**
     * A renewal the gateway declined. The donor is the only person who can fix
     * it, so they are told while the plan is still alive rather than finding
     * out when it is cancelled.
     *
     * @param array<string,mixed> $context
     *
     * @since 1.0.0
     */
    public function onRecurringFailed(RecurringPlan $plan, array $context = []): void
    {
        // Stripe and friends retry a failed invoice on their own schedule. One
        // notice per failing card helps; four is nagging a donor who already
        // knows, so only the first failure mails. The action still fires every
        // time for anything that wants the full picture.
        if ((int) ($context['attempt'] ?? 1) !== 1) return;

        $donor = $this->donors->findById((int) $plan->donor_id);
        if (! $donor) return;
        $email = $this->donorService->decryptEmail($donor);
        if ($email === null || $email === '') return;

        $this->inDonorLocale((string) ($donor->locale ?? ''), fn (): bool => $this->mailer->sendTemplate('subscription_payment_failed', $email, [
            'donor_first_name'  => trim((string) ($donor->first_name ?? '')),
            'donor_name'        => trim(($donor->first_name ?? '') . ' ' . ($donor->last_name ?? '')),
            'organisation_name' => OrgProfile::load()['name'],
            'amount'            => Money::format((int) $plan->amount_cents, (string) $plan->currency),
            'campaign_title'    => $plan->campaign_id
                ? (($c = $this->campaigns->findById((int) $plan->campaign_id)) ? (string) $c->title : '')
                : '',
            // The portal page, not a signed link: a declined payment is not a
            // request to sign in, and mailing a working session key on an event
            // the donor did not trigger is a worse trade than one extra click.
            'portal_url'        => (new PortalPage())->url(),
        ]));
    }

    /** @since 1.0.0 */
    public function onRefunded(Donation $donation, Refund $refund): void
    {
        $email = $this->resolveDonorEmail($donation);
        if ($email === null) return;

        $this->inDonorLocale((string) ($donation->locale ?? ''), fn (): bool => $this->mailer->sendTemplate($this->templateFor('donation_refunded', $donation), $email, [
            'donor_first_name'  => $this->donorFirstName($donation),
            'donor_name'        => $this->donorName($donation),
            'organisation_name' => OrgProfile::load()['name'],
            'amount'            => Money::format((int) $refund->amount_cents, (string) $donation->currency),
            'campaign_title'    => $this->campaignTitle($donation),
            'reference'         => (string) $donation->reference,
        ]));
    }

    /**
     * Welcome the donor on their first self-submitted donation. Manual entries affect
     * aggregates but must neither trigger nor consume the welcome.
     *
     * @since 1.0.0
     */
    public function onDonationCompleted(Donation $donation): void
    {
        // A ticket order, a rehearsal, or a check an admin typed in is not the
        // donor's own first donation, and must not be counted as one either:
        // otherwise it welcomes a donor whose real first donation is still to
        // come, on the strength of a row that will never be part of the count.
        if (! $this->countsAsTheirOwn($donation)) return;
        if ($this->ownDonationCount((int) $donation->donor_id) !== 1) return;

        $donor = $this->donors->findById((int) $donation->donor_id);
        if (! $donor) return;
        $email = $this->donorService->decryptEmail($donor);
        if ($email === null || $email === '') return;

        $this->inDonorLocale((string) ($donor->locale ?? ''), fn (): bool => $this->mailer->sendTemplate('donation_first', $email, [
            'donor_first_name'  => trim((string) ($donor->first_name ?? '')),
            'donor_name'        => trim(($donor->first_name ?? '') . ' ' . ($donor->last_name ?? '')),
            'organisation_name' => OrgProfile::load()['name'],
        ]));
    }

    /**
     * How many donations this donor has made themselves, counting the one that
     * just completed. Scoped exactly as DonorAggregateSyncer scopes its counter
     * (real money, given rather than exchanged), minus the hand-recorded ones.
     *
     * @since 1.0.0
     */
    private function ownDonationCount(int $donorId): int
    {
        $rows = Donation::query()
            ->where('donor_id', $donorId)
            ->whereIn('status', ['paid', 'partial_refund'])
            ->getAll();

        $count = 0;
        foreach ($rows as $row) {
            if ($this->countsAsTheirOwn($row)) $count++;
        }

        return $count;
    }

    /** @since 1.0.0 */
    private function countsAsTheirOwn(Donation $donation): bool
    {
        return (string) $donation->kind === 'donation'
            && ! (bool) $donation->is_test
            && ChannelClassifier::classify((array) ($donation->source_attribution ?? [])) !== 'manual';
    }

    /**
     * Which template tells this donor what happened to their money.
     *
     * Core's donation wording is a claim about what the money was, and an
     * add-on can move something through these rails that is not a donation:
     * a ticket buyer told "we have refunded your donation" is being told the
     * wrong thing about their own purchase, and unlike the receipt this is a
     * notice they still have to get. The neutral set states the same facts
     * without the claim, and the filter lets whoever owns the kind answer in
     * its own words, naming the event or the order.
     *
     * @since 1.0.0
     */
    private function templateFor(string $donationTemplate, Donation $donation): string
    {
        $neutral = [
            'offline_instructions' => 'payment_instructions',
            'donation_pending'     => 'payment_pending',
            'donation_refunded'    => 'payment_refunded',
        ];

        $template = (string) $donation->kind === 'donation'
            ? $donationTemplate
            : ($neutral[$donationTemplate] ?? $donationTemplate);

        return (string) apply_filters('gratora.email.donation_template', $template, $donationTemplate, $donation);
    }

    /** @since 1.0.0 */
    private function resolveDonorEmail(Donation $donation): ?string
    {
        $donor = $this->donors->findById((int) $donation->donor_id);
        if (! $donor) return null;
        $email = $this->donorService->decryptEmail($donor);
        return $email !== '' && $email !== null ? $email : null;
    }

    /** @since 1.0.0 */
    private function donorName(Donation $donation): string
    {
        $first = trim((string) ($donation->donor_first_name ?? ''));
        $last  = trim((string) ($donation->donor_last_name  ?? ''));
        $name  = trim($first . ' ' . $last);
        if ($name !== '') return $name;
        $donor = $this->donors->findById((int) $donation->donor_id);
        if (! $donor) return '';
        return trim(($donor->first_name ?? '') . ' ' . ($donor->last_name ?? ''));
    }

    /** @since 1.0.0 */
    private function donorFirstName(Donation $donation): string
    {
        $first = trim((string) ($donation->donor_first_name ?? ''));
        if ($first !== '') return $first;
        $donor = $this->donors->findById((int) $donation->donor_id);
        return $donor ? trim((string) ($donor->first_name ?? '')) : '';
    }

    /** @since 1.0.0 */
    private function campaignTitle(Donation $donation): string
    {
        if (! $donation->campaign_id) return '';
        $campaign = $this->campaigns->findById((int) $donation->campaign_id);
        return $campaign ? (string) $campaign->title : '';
    }
}
