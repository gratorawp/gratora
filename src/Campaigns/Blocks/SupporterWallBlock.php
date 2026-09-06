<?php

declare(strict_types=1);

namespace FundKit\Campaigns\Blocks;

use FundKit\Campaigns\CampaignRepository;
use FundKit\Donations\Donation;
use FundKit\Donations\DonationQueries;
use FundKit\Donors\Donor;
use FundKit\Donors\PublicDonorNames;
use FundKit\Donors\DonorAvatars;
use FundKit\Foundation\Helpers\Money;
use FundKit\Foundation\Helpers\View;
use FundKit\Vendor\Queryable\DB;

/**
 * Renders a supporter wall: one card per non-anonymous donor, optionally
 * showing their total amount and message.
 *
 * @since 1.0.0
 */
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
        return 'fundkit/supporter-wall';
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
        $donations = $prefix . 'fundkit_donations';
        $donors    = $prefix . 'fundkit_donors';

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
            // Threshold is an org-currency figure, so compare against the base
            // amount, not the donor's (possibly foreign) amount_cents.
            $query = $query->where("{$donations}.base_amount_cents", $minAmountCents, '>=');
        }

        // The two rules that decide whether a donor can appear at all, pushed
        // into SQL so the limit counts rows the wall will actually show.
        $nameExpr = "TRIM(CONCAT(COALESCE(dn.first_name, ''), ' ', COALESCE(dn.last_name, '')))";

        // In the join, not in a where: whereRaw contributes no AND connector,
        // and these belong to which donor rows are joinable anyway.
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

        // The most recent public message per donor on the wall. Only donors who
        // opted in are shown; note_to_org is otherwise a private note.
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
            'emptyText'    => (string) ($attrs['emptyText'] ?? '') ?: __('The supporter wall is empty.', 'fundraising-toolkit'),
            'emptySubText' => __('Add the first name to it.', 'fundraising-toolkit'),
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
            // The wall is names and their words, so a hidden donor has nothing
            // left to show here. Their donation still counts toward the total.
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

        // The order is the query's; this only settles ties MySQL left open.
        usort($entries, static function (array $a, array $b) use ($sort): int {
            if ($sort === 'alphabetical') {
                return strcasecmp($a['name'], $b['name']);
            }
            return strcmp($b['latest_paid_at'], $a['latest_paid_at']);
        });

        return View::loadRelative(__DIR__, 'views/supporter-wall', [
            'title'        => (string) ($attrs['title'] ?? ''),
            'emptyText'    => (string) ($attrs['emptyText'] ?? '') ?: __('The supporter wall is empty.', 'fundraising-toolkit'),
            'emptySubText' => __('Add the first name to it.', 'fundraising-toolkit'),
            'emptyIcon'    => 'supporters',
            'entries'      => $entries,
            'showMessage'  => $showMessage,
            'showAmount'   => (bool) ($attrs['showAmount'] ?? false),
            'columns'      => $columns,
            'styleVars' => $this->styleVars($campaign),
        ]);
    }
}
