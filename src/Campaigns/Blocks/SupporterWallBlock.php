<?php

declare(strict_types=1);

namespace Dono\Campaigns\Blocks;

use Dono\Campaigns\CampaignRepository;
use Dono\Donations\Donation;
use Dono\Donations\DonationQueries;
use Dono\Donations\DonationRepository;
use Dono\Donors\Donor;
use Dono\Foundation\Helpers\Money;
use Dono\Foundation\Helpers\View;

/**
 * Renders a supporter wall: one card per non-anonymous donor, optionally
 * showing their total amount and message.
 *
 * @version 1.0.0
 */
final class SupporterWallBlock extends CampaignBlock
{
    public function __construct(
        CampaignRepository $campaigns,
        private readonly DonationRepository $donations,
    ) {
        parent::__construct($campaigns);
    }

    public function name(): string
    {
        return 'dono/supporter-wall';
    }

    public function attributes(): array
    {
        return $this->campaignIdAttr() + [
            'title'          => ['type' => 'string',  'default' => ''],
            'limit'          => ['type' => 'integer', 'default' => 50],
            'sort'           => ['type' => 'string',  'default' => 'recent'],
            'showMessage'    => ['type' => 'boolean', 'default' => true],
            'showAmount'     => ['type' => 'boolean', 'default' => false],
            'minAmountCents' => ['type' => 'integer', 'default' => 0],
            'columns'        => ['type' => 'string',  'default' => 'auto'],
        ];
    }

    public function render(array $attrs, string $content): string
    {
        $campaign = $this->resolveCampaign($attrs);
        if (! $campaign) return $this->notBoundNotice();

        $limit          = max(5, min(500, (int) ($attrs['limit'] ?? 50)));
        $sort           = (string) ($attrs['sort'] ?? 'recent') === 'alphabetical' ? 'alphabetical' : 'recent';
        $minAmountCents = max(0, (int) ($attrs['minAmountCents'] ?? 0));
        $showMessage    = (bool) ($attrs['showMessage'] ?? true);
        $columns        = in_array((string) ($attrs['columns'] ?? 'auto'), ['auto', '2', '3', '4'], true)
            ? (string) $attrs['columns'] : 'auto';

        // Pull a generous pool of paid non-anonymous donations, then collapse
        // to one card per donor, keeping the most recent message.
        $poolSize = max($limit * 4, 200);
        $query = DonationQueries::live(Donation::query())
            ->whereIn('status', ['paid', 'partial_refund'])
            ->where('campaign_id', (int) $campaign->id)
            ->where('is_anonymous', false);

        if ($minAmountCents > 0) {
            $query = $query->where('amount_cents', $minAmountCents, '>=');
        }

        $donations = $query->orderBy('paid_at', 'DESC')->limit($poolSize)->getAll();

        // Aggregate per donor.
        $byDonor = [];
        foreach ($donations as $donation) {
            $id = (int) $donation->donor_id;
            // Base/org currency so totals stay coherent across mixed-currency donations.
            $amount = (int) ($donation->base_amount_cents ?? $donation->amount_cents);
            $paidAt = (string) ($donation->paid_at ?: $donation->created_at);
            $note   = trim((string) ($donation->note_to_org ?? ''));

            if (! isset($byDonor[$id])) {
                $byDonor[$id] = [
                    'donor_id'        => $id,
                    'total_cents'     => 0,
                    'currency'        => Money::defaultCurrency(),
                    'latest_paid_at'  => $paidAt,
                    'message'         => $note,
                    'message_paid_at' => $note !== '' ? $paidAt : '',
                ];
            }
            $byDonor[$id]['total_cents'] += $amount;
            if (strcmp($paidAt, $byDonor[$id]['latest_paid_at']) > 0) {
                $byDonor[$id]['latest_paid_at'] = $paidAt;
            }
            // Keep the most recent non-empty message.
            if ($note !== '' && strcmp($paidAt, $byDonor[$id]['message_paid_at']) >= 0) {
                $byDonor[$id]['message']         = $note;
                $byDonor[$id]['message_paid_at'] = $paidAt;
            }
        }

        // The pool sum above is gross. Net each donor's total against refunds
        // in base currency so a partially-refunded donor's wall figure agrees
        // with the campaign counter and the Top Donors block (which both net).
        $donorIds = array_keys($byDonor);
        if ($donorIds) {
            $netQuery = DonationQueries::live(Donation::query())
                ->whereIn('status', ['paid', 'partial_refund'])
                ->where('campaign_id', (int) $campaign->id)
                ->where('is_anonymous', false)
                ->whereIn('donor_id', $donorIds);
            if ($minAmountCents > 0) {
                $netQuery = $netQuery->where('amount_cents', $minAmountCents, '>=');
            }
            $netRows = $netQuery
                ->selectRaw('donor_id, COALESCE(SUM(' . DonationQueries::netBaseExpr() . '), 0) AS net_cents')
                ->groupByRaw('donor_id')
                ->getAll();
            foreach ($netRows as $r) {
                $did = (int) $r['donor_id'];
                if (isset($byDonor[$did])) {
                    $byDonor[$did]['total_cents'] = max(0, (int) $r['net_cents']);
                }
            }
        }

        if (! $byDonor) {
            return View::loadRelative(__DIR__, 'views/supporter-wall', [
                'title'        => (string) ($attrs['title'] ?? ''),
                'entries'      => [],
                'showMessage'  => $showMessage,
                'showAmount'   => (bool) ($attrs['showAmount'] ?? false),
                'columns'      => $columns,
                'themePrimary' => $campaign->accentColor(),
            ]);
        }

        $donorIds = array_keys($byDonor);
        $donorsById = [];
        foreach (Donor::query()->whereIn('id', $donorIds)->getAll() as $d) {
            $donorsById[(int) $d->id] = $d;
        }

        $entries = [];
        foreach ($byDonor as $id => $info) {
            $donor = $donorsById[$id] ?? null;
            if (! $donor) continue;
            $name = trim((string) $donor->first_name . ' ' . (string) $donor->last_name);
            if ($name === '') continue;

            $entries[] = [
                'name'           => $name,
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

        $entries = array_slice($entries, 0, $limit);

        return View::loadRelative(__DIR__, 'views/supporter-wall', [
            'title'        => (string) ($attrs['title'] ?? ''),
            'entries'      => $entries,
            'showMessage'  => $showMessage,
            'showAmount'   => (bool) ($attrs['showAmount'] ?? false),
            'columns'      => $columns,
            'themePrimary' => $campaign->accentColor(),
        ]);
    }
}
