<?php

declare(strict_types=1);

namespace Dono\Donors;

use DateTimeImmutable;
use Dono\Donations\DonationQueries;
use Dono\Vendor\Queryable\DB;

final class DonorRepository
{
    // Days since last donation: active <ACTIVE, at_risk ACTIVE..AT_RISK,
    // lapsed AT_RISK..LAPSED, lost >LAPSED. Shared by the KPI buckets and the
    // at-risk list so the headline count and the listed rows agree.
    private const NEW_DAYS     = 30;
    private const ACTIVE_DAYS  = 90;
    private const AT_RISK_DAYS = 180;
    private const LAPSED_DAYS  = 365;

    public function findById(int $id): ?Donor
    {
        return Donor::query()->find('id', $id);
    }

    /** @return array<int, Donor> */
    public function findManyByIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (! $ids) return [];
        $rows = Donor::query()->whereIn('id', $ids)->getAll();
        $byId = [];
        foreach ($rows as $d) $byId[(int) $d->id] = $d;
        return $byId;
    }

    public function findByEmailHash(string $hash): ?Donor
    {
        return Donor::query()->find('email_hash', $hash);
    }

    public function existsByEmailHash(string $hash): bool
    {
        return $this->findByEmailHash($hash) !== null;
    }

    /**
     * Who belongs in the admin donor list. A donor row is written for every
     * donation, so the only ones held back are those whose entire footprint is
     * test-mode; somebody who signed up and has not given yet is a real person.
     *
     * Parenthesised because it is an OR, and it must be the FIRST
     * where-condition on a query: whereRaw adds no AND connector, but a where()
     * chained after it does.
     */
    public static function visibleDonorPredicate(): string
    {
        $prefix = DB::getPrefix();
        $any    = "SELECT 1 FROM {$prefix}dono_donations d WHERE d.donor_id = {$prefix}dono_donors.id";

        return "(EXISTS ({$any} AND d.is_test = 0) OR NOT EXISTS ({$any}))";
    }

    /**
     * A lifecycle stage is a stage of giving, so somebody who has never given
     * has none and counting them dilutes every share on the insights screen.
     */
    private function givingDonorPredicate(): string
    {
        $prefix = DB::getPrefix();
        return "EXISTS (SELECT 1 FROM {$prefix}dono_donations d "
            . "WHERE d.donor_id = {$prefix}dono_donors.id AND d.is_test = 0)";
    }

    /**
     * @param array{page?:int,per_page?:int,orderby?:string,order?:string,country?:string,matching_ids?:array<int>,has_search?:bool} $args
     */
    public function listAdmin(array $args = []): array
    {
        $page    = max(1, (int) ($args['page']     ?? 1));
        $perPage = max(1, min(100, (int) ($args['per_page'] ?? 25)));
        $offset  = ($page - 1) * $perPage;

        $allowedSort = ['last_donation_at', 'total_donated_cents', 'donations_count', 'created_at', 'last_name'];
        $orderBy = in_array($args['orderby'] ?? '', $allowedSort, true)
            ? $args['orderby']
            : 'last_donation_at';
        $order   = strtoupper((string) ($args['order'] ?? 'desc')) === 'ASC' ? 'ASC' : 'DESC';

        $ids = array_values(array_unique(array_map('intval', (array) ($args['matching_ids'] ?? []))));
        $hasSearch = ! empty($args['has_search']);

        $applyFilters = function ($q) use ($args, $ids, $hasSearch) {
            if (! empty($args['country'])) {
                $q = $q->where('country', strtoupper((string) $args['country']));
            }
            if (! empty($args['donor_type'])) {
                $q = $q->where('donor_type', (string) $args['donor_type']);
            }
            if ($hasSearch) {
                // Empty $ids is a never-matches sentinel, not "no filter".
                $q = $q->whereIn('id', $ids ?: [0]);
            }
            return $q;
        };

        $total = (int) $applyFilters(Donor::query()->whereRaw(self::visibleDonorPredicate()))->count();
        $items = $applyFilters(Donor::query()->whereRaw(self::visibleDonorPredicate()))
            ->orderBy($orderBy, $order)
            ->limit($perPage)
            ->offset($offset)
            ->getAll();

        return ['items' => $items, 'total' => $total];
    }

    /**
     * @param array{country?:?string,donor_type?:?string,has_search?:bool,matching_ids?:array<int>} $args
     * @return array{total_count:int,with_donations:int,total_donated_cents:int,avg_ltv_cents:int}
     */
    public function aggregateAdmin(array $args = []): array
    {
        $ids       = array_values(array_unique(array_map('intval', (array) ($args['matching_ids'] ?? []))));
        $hasSearch = ! empty($args['has_search']);

        $applyFilters = function ($q) use ($args, $ids, $hasSearch) {
            if (! empty($args['country'])) {
                $q = $q->where('country', strtoupper((string) $args['country']));
            }
            if (! empty($args['donor_type'])) {
                $q = $q->where('donor_type', (string) $args['donor_type']);
            }
            if ($hasSearch) {
                $q = $q->whereIn('id', $ids ?: [0]);
            }
            return $q;
        };

        $base = fn () => DB::table('dono_donors')->whereRaw(self::visibleDonorPredicate());

        $totalCount    = (int) $applyFilters($base())->count();
        $withDonations = (int) $applyFilters($base())->where('donations_count', 0, '>')->count();

        $sumRow = $applyFilters($base())
            ->selectRaw('COALESCE(SUM(total_donated_cents),0) AS raised')
            ->get();
        $raised = (int) ($sumRow['raised'] ?? 0);

        return [
            'total_count'         => $totalCount,
            'with_donations'      => $withDonations,
            'total_donated_cents' => $raised,
            'avg_ltv_cents'       => $withDonations > 0 ? (int) round($raised / $withDonations) : 0,
        ];
    }

    /**
     * @return array{
     *   total:int,
     *   new:int,
     *   active:int,
     *   at_risk:int,
     *   lapsed:int,
     *   lost:int,
     *   avg_ltv_cents:int,
     *   median_ltv_cents:int,
     *   total_ltv_cents:int
     * }
     */
    public function lifecycleKpi(string $today, int $newDays = self::NEW_DAYS, int $activeDays = self::ACTIVE_DAYS, int $atRiskDays = self::AT_RISK_DAYS, int $lapsedDays = self::LAPSED_DAYS): array
    {
        $newCut    = esc_sql($this->daysAgo($today, $newDays));
        $activeCut = esc_sql($this->daysAgo($today, $activeDays));
        $atRiskCut = esc_sql($this->daysAgo($today, $atRiskDays));
        $lapsedCut = esc_sql($this->daysAgo($today, $lapsedDays));

        $row = DB::table('dono_donors')
            ->whereRaw('redacted_at IS NULL AND ' . $this->givingDonorPredicate())
            ->selectRaw("
                COUNT(*) AS total,
                SUM(CASE WHEN created_at >= '{$newCut}' THEN 1 ELSE 0 END) AS new_donors,
                SUM(CASE WHEN last_donation_at >= '{$activeCut}' THEN 1 ELSE 0 END) AS active,
                SUM(CASE WHEN last_donation_at <  '{$activeCut}' AND last_donation_at >= '{$atRiskCut}' THEN 1 ELSE 0 END) AS at_risk,
                SUM(CASE WHEN last_donation_at <  '{$atRiskCut}' AND last_donation_at >= '{$lapsedCut}' THEN 1 ELSE 0 END) AS lapsed,
                SUM(CASE WHEN last_donation_at <  '{$lapsedCut}' THEN 1 ELSE 0 END) AS lost,
                COALESCE(SUM(total_donated_cents), 0) AS ltv_total,
                SUM(CASE WHEN total_donated_cents > 0 THEN 1 ELSE 0 END) AS givers
            ")
            ->get();

        $total = (int) ($row['total'] ?? 0);
        // Avg and median lifetime value describe donors who actually gave:
        // never-gave rows would drag both toward 0 and disagree with the
        // donors-list "Avg lifetime value".
        $givers = (int) ($row['givers'] ?? 0);
        $avgLtv = $givers > 0 ? (int) round(((int) ($row['ltv_total'] ?? 0)) / $givers) : 0;
        $median = $givers > 0 ? $this->medianLtv($givers) : 0;

        return [
            'total'            => $total,
            'new'              => (int) ($row['new_donors'] ?? 0),
            'active'           => (int) ($row['active']     ?? 0),
            'at_risk'          => (int) ($row['at_risk']    ?? 0),
            'lapsed'           => (int) ($row['lapsed']     ?? 0),
            'lost'             => (int) ($row['lost']       ?? 0),
            'avg_ltv_cents'    => $avgLtv,
            'median_ltv_cents' => $median,
            'total_ltv_cents'  => (int) ($row['ltv_total'] ?? 0),
        ];
    }

    /**
     * The exact bucket lifecycleKpi() counts as `at_risk`, so the headline
     * count and these rows always agree.
     */
    public function listAtRisk(string $today, int $page = 1, int $perPage = 25, int $activeDays = self::ACTIVE_DAYS, int $atRiskDays = self::AT_RISK_DAYS): array
    {
        $activeCut = esc_sql($this->daysAgo($today, $activeDays));
        $atRiskCut = esc_sql($this->daysAgo($today, $atRiskDays));
        $offset    = max(0, ($page - 1) * $perPage);

        $where = "redacted_at IS NULL AND last_donation_at < '{$activeCut}' AND last_donation_at >= '{$atRiskCut}'";

        $total = (int) DB::table('dono_donors')->whereRaw($where)->count();

        $rows = DB::table('dono_donors')
            ->whereRaw($where)
            ->selectRaw('id, first_name, last_name, email_encrypted, country, donations_count, total_donated_cents, last_donation_at, first_donation_at')
            ->orderBy('total_donated_cents', 'DESC')
            ->limit($perPage)
            ->offset($offset)
            ->getAll();

        $shaped = array_map(static fn ($r) => [
            'id'                  => (int) $r['id'],
            'first_name'          => $r['first_name'],
            'last_name'           => $r['last_name'],
            'email_encrypted'     => $r['email_encrypted'],
            'country'             => $r['country'],
            'donations_count'     => (int) $r['donations_count'],
            'total_donated_cents' => (int) $r['total_donated_cents'],
            'last_donation_at'    => $r['last_donation_at'],
            'first_donation_at'   => $r['first_donation_at'],
        ], $rows);

        return ['rows' => $shaped, 'total' => $total];
    }

    /**
     * @return array<array{
     *   id:int, first_name:?string, last_name:?string, country:?string,
     *   total_donated_cents:int, donations_count:int, last_donation_at:?string
     * }>
     */
    public function topByLifetimeValue(int $limit = 20): array
    {
        // Donors who have given nothing are not part of a lifetime-value
        // ranking. Without this they pad the list to its limit, so a site with
        // three donors showed seventeen rows of nobody at 0.00.
        $rows = DB::table('dono_donors')
            // One fragment, not two: whereRaw contributes no AND connector, so
            // a second call runs straight into the first and the SQL will not
            // parse.
            ->whereRaw('redacted_at IS NULL AND total_donated_cents > 0')
            ->selectRaw('id, first_name, last_name, email_encrypted, country, total_donated_cents, donations_count, last_donation_at')
            ->orderBy('total_donated_cents', 'DESC')
            ->limit($limit)
            ->getAll();

        return array_map(static fn ($r) => [
            'id'                  => (int) $r['id'],
            'first_name'          => $r['first_name'],
            'last_name'           => $r['last_name'],
            'email_encrypted'     => $r['email_encrypted'],
            'country'             => $r['country'],
            'total_donated_cents' => (int) $r['total_donated_cents'],
            'donations_count'     => (int) $r['donations_count'],
            'last_donation_at'    => $r['last_donation_at'],
        ], $rows);
    }

    /**
     * The last bucket is an overflow above the highest threshold.
     *
     * @return array<array{min_cents:int, max_cents:?int, donor_count:int, total_ltv_cents:int}>
     */
    public function lifetimeValueHistogram(array $thresholdsCents): array
    {
        if (! $thresholdsCents) return [];
        sort($thresholdsCents);

        $cases = [];
        foreach ($thresholdsCents as $t) {
            $t = (int) $t;
            $cases[] = "WHEN total_donated_cents <= {$t} THEN {$t}";
        }
        $bucketExpr = 'CASE ' . implode(' ', $cases) . ' ELSE NULL END';

        $rows = DB::table('dono_donors')
            ->whereRaw('redacted_at IS NULL AND total_donated_cents > 0')
            ->selectRaw("{$bucketExpr} AS bucket, COUNT(*) AS donor_count, COALESCE(SUM(total_donated_cents), 0) AS ltv")
            ->groupByRaw($bucketExpr)
            ->getAll();

        $byBucket = [];
        foreach ($rows as $r) {
            $key = $r['bucket'] === null ? 'overflow' : (int) $r['bucket'];
            $byBucket[$key] = [
                'donor_count' => (int) $r['donor_count'],
                'ltv'         => (int) $r['ltv'],
            ];
        }

        $out  = [];
        $prev = 0;
        foreach ($thresholdsCents as $t) {
            $out[] = [
                'min_cents'       => $prev + 1,
                'max_cents'       => $t,
                'donor_count'     => $byBucket[$t]['donor_count'] ?? 0,
                'total_ltv_cents' => $byBucket[$t]['ltv']         ?? 0,
            ];
            $prev = $t;
        }
        $out[] = [
            'min_cents'       => $prev + 1,
            'max_cents'       => null,
            'donor_count'     => $byBucket['overflow']['donor_count'] ?? 0,
            'total_ltv_cents' => $byBucket['overflow']['ltv']         ?? 0,
        ];
        return $out;
    }

    /**
     * @return array<array{segment:string, donor_count:int, total_ltv_cents:int, avg_ltv_cents:int}>
     */
    public function rfmSegments(string $today, int $activeDays = 90, int $atRiskDays = 180, int $lostDays = 365, int $newDays = 30): array
    {
        $activeCut = esc_sql($this->daysAgo($today, $activeDays));
        $atRiskCut = esc_sql($this->daysAgo($today, $atRiskDays));
        $lostCut   = esc_sql($this->daysAgo($today, $lostDays));
        $newCut    = esc_sql($this->daysAgo($today, $newDays));

        $segmentCase = "
            CASE
                WHEN last_donation_at IS NULL THEN 'other'
                WHEN last_donation_at < '{$lostCut}' THEN 'lost'
                WHEN last_donation_at >= '{$activeCut}' AND donations_count >= 4 AND total_donated_cents >= 25000 THEN 'champions'
                WHEN last_donation_at >= '{$activeCut}' AND donations_count >= 2 THEN 'loyal'
                WHEN last_donation_at >= '{$activeCut}' AND created_at >= '{$newCut}' THEN 'new'
                WHEN last_donation_at < '{$activeCut}' AND last_donation_at >= '{$atRiskCut}' THEN 'at_risk'
                WHEN last_donation_at < '{$atRiskCut}' AND last_donation_at >= '{$lostCut}' THEN 'hibernating'
                ELSE 'other'
            END
        ";

        $rows = DB::table('dono_donors')
            ->whereRaw('redacted_at IS NULL')
            ->selectRaw("
                {$segmentCase} AS segment,
                COUNT(*) AS donor_count,
                COALESCE(SUM(total_donated_cents), 0) AS ltv,
                COALESCE(AVG(total_donated_cents), 0) AS ltv_avg
            ")
            ->groupByRaw($segmentCase)
            ->getAll();

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'segment'         => (string) $r['segment'],
                'donor_count'     => (int) $r['donor_count'],
                'total_ltv_cents' => (int) $r['ltv'],
                'avg_ltv_cents'   => (int) round((float) $r['ltv_avg']),
            ];
        }
        $order = ['champions', 'loyal', 'new', 'at_risk', 'hibernating', 'lost', 'other'];
        usort($out, static fn ($a, $b) => array_search($a['segment'], $order, true) <=> array_search($b['segment'], $order, true));
        return $out;
    }

    /**
     * @return array{
     *   cohorts: array<array{
     *     month:string, size:int,
     *     retention: array<int, array{count:int, pct:float}>
     *   }>,
     *   max_offset: int
     * }
     */
    public function donorCohortRetention(int $cohortMonths = 12, int $maxOffset = 12): array
    {
        $donorsT    = DB::getPrefix() . 'dono_donors';
        $donationsT = DB::getPrefix() . 'dono_donations';
        $cutoff     = esc_sql((new DateTimeImmutable("first day of -{$cohortMonths} months"))->format('Y-m-d'));

        // Anchor each donor's cohort on their own earliest live donation, over
        // the SAME status set the offsets count, not the denormalized
        // first_donation_at: otherwise a donor whose first gift is not a plain
        // 'paid' row misses offset 0, so the cohort size undercounts while
        // later offsets still populate (>100% retention). The MIN spans all of
        // the donor's donations, then cohorts are trimmed to the window, so a
        // pre-window first gift cannot mis-anchor a donor.
        $sql = "
            SELECT
                DATE_FORMAT(fd.first_paid, '%Y-%m') AS cohort,
                PERIOD_DIFF(DATE_FORMAT(d.paid_at, '%Y%m'), DATE_FORMAT(fd.first_paid, '%Y%m')) AS offset_m,
                COUNT(DISTINCT d.donor_id) AS donors
            FROM (
                SELECT d2.donor_id, MIN(d2.paid_at) AS first_paid
                FROM {$donationsT} d2
                JOIN {$donorsT} dn2 ON dn2.id = d2.donor_id
                WHERE dn2.redacted_at IS NULL
                  AND d2.status IN ('paid', 'partial_refund')
                  AND d2.is_test = 0
                  AND d2.paid_at IS NOT NULL
                GROUP BY d2.donor_id
            ) fd
            JOIN {$donationsT} d
                ON d.donor_id = fd.donor_id
               AND d.status IN ('paid', 'partial_refund')
               AND d.is_test = 0
            WHERE fd.first_paid >= '{$cutoff} 00:00:00'
            GROUP BY cohort, offset_m
            ORDER BY cohort ASC, offset_m ASC
        ";
        $result = DB::raw($sql);
        $rows = is_array($result) ? ($result['rows'] ?? []) : [];

        $grid = [];
        foreach ($rows as $r) {
            $cohort = (string) $r->cohort;
            $offset = (int) $r->offset_m;
            $count  = (int) $r->donors;
            if ($offset < 0 || $offset > $maxOffset) continue;
            $grid[$cohort][$offset] = $count;
        }

        $cohorts = [];
        foreach ($grid as $cohort => $offsets) {
            $size = (int) ($offsets[0] ?? 0);
            $retention = [];
            for ($i = 0; $i <= $maxOffset; $i++) {
                $cnt = (int) ($offsets[$i] ?? 0);
                $retention[$i] = [
                    'count' => $cnt,
                    'pct'   => $size > 0 ? round(($cnt / $size) * 100, 1) : 0.0,
                ];
            }
            $cohorts[] = ['month' => $cohort, 'size' => $size, 'retention' => $retention];
        }

        return ['cohorts' => $cohorts, 'max_offset' => $maxOffset];
    }

    /**
     * @return array<array{month:string, amount_cents:int, donations_count:int}>
     */
    public function monthlyTimelineForDonor(int $donorId): array
    {
        $netExpr = DonationQueries::netBaseExpr();
        $rows = DonationQueries::live(DB::table('dono_donations')
            ->whereIn('status', ['paid', 'partial_refund'])
            ->where('donor_id', $donorId))
            ->selectRaw("DATE_FORMAT(paid_at, '%Y-%m') AS month, COALESCE(SUM({$netExpr}), 0) AS amount, COUNT(*) AS cnt")
            ->groupByRaw("DATE_FORMAT(paid_at, '%Y-%m')")
            ->orderByRaw('month ASC')
            ->getAll();

        return array_map(static fn ($r) => [
            'month'           => (string) $r['month'],
            'amount_cents'    => (int) $r['amount'],
            'donations_count' => (int) $r['cnt'],
        ], $rows);
    }

    /**
     * @return array<array{utm_source:?string, utm_medium:?string, amount_cents:int, donations_count:int}>
     */
    public function attributionMixForDonor(int $donorId): array
    {
        $netExpr = DonationQueries::netBaseExpr();
        $rows = DonationQueries::live(DB::table('dono_donations')
            ->whereIn('status', ['paid', 'partial_refund'])
            ->where('donor_id', $donorId))
            ->selectRaw("
                JSON_UNQUOTE(JSON_EXTRACT(source_attribution, '$.utm_source')) AS utm_source,
                JSON_UNQUOTE(JSON_EXTRACT(source_attribution, '$.utm_medium')) AS utm_medium,
                COALESCE(SUM({$netExpr}), 0) AS amount,
                COUNT(*) AS cnt
            ")
            ->groupByRaw("
                JSON_UNQUOTE(JSON_EXTRACT(source_attribution, '$.utm_source')),
                JSON_UNQUOTE(JSON_EXTRACT(source_attribution, '$.utm_medium'))
            ")
            ->getAll();

        return array_map(static fn ($r) => [
            'utm_source'      => $r['utm_source'] !== null && $r['utm_source'] !== 'null' ? (string) $r['utm_source'] : null,
            'utm_medium'      => $r['utm_medium'] !== null && $r['utm_medium'] !== 'null' ? (string) $r['utm_medium'] : null,
            'amount_cents'    => (int) $r['amount'],
            'donations_count' => (int) $r['cnt'],
        ], $rows);
    }

    private function medianLtv(int $giverCount): int
    {
        if ($giverCount === 0) return 0;
        $offset = (int) floor($giverCount / 2);
        $row = DB::table('dono_donors')
            ->whereRaw('redacted_at IS NULL')
            ->where('total_donated_cents', 0, '>')
            ->selectRaw('total_donated_cents')
            ->orderBy('total_donated_cents', 'ASC')
            ->limit(1)
            ->offset($offset)
            ->get();
        return (int) ($row['total_donated_cents'] ?? 0);
    }

    private function daysAgo(string $today, int $days): string
    {
        return (new DateTimeImmutable($today))->modify("-{$days} days")->format('Y-m-d 00:00:00');
    }
}
