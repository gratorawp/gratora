<?php

declare(strict_types=1);

namespace Dono\Donations;

use Dono\Receipts\Receipt;
use Dono\Vendor\Queryable\DB;
use Dono\Vendor\Queryable\QueryBuilder;

/**
 * Query and aggregate helpers for the donations table.
 *
 * @version 1.0.0
 */
final class DonationRepository
{
    public function findById(int $id): ?Donation
    {
        return Donation::query()->find('id', $id);
    }

    public function findByReference(string $reference): ?Donation
    {
        return Donation::query()->find('reference', $reference);
    }

    /**
     * Every paid (paid or partial_refund), non-test donation by one donor within
     * a calendar year, oldest first, for a year-end tax statement. The year
     * boundary uses paid_at (when the money actually arrived), not created_at.
     *
     * Each row carries the gross amount plus the succeeded-refund total for that
     * donation (source of truth is the Refund table, matching the receipt/annual
     * statement renderers) so the caller can net it to a deductible figure.
     * receipt_number is the issued, non-voided receipt's number when one exists.
     *
     * @return list<array{date:string,amount_cents:int,refunded_cents:int,currency:string,reference:string,receipt_number:?string}>
     */
    public function paidForDonorInYear(int $donorId, int $year): array
    {
        $start = sprintf('%04d-01-01 00:00:00', $year);
        $end   = sprintf('%04d-12-31 23:59:59', $year);

        // donationsOnly: a ticket purchase is goods received, not a gift, and
        // must never appear on a tax-deductible year-end statement.
        $rows = DonationQueries::donationsOnly(Donation::query())
            ->whereIn('status', ['paid', 'partial_refund'])
            ->where('donor_id', $donorId)
            ->whereBetween('paid_at', $start, $end)
            ->orderBy('paid_at', 'ASC')
            ->getAll();
        if (! $rows) {
            return [];
        }

        $ids = array_map(static fn ($d): int => (int) $d->id, $rows);

        $refundedById = [];
        foreach (Refund::query()->whereIn('donation_id', $ids)->where('status', 'succeeded')->getAll() as $r) {
            $did                = (int) $r->donation_id;
            $refundedById[$did] = ($refundedById[$did] ?? 0) + (int) $r->amount_cents;
        }

        $receiptById = [];
        foreach (Receipt::query()->whereIn('donation_id', $ids)->where('voided', 0)->getAll() as $rc) {
            $did = (int) $rc->donation_id;
            if (! isset($receiptById[$did])) {
                $receiptById[$did] = (string) $rc->receipt_number;
            }
        }

        $out = [];
        foreach ($rows as $d) {
            $id    = (int) $d->id;
            $out[] = [
                'date'           => (string) ($d->paid_at ?? $d->created_at),
                'amount_cents'   => (int) $d->amount_cents,
                'refunded_cents' => (int) ($refundedById[$id] ?? 0),
                'currency'       => (string) $d->currency,
                'reference'      => (string) $d->reference,
                'receipt_number' => $receiptById[$id] ?? null,
            ];
        }
        return $out;
    }

    public function findByGatewayIntent(string $gateway, string $intentId): ?Donation
    {
        return Donation::query()
            ->where('gateway', $gateway)
            ->where('gateway_intent_id', $intentId)
            ->get();
    }

    public function findByGatewayTxn(string $gateway, string $txnId): ?Donation
    {
        return Donation::query()
            ->where('gateway', $gateway)
            ->where('gateway_txn_id', $txnId)
            ->get();
    }

    /**
     * Paginated list for the admin. `matching_donor_ids` lets the caller
     * pre-resolve a search term to donor ids so the search covers donor
     * name / exact email as well as `reference`.
     *
     * @param array{page?:int,per_page?:int,orderby?:string,order?:string,status?:string,search?:string,matching_donor_ids?:array<int>} $args
     * @return array{items: array<Donation>, total: int}
     */
    /**
     * How many test donations the same filters would have matched.
     *
     * The list is live-only unless asked otherwise, which is right - but it
     * excluded them in total silence, so an admin donating while the org is in
     * test mode watched their donation vanish. This is what the screen needs to
     * say so.
     *
     * @param array<string,mixed> $args
     */
    public function countTestHidden(array $args = []): int
    {
        $args['is_test'] = true;

        return (int) $this->applyAdminFilters(Donation::query(), $args)->count();
    }

    public function listAdmin(array $args = []): array
    {
        $page    = max(1, (int) ($args['page']     ?? 1));
        $perPage = max(1, min(100, (int) ($args['per_page'] ?? 25)));
        $offset  = ($page - 1) * $perPage;

        $allowedSort = ['created_at', 'paid_at', 'amount_cents', 'reference', 'status'];
        $orderBy = in_array($args['orderby'] ?? '', $allowedSort, true)
            ? $args['orderby']
            : 'created_at';
        $order   = strtoupper((string) ($args['order'] ?? 'desc')) === 'ASC' ? 'ASC' : 'DESC';

        $applyFilters = fn ($q) => $this->applyAdminFilters($q, $args);

        $total = (int) $applyFilters(Donation::query())->count();

        $items = $applyFilters(Donation::query())
            ->orderBy($orderBy, $order)
            ->limit($perPage)
            ->offset($offset)
            ->getAll();

        return ['items' => $items, 'total' => $total];
    }

