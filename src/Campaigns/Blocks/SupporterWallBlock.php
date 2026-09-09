<?php

declare(strict_types=1);

namespace Gratora\Campaigns\Blocks;

use Gratora\Campaigns\CampaignRepository;
use Gratora\Donations\Donation;
use Gratora\Donations\DonationQueries;
use Gratora\Donors\Donor;
use Gratora\Donors\DonorAvatars;
use Gratora\Donors\PublicDonorNames;
use Gratora\Foundation\Helpers\Money;
use Gratora\Foundation\Helpers\View;
use Gratora\Vendor\Queryable\DB;

/** @since 1.0.0 */
final class SupporterWallBlock extends CampaignBlock
{
    /** @since 1.0.0 */
    public function __construct(
        CampaignRepository $campaigns,
        private readonly DonorAvatars $avatars,
    ) {
        parent::__construct($campaigns);
    }

    /** @since 1.0.0 */
    public function name(): string
    {
        return 'gratora/supporter-wall';
    }

    /** @since 1.0.0 */
    public function attributes(): array
    {
        return $this->campaignIdAttr() + [
            'title'          => ['type' => 'string',  'default' => ''],
            'emptyText'      => ['type' => 'string',  'default' => ''],
            'limit'          => ['type' => 'integer', 'default' => 50],
            'sort'           => ['type' => 'string',  'default' => 'recent'],
            'showMessage'    => ['type' => 'boolean', 'default' => true],
            'showAmount'     => ['type' => 'boolean', 'default' => false],
            'minAmountCents' => ['type' => 'integer', 'default' => 0],
            'columns'        => ['type' => 'string',  'default' => 'auto'],
        ];
    }

