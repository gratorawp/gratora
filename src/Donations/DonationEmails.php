<?php

declare(strict_types=1);

namespace Dono\Donations;

use Dono\Campaigns\CampaignRepository;
use Dono\Donors\DonorRepository;
use Dono\Donors\DonorService;
use Dono\Donors\Portal\PortalPage;
use Dono\Foundation\Helpers\Money;
use Dono\Foundation\Hooks\HookProvider;
use Dono\Mail\Mailer;
use Dono\Recurring\RecurringPlan;
use Dono\Recurring\RecurringPlanChange;
use Dono\Settings\SettingsService;

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
            'dono.donation.intent_created' => 'onIntentCreated',
            'dono.donation.pending'        => ['onPending', 10, 3],
            'dono.donation.refunded'       => ['onRefunded', 10, 2],
            'dono.recurring.renewed'       => ['onRecurringRenewed', 10, 2],
            'dono.recurring.cancelled'     => ['onRecurringCancelled', 10, 2],
            'dono.recurring.renewal_failed' => ['onRecurringFailed', 10, 2],
            'dono.donation.completed'      => 'onDonationCompleted',
            // Fires for every plan change, donor-made or admin-made; the
            // handler decides whether to send.
            'dono.recurring.plan_changed'  => ['onPlanChanged', 10, 2],
        ];
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

        $this->mailer->sendTemplate('offline_instructions', $email, [
            'donor_name'        => $donorName,
            'organisation_name' => (string) get_bloginfo('name'),
            'campaign_title'    => $this->campaignTitle($donation),
            'amount'            => $amount,
            'reference'         => $reference,
            'instructions'      => $fill((string) ($offline['instructions'] ?? '')),
            'bank_details'      => $fill((string) ($offline['bank_details'] ?? '')),
        ]);
    }

    /** @since 1.0.0 */
    public function onPending(Donation $donation, string $reason, array $metadata): void
    {
        $email = $this->resolveDonorEmail($donation);
        if ($email === null) return;

        $this->mailer->sendTemplate('donation_pending', $email, [
            'donor_first_name'  => $this->donorFirstName($donation),
            'donor_name'        => $this->donorName($donation),
            'organisation_name' => (string) get_bloginfo('name'),
            'amount'            => Money::format((int) $donation->amount_cents, (string) $donation->currency),
            'campaign_title'    => $this->campaignTitle($donation),
            'reference'         => (string) $donation->reference,
        ]);
    }

    /** @since 1.0.0 */
    public function onRecurringRenewed(Donation $donation, RecurringPlan $plan): void
    {
        $email = $this->resolveDonorEmail($donation);
        if ($email === null) return;

        // No receipt number here: the receipt row is issued asynchronously and
        // does not exist yet. The receipt email carries it, and a notice that
        // needs it can be sent from dono.async.receipt_issued instead.
        $this->mailer->sendTemplate('recurring_renewal', $email, [
            'donor_first_name'  => $this->donorFirstName($donation),
            'donor_name'        => $this->donorName($donation),
            'organisation_name' => (string) get_bloginfo('name'),
            'amount'            => Money::format((int) $donation->amount_cents, (string) $donation->currency),
            'campaign_title'    => $this->campaignTitle($donation),
            'reference'         => (string) $donation->reference,
        ]);
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

        $this->mailer->sendTemplate('subscription_cancelled', $email, [
            'donor_first_name'  => $first,
            'donor_name'        => $name,
            'organisation_name' => (string) get_bloginfo('name'),
            'amount'            => Money::format((int) $plan->amount_cents, (string) $plan->currency),
            'campaign_title'    => $plan->campaign_id
                ? (($c = $this->campaigns->findById((int) $plan->campaign_id)) ? (string) $c->title : '')
                : '',
        ]);
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
            'change_amount' => 'recurring_amount_changed',
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

        $this->mailer->sendTemplate($template, $email, [
            'donor_first_name'  => trim((string) ($donor->first_name ?? '')),
            'donor_name'        => trim(($donor->first_name ?? '') . ' ' . ($donor->last_name ?? '')),
            'organisation_name' => (string) get_bloginfo('name'),
            'amount'            => Money::format((int) $plan->amount_cents, $currency),
            'old_amount'        => $oldCents !== null ? Money::format($oldCents, $currency) : '',
            'resumes_at'        => $this->onDate($plan->resume_at),
            'next_payment_at'   => $this->onDate($plan->next_payment_at),
            'portal_url'        => (new PortalPage())->url(),
            'campaign_title'    => $plan->campaign_id
                ? (($c = $this->campaigns->findById((int) $plan->campaign_id)) ? (string) $c->title : '')
                : '',
        ]);
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

        $this->mailer->sendTemplate('subscription_payment_failed', $email, [
            'donor_first_name'  => trim((string) ($donor->first_name ?? '')),
            'donor_name'        => trim(($donor->first_name ?? '') . ' ' . ($donor->last_name ?? '')),
            'organisation_name' => (string) get_bloginfo('name'),
            'amount'            => Money::format((int) $plan->amount_cents, (string) $plan->currency),
            'campaign_title'    => $plan->campaign_id
                ? (($c = $this->campaigns->findById((int) $plan->campaign_id)) ? (string) $c->title : '')
                : '',
            // The portal page, not a signed link: a declined payment is not a
            // request to sign in, and mailing a working session key on an event
            // the donor did not trigger is a worse trade than one extra click.
            'portal_url'        => (new PortalPage())->url(),
        ]);
    }

    /** @since 1.0.0 */
    public function onRefunded(Donation $donation, Refund $refund): void
    {
        $email = $this->resolveDonorEmail($donation);
        if ($email === null) return;

        $this->mailer->sendTemplate('donation_refunded', $email, [
            'donor_first_name'  => $this->donorFirstName($donation),
            'donor_name'        => $this->donorName($donation),
            'organisation_name' => (string) get_bloginfo('name'),
            'amount'            => Money::format((int) $refund->amount_cents, (string) $donation->currency),
            'campaign_title'    => $this->campaignTitle($donation),
            'reference'         => (string) $donation->reference,
        ]);
    }

    /**
     * The donor's first donation they made themselves: a one-off welcome,
     * separate from the transactional receipt.
     *
     * Not dono.donor.first_donation_completed, which is the aggregate's 0 -> 1
     * crossing. Nobody typed their address into this site when an admin entered
     * a check, so no welcome goes out for one - but that check still crosses
     * 0 -> 1, and when the donor later gives online the count moves 1 -> 2, the
     * crossing never happens again, and they are never welcomed at all.
     * Counting what this donor has actually given themselves, rather than
     * watching a counter move, welcomes them the day they donate and never
     * twice: an existing repeat donor is already past one and stays silent.
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

        $this->mailer->sendTemplate('donation_first', $email, [
            'donor_first_name'  => trim((string) ($donor->first_name ?? '')),
            'donor_name'        => trim(($donor->first_name ?? '') . ' ' . ($donor->last_name ?? '')),
            'organisation_name' => (string) get_bloginfo('name'),
        ]);
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
