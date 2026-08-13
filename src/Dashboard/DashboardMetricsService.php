<?php

declare(strict_types=1);

namespace Dono\Dashboard;

use DateTimeImmutable;
use Dono\Campaigns\Campaign;
use Dono\Donations\ChannelClassifier;
use Dono\Donations\Donation;
use Dono\Donations\DonationQueries;
use Dono\Donations\DonationRepository;
use Dono\Donations\Refund;
use Dono\Donors\Donor;
use Dono\Foundation\Helpers\Money;
use Dono\Foundation\Time\Clock;
use Dono\Recurring\RecurringPlan;
use Dono\Recurring\RecurringPlanRepository;
use Dono\Vendor\Queryable\DB;

/**
 * Aggregates metrics across all campaigns for the platform dashboard.
 *
 * @since 1.0.0
 */
final class DashboardMetricsService
{
    /** @since 1.0.0 */
    public function __construct(
        private Clock $clock,
        private DonationRepository $donations,
        private RecurringPlanRepository $recurringPlans,
    ) {
    }

    /**
     * @return array<string,mixed>
     * @since 1.0.0
     */
    public function kpi(string $range = 'last-30', string $compare = 'none', bool $includeTest = false): array
    {
        $bounds = $this->rangeBounds($range, $includeTest);
        $current = $this->aggregateInSql($bounds, $range === 'all-time', $includeTest);

        if ($range === 'all-time' || $compare === 'none') {
            return $current + ['comparison' => null];
        }

        $prev = $this->previousRangeBounds($range, $compare);
        $previous = $this->aggregateInSql($prev, false, $includeTest);

        $changes = [];
        foreach (['amount_raised_cents', 'donations_count', 'donors_count', 'avg_donation_cents'] as $key) {
            $changes[$key] = $this->percentChange($previous[$key], $current[$key]);
        }

        return $current + ['comparison' => [
            'mode'           => $compare,
            'previous'       => $previous,
            'change_percent' => $changes,
            'from'           => $prev[0],
            'to'             => $prev[1],
        ]];
    }

    /**
     * Daily revenue series across all campaigns. Zero-fills missing dates.
     * Optionally returns the equivalent previous-period series alongside.
     *
     * @return array{
     *   series:array<array{date:string,amount_cents:int,donations_count:int}>,
     *   previous_series:?array<array{date:string,amount_cents:int,donations_count:int}>
     * }
     * @since 1.0.0
     */
    public function revenueSeries(string $range = 'last-30', string $compare = 'none', bool $includeTest = false): array
    {
        $bounds = $this->rangeBounds($range, $includeTest);
        $series = $this->seriesBetween($bounds, $range === 'all-time', $includeTest);

        $previous = null;
        if ($range !== 'all-time' && $compare !== 'none') {
            $previous = $this->seriesBetween(
                $this->previousRangeBounds($range, $compare),
                false,
                $includeTest
            );
        }

        return ['series' => $series, 'previous_series' => $previous];
    }

