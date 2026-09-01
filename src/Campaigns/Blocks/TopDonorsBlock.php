<?php

declare(strict_types=1);

namespace FundKit\Campaigns\Blocks;

use FundKit\Campaigns\CampaignRepository;
use FundKit\Donations\DonationRepository;
use FundKit\Donors\Donor;
use FundKit\Donors\DonorAvatars;
use FundKit\Foundation\Helpers\View;

/**
 * Renders a ranked list or podium of top donors for a campaign.
 *
 * @since 1.0.0
 */
final class TopDonorsBlock extends CampaignBlock
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
        return 'fundkit/top-donors';
    }

    /** @since 1.0.0 */
    public function attributes(): array
    {
        return $this->campaignIdAttr() + [
            'title'          => ['type' => 'string',  'default' => ''],
            'emptyText'      => ['type' => 'string',  'default' => ''],
            'limit'          => ['type' => 'integer', 'default' => 10],
            'showAmount'     => ['type' => 'boolean', 'default' => true],
            'showDonorCount' => ['type' => 'boolean', 'default' => false],
            'hideAnonymous'  => ['type' => 'boolean', 'default' => false],
            'layout'         => ['type' => 'string',  'default' => 'list'],
        ];
    }

    /** @since 1.0.0 */
    public function render(array $attrs, string $content): string
    {
        $campaign = $this->resolveCampaign($attrs);
        if (! $campaign) return $this->notBoundNotice($attrs);

        $limit = max(3, min(50, (int) ($attrs['limit'] ?? 10)));
        $hideAnonymous = (bool) ($attrs['hideAnonymous'] ?? false);
        // Named rankings never include anonymous donations: a donation the
        // donor chose to hide must not surface their name or pad their public
        // total. Anonymous giving appears only as the masked aggregate below.
        $rows  = $this->donations->topPaidDonors(null, null, (int) $campaign->id, $limit, false);

        $donorIds = array_values(array_filter(array_map(static fn ($r) => (int) $r['donor_id'], $rows)));
        $donorsById = [];
        if ($donorIds) {
            foreach (Donor::query()->whereIn('id', $donorIds)->getAll() as $d) {
                $donorsById[(int) $d->id] = $d;
            }
        }

        $avatarUrls = $this->avatars->urlsFor($donorsById);

        $entries = [];
        foreach ($rows as $row) {
            $donorId = (int) $row['donor_id'];
            $donor   = $donorsById[$donorId] ?? null;
            $name    = $donor
                ? trim((string) $donor->first_name . ' ' . (string) $donor->last_name)
                : '';
            // Hidden reads the same as unnamed: the amount still ranks, the
            // person behind it does not appear.
            $isAnonymousAggregate = ($name === '' || ($donor && $donor->public_hidden_at !== null));

            if ($hideAnonymous && $isAnonymousAggregate) continue;

            $entries[] = [
                // Masked on the aggregate flag, not on whether a name exists:
                // a hidden donor has one, and printing it is the whole thing
                // hiding was meant to stop. It also keeps the real initial out
                // of the avatar, which is built from this string.
                'name'            => $isAnonymousAggregate ? __('Anonymous', 'fundkit-fundraising-campaigns') : $name,
                'amount_cents'    => (int) $row['amount_cents'],
                'donations_count' => (int) $row['donations_count'],
                'is_anonymous'    => $isAnonymousAggregate,
                'avatar_url'      => $isAnonymousAggregate ? '' : ($avatarUrls[$donorId] ?? ''),
            ];
        }

        if (! $hideAnonymous) {
            $anon = $this->donations->anonymousPaidTotal(null, null, (int) $campaign->id);
            if ($anon['donations_count'] > 0) {
                $entries[] = [
                    'name'            => __('Anonymous', 'fundkit-fundraising-campaigns'),
                    'amount_cents'    => $anon['amount_cents'],
                    'donations_count' => $anon['donations_count'],
                    'is_anonymous'    => true,
                    'avatar_url'      => '',
                ];
                usort($entries, static fn ($a, $b) => $b['amount_cents'] <=> $a['amount_cents']);
                $entries = array_slice($entries, 0, $limit);
            }
        }

        return View::loadRelative(__DIR__, 'views/top-donors', [
            'title'          => (string) ($attrs['title'] ?? ''),
            'emptyText'    => (string) ($attrs['emptyText'] ?? '') ?: __('No donors to rank yet.', 'fundkit-fundraising-campaigns'),
            'emptySubText' => __('The first donation starts the list.', 'fundkit-fundraising-campaigns'),
            'emptyIcon'    => 'donor',
            'entries'        => $entries,
            'currency'       => $campaign->currency,
            'showAmount'     => (bool) ($attrs['showAmount'] ?? true),
            'showDonorCount' => (bool) ($attrs['showDonorCount'] ?? false),
            'layout'         => (string) ($attrs['layout'] ?? 'list') === 'podium' ? 'podium' : 'list',
            'styleVars'      => $this->styleVars($campaign),
        ]);
    }
}