    /**
     * Paid (paid or partial_refund), non-test donations that have no issued,
     * non-voided receipt - the "these donors never got their receipt" sweep.
     * Paged (1..100), newest paid first, optionally scoped to one campaign.
     *
     * @param array{page?:int,per_page?:int,campaign_id?:int} $args
     * @return array{items: array<Donation>, total: int}
     */
    public function paidWithoutReceipt(array $args = []): array
    {
        $page       = max(1, (int) ($args['page'] ?? 1));
        $perPage    = max(1, min(100, (int) ($args['per_page'] ?? 25)));
        $offset     = ($page - 1) * $perPage;
        $campaignId = isset($args['campaign_id']) ? (int) $args['campaign_id'] : null;

        $prefix = DB::getPrefix();
        // Correlated NOT EXISTS against a non-voided receipt for the donation.
        // Kept the FIRST where-condition: whereRaw adds no AND connector, but the
        // where()s chained after it (status, is_test, campaign) do.
        $noReceipt = "NOT EXISTS (SELECT 1 FROM {$prefix}dono_receipts rc "
            . "WHERE rc.donation_id = {$prefix}dono_donations.id AND rc.voided = 0)";

        // A hand-recorded donation with no receipt is not one that went missing:
        // the admin was asked and said not to send one, because the donor never
        // gave this site an address. Left in, every cheque ever entered sits in
        // this report permanently, and the AI assistant reports them as donors
        // who never got their receipt. The ones where a receipt WAS asked for
        // have a row, so the check above already excludes them.
        //
        // Matches ChannelClassifier::MANUAL, which core reserves and the public
        // route strips; there is no channel column to read instead.
        $notByHand = "(source_attribution IS NULL OR JSON_UNQUOTE(JSON_EXTRACT("
            . "source_attribution, '$.utm_medium')) <> 'manual')";

        $build = function () use ($noReceipt, $notByHand, $campaignId) {
            $q = DonationQueries::live(
                Donation::query()
                    ->whereRaw($noReceipt . ' AND ' . $notByHand)
                    ->whereIn('status', ['paid', 'partial_refund'])
            );
            if ($campaignId !== null) {
                $q = $q->where('campaign_id', $campaignId);
            }
            return $q;
        };

        $total = (int) $build()->count();
        $items = $build()
            ->orderBy('paid_at', 'DESC')
            ->limit($perPage)
            ->offset($offset)
            ->getAll();

        return ['items' => $items, 'total' => $total];
    }

    /**
     * All matching rows for export. Same filter shape as listAdmin(). Capped at 50k rows.
     *
     * @param array<string,mixed> $args
     * @return array<Donation>
     */
    public function listForExport(array $args = []): array
    {
        $allowedSort = ['created_at', 'paid_at', 'amount_cents', 'reference', 'status'];
        $orderBy = in_array($args['orderby'] ?? '', $allowedSort, true)
            ? $args['orderby']
            : 'created_at';
        $order = strtoupper((string) ($args['order'] ?? 'desc')) === 'ASC' ? 'ASC' : 'DESC';

        $q = $this->applyAdminFilters(Donation::query(), $args);

        return $q->orderBy($orderBy, $order)->limit(50000)->getAll();
    }

    /**
     * @return array{amount_cents:int, donations_count:int, donors_count:int}
     */
    public function aggregatePaidBetween(?string $from = null, ?string $to = null, ?int $campaignId = null): array
    {
        // Refund-aware totals. Include partial_refund so the net portion still
        // counts, then LEFT JOIN a per-donation refunded-sum subquery so we can
        // subtract refunded cents from the donation amount in a single SUM.
        $prefix = DB::getPrefix();
        $row = $this->netPaidQuery($from, $to, $campaignId)
            ->selectRaw(
                "COALESCE(SUM(COALESCE({$prefix}dono_donations.base_amount_cents, 0) - COALESCE(r.refunded, 0)), 0) AS amount,
                 COUNT(*) AS cnt,
                 COUNT(DISTINCT {$prefix}dono_donations.donor_id) AS donors"
            )
            ->get();

        return [
            'amount_cents'    => (int) ($row['amount'] ?? 0),
            'donations_count' => (int) ($row['cnt']    ?? 0),
            'donors_count'    => (int) ($row['donors'] ?? 0),
        ];
    }