    /**
     * Last-24-hour activity summary for the live ribbon at the top.
     *
     * @return array{donations_count:int,amount_raised_cents:int,refunds_count:int,notes_count:int,currency:string}
     * @since 1.0.0
     */
    public function today(bool $includeTest = false): array
    {
        $since = $this->clock->now()->modify('-24 hours')->format('Y-m-d H:i:s');

        $rows = DonationQueries::donationRows(Donation::query(), $includeTest)
            ->whereIn('status', ['paid', 'partial_refund'])
            ->where('paid_at', $since, '>=')
            ->getAll();

        $amount = 0;
        $notes  = 0;
        // Single org reporting currency; sum base amounts so mixed-currency
        // donations stay coherent. A foreign donation with no FX rate has a NULL
        // base and no known base value, so it (and its refunds) contribute 0 -
        // never fold its raw foreign cents into the base total.
        $fxByDonation = [];
        $currency = Money::defaultCurrency();
        foreach ($rows as $d) {
            $amount += (int) ($d->base_amount_cents ?? 0);
            $fxByDonation[(int) $d->id] = $d->base_amount_cents !== null ? (float) $d->fx_rate : 0.0;
            if (trim((string) ($d->note_to_org ?? '')) !== '') $notes++;
        }
        if ($fxByDonation !== []) {
            // A foreign-currency refund must be scaled by its donation's fx
            // rate before it can be subtracted from the base-currency total -
            // subtracting raw amount_cents would mix currencies.
            $refunds = Refund::query()
                ->whereIn('donation_id', array_keys($fxByDonation))
                ->where('status', 'succeeded')
                ->getAll();
            foreach ($refunds as $r) {
                $rate = $fxByDonation[(int) $r->donation_id] ?? 1.0;
                $amount -= (int) round(((int) $r->amount_cents) * $rate);
            }
        }

        // Both full and partial refunds stamp refunded_at; counting only
        // 'refunded' misses partial refunds issued in the window.
        $refunds = DonationQueries::donationRows(Donation::query(), $includeTest)
            ->whereIn('status', ['refunded', 'partial_refund'])
            ->where('refunded_at', $since, '>=')
            ->count();

        return [
            'donations_count'     => count($rows),
            'amount_raised_cents' => $amount,
            'refunds_count'       => $refunds,
            'notes_count'         => $notes,
            'currency'            => $currency,
        ];
    }

    /**
     * Recent donations across all campaigns.
     *
     * @return array<array{
     *   id:int, donor_name:string, amount_cents:int, currency:string,
     *   paid_at:?string, campaign_id:?int, campaign_title:?string,
     *   is_anonymous:bool, frequency:string
     * }>
     * @since 1.0.0
     */
    public function recentActivity(int $limit = 8, bool $includeTest = false): array
    {
        $rows = DonationQueries::donationRows(Donation::query(), $includeTest)
            ->whereIn('status', ['paid', 'partial_refund'])
            ->orderBy('paid_at', 'DESC')
            ->limit($limit)
            ->getAll();

        // Batch donor + campaign lookups so we hit each table once per render
        // instead of once per row.
        $donorIds    = array_values(array_unique(array_filter(array_map(static fn ($d) => (int) $d->donor_id,    $rows))));
        $campaignIds = array_values(array_unique(array_filter(array_map(static fn ($d) => (int) $d->campaign_id, $rows))));
        $donorsById    = [];
        if ($donorIds !== []) {
            foreach (Donor::query()->whereIn('id', $donorIds)->getAll() as $don) {
                $donorsById[(int) $don->id] = $don;
            }
        }
        $campaignsById = [];
        if ($campaignIds !== []) {
            foreach (Campaign::query()->whereIn('id', $campaignIds)->getAll() as $c) {
                $campaignsById[(int) $c->id] = $c;
            }
        }

        $out = [];
        foreach ($rows as $d) {
            $donor    = $donorsById[(int) $d->donor_id] ?? null;
            $name     = $donor && ! $d->is_anonymous
                ? trim(($donor->first_name ?? '') . ' ' . ($donor->last_name ?? ''))
                : '';
            $campaign = $d->campaign_id ? ($campaignsById[(int) $d->campaign_id] ?? null) : null;

            $out[] = [
                'id'             => (int) $d->id,
                'donor_name'     => $name !== '' ? $name : __('Anonymous', 'dono-fundraising-platform'),
                'amount_cents'   => (int) $d->amount_cents,
                'currency'       => (string) $d->currency,
                'paid_at'        => $d->paid_at,
                'campaign_id'    => $d->campaign_id ? (int) $d->campaign_id : null,
                'campaign_title' => $campaign?->title,
                'is_anonymous'   => (bool) $d->is_anonymous,
                // The row has to be able to say it is a rehearsal once the
                // toggle lets it through.
                'is_test'        => (bool) $d->is_test,
                'frequency'      => (string) $d->frequency,
            ];
        }
        return $out;
    }

