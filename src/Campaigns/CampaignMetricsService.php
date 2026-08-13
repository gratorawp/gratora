<?php

declare(strict_types=1);

namespace Dono\Campaigns;

use DateTimeImmutable;
use Dono\Donations\ChannelClassifier;
use Dono\Donations\Donation;
use Dono\Donations\DonationQueries;
use Dono\Donations\DonationRepository;
use Dono\Donors\Donor;
use Dono\Donors\DonorRepository;
use Dono\Forms\Form;
use Dono\Foundation\Time\Clock;

/**
 * Computes aggregate metrics and lists for campaign analytics.
 * Date range matches the UI selector (today / 7d / 30d / 90d / all-time).
 *
 * @since 1.0.0
 */
final class CampaignMetricsService
{
    /** @since 1.0.0 */
    public function __construct(
        private Clock $clock,
        private DonationRepository $donations,
        private DonorRepository $donors,
    ) {
    }

    /**
     * @return array{amount_raised_cents:int,donations_count:int,donors_count:int,avg_donation_cents:int}
     *
     * @since 1.0.0
     */
    public function summary(int $campaignId, string $range = 'all-time'): array
    {
        return $this->summaryForRange($campaignId, $this->rangeBounds($range, $campaignId), $range === 'all-time');
    }

    /**
     * summary() plus an optional comparison block. $mode: 'period' compares to the
     * equivalent window immediately before, 'year' to the same window one year back,
     * 'none' skips it. all-time always gets null.
     *
     * @return array<string,mixed>
     *
     * @since 1.0.0
     */
    public function summaryWithComparison(
        int $campaignId,
        string $range = 'all-time',
        string $mode = 'period'
    ): array {
        $current = $this->summary($campaignId, $range);
        if ($range === 'all-time' || $mode === 'none') {
            return $current + ['comparison' => null];
        }

        $prev = $this->previousRangeBounds($range, $campaignId, $mode);
        $previous = $this->summaryForRange($campaignId, $prev, false);

        $changes = [];
        foreach (['amount_raised_cents', 'donations_count', 'donors_count', 'avg_donation_cents'] as $key) {
            $changes[$key] = $this->percentChange($previous[$key], $current[$key]);
        }

        return $current + ['comparison' => [
            'mode'            => $mode,
            'previous'        => $previous,
            'previous_series' => $this->revenueSeriesBetween($campaignId, $prev, false),
            'change_percent'  => $changes,
            'from'            => $prev[0],
            'to'              => $prev[1],
        ]];
    }

    /**
     * @return array<array{form_id:int,form_title:string,amount_cents:int,donations_count:int}>
     *
     * @since 1.0.0
     */
    public function topForms(int $campaignId, string $range = 'all-time', int $limit = 5): array
    {
        [$from, $to, $isAllTime] = $this->rangeArgs($range, $campaignId);
        $tops = $this->donations->topPaidForms(
            $isAllTime ? null : $from,
            $isAllTime ? null : $to,
            $campaignId,
            $limit,
        );

        $forms = $this->formsByIds(array_map(static fn ($r) => (int) $r['form_id'], $tops));

        $out = [];
        foreach ($tops as $row) {
            if ($row['form_id'] === 0) continue;
            $form = $forms[(int) $row['form_id']] ?? null;
            $out[] = [
                'form_id'         => $row['form_id'],
                'form_title'      => $form ? $form->title : __('Removed form', 'dono-fundraising-platform'),
                'amount_cents'    => $row['amount_cents'],
                'donations_count' => $row['donations_count'],
            ];
        }
        return $out;
    }

    /**
     * @return array<array{gateway:string,amount_cents:int,donations_count:int}>
     *
     * @since 1.0.0
     */
    public function byGateway(int $campaignId, string $range = 'all-time'): array
    {
        [$from, $to, $isAllTime] = $this->rangeArgs($range, $campaignId);
        return $this->donations->aggregatePaidByGateway(
            $isAllTime ? null : $from,
            $isAllTime ? null : $to,
            $campaignId,
        );
    }

