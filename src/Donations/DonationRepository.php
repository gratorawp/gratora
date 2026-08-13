<?php

declare(strict_types=1);

namespace Dono\Donations;

use Dono\Receipts\Receipt;
use Dono\Vendor\Queryable\DB;
use Dono\Vendor\Queryable\QueryBuilder;

/** @since 1.0.0 */
final class DonationRepository
{
    /** @since 1.0.0 */
    public function findById(int $id): ?Donation
    {
        return Donation::query()->find('id', $id);
    }

    /** @since 1.0.0 */
    public function findByReference(string $reference): ?Donation
    {
        return Donation::query()->find('reference', $reference);
    }

    /**
     * For a year-end tax statement. The year boundary is paid_at, when the
     * money arrived, not created_at. Rows carry gross plus the succeeded-refund
     * total from the Refund table so the caller can net them to a deductible
     * figure.
     *
     * @return list<array{date:string,amount_cents:int,refunded_cents:int,currency:string,reference:string,receipt_number:?string}>
     *
     * @since 1.0.0
     */
    public function paidForDonorInYear(int $donorId, int $year): array
    {
        [$start, $end] = DonationQueries::yearBoundsUtc($year);

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

    /** @since 1.0.0 */
    public function findByGatewayIntent(string $gateway, string $intentId): ?Donation
    {
        return Donation::query()
            ->where('gateway', $gateway)
            ->where('gateway_intent_id', $intentId)
            ->get();
    }

    /** @since 1.0.0 */
    public function findByGatewayTxn(string $gateway, string $txnId): ?Donation
    {
        return Donation::query()
            ->where('gateway', $gateway)
            ->where('gateway_txn_id', $txnId)
            ->get();
    }

    /**
     * How many test donations the same filters would have matched. The list is
     * live-only, and silent exclusion means an admin donating while the org is
     * in test mode watches their donation vanish.
     *
     * @since 1.0.0
     */
    public function countTestHidden(array $args = []): int
    {
        // Explicit filter, so it wins over include_test whatever scope the
        // caller was using.
        $args['is_test'] = true;

        return (int) $this->applyAdminFilters(Donation::query(), $args)->count();
    }

    /**
     * `matching_donor_ids` lets the caller pre-resolve a search term to donor
     * ids so the search covers donor name and exact email as well as
     * `reference`.
     *
     * @param array{page?:int,per_page?:int,orderby?:string,order?:string,status?:string,search?:string,matching_donor_ids?:array<int>} $args
     *
     * @since 1.0.0
     */
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
     * The "these donors never got their receipt" sweep.
     *
     * @param array{page?:int,per_page?:int,campaign_id?:int} $args
     *
     * @since 1.0.0
     */
    public function paidWithoutReceipt(array $args = []): array
    {
        $page       = max(1, (int) ($args['page'] ?? 1));
        $perPage    = max(1, min(100, (int) ($args['per_page'] ?? 25)));
        $offset     = ($page - 1) * $perPage;
        $campaignId = isset($args['campaign_id']) ? (int) $args['campaign_id'] : null;

        $prefix = DB::getPrefix();
        // Must stay the FIRST where-condition: whereRaw adds no AND connector,
        // but the where()s chained after it (status, is_test, campaign) do.
        //
        // sent_to_email_at, not merely the row: ReceiptIssuer commits the
        // receipt before it renders the PDF and before it attempts the send,
        // and skips the send outright when the donor has no address, so a
        // numbered receipt is not a delivered one.
        $noReceipt = "NOT EXISTS (SELECT 1 FROM {$prefix}dono_receipts rc "
            . "WHERE rc.donation_id = {$prefix}dono_donations.id "
            . "AND rc.voided = 0 AND rc.sent_to_email_at IS NOT NULL)";

        // A hand-recorded donation with no receipt is not one that went
        // missing: the admin was asked and declined, because the donor never
        // gave this site an address. Matches ChannelClassifier::MANUAL, which
        // core reserves and the public route strips; there is no channel column
        // to read instead.
        // IF(JSON_VALID(...)) because the column is LONGTEXT, so nothing stops a
        // non-JSON string reaching it. MySQL raises an error on one, MariaDB
        // returns NULL; guarding makes both return NULL.
        $notByHand = "(source_attribution IS NULL OR JSON_UNQUOTE(JSON_EXTRACT("
            . "IF(JSON_VALID(source_attribution), source_attribution, NULL), "
            . "'$.utm_medium')) <> 'manual')";

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
     * Ids only, in export order. Paging the export by OFFSET makes MySQL walk
     * and discard every earlier row for each page, which is quadratic in the
     * export size. This is one ordered pass, and each page is then a
     * primary-key lookup.
     *
     * @param  array<string,mixed> $args
     * @return list<int>
     *
     * @since 1.0.0
     */
    public function listIdsForExport(array $args = []): array
    {
        $allowedSort = ['created_at', 'paid_at', 'amount_cents', 'reference', 'status'];
        $orderBy = in_array($args['orderby'] ?? '', $allowedSort, true)
            ? $args['orderby']
            : 'created_at';
        $order = strtoupper((string) ($args['order'] ?? 'desc')) === 'ASC' ? 'ASC' : 'DESC';

        $rows = $this->applyAdminFilters(DB::table('dono_donations')->selectRaw('id'), $args)
            ->orderBy($orderBy, $order)
            ->orderBy('id', $order)
            ->limit((int) ($args['limit'] ?? 50000))
            ->getAll();

        return array_map(static fn ($r): int => (int) (is_array($r) ? $r['id'] : $r->id), $rows);
    }

    /**
     * @param  list<int> $ids
     * @return array<int, Donation> keyed by id
     *
     * @since 1.0.0
     */
    public function findManyDonationsByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $out = [];
        foreach (Donation::query()->whereIn('id', $ids)->getAll() as $donation) {
            $out[(int) $donation->id] = $donation;
        }

        return $out;
    }

    /**
     * @return array{amount_cents:int, donations_count:int, donors_count:int}
     *
     * @since 1.0.0
     */
    public function aggregatePaidBetween(?string $from = null, ?string $to = null, ?int $campaignId = null, bool $includeTest = false): array
    {
        $prefix = DB::getPrefix();
        $row = $this->netPaidQuery($from, $to, $campaignId, $includeTest)
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
     * @return array<array{day:string, amount_cents:int, donations_count:int}>
     *
     * @since 1.0.0
     */
    public function dailyPaidBetween(string $from, string $to, ?int $campaignId = null, bool $includeTest = false): array
    {
        $prefix = DB::getPrefix();
        $rows = $this->netPaidQuery($from, $to, $campaignId, $includeTest)
            ->selectRaw("DATE({$prefix}dono_donations.paid_at) AS day, COALESCE(SUM(COALESCE({$prefix}dono_donations.base_amount_cents, 0) - COALESCE(r.refunded, 0)), 0) AS amount, COUNT(*) AS cnt")
            ->groupByRaw("DATE({$prefix}dono_donations.paid_at)")
            ->getAll();

        return array_map(static fn ($r) => [
            'day'             => (string) $r['day'],
            'amount_cents'    => (int) $r['amount'],
            'donations_count' => (int) $r['cnt'],
        ], $rows);
    }

    /**
     * Net of refunds so it agrees with the raised total shown beside it: a
     * headline gift that was half refunded is not still the biggest one.
     *
     * @since 1.0.0
     */
    public function maxNetPaidAmount(?int $campaignId = null): int
    {
        $prefix = DB::getPrefix();
        $row = $this->netPaidQuery(null, null, $campaignId)
            ->selectRaw(
                "COALESCE(MAX(COALESCE({$prefix}dono_donations.base_amount_cents, 0) - COALESCE(r.refunded, 0)), 0) AS top"
            )
            ->get();

        return max(0, (int) ($row['top'] ?? 0));
    }

    /** @since 1.0.0 */
    public function firstPaidDate(?int $campaignId = null): ?string
    {
        $q = DonationQueries::donationsOnly(DB::table('dono_donations')
            ->selectRaw('MIN(paid_at) AS first_paid')
            ->whereIn('status', ['paid', 'partial_refund']));

        if ($campaignId !== null) {
            $q = $q->where('campaign_id', $campaignId);
        }

        $val = $q->get()['first_paid'] ?? null;

        return $val ? substr((string) $val, 0, 10) : null;
    }

    /**
     * A single currency to format aggregated totals against a mixed-currency
     * pool.
     *
     * @since 1.0.0
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
     * @return array<array{utm_source:?string, utm_medium:?string, amount_cents:int, donations_count:int}>
     *
     * @since 1.0.0
     */
    public function aggregatePaidByAttribution(?string $from = null, ?string $to = null, ?int $campaignId = null, bool $includeTest = false): array
    {
        $prefix = DB::getPrefix();
        $rows = $this->netPaidQuery($from, $to, $campaignId, $includeTest)
            ->selectRaw("
                JSON_UNQUOTE(JSON_EXTRACT(IF(JSON_VALID({$prefix}dono_donations.source_attribution), {$prefix}dono_donations.source_attribution, NULL), '$.utm_source')) AS utm_source,
                JSON_UNQUOTE(JSON_EXTRACT(IF(JSON_VALID({$prefix}dono_donations.source_attribution), {$prefix}dono_donations.source_attribution, NULL), '$.utm_medium')) AS utm_medium,
                COALESCE(SUM(COALESCE({$prefix}dono_donations.base_amount_cents, 0) - COALESCE(r.refunded, 0)), 0) AS amount,
                COUNT(*) AS cnt
            ")
            ->groupByRaw("
                JSON_UNQUOTE(JSON_EXTRACT(IF(JSON_VALID({$prefix}dono_donations.source_attribution), {$prefix}dono_donations.source_attribution, NULL), '$.utm_source')),
                JSON_UNQUOTE(JSON_EXTRACT(IF(JSON_VALID({$prefix}dono_donations.source_attribution), {$prefix}dono_donations.source_attribution, NULL), '$.utm_medium'))
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
     * @return array<array{campaign_id:int, amount_cents:int, donations_count:int}>
     *
     * @since 1.0.0
     */
    public function topPaidCampaigns(?string $from = null, ?string $to = null, int $limit = 5, bool $includeTest = false): array
    {
        $prefix = DB::getPrefix();
        $rows = $this->netPaidQuery($from, $to, null, $includeTest)
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
     * @return array<array{day:string, amount_cents:int}>
     *
     * @since 1.0.0
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
     * Batched so the top-campaigns sparkline does not fire one daily query per
     * row.
     *
     * @return array<int, array<string, int>> map of campaign_id => [day => amount_cents]
     *
     * @since 1.0.0
     */
    public function dailyPaidByCampaignsBetween(array $campaignIds, string $from, string $to, bool $includeTest = false): array
    {
        if ($campaignIds === []) return [];
        $prefix = DB::getPrefix();
        $rows = $this->netPaidQuery($from, $to, null, $includeTest)
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
     * @return array<array{gateway:string, amount_cents:int, donations_count:int}>
     *
     * @since 1.0.0
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
     * @return array<array{frequency:string, amount_cents:int, donations_count:int}>
     *
     * @since 1.0.0
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
     * @return array<array{form_id:int, amount_cents:int, donations_count:int}>
     *
     * @since 1.0.0
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

    /** @since 1.0.0 */
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
     * @return array<array{donor_id:int, amount_cents:int, donations_count:int}>
     *
     * @since 1.0.0
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
     * One masked aggregate, so anonymous giving shows without naming anyone.
     *
     * @since 1.0.0
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
     * @return array<array{label:string, threshold:?int, donations_count:int, amount_cents:int}>
     *
     * @since 1.0.0
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
     * @return array<array{dow:int, hour:int, donations_count:int, amount_cents:int}>
     *
     * @since 1.0.0
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

    /** @since 1.0.0 */
    public function medianPaidAmount(?string $from, ?string $to, ?int $campaignId, int $totalCount): int
    {
        if ($totalCount === 0) return 0;
        $offset = (int) floor($totalCount / 2);

        // Ordered over the same set the histogram counts and in the same
        // currency it buckets by: an offset from a paid-only total would
        // overshoot, and donor-currency amount_cents would rank foreign gifts
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
     * @return array<array{
     *   donor_id:int,
     *   first_paid_at:string,
     *   in_range_count:int,
     *   recurring_amount_cents:int,
     *   one_time_amount_cents:int,
     *   recurring_new_count:int
     * }>
     *
     * @since 1.0.0
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
            if ($inRange === 0) continue;
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
     * Donors, not rows: six monthly renewals are one recurring donor.
     *
     * @since 1.0.0
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

    /** @since 1.0.0 */
    private function paidQuery(?string $from, ?string $to, ?int $campaignId): QueryBuilder
    {
        $q = DonationQueries::live(DB::table('dono_donations')->where('status', 'paid'));
        if ($from !== null)       $q = $q->where('paid_at', $from . ' 00:00:00', '>=');
        if ($to   !== null)       $q = $q->where('paid_at', $to   . ' 23:59:59', '<=');
        if ($campaignId !== null) $q = $q->where('campaign_id', $campaignId);
        return $q;
    }

    /**
     * `joinRaw` does not auto-prefix table names the way DB::table() does, so
     * the subquery and anything referenced from selectRaw have to use the
     * prefixed names explicitly.
     *
     * @since 1.0.0
     */
    private function netPaidQuery(?string $from, ?string $to, ?int $campaignId, bool $includeTest = false): QueryBuilder
    {
        // donationRows, not live: every caller below is donation reporting,
        // and a ticket order is a purchase riding the same table. The flag
        // only ever admits test rows; it never admits orders.
        $q = DonationQueries::donationRows(
            DB::table('dono_donations')->whereIn('status', ['paid', 'partial_refund']),
            $includeTest
        );
        if ($from !== null)       $q = $q->where('paid_at', $from . ' 00:00:00', '>=');
        if ($to   !== null)       $q = $q->where('paid_at', $to   . ' 23:59:59', '<=');
        if ($campaignId !== null) $q = $q->where('campaign_id', $campaignId);

        $prefix = DB::getPrefix();
        // Each refund is scaled by its donation's fx_rate so refunded cents
        // land in the base currency they get netted against; summing raw
        // amount_cents would mix foreign cents into base totals.
        //
        // Summed before rounding, for the reason given on refundedBaseExpr: a
        // foreign donation refunded in instalments otherwise drifts a cent past
        // what the same total is worth in base.
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

    /** @since 1.0.0 */
    private function formatBucketLabel(int $lowExclusiveCents, int $highInclusiveCents): string
    {
        if ($lowExclusiveCents === 0) {
            return '0-' . $this->formatMoneyShort($highInclusiveCents);
        }
        return $this->formatMoneyShort($lowExclusiveCents) . '-' . $this->formatMoneyShort($highInclusiveCents);
    }

    /** @since 1.0.0 */
    private function formatMoneyShort(int $cents): string
    {
        $major = $cents / 100;
        if ($major >= 1000) return rtrim(rtrim(number_format($major / 1000, 1, '.', ''), '0'), '.') . 'k';
        return (string) (int) $major;
    }

    /**
     * `include_test` widens every figure here, not just the row count, so the
     * strip describes the same donations the list is showing. `includes_test`
     * comes back with them so the caller can say so next to each number: a
     * figure counting rehearsal money must never be readable as real money.
     *
     * @return array{total_count:int,paid_count:int,raised_cents:int,currency:?string,donors_count:int,includes_test:bool}
     *
     * @since 1.0.0
     */
    public function aggregateAdmin(array $args = []): array
    {
        $applyFilters = fn ($q) => $this->applyAdminFilters($q, $args);

        // DB::table, so selectRaw/get() return arrays rather than hydrated
        // Donation models.
        $base = fn () => DB::table('dono_donations');

        $totalCount = (int) $applyFilters($base())->count();

        // Net refunds in base currency to match the base-currency raised sum.
        $refundedExpr = DonationQueries::refundedBaseExpr();

        // paid_count, raised_cents and donors_count all read off this one
        // query, so the three cannot disagree about which donations they are
        // counting, and applyAdminFilters is the only thing scoping test rows.
        $paidQuery = $applyFilters($base())->whereIn('status', ['paid', 'partial_refund']);

        $paidRow = $paidQuery
            ->selectRaw("COALESCE(SUM(COALESCE(base_amount_cents, 0) - {$refundedExpr}), 0) AS amount, COUNT(*) AS cnt, COUNT(DISTINCT donor_id) AS donors")
            ->get();

        return [
            'total_count'   => $totalCount,
            'paid_count'    => (int) ($paidRow['cnt']    ?? 0),
            'raised_cents'  => (int) ($paidRow['amount'] ?? 0),
            // raised_cents sums the base currency, so it is labelled as such,
            // not as the most-common donation currency.
            'currency'      => \Dono\Foundation\Helpers\Money::defaultCurrency(),
            'donors_count'  => (int) ($paidRow['donors'] ?? 0),
            'includes_test' => self::countsTestDonations($args),
        ];
    }

    /**
     * Whether the admin filters let a test donation into a figure at all. The
     * explicit filter wins both ways: "Test only" makes every figure test
     * money, "Live only" keeps them clean whatever the scope is set to.
     *
     * @since 1.0.0
     */
    private static function countsTestDonations(array $args): bool
    {
        if (self::hasExplicitTestFilter($args)) {
            return (bool) $args['is_test'];
        }
        return ! empty($args['include_test']);
    }

    /**
     * Shared by the donations list, the CSV export and the KPI aggregate, so
     * the three cannot drift apart.
     *
     * @since 1.0.0
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
        // "recurring" is every cadence at once, which is the question actually
        // asked of this column; the individual cadences filter on themselves.
        if (! empty($args['frequency'])) {
            $frequency = (string) $args['frequency'];
            if ($frequency === 'recurring') {
                $q = $q->where('frequency', 'one_time', '<>');
            } else {
                $q = $q->where('frequency', $frequency);
            }
        }
        if (self::hasExplicitTestFilter($args)) {
            $q = $q->where('is_test', (bool) $args['is_test'] ? 1 : 0);
        } elseif (empty($args['include_test'])) {
            // Live-only by default; test donations surface in the list, the CSV
            // export and the KPI figures only when the admin asks for them.
            $q = $q->where('is_test', 0);
        }
        // include_test with no explicit is_test filter adds no predicate at
        // all: the two exclusive filters each show one kind only, so neither
        // can show a run of donations as it actually happened.

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

    /** @since 1.0.0 */
    private static function hasExplicitTestFilter(array $args): bool
    {
        return array_key_exists('is_test', $args) && $args['is_test'] !== '' && $args['is_test'] !== null;
    }

    /**
     * A bare date normalises to the start of the day, or the end of it for an
     * inclusive upper bound. Null for unparseable input, so the caller can skip
     * the filter.
     *
     * @since 1.0.0
     */
    private static function normaliseDateFilter(mixed $value, bool $endOfDay = false): ?string
    {
        if ($value === null) return null;
        $raw = trim((string) $value);
        if ($raw === '') return null;
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            return $raw . ($endOfDay ? ' 23:59:59' : ' 00:00:00');
        }
        $ts = strtotime($raw);
        return $ts !== false ? gmdate('Y-m-d H:i:s', $ts) : null;
    }
}