    /**
     * Per-day series of paid donations
     *
     * @return array<array{day:string, amount_cents:int, donations_count:int}>
     */
    public function dailyPaidBetween(string $from, string $to, ?int $campaignId = null): array
    {
        $prefix = DB::getPrefix();
        $rows = $this->netPaidQuery($from, $to, $campaignId)
            ->selectRaw("DATE({$prefix}dono_donations.paid_at) AS day, COALESCE(SUM(COALESCE({$prefix}dono_donations.base_amount_cents, 0) - COALESCE(r.refunded, 0)), 0) AS amount, COUNT(*) AS cnt")
            ->groupByRaw("DATE({$prefix}dono_donations.paid_at)")
            ->getAll();

        return array_map(static fn ($r) => [
            'day'             => (string) $r['day'],
            'amount_cents'    => (int) $r['amount'],
            'donations_count' => (int) $r['cnt'],
        ], $rows);
    }

    /** Earliest paid_at (Y-m-d) among a campaign's live donations, or null if none. */
    public function firstPaidDate(int $campaignId): ?string
    {
        $row = DonationQueries::live(DB::table('dono_donations')
            ->selectRaw('MIN(paid_at) AS first_paid')
            ->whereIn('status', ['paid', 'partial_refund']))
            ->where('campaign_id', $campaignId)
            ->get();

        $val = $row['first_paid'] ?? null;
        return $val ? substr((string) $val, 0, 10) : null;
    }

    /**
     * Most-common currency among paid donations in scope. Used when callers
     * need a single currency to format aggregated totals against a
     * mixed-currency pool (the dashboard KPI strip).
     */
    public function topCurrencyForPaid(?string $from = null, ?string $to = null): ?string
    {
        $q = DonationQueries::live(DB::table('dono_donations')
            ->selectRaw('currency, COUNT(*) AS c')
            ->whereIn('status', ['paid', 'partial_refund']));

        if ($from !== null) $q = $q->where('paid_at', $from . ' 00:00:00', '>=');
        if ($to   !== null) $q = $q->where('paid_at', $to   . ' 23:59:59', '<=');

        $row = $q->groupBy('currency')->orderBy('c', 'DESC')->get();
        return $row['currency'] ?? null;
    }