    /**
     * Daily revenue series. Aggregation runs in SQL (GROUP BY DATE) inside
     * DonationRepository to stay memory-bounded at all-time scale. We then
     * zero-fill missing days against the cursor range so the chart's x-axis
     * is always continuous.
     *
     * @param array{0:string,1:string} $bounds
     * @since 1.0.0
     */
    private function seriesBetween(array $bounds, bool $unbounded, bool $includeTest = false): array
    {
        $rows = $this->donations->dailyPaidBetween($bounds[0], $bounds[1], null, $includeTest);

        $byDate = [];
        foreach ($rows as $r) {
            $byDate[$r['day']] = [
                'amount' => $r['amount_cents'],
                'count'  => $r['donations_count'],
            ];
        }

        $start = new DateTimeImmutable($bounds[0]);
        $endDt = new DateTimeImmutable($bounds[1]);

        // All-time runs from the earliest donation to today, which can span
        // years - one point per calendar day would emit thousands of points
        // (times the per-campaign sparklines). Past ~a year, down-bucket the
        // daily totals into months so the series stays bounded as the org ages.
        if ($unbounded && (int) $start->diff($endDt)->days > 366) {
            return $this->monthlySeries($byDate, $start, $endDt);
        }

        $series = [];
        $cursor = $start;
        while ($cursor <= $endDt) {
            $day = $cursor->format('Y-m-d');
            $series[] = [
                'date'            => $day,
                'amount_cents'    => $byDate[$day]['amount'] ?? 0,
                'donations_count' => $byDate[$day]['count']  ?? 0,
            ];
            $cursor = $cursor->modify('+1 day');
        }
        return $series;
    }

    /**
     * Down-bucketed monthly series for long all-time spans: sum the daily
     * totals into their YYYY-MM bucket and zero-fill missing months. Each point
     * is dated to the first of its month.
     *
     * @param array<string,array{amount:int,count:int}> $byDate
     * @return array<array{date:string,amount_cents:int,donations_count:int}>
     * @since 1.0.0
     */
    private function monthlySeries(array $byDate, DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        $months = [];
        foreach ($byDate as $day => $vals) {
            $key = substr((string) $day, 0, 7);
            if (! isset($months[$key])) $months[$key] = ['amount' => 0, 'count' => 0];
            $months[$key]['amount'] += $vals['amount'];
            $months[$key]['count']  += $vals['count'];
        }

        $series   = [];
        $cursor   = $start->modify('first day of this month');
        $endMonth = $end->modify('first day of this month');
        while ($cursor <= $endMonth) {
            $key = $cursor->format('Y-m');
            $series[] = [
                'date'            => $cursor->format('Y-m-d'),
                'amount_cents'    => $months[$key]['amount'] ?? 0,
                'donations_count' => $months[$key]['count']  ?? 0,
            ];
            $cursor = $cursor->modify('+1 month');
        }
        return $series;
    }