    /**
     * @return array<array{frequency:string,amount_cents:int,donations_count:int}>
     *
     * @since 1.0.0
     */
    public function byFrequency(int $campaignId, string $range = 'all-time'): array
    {
        [$from, $to, $isAllTime] = $this->rangeArgs($range, $campaignId);
        return $this->donations->aggregatePaidByFrequency(
            $isAllTime ? null : $from,
            $isAllTime ? null : $to,
            $campaignId,
        );
    }

    /**
     * @param array{0:string,1:string} $bounds
     *
     * @since 1.0.0
     */
    private function summaryForRange(int $campaignId, array $bounds, bool $isAllTime): array
    {
        $agg = $this->donations->aggregatePaidBetween(
            $isAllTime ? null : $bounds[0],
            $isAllTime ? null : $bounds[1],
            $campaignId,
        );
        $count = $agg['donations_count'];
        $amount = $agg['amount_cents'];

        return [
            'amount_raised_cents' => $amount,
            'donations_count'     => $count,
            'donors_count'        => $agg['donors_count'],
            'avg_donation_cents'  => $count > 0 ? (int) round($amount / $count) : 0,
        ];
    }

    /**
     * @return float|null Null when the previous value was 0 and current also 0; otherwise change in % (rounded).
     *
     * @since 1.0.0
     */
    private function percentChange(int $previous, int $current): ?float
    {
        if ($previous === 0 && $current === 0) return null;
        if ($previous === 0) return 100.0;
        return round((($current - $previous) / $previous) * 100, 1);
    }

    /**
     * Daily revenue series for charting. Zero-fills dates without donations.
     *
     * @return array<array{date:string,amount_cents:int,donations_count:int}>
     *
     * @since 1.0.0
     */
    public function revenueSeries(int $campaignId, string $range = 'all-time'): array
    {
        return $this->revenueSeriesBetween(
            $campaignId,
            $this->rangeBounds($range, $campaignId),
            $range === 'all-time'
        );
    }

    /**
     * @param array{0:string,1:string} $bounds
     * @return array<array{date:string,amount_cents:int,donations_count:int}>
     *
     * @since 1.0.0
     */
    private function revenueSeriesBetween(int $campaignId, array $bounds, bool $unbounded): array
    {
        [$from, $to] = $bounds;
        $rows = $this->donations->dailyPaidBetween($from, $to, $campaignId);

        $byDate = [];
        foreach ($rows as $r) {
            $byDate[$r['day']] = [
                'amount' => $r['amount_cents'],
                'count'  => $r['donations_count'],
            ];
        }

        $series = [];
        $cursor = new \DateTimeImmutable($from);
        $endDt  = new \DateTimeImmutable($to);
        while ($cursor <= $endDt) {
            $day = $cursor->format('Y-m-d');
            $series[] = [
                'date'            => $day,
                'amount_cents'    => $byDate[$day]['amount'] ?? 0,
                'donations_count' => $byDate[$day]['count']  ?? 0,
            ];
            $cursor = $cursor->modify('+1 day');
        }
        unset($unbounded);
        return $series;
    }