    /**
     * Group paid donations by (utm_source, utm_medium) tuples. The dashboard
     * passes each row through ChannelClassifier to bucket into a channel name.
     *
     * @return array<array{utm_source:?string, utm_medium:?string, amount_cents:int, donations_count:int}>
     */
    public function aggregatePaidByAttribution(?string $from = null, ?string $to = null, ?int $campaignId = null): array
    {
        $prefix = DB::getPrefix();
        $rows = $this->netPaidQuery($from, $to, $campaignId)
            ->selectRaw("
                JSON_UNQUOTE(JSON_EXTRACT({$prefix}dono_donations.source_attribution, '$.utm_source')) AS utm_source,
                JSON_UNQUOTE(JSON_EXTRACT({$prefix}dono_donations.source_attribution, '$.utm_medium')) AS utm_medium,
                COALESCE(SUM(COALESCE({$prefix}dono_donations.base_amount_cents, 0) - COALESCE(r.refunded, 0)), 0) AS amount,
                COUNT(*) AS cnt
            ")
            ->groupByRaw("
                JSON_UNQUOTE(JSON_EXTRACT({$prefix}dono_donations.source_attribution, '$.utm_source')),
                JSON_UNQUOTE(JSON_EXTRACT({$prefix}dono_donations.source_attribution, '$.utm_medium'))
            ")
            ->getAll();

        return array_map(static fn ($r) => [
            'utm_source'      => $r['utm_source'] !== null && $r['utm_source'] !== 'null' ? (string) $r['utm_source'] : null,
            'utm_medium'      => $r['utm_medium'] !== null && $r['utm_medium'] !== 'null' ? (string) $r['utm_medium'] : null,
            'amount_cents'    => (int) $r['amount'],
            'donations_count' => (int) $r['cnt'],
        ], $rows);
    }

    /**
     * Top N campaigns by paid-donation total in scope.
     *
     * @return array<array{campaign_id:int, amount_cents:int, donations_count:int}>
     */
    public function topPaidCampaigns(?string $from = null, ?string $to = null, int $limit = 5): array
    {
        $prefix = DB::getPrefix();
        $rows = $this->netPaidQuery($from, $to, null)
            ->selectRaw("{$prefix}dono_donations.campaign_id AS campaign_id, COALESCE(SUM(COALESCE({$prefix}dono_donations.base_amount_cents, 0) - COALESCE(r.refunded, 0)), 0) AS amount, COUNT(*) AS cnt")
            ->groupByRaw("{$prefix}dono_donations.campaign_id")
            ->orderByRaw('amount DESC')
            ->limit($limit)
            ->getAll();

        return array_map(static fn ($r) => [
            'campaign_id'     => (int) $r['campaign_id'],
            'amount_cents'    => (int) $r['amount'],
            'donations_count' => (int) $r['cnt'],
        ], $rows);
    }

    /**
     * Per-day paid-donation totals for one campaign.
     *
     * @return array<array{day:string, amount_cents:int}>
     */
    public function dailyPaidForCampaignBetween(int $campaignId, string $from, string $to): array
    {
        $prefix = DB::getPrefix();
        $rows = $this->netPaidQuery($from, $to, $campaignId)
            ->selectRaw("DATE({$prefix}dono_donations.paid_at) AS day, COALESCE(SUM(COALESCE({$prefix}dono_donations.base_amount_cents, 0) - COALESCE(r.refunded, 0)), 0) AS amount")
            ->groupByRaw("DATE({$prefix}dono_donations.paid_at)")
            ->getAll();

        return array_map(static fn ($r) => [
            'day'          => (string) $r['day'],
            'amount_cents' => (int) $r['amount'],
        ], $rows);
    }

    /**
     * Per-day paid totals for a batch of campaigns. Used by the dashboard's
     * top-campaigns sparkline so we don't fire one daily query per row.
     *
     * @param list<int> $campaignIds
     * @return array<int, array<string, int>> map of campaign_id => [day => amount_cents]
     */
    public function dailyPaidByCampaignsBetween(array $campaignIds, string $from, string $to): array
    {
        if ($campaignIds === []) return [];
        $prefix = DB::getPrefix();
        $rows = $this->netPaidQuery($from, $to, null)
            ->whereIn('campaign_id', $campaignIds)
            ->selectRaw("{$prefix}dono_donations.campaign_id AS campaign_id, DATE({$prefix}dono_donations.paid_at) AS day, COALESCE(SUM(COALESCE({$prefix}dono_donations.base_amount_cents, 0) - COALESCE(r.refunded, 0)), 0) AS amount")
            ->groupByRaw("{$prefix}dono_donations.campaign_id, DATE({$prefix}dono_donations.paid_at)")
            ->getAll();

        $out = [];
        foreach ($rows as $r) {
            $cid = (int) ($r['campaign_id'] ?? 0);
            $day = (string) ($r['day'] ?? '');
            if ($cid <= 0 || $day === '') continue;
            $out[$cid][$day] = (int) ($r['amount'] ?? 0);
        }
        return $out;
    }

    /**
     * Group paid donations by gateway. Optionally scoped to a campaign.
     *
     * @return array<array{gateway:string, amount_cents:int, donations_count:int}>
     */
    public function aggregatePaidByGateway(?string $from = null, ?string $to = null, ?int $campaignId = null): array
    {
        $prefix = DB::getPrefix();
        $rows = $this->netPaidQuery($from, $to, $campaignId)
            ->selectRaw("{$prefix}dono_donations.gateway AS gateway, COALESCE(SUM(COALESCE({$prefix}dono_donations.base_amount_cents, 0) - COALESCE(r.refunded, 0)), 0) AS amount, COUNT(*) AS cnt")
            ->groupByRaw("{$prefix}dono_donations.gateway")
            ->orderByRaw('amount DESC')
            ->getAll();

        return array_map(static fn ($r) => [
            'gateway'         => (string) ($r['gateway'] ?? ''),
            'amount_cents'    => (int) $r['amount'],
            'donations_count' => (int) $r['cnt'],
        ], $rows);
    }

    /**
     * Group paid donations by frequency (one_time / monthly / yearly / weekly).
     *
     * @return array<array{frequency:string, amount_cents:int, donations_count:int}>
     */
    public function aggregatePaidByFrequency(?string $from = null, ?string $to = null, ?int $campaignId = null): array
    {
        $prefix = DB::getPrefix();
        $rows = $this->netPaidQuery($from, $to, $campaignId)
            ->selectRaw("{$prefix}dono_donations.frequency AS frequency, COALESCE(SUM(COALESCE({$prefix}dono_donations.base_amount_cents, 0) - COALESCE(r.refunded, 0)), 0) AS amount, COUNT(*) AS cnt")
            ->groupByRaw("{$prefix}dono_donations.frequency")
            ->orderByRaw('amount DESC')
            ->getAll();

        return array_map(static fn ($r) => [
            'frequency'       => (string) ($r['frequency'] ?? 'one_time'),
            'amount_cents'    => (int) $r['amount'],
            'donations_count' => (int) $r['cnt'],
        ], $rows);
    }

    /**
     * Top N forms by paid-donation total in scope.
     *
     * @return array<array{form_id:int, amount_cents:int, donations_count:int}>
     */
    public function topPaidForms(?string $from = null, ?string $to = null, ?int $campaignId = null, int $limit = 5): array
    {
        $prefix = DB::getPrefix();
        $rows = $this->netPaidQuery($from, $to, $campaignId)
            ->selectRaw("{$prefix}dono_donations.form_id AS form_id, COALESCE(SUM(COALESCE({$prefix}dono_donations.base_amount_cents, 0) - COALESCE(r.refunded, 0)), 0) AS amount, COUNT(*) AS cnt")
            ->groupByRaw("{$prefix}dono_donations.form_id")
            ->orderByRaw('amount DESC')
            ->limit($limit)
            ->getAll();

        return array_map(static fn ($r) => [
            'form_id'         => (int) ($r['form_id'] ?? 0),
            'amount_cents'    => (int) $r['amount'],
            'donations_count' => (int) $r['cnt'],
        ], $rows);
    }

    /**
     * Most recent paid donations for a campaign. Returns hydrated Donation
     * rows so views can pull amount/currency/note/anonymity per donation.
     *
     * @return array<Donation>
     */
    public function recentForCampaign(int $campaignId, int $limit = 10, bool $includeAnonymous = true): array
    {
        $q = DonationQueries::live(Donation::query()
            ->whereIn('status', ['paid', 'partial_refund'])
            ->where('campaign_id', $campaignId));

        if (! $includeAnonymous) {
            $q = $q->where('is_anonymous', false);
        }

        return $q->orderBy('paid_at', 'DESC')->limit($limit)->getAll();
    }

    /**
     * Top N donors by paid-donation total in scope.
     *
     * @return array<array{donor_id:int, amount_cents:int, donations_count:int}>
     */
    public function topPaidDonors(?string $from = null, ?string $to = null, ?int $campaignId = null, int $limit = 5, bool $includeAnonymous = true): array
    {
        $prefix = DB::getPrefix();
        $q = $this->netPaidQuery($from, $to, $campaignId);
        if (! $includeAnonymous) {
            // Per-donation anonymity: a donor who chose to hide must not be
            // named publicly, even though their Donor record keeps the name.
            $q = $q->where("{$prefix}dono_donations.is_anonymous", false);
        }
        $rows = $q
            ->selectRaw("{$prefix}dono_donations.donor_id AS donor_id, COALESCE(SUM(COALESCE({$prefix}dono_donations.base_amount_cents, 0) - COALESCE(r.refunded, 0)), 0) AS amount, COUNT(*) AS cnt")
            ->groupByRaw("{$prefix}dono_donations.donor_id")
            ->orderByRaw('amount DESC')
            ->limit($limit)
            ->getAll();

        return array_map(static fn ($r) => [
            'donor_id'        => (int) $r['donor_id'],
            'amount_cents'    => (int) $r['amount'],
            'donations_count' => (int) $r['cnt'],
        ], $rows);
    }

    /**
     * Net paid total + count of anonymous donations in scope, for surfacing
     * anonymous giving as one masked aggregate without naming anyone.
     *
     * @return array{amount_cents:int, donations_count:int}
     */
    public function anonymousPaidTotal(?string $from = null, ?string $to = null, ?int $campaignId = null): array
    {
        $prefix = DB::getPrefix();
        $row = $this->netPaidQuery($from, $to, $campaignId)
            ->where("{$prefix}dono_donations.is_anonymous", true)
            ->selectRaw("COALESCE(SUM(COALESCE({$prefix}dono_donations.base_amount_cents, 0) - COALESCE(r.refunded, 0)), 0) AS amount, COUNT(*) AS cnt")
            ->get();

        return [
            'amount_cents'    => (int) ($row['amount'] ?? 0),
            'donations_count' => (int) ($row['cnt'] ?? 0),
        ];
    }

    /**
     * Donation-amount histogram
     *
     * @param array<int> $thresholdsCents  e.g. [1000, 2500, 5000, 10000, 50000]
     * @return array<array{label:string, threshold:?int, donations_count:int, amount_cents:int}>
     */
    public function amountHistogramBuckets(array $thresholdsCents, ?string $from = null, ?string $to = null, ?int $campaignId = null): array
    {
        if (! $thresholdsCents) return [];

        sort($thresholdsCents);
        $prefix = DB::getPrefix();
        // Bucket on base_amount_cents (org currency), not the donor-currency
        // amount_cents. Donations can be taken in different currencies, so
        // bucketing by face value would compare foreign amounts against
        // one-currency thresholds and land near-boundary gifts in the wrong bar.
        $cases = [];
        foreach ($thresholdsCents as $t) {
            $cases[] = "WHEN COALESCE({$prefix}dono_donations.base_amount_cents, 0) <= {$t} THEN {$t}";
        }
        $bucketExpr = 'CASE ' . implode(' ', $cases) . ' ELSE NULL END';

        $rows = $this->netPaidQuery($from, $to, $campaignId)
            ->selectRaw("{$bucketExpr} AS bucket, COALESCE(SUM(COALESCE({$prefix}dono_donations.base_amount_cents, 0) - COALESCE(r.refunded, 0)), 0) AS amount, COUNT(*) AS cnt")
            ->groupByRaw($bucketExpr)
            ->getAll();

        $byBucket = [];
        foreach ($rows as $r) {
            $key = $r['bucket'] === null ? 'overflow' : (int) $r['bucket'];
            $byBucket[$key] = [
                'amount' => (int) $r['amount'],
                'cnt'    => (int) $r['cnt'],
            ];
        }

        $out = [];
        $prev = 0;
        foreach ($thresholdsCents as $t) {
            $out[] = [
                'label'           => $this->formatBucketLabel($prev, $t),
                'threshold'       => $t,
                'donations_count' => $byBucket[$t]['cnt']    ?? 0,
                'amount_cents'    => $byBucket[$t]['amount'] ?? 0,
            ];
            $prev = $t;
        }
        $out[] = [
            'label'           => '> ' . $this->formatMoneyShort($prev),
            'threshold'       => null,
            'donations_count' => $byBucket['overflow']['cnt']    ?? 0,
            'amount_cents'    => $byBucket['overflow']['amount'] ?? 0,
        ];
        return $out;
    }

    /**
     * 7×24 day-of-week × hour count grid of paid donations.
     *
     * @return array<array{dow:int, hour:int, donations_count:int, amount_cents:int}>
     */
    public function dowHourGridForPaid(?string $from = null, ?string $to = null, ?int $campaignId = null): array
    {
        $prefix = DB::getPrefix();
        $rows = $this->netPaidQuery($from, $to, $campaignId)
            ->selectRaw("DAYOFWEEK({$prefix}dono_donations.paid_at) AS dow_mysql, HOUR({$prefix}dono_donations.paid_at) AS hour, COALESCE(SUM(COALESCE({$prefix}dono_donations.base_amount_cents, 0) - COALESCE(r.refunded, 0)), 0) AS amount, COUNT(*) AS cnt")
            ->groupByRaw("DAYOFWEEK({$prefix}dono_donations.paid_at), HOUR({$prefix}dono_donations.paid_at)")
            ->getAll();

        return array_map(static function ($r) {
            // MySQL: 1=Sun..7=Sat. Re-key to 0=Mon..6=Sun.
            $mysqlDow = (int) $r['dow_mysql'];
            $dow      = ($mysqlDow + 5) % 7;
            return [
                'dow'             => $dow,
                'hour'            => (int) $r['hour'],
                'donations_count' => (int) $r['cnt'],
                'amount_cents'    => (int) $r['amount'],
            ];
        }, $rows);
    }

    public function medianPaidAmount(?string $from, ?string $to, ?int $campaignId, int $totalCount): int
    {
        if ($totalCount === 0) return 0;
        $offset = (int) floor($totalCount / 2);

        // Order over the same set the histogram counts (paid + partial_refund)
        // and in the same currency it buckets by (base_amount_cents). paidQuery()
        // is paid-only, so an offset from the paid+partial total would overshoot;
        // ordering by donor-currency amount_cents would rank foreign gifts
        // against org-currency ones.
        $q = DonationQueries::live(
            DB::table('dono_donations')->whereIn('status', ['paid', 'partial_refund'])
        );
        if ($from !== null)       $q = $q->where('paid_at', $from . ' 00:00:00', '>=');
        if ($to   !== null)       $q = $q->where('paid_at', $to   . ' 23:59:59', '<=');
        if ($campaignId !== null) $q = $q->where('campaign_id', $campaignId);

        $row = $q->selectRaw('COALESCE(base_amount_cents, 0) AS amount_cents')
            ->orderBy('base_amount_cents', 'ASC')
            ->limit(1)
            ->offset($offset)
            ->get();

        return (int) ($row['amount_cents'] ?? 0);
    }

    /**
     * Per-donor cohort rows for a campaign. One row per donor who donated in
     * the range
     *
     * @return array<array{
     *   donor_id:int,
     *   first_paid_at:string,
     *   in_range_count:int,
     *   recurring_amount_cents:int,
     *   one_time_amount_cents:int,
     *   recurring_new_count:int
     * }>
     */
    public function donorCohortRowsForCampaign(int $campaignId, ?string $from, ?string $to): array
    {
        $rangePredicate = '1';
        if ($from !== null && $to !== null) {
            $fromQ = esc_sql($from . ' 00:00:00');
            $toQ   = esc_sql($to   . ' 23:59:59');
            $rangePredicate = "paid_at >= '{$fromQ}' AND paid_at <= '{$toQ}'";
        }

        $netExpr = DonationQueries::netBaseExpr();

        $rows = DonationQueries::live(DB::table('dono_donations')
            ->whereIn('status', ['paid', 'partial_refund'])
            ->where('campaign_id', $campaignId))
            ->selectRaw("
                donor_id,
                MIN(paid_at) AS first_paid_at,
                SUM(CASE WHEN {$rangePredicate} THEN 1 ELSE 0 END) AS in_range_count,
                SUM(CASE WHEN ({$rangePredicate}) AND frequency IS NOT NULL AND frequency <> 'one_time' THEN {$netExpr} ELSE 0 END) AS rec_amount,
                SUM(CASE WHEN ({$rangePredicate}) AND (frequency IS NULL OR frequency = 'one_time') THEN {$netExpr} ELSE 0 END) AS ot_amount,
                SUM(CASE WHEN ({$rangePredicate}) AND recurring_plan_id IS NOT NULL THEN 1 ELSE 0 END) AS rec_new
            ")
            ->groupBy('donor_id')
            ->getAll();

        $out = [];
        foreach ($rows as $r) {
            $inRange = (int) $r['in_range_count'];
            if ($inRange === 0) continue; // donor exists in campaign but not in range
            $out[] = [
                'donor_id'               => (int) $r['donor_id'],
                'first_paid_at'          => (string) $r['first_paid_at'],
                'in_range_count'         => $inRange,
                'recurring_amount_cents' => (int) $r['rec_amount'],
                'one_time_amount_cents'  => (int) $r['ot_amount'],
                'recurring_new_count'    => (int) $r['rec_new'],
            ];
        }
        return $out;
    }

    /**
     * Distinct donors with a paid recurring donation for a campaign (the
     * "Recurring donors" KPI). Counts donors, not rows: a donor with six
     * monthly renewals is one recurring donor, not six.
     */
    public function countActiveRecurringForCampaign(int $campaignId): int
    {
        $row = DonationQueries::live(DB::table('dono_donations')
            ->whereIn('status', ['paid', 'partial_refund'])
            ->where('campaign_id', $campaignId))
            ->where('frequency', 'one_time', '<>')
            ->selectRaw('COUNT(DISTINCT donor_id) AS c')
            ->get();

        return (int) ($row['c'] ?? 0);
    }

    // ── Shared query builder ──────────────────────────────────────────────

    private function paidQuery(?string $from, ?string $to, ?int $campaignId): QueryBuilder
    {
        $q = DonationQueries::live(DB::table('dono_donations')->where('status', 'paid'));
        if ($from !== null)       $q = $q->where('paid_at', $from . ' 00:00:00', '>=');
        if ($to   !== null)       $q = $q->where('paid_at', $to   . ' 23:59:59', '<=');
        if ($campaignId !== null) $q = $q->where('campaign_id', $campaignId);
        return $q;
    }

    /**
     * paidQuery + partial_refund + LEFT JOIN to per-donation refund totals as
     * alias `r`. Use when the caller wants to subtract refunded cents from the
     * SUM (and so cares about partial_refund as well as paid).
     *
     * `joinRaw` doesn't auto-prefix table names like DB::table() does, so the
     * subquery + the table referenced from selectRaw have to use the prefixed
     * names explicitly.
     */
    private function netPaidQuery(?string $from, ?string $to, ?int $campaignId): QueryBuilder
    {
        $q = DonationQueries::live(DB::table('dono_donations')->whereIn('status', ['paid', 'partial_refund']));
        if ($from !== null)       $q = $q->where('paid_at', $from . ' 00:00:00', '>=');
        if ($to   !== null)       $q = $q->where('paid_at', $to   . ' 23:59:59', '<=');
        if ($campaignId !== null) $q = $q->where('campaign_id', $campaignId);

        $prefix = DB::getPrefix();
        // Scale each refund by its donation's fx_rate so refunded cents land in
        // the base currency, matching the donations' base_amount_cents they get
        // netted against (and DonationQueries::refundedBaseExpr on the public
        // path). Summing raw amount_cents mixed foreign cents into base totals.
        //
        // Summed before rounding for the reason given on refundedBaseExpr: a
        // foreign donation refunded in instalments drifts a cent past what the
        // same total is actually worth in base.
        return $q->joinRaw(
            "LEFT JOIN (
                SELECT rf.donation_id, ROUND(SUM(rf.amount_cents) * COALESCE(d.fx_rate, 0)) AS refunded
                FROM {$prefix}dono_refunds rf
                JOIN {$prefix}dono_donations d ON d.id = rf.donation_id
                WHERE rf.status = 'succeeded'
                GROUP BY rf.donation_id, d.fx_rate
            ) r ON r.donation_id = {$prefix}dono_donations.id"
        );
    }

    private function formatBucketLabel(int $lowExclusiveCents, int $highInclusiveCents): string
    {
        if ($lowExclusiveCents === 0) {
            return '0-' . $this->formatMoneyShort($highInclusiveCents);
        }
        return $this->formatMoneyShort($lowExclusiveCents) . '-' . $this->formatMoneyShort($highInclusiveCents);
    }

    private function formatMoneyShort(int $cents): string
    {
        $major = $cents / 100;
        if ($major >= 1000) return rtrim(rtrim(number_format($major / 1000, 1, '.', ''), '0'), '.') . 'k';
        return (string) (int) $major;
    }

    /**
     * Aggregate KPIs for the admin donations list, scoped by the listAdmin() filter shape.
     * Raised totals are paid-only; currency is the most common in the filtered set.
     *
     * @return array{total_count:int,paid_count:int,raised_cents:int,currency:?string,donors_count:int}
     */
    public function aggregateAdmin(array $args = []): array
    {
        $applyFilters = fn ($q) => $this->applyAdminFilters($q, $args);

        // Uses DB::table (raw query builder) so selectRaw/get() return arrays
        // rather than hydrated Donation models. The Donation::query() path is
        // for list pages where we want model rows.
        $base = fn () => DB::table('dono_donations');

        // total mirrors the list row count (test rows included), while the money
        // KPIs below are live-only; the strip's "total" is a view count, not a
        // money metric.
        $totalCount = (int) $applyFilters($base())->count();

        // Net refunds in base currency to match the base-currency raised sum.
        $refundedExpr = DonationQueries::refundedBaseExpr();

        // "Raised" must reflect real money: exclude test donations from the
        // money aggregate unless the admin is explicitly viewing test data.
        $paidQuery = $applyFilters($base())->whereIn('status', ['paid', 'partial_refund']);
        if (! self::hasExplicitTestFilter($args)) {
            $paidQuery = $paidQuery->where('is_test', 0);
        }

        $paidRow = $paidQuery
            ->selectRaw("COALESCE(SUM(COALESCE(base_amount_cents, 0) - {$refundedExpr}), 0) AS amount, COUNT(*) AS cnt, COUNT(DISTINCT donor_id) AS donors")
            ->get();

        return [
            'total_count'  => $totalCount,
            'paid_count'   => (int) ($paidRow['cnt']    ?? 0),
            'raised_cents' => (int) ($paidRow['amount'] ?? 0),
            // raised_cents sums the base/org currency; label it as such, not the
            // most-common donation currency.
            'currency'     => \Dono\Foundation\Helpers\Money::defaultCurrency(),
            'donors_count' => (int) ($paidRow['donors'] ?? 0),
        ];
    }

    /**
     * Shared admin filter set for the donations list, CSV export, and KPI
     * aggregate. Single source of truth so the three can never drift apart -
     * the export previously dropped campaign_id/form_id/gateway/is_test here.
     *
     * @param array<string,mixed> $args
     */
    private function applyAdminFilters($q, array $args)
    {
        $term         = trim((string) ($args['search'] ?? ''));
        $donorIds     = array_values(array_unique(array_map('intval', (array) ($args['matching_donor_ids'] ?? []))));
        $scopeDonorId = isset($args['donor_id']) ? (int) $args['donor_id'] : 0;
        $createdFrom  = self::normaliseDateFilter($args['created_from'] ?? null);
        $createdTo    = self::normaliseDateFilter($args['created_to']   ?? null, true);

        if (! empty($args['status'])) {
            $q = $q->where('status', (string) $args['status']);
        }
        if (! empty($args['campaign_id'])) {
            $q = $q->where('campaign_id', (int) $args['campaign_id']);
        }
        if (! empty($args['form_id'])) {
            $q = $q->where('form_id', (int) $args['form_id']);
        }
        if (! empty($args['gateway'])) {
            $q = $q->where('gateway', (string) $args['gateway']);
        }
        if (array_key_exists('is_test', $args) && $args['is_test'] !== '' && $args['is_test'] !== null) {
            $q = $q->where('is_test', (bool) $args['is_test'] ? 1 : 0);
        } else {
            // Live-only by default; test donations are a dev/staging concern and
            // surface (in the list, CSV export and the strip total alike) only
            // when the admin explicitly filters to them.
            $q = $q->where('is_test', 0);
        }
        if ($createdFrom !== null) {
            $q = $q->where('created_at', $createdFrom, '>=');
        }
        if ($createdTo !== null) {
            $q = $q->where('created_at', $createdTo, '<=');
        }
        if ($scopeDonorId > 0) {
            $q = $q->where('donor_id', $scopeDonorId);
            if ($term !== '') {
                $q = $q->where(function ($g) use ($term): void {
                    $g->whereLike('reference', $term)
                      ->orWhereLike('gateway_intent_id', $term)
                      ->orWhereLike('gateway_txn_id', $term);
                });
            }
        } elseif ($term !== '' || ! empty($donorIds)) {
            $q = $q->where(function ($g) use ($term, $donorIds): void {
                $added = false;
                if ($term !== '') {
                    $g->whereLike('reference', $term)
                      ->orWhereLike('gateway_intent_id', $term)
                      ->orWhereLike('gateway_txn_id', $term);
                    $added = true;
                }
                if (! empty($donorIds)) {
                    if ($added) {
                        $g->orWhereIn('donor_id', $donorIds);
                    } else {
                        $g->whereIn('donor_id', $donorIds);
                    }
                }
            });
        }
        return $q;
    }

    /**
     * True when the caller passed an explicit is_test filter (true or false).
     * When false, money KPIs default to live-only so test donations never
     * inflate "Raised".
     *
     * @param array<string,mixed> $args
     */
    private static function hasExplicitTestFilter(array $args): bool
    {
        return array_key_exists('is_test', $args) && $args['is_test'] !== '' && $args['is_test'] !== null;
    }

    /**
     * Accept "YYYY-MM-DD" or a full datetime and normalise to "YYYY-MM-DD 00:00:00".
     * When `$endOfDay` is true, normalises a bare date to "YYYY-MM-DD 23:59:59"
     * so the inclusive-upper-bound feels natural. Returns null for unparseable
     * / empty input so the caller can skip the filter.
     */
    private static function normaliseDateFilter(mixed $value, bool $endOfDay = false): ?string
    {
        if ($value === null) return null;
        $raw = trim((string) $value);
        if ($raw === '') return null;
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            return $raw . ($endOfDay ? ' 23:59:59' : ' 00:00:00');
        }
        // Otherwise pass through if it parses as a date.
        $ts = strtotime($raw);
        return $ts !== false ? gmdate('Y-m-d H:i:s', $ts) : null;
    }
}
