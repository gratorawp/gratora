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
use Dono\Settings\SettingsService;

/**
 * Wires the non-receipt donation email templates (offline instructions, refund
 * notice, pending notice). Each fires via Mailer::sendTemplate, so the
 * `enabled` toggle and the user-edited subject/body are both honored.
 *
 * @version 1.0.0
 */
final class DonationEmails extends HookProvider
{
    public function __construct(
        private Mailer $mailer,
        private DonorRepository $donors,
        private DonorService $donorService,
        private SettingsService $settings,
        private CampaignRepository $campaigns,
    ) {
    }

    protected function actions(): array
    {
        return [
            'dono.donation.intent_created' => 'onIntentCreated',
            // 3-arg: $donation, $reason, $metadata
            'dono.donation.pending'        => ['onPending', 10, 3],
            // 2-arg: $donation, $refund
            'dono.donation.refunded'       => ['onRefunded', 10, 2],
            // 2-arg: $donation, $plan
            'dono.recurring.renewed'       => ['onRecurringRenewed', 10, 2],
            // 2-arg: $plan, $reason
            'dono.recurring.cancelled'     => ['onRecurringCancelled', 10, 2],
            // 2-arg: $plan, $context
            'dono.recurring.renewal_failed' => ['onRecurringFailed', 10, 2],
            'dono.donation.completed'      => 'onDonationCompleted',
        ];
    }

    public function onIntentCreated(Donation $donation): void
    {
        if ($donation->gateway !== 'offline') return;

        // A hand-recorded donation is money already banked. It rides the
        // offline gateway because that is what it is, but sending the bank
        // details asks the donor to pay a cheque they posted six weeks ago.
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

    public function onRecurringRenewed(Donation $donation, RecurringPlan $plan): void
    {
        $email = $this->resolveDonorEmail($donation);
        if ($email === null) return;

        $this->mailer->sendTemplate('recurring_renewal', $email, [
            'donor_first_name'  => $this->donorFirstName($donation),
            'donor_name'        => $this->donorName($donation),
            'organisation_name' => (string) get_bloginfo('name'),
            'amount'            => Money::format((int) $donation->amount_cents, (string) $donation->currency),
            'campaign_title'    => $this->campaignTitle($donation),
            'reference'         => (string) $donation->reference,
            // Receipt number lives on the receipt row, which is async; left
            // blank when not yet issued. Subscribers wanting to delay can hook
            // on dono.async.receipt_issued instead.
            'receipt_number'    => '',
        ]);
    }

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
     * A renewal the gateway declined. The donor is the only person who can fix
     * it, so they are told while the plan is still alive rather than finding
     * out when it is cancelled.
     *
     * @param array<string,mixed> $context
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
     * a cheque, so no welcome goes out for one - but that cheque still crosses
     * 0 -> 1, and when the donor later gives online the count moves 1 -> 2, the
     * crossing never happens again, and they are never welcomed at all.
     * Counting what this donor has actually given themselves, rather than
     * watching a counter move, welcomes them the day they donate and never
     * twice: an existing repeat donor is already past one and stays silent.
     */
    public function onDonationCompleted(Donation $donation): void
    {
        // A ticket order, a rehearsal, or a cheque an admin typed in is not the
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
     * just completed. Scoped exactly as DonorAggregateSyncer scopes the counter
     * this replaces (real money, given as a gift), minus the hand-recorded ones.
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

    private function countsAsTheirOwn(Donation $donation): bool
    {
        return (string) $donation->kind === 'donation'
            && ! (bool) $donation->is_test
            && ChannelClassifier::classify((array) ($donation->source_attribution ?? [])) !== 'manual';
    }

    private function resolveDonorEmail(Donation $donation): ?string
    {
        $donor = $this->donors->findById((int) $donation->donor_id);
        if (! $donor) return null;
        $email = $this->donorService->decryptEmail($donor);
        return $email !== '' && $email !== null ? $email : null;
    }

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

    private function donorFirstName(Donation $donation): string
    {
        $first = trim((string) ($donation->donor_first_name ?? ''));
        if ($first !== '') return $first;
        $donor = $this->donors->findById((int) $donation->donor_id);
        return $donor ? trim((string) ($donor->first_name ?? '')) : '';
    }

    private function campaignTitle(Donation $donation): string
    {
        if (! $donation->campaign_id) return '';
        $campaign = $this->campaigns->findById((int) $donation->campaign_id);
        return $campaign ? (string) $campaign->title : '';
    }
}
