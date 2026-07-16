<?php

declare(strict_types=1);

namespace Dono\Campaigns\Blocks;

use Dono\Campaigns\CampaignRepository;
use Dono\Donations\DonationRepository;
use Dono\Donors\Donor;
use Dono\Foundation\Helpers\View;

/**
 * Renders a ranked list or podium of top donors for a campaign.
 *
 * @version 1.0.0
 */
final class TopDonorsBlock extends CampaignBlock
{
    public function __construct(
        CampaignRepository $campaigns,
        private readonly DonationRepository $donations,
    ) {
        parent::__construct($campaigns);
    }

    public function name(): string
    {
        return 'dono/top-donors';
    }

    public function attributes(): array
    {
        return $this->campaignIdAttr() + [
            'title'          => ['type' => 'string',  'default' => ''],
            'limit'          => ['type' => 'integer', 'default' => 10],
            'showAmount'     => ['type' => 'boolean', 'default' => true],
            'showDonorCount' => ['type' => 'boolean', 'default' => false],
            'hideAnonymous'  => ['type' => 'boolean', 'default' => false],
            'layout'         => ['type' => 'string',  'default' => 'list'],
            'showRank'       => ['type' => 'boolean', 'default' => true],
        ];
    }

    public function render(array $attrs, string $content): string
    {
        $campaign = $this->resolveCampaign($attrs);
        if (! $campaign) return $this->notBoundNotice();

        $limit = max(3, min(50, (int) ($attrs['limit'] ?? 10)));
        // Exclude anonymous in SQL only when the toggle asks, so the limit
        // applies to the visible rows (not trimmed afterwards) and the toggle
        // actually does something.
        $hideAnonymous = (bool) ($attrs['hideAnonymous'] ?? false);
        $rows  = $this->donations->topPaidDonors(null, null, (int) $campaign->id, $limit, ! $hideAnonymous);

        $donorIds = array_values(array_filter(array_map(static fn ($r) => (int) $r['donor_id'], $rows)));
        $donorsById = [];
        if ($donorIds) {
            foreach (Donor::query()->whereIn('id', $donorIds)->getAll() as $d) {
                $donorsById[(int) $d->id] = $d;
            }
        }

        $entries = [];
        foreach ($rows as $row) {
            $donorId = (int) $row['donor_id'];
            $donor   = $donorsById[$donorId] ?? null;
            $name    = $donor
                ? trim((string) $donor->first_name . ' ' . (string) $donor->last_name)
                : '';
            $isAnonymousAggregate = ($name === '');

            if ($hideAnonymous && $isAnonymousAggregate) continue;

            $entries[] = [
                'name'            => $name !== '' ? $name : __('Anonymous', 'dono'),
                'amount_cents'    => (int) $row['amount_cents'],
                'donations_count' => (int) $row['donations_count'],
                'is_anonymous'    => $isAnonymousAggregate,
            ];
        }

        return View::loadRelative(__DIR__, 'views/top-donors', [
            'title'          => (string) ($attrs['title'] ?? ''),
            'entries'        => $entries,
            'currency'       => $campaign->currency,
            'showAmount'     => (bool) ($attrs['showAmount'] ?? true),
            'showDonorCount' => (bool) ($attrs['showDonorCount'] ?? false),
            'layout'         => (string) ($attrs['layout'] ?? 'list') === 'podium' ? 'podium' : 'list',
            'showRank'       => (bool) ($attrs['showRank'] ?? true),
            'themePrimary'   => $campaign->accentColor(),
        ]);
    }
}