    /**
     * Operational queue: things in the system that probably need a decision
     * today. Each item carries a tone and an optional admin URL to act on it.
     *
     * @return array<array{
     *   key:string,
     *   tone:'info'|'warn'|'error',
     *   title:string,
     *   action_label?:string,
     *   action_href?:string,
     *   count?:int
     * }>
     * @since 1.0.0
     */
    public function attention(bool $includeTest = false): array
    {
        $items = [];

        $since24h = $this->clock->now()->modify('-24 hours')->format('Y-m-d H:i:s');
        $since7d  = $this->clock->now()->modify('-7 days')->format('Y-m-d H:i:s');

        // 1. Failed donations in last 24h.
        $failed = (int) DonationQueries::donationRows(Donation::query(), false)
            ->where('status', 'failed')
            ->where('updated_at', $since24h, '>=')
            ->count();
        if ($failed > 0) {
            $items[] = [
                'key'   => 'failed-donations',
                'tone'  => 'error',
                'title' => sprintf(
                    /* translators: %d: failed donations count */
                    _n('%d donation failed in the last 24 hours.', '%d donations failed in the last 24 hours.', $failed, 'dono-fundraising-platform'),
                    $failed
                ),
                'action_label' => __('Review', 'dono-fundraising-platform'),
                'action_href'  => admin_url('admin.php?page=dono-donations&status=failed'),
                'count'        => $failed,
            ];
        }

        // Test failures get their own item and ignore the toggle. A gateway
        // misconfigured during a rehearsal is exactly what a rehearsal is for
        // finding, and it would go unseen behind a switch that is off by
        // default. Separate key because dismissal is keyed: one must not
        // silence the other, and the live count has to stay quotable.
        $failedTest = (int) Donation::query()
            ->where('is_test', 1)
            ->where('kind', 'donation')
            ->where('status', 'failed')
            ->where('updated_at', $since24h, '>=')
            ->count();
        if ($failedTest > 0) {
            $items[] = [
                'key'   => 'failed-test-donations',
                'tone'  => 'error',
                'title' => sprintf(
                    /* translators: %d: failed test donations count */
                    _n('%d test donation failed in the last 24 hours.', '%d test donations failed in the last 24 hours.', $failedTest, 'dono-fundraising-platform'),
                    $failedTest
                ),
                'action_label' => __('Review', 'dono-fundraising-platform'),
                // The live link hides test rows, so it would land on an empty
                // screen.
                'action_href'  => admin_url('admin.php?page=dono-donations&status=failed&include_test=1'),
                'count'        => $failedTest,
            ];
        }

        // 2. Campaigns ending within 7 days. ends_at is a datetime, so bound on
        // the full second range: from now (not midnight, else campaigns that
        // already ended earlier today still match) to end-of-day 7 days out (not
        // 00:00:00, else campaigns ending later on the 7th day are missed).
        $now     = $this->clock->now()->format('Y-m-d H:i:s');
        $soonEnd = $this->clock->now()->modify('+7 days')->format('Y-m-d 23:59:59');
        $ending  = Campaign::query()
            ->where('status', 'published')
            ->where('ends_at', $now, '>=')
            ->where('ends_at', $soonEnd, '<=')
            ->getAll();
        foreach ($ending as $c) {
            $daysLeft = max(0, (int) (new DateTimeImmutable($c->ends_at))->diff($this->clock->now())->format('%a'));
            $items[] = [
                'key'   => 'ending-' . $c->id,
                'tone'  => 'warn',
                'title' => sprintf(
                    /* translators: 1: campaign title, 2: days remaining */
                    _n('"%1$s" ends in %2$d day.', '"%1$s" ends in %2$d days.', $daysLeft, 'dono-fundraising-platform'),
                    $c->title,
                    $daysLeft
                ),
                'action_label' => __('Open', 'dono-fundraising-platform'),
                'action_href'  => admin_url('admin.php?page=dono-campaigns&view=detail&id=' . $c->id . '&tab=overview'),
            ];
        }

        // 3. Published campaigns missing a default form. default_form_id is
        // nullable and update() clears it to NULL (not 0), and NULL <= 0 is NULL
        // in SQL, so the cleared-form case needs an explicit IS NULL.
        $missingForm = Campaign::query()
            ->where('status', 'published')
            ->where(function ($q) {
                $q->whereIsNull('default_form_id')->orWhere('default_form_id', 0, '<=');
            })
            ->getAll();
        foreach ($missingForm as $c) {
            $items[] = [
                'key'   => 'no-form-' . $c->id,
                'tone'  => 'warn',
                'title' => sprintf(
                    /* translators: %s: campaign title */
                    __('"%s" has no default form. The donate button on its page does nothing.', 'dono-fundraising-platform'),
                    $c->title
                ),
                'action_label' => __('Set form', 'dono-fundraising-platform'),
                'action_href'  => admin_url('admin.php?page=dono-campaigns&view=detail&id=' . $c->id . '&tab=settings'),
            ];
        }

        // 4. Recent donor notes worth a reply (last 7 days, non-empty note).
        // Counted in SQL over the whole window. The title counts distinct
        // donors, so one donor leaving three notes is one donor.
        // whereRaw emits no AND connector, so it has to open the chain.
        $noteRows = DonationQueries::donationRows(
            DB::table('dono_donations')->whereRaw("TRIM(COALESCE(note_to_org, '')) <> ''"),
            $includeTest
        )
            ->whereIn('status', ['paid', 'partial_refund'])
            ->where('paid_at', $since7d, '>=')
            ->selectRaw('COUNT(*) AS notes, COUNT(DISTINCT donor_id) AS donors')
            ->get();

        $noteCount  = (int) ($noteRows['notes'] ?? 0);
        $donorCount = (int) ($noteRows['donors'] ?? 0);

        // Resolved only for the link target: one donor means their profile,
        // several mean the donor list.
        $noteDonors = [];
        if ($noteCount > 0 && $donorCount === 1) {
            $one = DonationQueries::donationRows(
                DB::table('dono_donations')->whereRaw("TRIM(COALESCE(note_to_org, '')) <> ''"),
                $includeTest
            )
                ->whereIn('status', ['paid', 'partial_refund'])
                ->where('paid_at', $since7d, '>=')
                ->selectRaw('MIN(donor_id) AS donor_id')
                ->get();
            $id = (int) ($one['donor_id'] ?? 0);
            if ($id > 0) $noteDonors[$id] = true;
        }
        if ($noteCount > 0) {
            // The note is the donor's, so open their profile when it points at one
            // person: their timeline shows the note in context. Several donors
            // have no single profile, so fall back to the donor list rather than
            // the donations ledger, which is where you read amounts, not messages.
            $href = count($noteDonors) === 1
                ? admin_url('admin.php?page=dono-donors#donor/' . array_key_first($noteDonors))
                : admin_url('admin.php?page=dono-donors');
            $items[] = [
                'key'   => 'donor-notes',
                'tone'  => 'info',
                'title' => sprintf(
                    /* translators: %d: number of donors who left a note */
                    _n(
                        '%d donor left a note in the last 7 days.',
                        '%d donors left notes in the last 7 days.',
                        $donorCount,
                        'dono-fundraising-platform'
                    ),
                    $donorCount
                ),
                'action_label' => __('Read', 'dono-fundraising-platform'),
                'action_href'  => $href,
                'count'        => $noteCount,
            ];
        }

        // 5. Empty state: no published campaigns at all.
        $published = (int) Campaign::query()->where('status', 'published')->count();
        if ($published === 0) {
            $items[] = [
                'key'          => 'no-campaigns',
                'tone'         => 'info',
                'title'        => __('No published campaigns yet. Start one to begin collecting donations.', 'dono-fundraising-platform'),
                'action_label' => __('Create campaign', 'dono-fundraising-platform'),
                'action_href'  => admin_url('admin.php?page=dono-campaigns'),
            ];
        }

        $toneOrder = ['error' => 0, 'warn' => 1, 'info' => 2];
        usort($items, fn ($a, $b) => $toneOrder[$a['tone']] <=> $toneOrder[$b['tone']]);

        // The signature travels with the item so the client can hand it back on
        // dismiss, and the dismissal lapses as soon as the state moves on.
        foreach ($items as $i => $item) {
            $items[$i]['signature'] = AttentionDismissals::signatureFor($item);
        }

        return (new AttentionDismissals())->filter($items, get_current_user_id());
    }

