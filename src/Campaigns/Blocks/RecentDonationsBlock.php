<?php

declare(strict_types=1);

namespace Dono\Campaigns\Blocks;

use Dono\Campaigns\CampaignRepository;
use Dono\Donations\DonationRepository;
use Dono\Donors\Donor;
use Dono\Donors\DonorAvatars;
use Dono\Foundation\Helpers\View;

/**
 * Renders the most recent paid donations for a campaign.
 *
 * @since 1.0.0
 */
final class RecentDonationsBlock extends CampaignBlock
{
    /** @since 1.0.0 */
    public function __construct(
        CampaignRepository $campaigns,
        private readonly DonationRepository $donations,
        private readonly DonorAvatars $avatars,
    ) {
        parent::__construct($campaigns);
    }

    /** @since 1.0.0 */
    public function name(): string
    {
        return 'dono/recent-donations';
    }

    /** @since 1.0.0 */
    public function attributes(): array
    {
        return $this->campaignIdAttr() + [
            'title'          => ['type' => 'string',  'default' => ''],
            'emptyText'      => ['type' => 'string',  'default' => ''],
            'limit'          => ['type' => 'integer', 'default' => 10],
            'showAmount'     => ['type' => 'boolean', 'default' => true],
            'showTime'       => ['type' => 'boolean', 'default' => true],
            'showMessage'    => ['type' => 'boolean', 'default' => true],
            'showAnonymous'  => ['type' => 'boolean', 'default' => true],
        ];
    }

    /** @since 1.0.0 */
    public function render(array $attrs, string $content): string
    {
        $campaign = $this->resolveCampaign($attrs);
        if (! $campaign) return $this->notBoundNotice($attrs);

        $limit         = max(1, min(50, (int) ($attrs['limit'] ?? 10)));
        $showAnonymous = (bool) ($attrs['showAnonymous'] ?? true);

        $donations = $this->donations->recentForCampaign((int) $campaign->id, $limit, $showAnonymous);

        $donorIds = array_values(array_unique(array_filter(array_map(
            static fn ($d) => (int) $d->donor_id,
            $donations
        ))));

        $donorsById = [];
        if ($donorIds) {
            foreach (Donor::query()->whereIn('id', $donorIds)->getAll() as $d) {
                $donorsById[(int) $d->id] = $d;
            }
        }

        $nowTs = time();

        $anonymousByDonor = [];
        foreach ($donations as $d) {
            if ($d->is_anonymous) $anonymousByDonor[(int) $d->donor_id] = true;
        }
        $avatarUrls = $this->avatars->urlsFor($donorsById, $anonymousByDonor);

        $entries = [];
        foreach ($donations as $donation) {
            $isAnonymous = (bool) $donation->is_anonymous;
            $donor       = $donorsById[(int) $donation->donor_id] ?? null;
            $hidden      = $donor !== null && $donor->public_hidden_at !== null;
            $name        = $donor
                ? trim((string) $donor->first_name . ' ' . (string) $donor->last_name)
                : '';

            // An admin hiding a donor has to reach the name and the message
            // too. A suppressed picture beside their name and their words
            // would be no answer at all.
            if ($isAnonymous || $name === '' || $hidden) {
                $name = __('Anonymous', 'dono');
                $isAnonymous = true;
            }

            $paidAt = $donation->paid_at ?: $donation->created_at;
            $paidTs = strtotime((string) $paidAt) ?: $nowTs;
            $timeAgo = sprintf(
                /* translators: %s: human-readable time difference, e.g. "5 minutes" */
                __('%s ago', 'dono'),
                human_time_diff($paidTs, $nowTs)
            );

            $entries[] = [
                'name'         => $name,
                'is_anonymous' => $isAnonymous,
                'avatar_url'   => $isAnonymous ? '' : ($avatarUrls[(int) $donation->donor_id] ?? ''),
                // Net of refunds, in the donation's own currency, so a partly
                // refunded donation agrees with the campaign counter and the
                // other donor blocks rather than overstating what was given.
                'amount_cents' => max(0, (int) $donation->amount_cents - (int) $donation->refunded_cents),
                'currency'     => (string) $donation->currency,
                'time_ago'     => $timeAgo,
                'paid_at_iso'  => (string) $paidAt,
                // Private unless the donor opted in to a public message, and
                // withheld outright once an admin hides the donor.
                'message'      => (! $hidden && $donation->note_public)
                    ? (string) ($donation->note_to_org ?? '')
                    : '',
            ];
        }

        return View::loadRelative(__DIR__, 'views/recent-donations', [
            'title'        => (string) ($attrs['title'] ?? ''),
            'emptyText'    => (string) ($attrs['emptyText'] ?? '') ?: __('No donations to show yet.', 'dono'),
            // No button when the campaign cannot take the money: a
            // draft or finished campaign has nothing to offer.
            'emptySubText' => __('Donations tend to follow the first one.', 'dono'),
            'emptyIcon'    => 'donation',
            'entries'      => $entries,
            'showAmount'   => (bool) ($attrs['showAmount'] ?? true),
            'showTime'     => (bool) ($attrs['showTime'] ?? true),
            'showMessage'  => (bool) ($attrs['showMessage'] ?? true),
            'styleVars' => $this->styleVars($campaign),
        ]);
    }
}