    /** @since 1.0.0 */
    public function render(array $attrs, string $content): string
    {
        $campaign = $this->resolveCampaign($attrs);
        if (! $campaign) return $this->notBoundNotice($attrs);

        $limit          = max(5, min(500, (int) ($attrs['limit'] ?? 50)));
        $sort           = (string) ($attrs['sort'] ?? 'recent') === 'alphabetical' ? 'alphabetical' : 'recent';
        $minAmountCents = max(0, (int) ($attrs['minAmountCents'] ?? 0));
        $showMessage    = (bool) ($attrs['showMessage'] ?? true);
        $columns        = in_array((string) ($attrs['columns'] ?? 'auto'), ['auto', '2', '3', '4'], true)
            ? (string) $attrs['columns'] : 'auto';

        $prefix   = DB::getPrefix();
        $donations = $prefix . 'gratora_donations';
        $donors    = $prefix . 'gratora_donors';

        // Grouped and limited by DONOR, not by donation. Reading a slice of
        // recent donations and collapsing it left every earlier supporter off
        // the wall entirely, and made "alphabetical" A to Z of that slice.
        //
        // donationsOnly, not live: a ticket order rides the same table with
        // kind='order' and is a purchase rather than a donation. Listing one
        // here put a ticket buyer on the wall and made it disagree with the
        // campaign counter beside it, which excludes orders.
        $query = DonationQueries::donationsOnly(Donation::query())
            ->whereIn("{$donations}.status", ['paid', 'partial_refund'])
            ->where("{$donations}.campaign_id", (int) $campaign->id)
            ->where("{$donations}.is_anonymous", false);

        if ($minAmountCents > 0) {
            // Compare the threshold in org currency.
            $query = $query->where("{$donations}.base_amount_cents", $minAmountCents, '>=');
        }

        // Filter before LIMIT so every selected donor is displayable.
        $nameExpr = "TRIM(CONCAT(COALESCE(dn.first_name, ''), ' ', COALESCE(dn.last_name, '')))";

        // Keep conditions in the join; whereRaw adds no AND connector.
        $rows = $query
            ->joinRaw(
                "JOIN {$donors} dn ON dn.id = {$donations}.donor_id"
                . " AND dn.public_hidden_at IS NULL AND {$nameExpr} <> ''"
            )
            ->selectRaw(
                "{$donations}.donor_id AS donor_id,"
                . ' COALESCE(SUM(' . DonationQueries::netBaseExpr() . '), 0) AS net_cents,'
                . " MAX(COALESCE({$donations}.paid_at, {$donations}.created_at)) AS latest_paid_at,"
                . " {$nameExpr} AS donor_name"
            )
            ->groupByRaw("{$donations}.donor_id, {$nameExpr}")
            ->orderByRaw($sort === 'alphabetical' ? 'donor_name ASC' : 'latest_paid_at DESC')
            ->limit($limit)
            ->getAll();

        $byDonor = [];
        foreach ($rows as $r) {
            $byDonor[(int) $r['donor_id']] = [
                'donor_id'       => (int) $r['donor_id'],
                'total_cents'    => max(0, (int) $r['net_cents']),
                'currency'       => Money::defaultCurrency(),
                'latest_paid_at' => (string) $r['latest_paid_at'],
                'message'        => '',
            ];
        }

        // Use the latest opted-in public message per donor.
        if ($byDonor && $showMessage) {
            $messages = DonationQueries::donationsOnly(Donation::query())
                ->whereIn('status', ['paid', 'partial_refund'])
                ->where('campaign_id', (int) $campaign->id)
                ->where('is_anonymous', false)
                ->where('note_public', true)
                ->whereIn('donor_id', array_keys($byDonor))
                ->orderBy('paid_at', 'ASC')
                ->getAll();

            foreach ($messages as $m) {
                $note = trim((string) ($m->note_to_org ?? ''));
                if ($note === '') continue;
                $did = (int) $m->donor_id;
                if (isset($byDonor[$did])) $byDonor[$did]['message'] = $note;
            }
        }

        if (! $byDonor) {
            return View::loadRelative(__DIR__, 'views/supporter-wall', [
                'title'        => (string) ($attrs['title'] ?? ''),
            'emptyText'    => (string) ($attrs['emptyText'] ?? '') ?: __('The supporter wall is empty.', 'gratora'),
            'emptySubText' => __('Add the first name to it.', 'gratora'),
            'emptyIcon'    => 'supporters',
                'entries'      => [],
                'showMessage'  => $showMessage,
                'showAmount'   => (bool) ($attrs['showAmount'] ?? false),
                'columns'      => $columns,
                'styleVars' => $this->styleVars($campaign),
            ]);
        }

        $donorsById = [];
        foreach (Donor::query()->whereIn('id', array_keys($byDonor))->getAll() as $d) {
            $donorsById[(int) $d->id] = $d;
        }

        $avatarUrls = $this->avatars->urlsFor($donorsById);

        $entries = [];
        foreach ($byDonor as $id => $info) {
            $donor = $donorsById[$id] ?? null;
            if (! $donor) continue;
            if ($donor->public_hidden_at !== null) continue;
            $name = PublicDonorNames::of($donor);
            if ($name === '') continue;

            $entries[] = [
                'name'           => $name,
                'avatar_url'     => $avatarUrls[(int) $id] ?? '',
                'message'        => $info['message'],
                'amount_cents'   => $info['total_cents'],
                'currency'       => $info['currency'],
                'latest_paid_at' => $info['latest_paid_at'],
            ];
        }

        usort($entries, static function (array $a, array $b) use ($sort): int {
            if ($sort === 'alphabetical') {
                return strcasecmp($a['name'], $b['name']);
            }
            return strcmp($b['latest_paid_at'], $a['latest_paid_at']);
        });

        return View::loadRelative(__DIR__, 'views/supporter-wall', [
            'title'        => (string) ($attrs['title'] ?? ''),
            'emptyText'    => (string) ($attrs['emptyText'] ?? '') ?: __('The supporter wall is empty.', 'gratora'),
            'emptySubText' => __('Add the first name to it.', 'gratora'),
            'emptyIcon'    => 'supporters',
            'entries'      => $entries,
            'showMessage'  => $showMessage,
            'showAmount'   => (bool) ($attrs['showAmount'] ?? false),
            'columns'      => $columns,
            'styleVars' => $this->styleVars($campaign),
        ]);
    }
}