    /**
     * Channel attribution across all paid donations in range. Aggregation is
     * SQL-side (GROUP BY on JSON-extracted UTM tuples); the resulting handful
     * of rows are classified in PHP and summed into channel buckets.
     *
     * @return array<array{channel:string,amount_cents:int,donations_count:int}>
     * @since 1.0.0
     */
    public function byChannel(string $range = 'last-30', bool $includeTest = false): array
    {
        $bounds = $this->rangeBounds($range, $includeTest);
        $unbounded = $range === 'all-time';

        $tuples = $this->donations->aggregatePaidByAttribution(
            $unbounded ? null : $bounds[0],
            $unbounded ? null : $bounds[1],
            null,
            $includeTest,
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
     * Top campaigns by amount raised in range, with a per-row sparkline series.
     *
     * @return array<array<string,mixed>>
     * @since 1.0.0
     */
    public function topCampaigns(string $range = 'last-30', int $limit = 5, bool $includeTest = false): array
    {
        $bounds    = $this->rangeBounds($range, $includeTest);
        $unbounded = $range === 'all-time';

        $tops = $this->donations->topPaidCampaigns(
            $unbounded ? null : $bounds[0],
            $unbounded ? null : $bounds[1],
            $limit,
            $includeTest,
        );
        if (! $tops) return [];

        // Batch the per-campaign lookups + the sparkline series so we only
        // hit the campaigns table + the donations table once each.
        $campaignIds = array_values(array_filter(array_map(static fn ($r) => (int) $r['campaign_id'], $tops)));
        $campaignsById = [];
        foreach (Campaign::query()->whereIn('id', $campaignIds)->getAll() as $c) {
            $campaignsById[(int) $c->id] = $c;
        }
        $seriesByCampaign = $this->donations->dailyPaidByCampaignsBetween(
            $campaignIds,
            $bounds[0],
            $bounds[1],
            $includeTest,
        );

        $cursorStart = new DateTimeImmutable($bounds[0]);
        $cursorEnd   = new DateTimeImmutable($bounds[1]);

        $out = [];
        foreach ($tops as $row) {
            $campaign = $campaignsById[(int) $row['campaign_id']] ?? null;
            if (! $campaign) continue;

            $byDay = $seriesByCampaign[(int) $row['campaign_id']] ?? [];

            $sparkline = [];
            $cursor = $cursorStart;
            while ($cursor <= $cursorEnd) {
                $day = $cursor->format('Y-m-d');
                $sparkline[] = ['date' => $day, 'amount_cents' => $byDay[$day] ?? 0];
                $cursor = $cursor->modify('+1 day');
            }

            $out[] = [
                'id'              => (int) $campaign->id,
                'title'           => (string) $campaign->title,
                // Raised is summed in the org base currency, so label it that way
                // (a per-campaign currency would mis-symbol a base-currency value).
                'currency'        => Money::defaultCurrency(),
                'amount_cents'    => $row['amount_cents'],
                'donations_count' => $row['donations_count'],
                'sparkline'       => $sparkline,
            ];
        }
        return $out;
    }

    /**
     * Active recurring plans + simple monthly recurring revenue estimate.
     * MRR = sum of each active plan's normalized monthly amount, where
     * "active" means a paid donation in the last 60 days under that plan.
     *
     * @return array{
     *   active_plans:int, mrr_cents:int, projected_30d_cents:int,
     *   new_this_month:int, currency:string
     * }
     * @since 1.0.0
     */
    public function recurring(bool $includeTest = false): array
    {
        // Single SQL roll-up over dono_recurring_plans: monthly-normalized
        // amounts, bounded memory, currency-correct via the base column.
        $stats = $this->recurringPlans->recurringStats($this->clock->now()->format('Y-m-d'), $includeTest);
        $currency = strtoupper(Money::defaultCurrency());

        return [
            'active_plans'        => (int) $stats['active_count'],
            'mrr_cents'           => (int) $stats['mrr_cents'],
            'projected_30d_cents' => (int) $stats['mrr_cents'],
            'new_this_month'      => (int) $stats['new_this_month'],
            'currency'            => $currency,
        ];
    }

    /**
     * How much is being held back while the toggle is off.
     *
     * A dashboard that shows zero without saying why is indistinguishable from
     * a broken one, which is the whole complaint this answers.
     *
     * @return array{donations:int,plans:int}
     *
     * @since 1.0.0
     */
    public function hiddenTestCount(): array
    {
        $donations = (int) Donation::query()
            ->where('is_test', 1)
            ->where('kind', 'donation')
            ->whereIn('status', ['paid', 'partial_refund'])
            ->count();

        $plans = (int) RecurringPlan::query()->where('is_test', 1)->count();

        return ['donations' => $donations, 'plans' => $plans];
    }

    /**
     * Published campaigns ordered by raised, newest tiebreaker.
     *
     * @return array<int, array<string,mixed>>
     * @since 1.0.0
     */
    public function activeCampaigns(int $limit = 6, bool $includeTest = false): array
    {
        $rows = Campaign::query()
            ->where('status', 'published')
            ->orderBy('raised_cents', 'DESC')
            ->limit($limit)
            ->getAll();
        if ($rows === []) return [];

        // Batch the "last donation paid_at" per campaign in a single grouped
        // query so the widget does not make N round-trips per render.
        $campaignIds = array_map(static fn ($c) => (int) $c->id, $rows);
        $lastByCampaign = [];
        $lastRows = DonationQueries::donationRows(
            DB::table('dono_donations')
                ->whereIn('status', ['paid', 'partial_refund'])
                ->whereIn('campaign_id', $campaignIds),
            $includeTest
        )
            ->selectRaw('campaign_id, MAX(paid_at) AS last_paid')
            ->groupBy('campaign_id')
            ->getAll();
        foreach ($lastRows as $r) {
            $lastByCampaign[(int) ($r['campaign_id'] ?? 0)] = $r['last_paid'] ?? null;
        }

        // The stored rollup is live-only and is read by the public progress bar,
        // the campaign grid and the bindings, so it is never widened. When the
        // operator asks to see test data the figures are recomputed here, at
        // read time, and overridden in the emitted rows only.
        $testTotals = [];
        if ($includeTest) {
            $totalRows = DonationQueries::donationRows(
                DB::table('dono_donations')
                    ->whereIn('status', ['paid', 'partial_refund'])
                    ->whereIn('campaign_id', $campaignIds),
                true
            )
                ->selectRaw(
                    'campaign_id, COALESCE(SUM(' . DonationQueries::netBaseExpr() . '),0) AS raised, '
                    . 'COUNT(*) AS cnt, COUNT(DISTINCT donor_id) AS donors'
                )
                ->groupBy('campaign_id')
                ->getAll();
            foreach ($totalRows as $r) {
                $testTotals[(int) ($r['campaign_id'] ?? 0)] = $r;
            }
        }

        $out = [];
        foreach ($rows as $c) {
            $totals = $testTotals[(int) $c->id] ?? null;
            $out[] = [
                'id'                 => (int) $c->id,
                'title'              => (string) $c->title,
                'slug'               => (string) $c->slug,
                'status'             => (string) $c->status,
                'currency'           => Money::defaultCurrency(),
                'goal_type'          => $c->goal_type ?: 'amount',
                'goal_cents'         => $c->goal_cents,
                'goal_count'         => $c->goal_count,
                'raised_cents'       => (int) ($totals['raised'] ?? $c->raised_cents),
                'donations_count'    => (int) ($totals['cnt']    ?? $c->donations_count),
                'donors_count'       => (int) ($totals['donors'] ?? $c->donors_count),
                'last_donation_at'   => $lastByCampaign[(int) $c->id] ?? null,
            ];
        }

        // Row selection stayed on the indexed stored column, so the recomputed
        // figures have to be re-ordered here.
        if ($includeTest) {
            usort($out, static fn (array $a, array $b): int => $b['raised_cents'] <=> $a['raised_cents']);
        }

        return $out;
    }

    /**
     * Aggregate paid donations into the dashboard KPI shape. Delegates to
     * DonationRepository so the heavy lifting (SUM, COUNT, COUNT DISTINCT)
     * runs in SQL and never hydrates rows into PHP.
     *
     * @param array{0:string,1:string} $bounds
     * @since 1.0.0
     */
    private function aggregateInSql(array $bounds, bool $unbounded, bool $includeTest = false): array
    {
        $from = $unbounded ? null : $bounds[0];
        $to   = $unbounded ? null : $bounds[1];

        $agg = $this->donations->aggregatePaidBetween($from, $to, null, $includeTest);
        $count = $agg['donations_count'];
        $amount = $agg['amount_cents'];

        // Totals come back already converted to base currency via
        // COALESCE(base_amount_cents, 0), so the KPI label must be the base
        // currency, not the most-common per-donation currency.
        $currency = strtoupper( Money::defaultCurrency());

        return [
            'amount_raised_cents' => $amount,
            'donations_count'     => $count,
            'donors_count'        => $agg['donors_count'],
            'avg_donation_cents'  => $count > 0 ? (int) round($amount / $count) : 0,
            'currency'            => $currency,
        ];
    }

    /** @since 1.0.0 */
    private function percentChange(int $previous, int $current): ?float
    {
        if ($previous === 0 && $current === 0) return null;
        if ($previous === 0) return 100.0;
        return round((($current - $previous) / $previous) * 100, 1);
    }

    /**
     * @return array{0:string,1:string}
     *
     * `all-time` uses the earliest paid donation's date as the lower bound so
     * the chart can show daily series from the very beginning, not just the
     * last 365 days. Falls back to 365 days when there are no donations yet.
     *
     * @since 1.0.0
     */
    private function rangeBounds(string $range, bool $includeTest = false): array
    {
        $today = $this->localNow()->format('Y-m-d');
        return match ($range) {
            'today'    => [$today, $today],
            'last-7'   => [$this->daysAgo(6),  $today],
            'last-30'  => [$this->daysAgo(29), $today],
            'last-90'  => [$this->daysAgo(89), $today],
            'all-time' => [$this->earliestPaidDate($includeTest) ?? $this->daysAgo(365), $today],
            default    => [$this->daysAgo(29), $today],
        };
    }

    /** @since 1.0.0 */
    private function earliestPaidDate(bool $includeTest = false): ?string
    {
        // Delegated, not restated: an inline predicate here drifted from the
        // consumers, which all apply the kind filter too, so a live ticket
        // order could start an all-time chart before the first donation.
        $row = DonationQueries::donationRows(
            DB::table('dono_donations')->whereIn('status', ['paid', 'partial_refund']),
            $includeTest
        )
            ->selectRaw('MIN(paid_at) AS first_paid')
            ->get();
        $val = $row['first_paid'] ?? null;
        if (! is_string($val) || $val === '') return null;
        // A range bound, so it has to be the org's calendar date: a donation
        // taken in the evening carries the next UTC date, and an all-time chart
        // anchored on that one skips the donation that set it.
        return (string) wp_date('Y-m-d', (int) strtotime($val . ' UTC'));
    }

    /**
     * @return array{0:string,1:string}
     * @since 1.0.0
     */
    private function previousRangeBounds(string $range, string $mode): array
    {
        $today = $this->localNow();
        if ($mode === 'year') {
            [$from, $to] = $this->rangeBounds($range);
            return [
                (new DateTimeImmutable($from))->modify('-1 year')->format('Y-m-d'),
                (new DateTimeImmutable($to))->modify('-1 year')->format('Y-m-d'),
            ];
        }
        return match ($range) {
            'today'   => [$today->modify('-1 day')->format('Y-m-d'), $today->modify('-1 day')->format('Y-m-d')],
            'last-7'  => [$this->daysAgo(13),  $this->daysAgo(7)],
            'last-30' => [$this->daysAgo(59),  $this->daysAgo(30)],
            'last-90' => [$this->daysAgo(179), $this->daysAgo(90)],
            default   => [$this->daysAgo(30),  $this->daysAgo(1)],
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
}