    /**
     * @param array<int> $ids
     * @return array<int,Form>
     *
     * @since 1.0.0
     */
    private function formsByIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (! $ids) return [];
        $byId = [];
        foreach (Form::query()->whereIn('id', $ids)->getAll() as $f) {
            $byId[(int) $f->id] = $f;
        }
        return $byId;
    }

    /**
     * @return array<array{id:int,donor_name:string,amount_cents:int,currency:string,paid_at:?string,form_title:?string,is_anonymous:bool}>
     *
     * @since 1.0.0
     */
    public function recentDonations(int $campaignId, int $limit = 10): array
    {
        $rows = DonationQueries::live(Donation::query())
            ->whereIn('status', ['paid', 'partial_refund'])
            ->where('campaign_id', $campaignId)
            ->orderBy('paid_at', 'DESC')
            ->limit($limit)
            ->getAll();

        $donors = $this->donors->findManyByIds(array_map(static fn ($d) => (int) $d->donor_id, $rows));
        $forms  = $this->formsByIds(array_map(static fn ($d) => (int) ($d->form_id ?? 0), $rows));

        $out = [];
        foreach ($rows as $d) {
            /** @var Donation $d */
            $donor = $donors[(int) $d->donor_id] ?? null;
            $form  = $d->form_id ? ($forms[(int) $d->form_id] ?? null) : null;

            $name = $donor && ! $d->is_anonymous
                ? trim(($donor->first_name ?? '') . ' ' . ($donor->last_name ?? ''))
                : '';

            $out[] = [
                'id'           => $d->id,
                'donor_name'   => $name !== '' ? $name : __('Anonymous', 'dono-fundraising-platform'),
                'amount_cents' => (int) $d->amount_cents,
                'currency'     => (string) $d->currency,
                'paid_at'      => $d->paid_at,
                'form_title'   => $form?->title,
                'is_anonymous' => (bool) $d->is_anonymous,
            ];
        }
        return $out;
    }

    /**
     * @return array<array{donor_id:int,name:string,total_cents:int,donations_count:int}>
     *
     * @since 1.0.0
     */
    public function topDonors(int $campaignId, int $limit = 5, string $range = 'all-time'): array
    {
        [$from, $to, $isAllTime] = $this->rangeArgs($range, $campaignId);
        $tops = $this->donations->topPaidDonors(
            $isAllTime ? null : $from,
            $isAllTime ? null : $to,
            $campaignId,
            $limit,
        );
        if (! $tops) return [];

        $donors = $this->donors->findManyByIds(array_column($tops, 'donor_id'));

        $out = [];
        foreach ($tops as $row) {
            $donor = $donors[$row['donor_id']] ?? null;
            $name = $donor
                ? trim(($donor->first_name ?? '') . ' ' . ($donor->last_name ?? ''))
                : '';
            $out[] = [
                'donor_id'        => $row['donor_id'],
                'name'            => $name !== '' ? $name : __('Donor', 'dono-fundraising-platform') . ' #' . $row['donor_id'],
                'total_cents'     => $row['amount_cents'],
                'donations_count' => $row['donations_count'],
            ];
        }
        return $out;
    }

    /**
     * Donor cohort breakdown for the selected range.
     *
     * @return array{
     *   first_time:int,
     *   returning:int,
     *   conversion_pct:?float,
     *   recurring_active:int,
     *   recurring_new_in_range:int,
     *   recurring_share_pct:int
     * }
     *
     * @since 1.0.0
     */
    public function cohort(int $campaignId, string $range = 'all-time'): array
    {
        $bounds    = $this->rangeBounds($range, $campaignId);
        $unbounded = $range === 'all-time';
        $rangeStart = $bounds[0] . ' 00:00:00';

        // One SQL aggregate per donor: scales by donor count, not donation count.
        $rows = $this->donations->donorCohortRowsForCampaign(
            $campaignId,
            $unbounded ? null : $bounds[0],
            $unbounded ? null : $bounds[1],
        );

        $firstTime = 0;
        $returning = 0;
        $recurringRevenue = 0;
        $oneTimeRevenue   = 0;
        $recurringNewInRange = 0;

        foreach ($rows as $r) {
            $recurringRevenue    += $r['recurring_amount_cents'];
            $oneTimeRevenue      += $r['one_time_amount_cents'];
            $recurringNewInRange += $r['recurring_new_count'];

            if ($unbounded) {
                // All-time: "returning" = donor has ≥2 paid donations for the campaign.
                if ($r['in_range_count'] >= 2) $returning++;
                else                            $firstTime++;
            } else {
                // Bounded: "returning" = earliest paid donation for the campaign
                // is before the window started.
                if ($r['first_paid_at'] < $rangeStart) $returning++;
                else                                   $firstTime++;
            }
        }

        $conversion = $firstTime > 0 ? round(($returning / $firstTime) * 100, 1) : null;
        $totalRev   = $recurringRevenue + $oneTimeRevenue;
        $recShare   = $totalRev > 0 ? (int) round(($recurringRevenue / $totalRev) * 100) : 0;

        $recurringActive = $this->donations->countActiveRecurringForCampaign($campaignId);

        return [
            'first_time'             => $firstTime,
            'returning'              => $returning,
            'conversion_pct'         => $conversion,
            'recurring_active'       => $recurringActive,
            'recurring_new_in_range' => $recurringNewInRange,
            'recurring_share_pct'    => $recShare,
        ];
    }

    /**
     * Recent donor notes: short messages donors left at checkout, surfaced
     * for the Stories widget. Anonymous notes are kept; donor name is hidden.
     *
     * @return array<array{id:int,donor_name:string,amount_cents:int,currency:string,paid_at:?string,note:string,is_anonymous:bool}>
     *
     * @since 1.0.0
     */
    public function notes(int $campaignId, int $limit = 6): array
    {
        // Queryable doesn't have whereNotNull: fetch a generous slice ordered
        // by recency, then filter to non-empty notes in PHP and take the first N.
        $candidates = DonationQueries::live(Donation::query())
            ->whereIn('status', ['paid', 'partial_refund'])
            ->where('campaign_id', $campaignId)
            ->orderBy('paid_at', 'DESC')
            ->limit(max($limit * 6, 30))
            ->getAll();

        $rows = [];
        foreach ($candidates as $c) {
            $note = trim((string) ($c->note_to_org ?? ''));
            if ($note === '') continue;
            $rows[] = $c;
            if (count($rows) >= $limit) break;
        }

        $donors = $this->donors->findManyByIds(array_map(static fn ($d) => (int) $d->donor_id, $rows));

        $out = [];
        foreach ($rows as $d) {
            $donor = $donors[(int) $d->donor_id] ?? null;
            $name = $donor && ! $d->is_anonymous
                ? trim(($donor->first_name ?? '') . ' ' . ($donor->last_name ?? ''))
                : '';
            $out[] = [
                'id'           => (int) $d->id,
                'donor_name'   => $name !== '' ? $name : __('A donor', 'dono-fundraising-platform'),
                'amount_cents' => (int) $d->amount_cents,
                'currency'     => (string) $d->currency,
                'paid_at'      => $d->paid_at,
                'note'         => (string) $d->note_to_org,
                'is_anonymous' => (bool) $d->is_anonymous,
            ];
        }
        return $out;
    }

    /**
     * Donation-size distribution across fixed, currency-agnostic cent buckets;
     * the front-end formats labels.
     *
     * @return array{buckets:array<array{min_cents:int,max_cents:?int,count:int,amount_cents:int}>,median_cents:int,total_count:int}
     *
     * @since 1.0.0
     */
    public function distributionBuckets(int $campaignId, string $range = 'all-time'): array
    {
        [$from, $to, $isAllTime] = $this->rangeArgs($range, $campaignId);

        $thresholds = [1000, 2500, 5000, 10000, 25000, 50000];
        $rows = $this->donations->amountHistogramBuckets(
            $thresholds,
            $isAllTime ? null : $from,
            $isAllTime ? null : $to,
            $campaignId,
        );

        // Re-shape from the repo's threshold-keyed shape into the
        // {min_cents, max_cents, count, amount_cents} buckets the UI expects.
        $defs = [
            [1,         1000],
            [1001,      2500],
            [2501,      5000],
            [5001,      10000],
            [10001,     25000],
            [25001,     50000],
            [50001,     null],
        ];
        $byThreshold = [];
        foreach ($rows as $r) {
            $byThreshold[$r['threshold'] ?? 'overflow'] = $r;
        }

        $buckets = [];
        $totalCount = 0;
        foreach ($defs as $d) {
            $key = $d[1] === null ? 'overflow' : $d[1];
            $row = $byThreshold[$key] ?? ['donations_count' => 0, 'amount_cents' => 0];
            $count = $row['donations_count'];
            $totalCount += $count;
            $buckets[] = [
                'min_cents'    => $d[0],
                'max_cents'    => $d[1],
                'count'        => $count,
                'amount_cents' => $row['amount_cents'],
            ];
        }

        $median = $this->donations->medianPaidAmount(
            $isAllTime ? null : $from,
            $isAllTime ? null : $to,
            $campaignId,
            $totalCount,
        );

        return [
            'buckets'      => $buckets,
            'median_cents' => $median,
            'total_count'  => $totalCount,
        ];
    }

    /**
     * 7×24 day-of-week × hour-of-day donation-count grid. Days are 0=Mon..6=Sun
     * (European ordering). Hours are 0..23.
     *
     * @return array{ grid: int[][], max: int, total: int }
     *
     * @since 1.0.0
     */
    public function dowHourGrid(int $campaignId, string $range = 'all-time'): array
    {
        [$from, $to, $isAllTime] = $this->rangeArgs($range, $campaignId);
        $cells = $this->donations->dowHourGridForPaid(
            $isAllTime ? null : $from,
            $isAllTime ? null : $to,
            $campaignId,
        );

        $grid = array_fill(0, 7, array_fill(0, 24, 0));
        $max  = 0;
        $total = 0;

        foreach ($cells as $c) {
            $grid[$c['dow']][$c['hour']] += $c['donations_count'];
            $total += $c['donations_count'];
            if ($grid[$c['dow']][$c['hour']] > $max) {
                $max = $grid[$c['dow']][$c['hour']];
            }
        }

        return ['grid' => $grid, 'max' => $max, 'total' => $total];
    }

    /**
     * Channel attribution: maps source_attribution.utm_* into canonical channel
     * buckets; raw values stay on the donation row.
     *
     * @return array<array{channel:string,amount_cents:int,donations_count:int}>
     *
     * @since 1.0.0
     */
    public function byChannel(int $campaignId, string $range = 'all-time'): array
    {
        [$from, $to, $isAllTime] = $this->rangeArgs($range, $campaignId);
        $tuples = $this->donations->aggregatePaidByAttribution(
            $isAllTime ? null : $from,
            $isAllTime ? null : $to,
            $campaignId,
        );

        $byChannel = [];
        foreach ($tuples as $t) {
            $attr = [];
            if ($t['utm_source'] !== null) $attr['utm_source'] = $t['utm_source'];
            if ($t['utm_medium'] !== null) $attr['utm_medium'] = $t['utm_medium'];
            $key = ChannelClassifier::classify($attr);

            if (! isset($byChannel[$key])) {
                $byChannel[$key] = ['amount' => 0, 'count' => 0];
            }
            $byChannel[$key]['amount'] += $t['amount_cents'];
            $byChannel[$key]['count']  += $t['donations_count'];
        }

        uasort($byChannel, fn ($a, $b) => $b['amount'] <=> $a['amount']);

        $out = [];
        foreach ($byChannel as $channel => $stats) {
            $out[] = [
                'channel'         => $channel,
                'amount_cents'    => $stats['amount'],
                'donations_count' => $stats['count'],
            ];
        }
        return $out;
    }

    /**
     * @return array{kind:'remaining'|'ended'|'running',days:int,total_days:?int}
     *
     * @since 1.0.0
     */
    public function timeline(Campaign $c): array
    {
        $now = $this->clock->now();
        $start = $c->starts_at ? new \DateTimeImmutable($c->starts_at) : new \DateTimeImmutable($c->created_at);

        if ($c->ends_at) {
            $end = new \DateTimeImmutable($c->ends_at);
            $totalDays = max(1, (int) $start->diff($end)->format('%a'));
            if ($end < $now) {
                return ['kind' => 'ended', 'days' => (int) $end->diff($now)->format('%a'), 'total_days' => $totalDays];
            }
            return ['kind' => 'remaining', 'days' => (int) $now->diff($end)->format('%a'), 'total_days' => $totalDays];
        }
        return ['kind' => 'running', 'days' => (int) $start->diff($now)->format('%a'), 'total_days' => null];
    }

    /**
     * @return array{0:string,1:string,2:bool} (from, to, isAllTime)
     *
     * @since 1.0.0
     */
    private function rangeArgs(string $range, int $campaignId): array
    {
        $bounds = $this->rangeBounds($range, $campaignId);
        return [$bounds[0], $bounds[1], $range === 'all-time'];
    }

    /**
     * Previous-window bounds for a comparison.
     *
     * Mode 'period': the same-length window immediately before the current one.
     * Mode 'year':   the same window shifted back exactly one calendar year.
     *
     * @since 1.0.0
     */
    private function previousRangeBounds(string $range, int $campaignId, string $mode = 'period'): array
    {
        $today = $this->localNow();

        if ($mode === 'year') {
            // Shift both ends of the current range back by one year.
            [$from, $to] = $this->rangeBounds($range, $campaignId);
            $fromDt = new \DateTimeImmutable($from);
            $toDt   = new \DateTimeImmutable($to);
            return [
                $fromDt->modify('-1 year')->format('Y-m-d'),
                $toDt->modify('-1 year')->format('Y-m-d'),
            ];
        }

        return match ($range) {
            'today'   => [$today->modify('-1 day')->format('Y-m-d'),  $today->modify('-1 day')->format('Y-m-d')],
            'last-7'  => [$this->daysAgo(13), $this->daysAgo(7)],
            'last-30' => [$this->daysAgo(59), $this->daysAgo(30)],
            'last-90' => [$this->daysAgo(179), $this->daysAgo(90)],
            default   => [$this->daysAgo(30), $this->daysAgo(1)],
        };
    }

    /**
     * @return array{0:string,1:string} [from, to] in Y-m-d
     *
     * @since 1.0.0
     */
    private function rangeBounds(string $range, int $campaignId): array
    {
        $today = $this->localNow()->format('Y-m-d');
        return match ($range) {
            'today'    => [$today, $today],
            'last-7'   => [$this->daysAgo(6),  $today],
            'last-30'  => [$this->daysAgo(29), $today],
            'last-90'  => [$this->daysAgo(89), $today],
            default    => [$this->campaignStartDate($campaignId), $today],
        };
    }

    /** @since 1.0.0 */
    private function daysAgo(int $n): string
    {
        return $this->localNow()->modify("-{$n} days")->format('Y-m-d');
    }

    /**
     * Ranges are named in the org's days ("today", "last 7"), so they are
     * resolved in the org's timezone and the repository converts them back to
     * the UTC instants paid_at is stored in.
     *
     * @since 1.0.0
     */
    private function localNow(): DateTimeImmutable
    {
        return $this->clock->now()->setTimezone(DonationQueries::siteTimezone());
    }

    /**
     * Every widget resolves its own range, and on the default range they all
     * land here, so the campaign row and the earliest-donation scan are read
     * once instead of once per widget. The container hands out one instance per
     * request, so this memo lives exactly as long as the request that filled it.
     *
     * @var array<int,string>
     */
    private array $startDates = [];

    /** @since 1.0.0 */
    private function campaignStartDate(int $campaignId): string
    {
        if (isset($this->startDates[$campaignId])) {
            return $this->startDates[$campaignId];
        }

        return $this->startDates[$campaignId] = $this->resolveCampaignStartDate($campaignId);
    }

    /** @since 1.0.0 */
    private function resolveCampaignStartDate(int $campaignId): string
    {
        $c = Campaign::query()->find('id', $campaignId);
        $start = $c ? substr((string) ($c->starts_at ?? $c->created_at), 0, 10) : $this->daysAgo(30);

        // Donations can predate the campaign's start date (a start set after
        // early gifts, imports, backfills), so anchor the all-time series at the
        // earliest paid donation when it is older; otherwise the chart cuts off
        // that revenue and reads as a flat zero line.
        $firstPaid = $this->donations->firstPaidDate($campaignId);
        return ($firstPaid !== null && $firstPaid < $start) ? $firstPaid : $start;
    }
}
