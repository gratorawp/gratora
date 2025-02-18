<?php

declare(strict_types=1);

namespace Dono\Donations;

use Dono\Campaigns\CampaignRepository;
use Dono\Donors\DonorRepository;
use Dono\Donors\DonorService;
use Dono\Foundation\Helpers\Money;
use Dono\Foundation\Hooks\HookProvider;
use Dono\Mail\Mailer;
use Dono\Recurring\RecurringPlan;
use Dono\Settings\SettingsService;

/**
 * Wires the non-receipt donation email templates (offline instructions, refund
 * notice). Each fires via Mailer::sendTemplate, so the `enabled` toggle and
 * the user-edited subject/body are both honored.
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
        ];
    }

    public function onIntentCreated(Donation $donation): void
    {
        if ($donation->gateway !== 'offline') return;

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
