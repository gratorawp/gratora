<?php

declare(strict_types=1);

namespace Dono\Donors;

use DateTimeImmutable;
use Dono\Analytics\Event;
use Dono\Campaigns\Campaign;
use Dono\Donations\ChannelClassifier;
use Dono\Donations\Donation;
use Dono\Donations\DonationQueries;
use Dono\Donors\DonorNoteRepository;
use Dono\Foundation\Helpers\Csv;
use Dono\Foundation\Helpers\Money;
use Dono\Foundation\Time\Clock;
use Dono\Receipts\Receipt;
use Dono\Recurring\RecurringPlan;
use Dono\Recurring\RecurringPlanRepository;
use Dono\Settings\SettingsService;
use Dono\Vendor\Queryable\DB;
use Throwable;

/**
 * Builds donor-insights and donor-profile payloads for the admin UI.
 *
 * @since 1.0.0
 */
final class DonorMetricsService
{
    /** @since 1.0.0 */
    public function __construct(
        private DonorRepository $donors,
        private DonorService $donorService,
        private RecurringPlanRepository $recurring,
        private DonorNoteRepository $notes,
        private MagicLinkService $magicLinks,
        private Clock $clock,
        private \Dono\Gateways\GatewayManager $gateways,
        private DonorAvatars $avatars,
    ) {
    }

    /**
     * @return array<string,mixed>
     *
     * @since 1.0.0
     */
    /**
     * Donors whose whole footprint is test-mode, so the operator can tell an
     * empty analysis from a broken one.
     *
     * @since 1.0.0
     */
    private function testOnlyDonorCount(): int
    {
        // One whereRaw, not two: it emits no AND connector, so a second one
        // runs straight into the first and the SQL will not parse.
        return (int) Donor::query()
            ->whereRaw(DonorRepository::testOnlyDonorPredicate() . ' AND redacted_at IS NULL')
            ->count();
    }

    public function insights(): array
    {
        $today = $this->clock->now()->format('Y-m-d');

        $kpi       = $this->donors->lifecycleKpi($today);
        $segments  = $this->donors->rfmSegments($today);
        $ltv       = $this->donors->lifetimeValueHistogram([2500, 10000, 25000, 50000, 100000, 250000]);
        $top       = $this->donors->topByLifetimeValue(20);
        $retention = $this->donors->donorCohortRetention(12, 12);
        $recurring = $this->recurring->recurringStats($today);

        $total = max(1, $kpi['total']); // avoid division by zero
        $kpi['active_pct']  = (int) round(($kpi['active']  / $total) * 100);
        $kpi['at_risk_pct'] = (int) round(($kpi['at_risk'] / $total) * 100);
        $kpi['lapsed_pct']  = (int) round(($kpi['lapsed']  / $total) * 100);
        $kpi['lost_pct']    = (int) round(($kpi['lost']    / $total) * 100);

        return [
            // Insights cannot be made test-inclusive the way the dashboard can.
            // Every figure here reads the donor rollup columns, and those are
            // synced through donationsOnly(), so a rehearsal contributes
            // nothing to total_donated_cents, donations_count or the donation
            // dates every segment and cohort is cut on. There is no
            // test-inclusive version of these numbers to offer, so the screen
            // says what it is missing instead of pretending to be empty.
            'test'         => ['test_only_donors' => $this->testOnlyDonorCount()],
            'kpi'          => $kpi,
            'segments'     => $segments,
            'ltv_buckets'  => $ltv,
            'top_donors'   => $this->shapeTopDonors($top),
            'retention'    => $retention,
            'recurring'    => $recurring,
            'generated_at' => $this->clock->now()->format('c'),
        ];
    }

    /**
     * Paged at-risk donors with decrypted email.
     *
     * @return array{rows:array<array<string,mixed>>, total:int}
     *
     * @since 1.0.0
     */
    public function atRisk(int $page = 1, int $perPage = 25): array
    {
        $today = $this->clock->now()->format('Y-m-d');
        $result = $this->donors->listAtRisk($today, $page, $perPage);

        // One grouped query for the whole page, not one per row.
        $plans  = $this->recurring->stateForDonors(array_column($result['rows'], 'id'));
        $labels = AtRiskReason::labels();

        $result['rows'] = array_map(function (array $r) use ($plans, $labels, $today): array {
            $donor = Donor::make();
            $donor->email_encrypted = (string) $r['email_encrypted'];
            $email = $this->donorService->decryptEmail($donor);
            unset($r['email_encrypted']);
            $name = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
            $reason = AtRiskReason::classify($r, $plans[(int) $r['id']] ?? null, $today);
            return [
                'id'                  => $r['id'],
                'name'                => $name !== '' ? $name : __('Donor', 'dono-fundraising-platform') . ' #' . $r['id'],
                'email'               => $email,
                'country'             => $r['country'],
                'donations_count'     => $r['donations_count'],
                'total_donated_cents' => $r['total_donated_cents'],
                'last_donation_at'    => $r['last_donation_at'],
                'first_donation_at'   => $r['first_donation_at'],
                'risk_reason'         => $reason['key'],
                'risk_reason_label'   => $labels[$reason['key']],
                'avg_gap_days'        => $reason['avg_gap_days'],
            ];
        }, $result['rows']);

        return $result;
    }

    /**
     * All at-risk donors as CSV. Capped at 10k rows to bound memory.
     *
     * @since 1.0.0
     */
    public function atRiskCsv(): string
    {
        $result = $this->atRisk(1, 10000);

        $out = fopen('php://temp', 'r+');
        // Appended, never inserted: every existing column index stays put for
        // anyone with a saved import mapping.
        Csv::writeRow($out, ['id', 'name', 'email', 'country', 'donations', 'total_donated', 'first_donation_at', 'last_donation_at', 'risk_reason', 'risk_reason_label', 'avg_gap_days']);
        foreach ($result['rows'] as $r) {
            Csv::writeRow($out, [
                $r['id'],
                $r['name'],
                $r['email'],
                $r['country'] ?? '',
                $r['donations_count'],
                number_format($r['total_donated_cents'] / 100, 2, '.', ''),
                $r['first_donation_at'] ?? '',
                $r['last_donation_at'] ?? '',
                // The key as well as the label: the key is what a fundraiser
                // filters and pivots on, and must not move when the site
                // language does.
                $r['risk_reason'] ?? '',
                $r['risk_reason_label'] ?? '',
                $r['avg_gap_days'] ?? '',
            ]);
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);
        return $csv;
    }

    /**
     * Full profile payload for the donor detail screen.
     *
     * @return array<string,mixed>|null null when donor doesn't exist.
     *
     * @since 1.0.0
     */
    public function profile(int $donorId, bool $includeMagicLink = false): ?array
    {
        $donor = $this->donors->findById($donorId);
        if (! $donor) return null;

        $today   = $this->clock->now()->format('Y-m-d');
        $segment = $this->classifySegment($donor, $today);

        $donations = Donation::query()
            ->where('donor_id', $donorId)
            ->orderBy('created_at', 'DESC')
            ->limit(25)
            ->getAll();

        $recurringPlans = RecurringPlan::query()
            ->where('donor_id', $donorId)
            ->orderBy('status', 'ASC')
            ->orderBy('started_at', 'DESC')
            ->getAll();

        $receipts = Receipt::query()
            ->where('donor_id', $donorId)
            ->orderBy('issued_at', 'DESC')
            ->limit(25)
            ->getAll();

        $events = Event::query()
            ->where('donor_id', $donorId)
            ->orderBy('occurred_at', 'DESC')
            ->limit(100)
            ->getAll();

        // The list above is capped, so its length is not the donor's history.
        // The overview footer states the real figure.
        $eventsTotal = Event::query()->where('donor_id', $donorId)->count();

        // Same reason, and the tab badge reads it. donations_count cannot: it
        // is synced live-only, so a donor who has only rehearsed reads zero
        // while the tab beside it lists their donations.
        $donationsTotal = Donation::query()->where('donor_id', $donorId)->count();

        $consents = Consent::query()
            ->where('donor_id', $donorId)
            ->orderBy('occurred_at', 'DESC')
            ->getAll();

        $notes      = $this->notes->listForDonor($donorId);
        $timeline   = $this->donors->monthlyTimelineForDonor($donorId);
        $attribution = $this->donors->attributionMixForDonor($donorId);

        // The note a donor left with a gift lives on the donation, not the event.
        // Pull the notes for the events' donations in one query so the timeline
        // can show the message inline instead of leaving it a click away.
        $eventNotes    = $this->noteMapForEvents($events);
        $eventReceipts = $this->receiptNumbersForEvents($events);

        // One query for campaign titles to avoid N+1 in donations/events.
        $campaignIds = array_unique(array_filter(array_merge(
            array_map(static fn ($d) => $d->campaign_id, $donations),
            array_map(static fn ($e) => $e->campaign_id, $events),
        )));
        $campaigns = [];
        if ($campaignIds) {
            foreach (Campaign::query()->whereIn('id', array_values($campaignIds))->getAll() as $c) {
                $campaigns[(int) $c->id] = ['id' => (int) $c->id, 'title' => (string) $c->title, 'slug' => (string) $c->slug];
            }
        }

        // Lifetime extras
        $totalCents = (int) $donor->total_donated_cents;
        $count      = (int) $donor->donations_count;
        $avgCents   = $count > 0 ? (int) round($totalCents / $count) : 0;

        $sparkline = $this->buildSparkline($timeline, 30);
        $netExpr = DonationQueries::netBaseExpr();
        $largestDonation = (int) (DonationQueries::live(DB::table('dono_donations')
            ->whereIn('status', ['paid', 'partial_refund'])
            ->where('donor_id', $donorId))
            ->selectRaw("COALESCE(MAX({$netExpr}), 0) AS m")
            ->get()['m'] ?? 0);

        $oneTimeCount = 0;
        $recurringCount = 0;
        foreach ($donations as $d) {
            if (($d->frequency ?? 'one_time') === 'one_time') $oneTimeCount++;
            else $recurringCount++;
        }

        // MRR from active plans (cadence-normalized monthly equivalent).
        $mrrCents = 0;
        $activePlanCount = 0;
        $mrrUnconverted  = 0;
        $nextPaymentAt = null;
        // A paused or past-due plan bills nothing, so MRR is legitimately zero
        // while the donor still has a subscription. Counted per status so the
        // card can say which rather than reading as "this donor has none".
        $planCounts = [];
        foreach ($recurringPlans as $p) {
            // Rehearsal plans stay in the tab's table, labeled, because an
            // admin testing wants to see them. They stay out of every figure on
            // this card: recurringStats() already excludes them, so counting
            // them here would make a donor's MRR disagree with the
            // Subscriptions totals it rolls up into.
            if ($p->is_test) {
                continue;
            }
            $planCounts[(string) $p->status] = ($planCounts[(string) $p->status] ?? 0) + 1;
            if ($p->status !== 'active') continue;
            $activePlanCount++;
            // Counted, like recurringStats() does, so the card can say the
            // figure is partial instead of quietly understating it.
            if ($p->base_amount_cents === null
                && strtoupper((string) $p->currency) !== strtoupper(Money::defaultCurrency())) {
                $mrrUnconverted++;
            }
            $mrrCents += $this->monthlyEquivalent($p);
            if ($nextPaymentAt === null || ($p->next_payment_at && $p->next_payment_at < $nextPaymentAt)) {
                $nextPaymentAt = $p->next_payment_at;
            }
        }

        // Attribution rollup into canonical channel buckets.
        $byChannel = [];
        foreach ($attribution as $t) {
            $attr = [];
            if ($t['utm_source'] !== null) $attr['utm_source'] = $t['utm_source'];
            if ($t['utm_medium'] !== null) $attr['utm_medium'] = $t['utm_medium'];
            $key = ChannelClassifier::classify($attr);
            $byChannel[$key] ??= ['amount' => 0, 'count' => 0];
            $byChannel[$key]['amount'] += $t['amount_cents'];
            $byChannel[$key]['count']  += $t['donations_count'];
        }
        uasort($byChannel, fn ($a, $b) => $b['amount'] <=> $a['amount']);
        $channels = [];
        foreach ($byChannel as $ch => $stats) {
            $channels[] = ['channel' => $ch, 'amount_cents' => $stats['amount'], 'donations_count' => $stats['count']];
        }

        // Consents: show EVERY configured purpose with this donor's latest
        // status, so purposes the donor never acted on still appear (not just
        // the ones with a recorded row). Latest row per purpose wins (rows are
        // sorted DESC by occurred_at). The full audit trail lives in `history`.
        $latestConsent = [];
        foreach ($consents as $c) {
            if (! isset($latestConsent[$c->purpose])) {
                $latestConsent[$c->purpose] = $c;
            }
        }
        $labels = [];
        $cfg    = (new SettingsService())->get('consents');
        foreach ((is_array($cfg['purposes'] ?? null) ? $cfg['purposes'] : []) as $p) {
            $k = (string) ($p['key'] ?? '');
            if ($k !== '') $labels[$k] = (string) ($p['label'] ?? $k);
        }
        $consentCurrent = [];
        foreach (array_unique(array_merge(array_keys($labels), array_keys($latestConsent))) as $key) {
            $c = $latestConsent[$key] ?? null;
            $consentCurrent[$key] = [
                'purpose'            => $labels[$key] ?? (string) $key,
                'granted'            => $c ? (bool) $c->granted : false,
                'source'             => $c ? (string) $c->source : '',
                'occurred_at'        => $c ? (string) $c->occurred_at : null,
                'source_form_id'     => ($c && $c->source_form_id !== null) ? (int) $c->source_form_id : null,
                'source_donation_id' => ($c && $c->source_donation_id !== null) ? (int) $c->source_donation_id : null,
            ];
        }

        // Contextual banners.
        $banners = [];
        if ($donor->redacted_at !== null) {
            $banners[] = ['kind' => 'redacted', 'message' => __('This donor has been redacted under GDPR. PII has been removed; lifetime totals are kept for accounting.', 'dono-fundraising-platform')];
        }
        $pastDuePlan = null;
        foreach ($recurringPlans as $p) {
            if ($p->status === 'past_due') { $pastDuePlan = $p; break; }
        }
        if ($pastDuePlan) {
            // Three outcomes, because "cannot retry" has two very different
            // causes: a gateway with no retry endpoint, and a gateway that is
            // not connected. Offering a retry the gateway cannot do sends the
            // admin looking for a button that does not exist.
            $gateway  = $this->gateways->get((string) $pastDuePlan->gateway);
            $name     = ucfirst((string) $pastDuePlan->gateway);

            if ($gateway instanceof \Dono\Gateways\SupportsPaymentRetry) {
                $message = __('A renewal was declined. Open the Recurring tab to collect it again.', 'dono-fundraising-platform');
            } elseif ($gateway === null) {
                $message = sprintf(
                    /* translators: %s: the payment gateway name, e.g. Stripe. */
                    __('A renewal was declined, but the %s connection is not active, so nothing can be collected from here. Reconnect it in Settings, Payment gateways.', 'dono-fundraising-platform'),
                    $name
                );
            } else {
                $message = sprintf(
                    /* translators: %s: the payment gateway name, e.g. PayPal. */
                    __('A renewal was declined. %s retries on its own schedule; to fix it sooner, ask the donor to update their card in the donor portal.', 'dono-fundraising-platform'),
                    $name
                );
            }

            $banners[] = ['kind' => 'past_due', 'message' => $message];
        }

        // Never minted here. This is a read, and issuing a token from a read
        // meant the number of live portal logins equalled the number of times
        // anyone had opened a donor: a rep working through forty donors left
        // forty credentials nobody asked for, each of them a 30-day login to
        // someone else's account, printed into the response body and the page.
        // The screen asks for one explicitly instead.
        $magicLinkUrl = null;

        return [
            'donor' => [
                'id'                  => (int) $donor->id,
                'reference'           => sprintf('DONOR_%04d', $donor->id),
                'name'                => $this->donorName($donor),
                'email'               => $donor->redacted_at === null ? $this->donorService->decryptEmail($donor) : null,
                'phone'               => $donor->redacted_at === null ? $this->donorService->decryptPhone($donor) : null,
                'address'             => $donor->redacted_at === null ? $this->donorService->decryptAddress($donor) : null,
                'address_parts'       => $donor->redacted_at === null ? $this->donorService->decryptAddressStruct($donor) : null,
                'country'             => $donor->country,
                'donor_type'          => $donor->donor_type,
                'company'             => $donor->company,
                'first_name'          => $donor->first_name,
                'last_name'           => $donor->last_name,
                'segment'             => $segment,
                'first_donation_at'   => $donor->first_donation_at,
                'last_donation_at'    => $donor->last_donation_at,
                'created_at'          => $donor->created_at,
                'redacted_at'         => $donor->redacted_at,
                'public_hidden'       => $donor->public_hidden_at !== null,
                // The screen that decides whether the public sees this picture
                // is the screen that has to show it.
                'avatar_url'          => $this->avatars->adminUrl($donor),
                'is_anonymous'        => $this->isAnonymous($donor),
            ],
            'lifetime' => [
                'total_cents'          => $totalCents,
                'count'                => $count,
                'avg_cents'            => $avgCents,
                'largest_cents'        => $largestDonation,
                'one_time_count'       => $oneTimeCount,
                'recurring_count'      => $recurringCount,
                'mrr_cents'            => $mrrCents,
                'mrr_unconverted'      => $mrrUnconverted,
                'active_plan_count'    => $activePlanCount,
                'plan_counts'          => (object) $planCounts,
                'next_payment_at'      => $nextPaymentAt,
                'sparkline'            => $sparkline,
            ],
            'donations' => array_map(fn (Donation $d) => $this->mapDonationRow($d), $donations),
            'recurring' => [
                'plans' => array_map(fn (RecurringPlan $p) => $this->mapRecurringPlanRow($p), $recurringPlans),
            ],
            'receipts' => $this->mapReceiptRows($receipts),
            'events' => array_map(function (Event $e) use ($eventNotes, $eventReceipts) {
                $row = $this->mapEventRow($e);
                $row['note'] = $this->noteForEvent($e, $eventNotes);
                $row['reference'] = $e->donation_id !== null
                    ? ($eventNotes[(int) $e->donation_id]['reference'] ?? null)
                    : null;
                $row['receipt_number'] = $e->receipt_id !== null
                    ? ($eventReceipts[(int) $e->receipt_id] ?? null)
                    : null;
                return $row;
            }, $events),
            'events_total' => (int) $eventsTotal,
            'donations_total' => (int) $donationsTotal,
            'consents' => [
                'current' => array_values($consentCurrent),
                'history' => array_map(fn (Consent $c) => $this->mapConsentRow($c), $consents),
            ],
            'notes'           => $notes,
            'campaigns'       => $campaigns,
            'by_channel'      => $channels,
            'monthly_timeline'=> $timeline,
            'banners'         => $banners,
            'magic_link_url'  => $magicLinkUrl,
        ];
    }

    /** @since 1.0.0 */
    private function isAnonymous(Donor $d): bool
    {
        if ($d->redacted_at !== null) return false; // redacted is a distinct state from anonymous
        return ! $d->first_name && ! $d->last_name;
    }

    /**
     * Pads monthly_timeline into a fixed-length array of the N most recent values.
     *
     * @param array<array{month:string,amount_cents:int,donations_count:int}> $timeline
     * @return array<int>
     *
     * @since 1.0.0
     */
    private function buildSparkline(array $timeline, int $buckets): array
    {
        if (! $timeline) return array_fill(0, $buckets, 0);
        $tail = array_slice($timeline, -$buckets);
        $out = array_fill(0, $buckets, 0);
        $offset = $buckets - count($tail);
        foreach ($tail as $i => $row) {
            $out[$offset + $i] = (int) $row['amount_cents'];
        }
        return $out;
    }

    /**
     * A plan's value in the org's base currency, or zero when there is none.
     *
     * The same rule RecurringPlanRepository::baseAmountExpr() uses in SQL: a
     * plan in the base currency needs no conversion, and one in a foreign
     * currency with no rate has no known base value, so it counts as nothing
     * rather than folding its raw foreign cents into a base total.
     *
     * @since 1.0.0
     */
    private static function baseAmountOf(RecurringPlan $p): int
    {
        if ($p->base_amount_cents !== null) {
            return (int) $p->base_amount_cents;
        }

        return strtoupper((string) $p->currency) === strtoupper(Money::defaultCurrency())
            ? (int) $p->amount_cents
            : 0;
    }

    /**
     * Monthly-equivalent value of one recurring plan, in the org base currency.
     *
     * Normalizes the base amount, never the raw amount_cents (which is in the
     * donor's currency): the card renders the result with the org's symbol and
     * no conversion, so a 500.00 INR plan would read as 500,00 EUR.
     *
     * @since 1.0.0
     */
    private function monthlyEquivalent(RecurringPlan $p): int
    {
        $amount = self::baseAmountOf($p);
        $n      = max(1, (int) $p->interval_count);
        return match ($p->interval_unit) {
            'month' => (int) round($amount / $n),
            'week'  => (int) round(($amount * 4.345) / $n),
            'year'  => (int) round($amount / (12 * $n)),
            'day'   => (int) round(($amount * 30) / $n),
            default => $amount,
        };
    }

    /**
     * Issues a self-service magic link. Raw token returned once; never stored cleartext.
     * Profile responses are admin-only.
     *
     * @since 1.0.0
     */
    /**
     * Mint a portal login for this donor.
     *
     * Only ever from an explicit request. It impersonates the donor for thirty
     * days and cannot be revoked, so nothing should create one as a side effect
     * of looking at a record.
     *
     * @since 1.0.0
     */
    public function issuePortalLink(Donor $donor): ?string
    {
        return $donor->redacted_at === null ? $this->magicLinkUrl($donor) : null;
    }

    private function magicLinkUrl(Donor $donor): ?string
    {
        try {
            $token = $this->magicLinks->issue((int) $donor->id, 'donor_portal', null, 2_592_000);
        } catch ( Throwable $e) {
            return null;
        }
        return add_query_arg('token', $token, (new \Dono\Donors\Portal\PortalPage())->url());
    }

    /** @since 1.0.0 */
    private function donorName(Donor $d): string
    {
        $name = trim(($d->first_name ?? '') . ' ' . ($d->last_name ?? ''));
        return $name !== '' ? $name : __('Donor', 'dono-fundraising-platform') . ' #' . $d->id;
    }

    /**
     * Classifies one donor into a segment. Must stay in sync with the SQL CASE
     * in DonorRepository::rfmSegments().
     *
     * @since 1.0.0
     */
    private function classifySegment(Donor $d, string $today): string
    {
        if (! $d->last_donation_at) return 'other';

        $now    = new DateTimeImmutable($today);
        $last   = new DateTimeImmutable(substr((string) $d->last_donation_at, 0, 10));
        $created = new DateTimeImmutable(substr((string) $d->created_at, 0, 10));
        $days = (int) $now->diff($last)->format('%a');
        $daysSinceCreated = (int) $now->diff($created)->format('%a');

        if ($days > 365) return 'lost';
        if ($days <= 90  && $d->donations_count >= 4 && $d->total_donated_cents >= 25000) return 'champions';
        if ($days <= 90  && $d->donations_count >= 2) return 'loyal';
        if ($days <= 90  && $daysSinceCreated <= 30) return 'new';
        if ($days <= 180 && $days > 90)  return 'at_risk';
        if ($days <= 365 && $days > 180) return 'hibernating';
        return 'other';
    }

    /**
     * @param array<array<string,mixed>> $rows
     *
     * @since 1.0.0
     */
    private function shapeTopDonors(array $rows): array
    {
        return array_map(function (array $r): array {
            $donor = Donor::make();
            $donor->email_encrypted = (string) ($r['email_encrypted'] ?? '');
            $name = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
            return [
                'id'                  => $r['id'],
                'name'                => $name !== '' ? $name : __('Donor', 'dono-fundraising-platform') . ' #' . $r['id'],
                'email'               => $this->donorService->decryptEmail($donor),
                'country'             => $r['country'],
                'total_donated_cents' => $r['total_donated_cents'],
                'donations_count'     => $r['donations_count'],
                'last_donation_at'    => $r['last_donation_at'],
            ];
        }, $rows);
    }

    /**
     * @return array<string,mixed>
     *
     * @since 1.0.0
     */
    private function mapDonationRow(Donation $d): array
    {
        return [
            'id'             => (int) $d->id,
            'reference'      => (string) $d->reference,
            'amount_cents'   => (int) $d->amount_cents,
            'currency'       => (string) $d->currency,
            'frequency'      => (string) $d->frequency,
            'status'         => (string) $d->status,
            'gateway'        => (string) $d->gateway,
            'campaign_id'    => $d->campaign_id !== null ? (int) $d->campaign_id : null,
            // The profile lists a donor's own history, test rows included, so
            // each row has to be able to say which it is. Without this the card
            // shows a rehearsal as an ordinary donation.
            'is_test'        => (bool) $d->is_test,
            'paid_at'        => $d->paid_at,
            'created_at'     => (string) $d->created_at,
        ];
    }

    /**
     * Uncapped donation list for a DSAR export. profile() caps at 25 for admin UI.
     *
     * @return list<array<string,mixed>>
     *
     * @since 1.0.0
     */
    public function donationsForExport(int $donorId): array
    {
        $rows = Donation::query()
            ->where('donor_id', $donorId)
            ->orderBy('created_at', 'DESC')
            ->getAll();

        return array_map(fn (Donation $d) => $this->mapDonationRow($d), $rows);
    }

    /**
     * @return array<string,mixed>
     *
     * @since 1.0.0
     */
    private function mapRecurringPlanRow(RecurringPlan $p): array
    {
        return [
            'id'                    => (int) $p->id,
            'gateway'               => (string) $p->gateway,
            'gateway_subscription_id'=> (string) $p->gateway_subscription_id,
            'amount_cents'          => (int) $p->amount_cents,
            'currency'              => (string) $p->currency,
            'interval_unit'         => (string) $p->interval_unit,
            'interval_count'        => (int) $p->interval_count,
            'status'                => (string) $p->status,
            'started_at'            => (string) $p->started_at,
            'next_payment_at'       => $p->next_payment_at,
            'last_payment_at'       => $p->last_payment_at,
            'cancelled_at'          => $p->cancelled_at,
            'payments_count'        => (int) $p->payments_count,
            'total_paid_cents'      => (int) $p->total_paid_cents,
            'failed_renewals_count' => (int) $p->failed_renewals_count,
            // The Recurring tab offers Retry only where the gateway can do it;
            // this shaper is separate from the Subscriptions one, so the flag
            // has to be set in both or the tab silently loses the action.
            'can_retry'             => $this->gateways->get((string) $p->gateway)
                instanceof \Dono\Gateways\SupportsPaymentRetry,
            'campaign_id'           => $p->campaign_id !== null ? (int) $p->campaign_id : null,
            'is_test'               => (bool) $p->is_test,
        ];
    }

    /**
     * A receipt number identifies the document, not the gift it covers, so the
     * reference is resolved for the whole page in one query rather than per row.
     *
     * @param array<int,Receipt> $receipts
     * @return array<int,array<string,mixed>>
     *
     * @since 1.0.0
     */
    private function mapReceiptRows(array $receipts): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map(static fn (Receipt $r) => (int) $r->donation_id, $receipts)
        )));
        $references = [];
        if ($ids) {
            foreach (Donation::query()->whereIn('id', $ids)->getAll() as $d) {
                $references[(int) $d->id] = (string) $d->reference;
            }
        }
        return array_map(fn (Receipt $r) => $this->mapReceiptRow($r, $references), $receipts);
    }

    /**
     * @param array<int,string> $references Donation reference keyed by donation id.
     * @return array<string,mixed>
     *
     * @since 1.0.0
     */
    private function mapReceiptRow(Receipt $r, array $references): array
    {
        return [
            'id'                 => (int) $r->id,
            'receipt_number'     => (string) $r->receipt_number,
            'renderer_id'        => (string) $r->renderer_id,
            'donation_id'        => (int) $r->donation_id,
            'donation_reference' => $references[(int) $r->donation_id] ?? null,
            'sent_to_email_at'   => $r->sent_to_email_at,
            'voided'             => (bool) $r->voided,
            'issued_at'          => (string) $r->issued_at,
        ];
    }

    /**
     * @return array<string,mixed>
     *
     * @since 1.0.0
     */
    private function mapEventRow(Event $e): array
    {
        return [
            'id'                => (int) $e->id,
            'type'              => (string) $e->type,
            'donation_id'       => $e->donation_id !== null ? (int) $e->donation_id : null,
            'recurring_plan_id' => $e->recurring_plan_id !== null ? (int) $e->recurring_plan_id : null,
            'receipt_id'        => $e->receipt_id !== null ? (int) $e->receipt_id : null,
            'campaign_id'       => $e->campaign_id !== null ? (int) $e->campaign_id : null,
            'amount_cents'      => $e->amount_cents !== null ? (int) $e->amount_cents : null,
            'currency'          => $e->currency,
            'payload'           => $e->payload,
            'occurred_at'       => (string) $e->occurred_at,
        ];
    }

    /**
     * One page of a donor's activity for the full log table. Same rows as the
     * overview timeline, but paged, and with the note and campaign title inlined
     * so the table needs no side lookups.
     *
     * @return array{items: array<int,array<string,mixed>>, total: int}
     *
     * @since 1.0.0
     */
    public function eventsPage(int $donorId, int $page, int $perPage, string $order = 'desc'): array
    {
        $order   = strtoupper($order) === 'ASC' ? 'ASC' : 'DESC';
        $perPage = max(1, min(100, $perPage));
        $page    = max(1, $page);

        $total = Event::query()->where('donor_id', $donorId)->count();
        if ($total === 0) {
            return ['items' => [], 'total' => 0];
        }

        $events = Event::query()
            ->where('donor_id', $donorId)
            ->orderBy('occurred_at', $order)
            ->limit($perPage)
            ->offset(($page - 1) * $perPage)
            ->getAll();

        $notes     = $this->noteMapForEvents($events);
        $receipts  = $this->receiptNumbersForEvents($events);
        $campaigns = $this->campaignTitlesForEvents($events);

        $items = array_map(function (Event $e) use ($notes, $receipts, $campaigns) {
            $row             = $this->mapEventRow($e);
            $row['note']     = $this->noteForEvent($e, $notes);
            $row['campaign'] = $e->campaign_id !== null ? ($campaigns[(int) $e->campaign_id] ?? null) : null;
            // Same identifiers the overview timeline carries, so a row in
            // either place can be traced back to what it is about.
            $row['reference'] = $e->donation_id !== null
                ? ($notes[(int) $e->donation_id]['reference'] ?? null)
                : null;
            $row['receipt_number'] = $e->receipt_id !== null
                ? ($receipts[(int) $e->receipt_id] ?? null)
                : null;
            return $row;
        }, $events);

        return ['items' => $items, 'total' => $total];
    }

    /**
     * Note and reference for every donation the timeline mentions, in one
     * query. A timeline row saying only "Donation paid" cannot be matched to
     * anything; the reference is what makes it findable.
     *
     * @param  array<int,Event> $events
     * @return array<int,array{note:?string,reference:string}>
     *
     * @since 1.0.0
     */
    private function noteMapForEvents(array $events): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map(static fn ($e) => $e->donation_id !== null ? (int) $e->donation_id : 0, $events)
        )));
        $map = [];
        if ($ids) {
            foreach (Donation::query()->whereIn('id', $ids)->getAll() as $d) {
                $note = trim((string) ($d->note_to_org ?? ''));
                $map[(int) $d->id] = [
                    'note'      => $note !== '' ? $note : null,
                    'reference' => (string) $d->reference,
                ];
            }
        }
        return $map;
    }

    /**
     * Receipt numbers for receipt events, in one query.
     *
     * @return array<int,string>
     *
     * @since 1.0.0
     */
    private function receiptNumbersForEvents(array $events): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map(static fn ($e) => $e->receipt_id !== null ? (int) $e->receipt_id : 0, $events)
        )));
        $map = [];
        if ($ids) {
            foreach (Receipt::query()->whereIn('id', $ids)->getAll() as $r) {
                $map[(int) $r->id] = (string) $r->receipt_number;
            }
        }
        return $map;
    }

    /**
     * The note belongs to the gift, and one donation spawns several events
     * (intent, completed, receipt), so surface it only on the gift event, not
     * on every row that shares the donation id.
     *
     * @param array<int,string> $noteMap
     *
     * @since 1.0.0
     */
    private function noteForEvent(Event $e, array $noteMap): ?string
    {
        $isGift = in_array((string) $e->type, ['donation.completed', 'donation.paid'], true);
        if (! $isGift || $e->donation_id === null) {
            return null;
        }

        return $noteMap[(int) $e->donation_id]['note'] ?? null;
    }

    /**
     * @param  array<int,Event> $events
     * @return array<int,array{id:int,title:string}>
     *
     * @since 1.0.0
     */
    private function campaignTitlesForEvents(array $events): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map(static fn ($e) => $e->campaign_id !== null ? (int) $e->campaign_id : 0, $events)
        )));
        $map = [];
        if ($ids) {
            foreach (Campaign::query()->whereIn('id', $ids)->getAll() as $c) {
                $map[(int) $c->id] = ['id' => (int) $c->id, 'title' => (string) $c->title];
            }
        }
        return $map;
    }

    /**
     * @return array<string,mixed>
     *
     * @since 1.0.0
     */
    private function mapConsentRow(Consent $c): array
    {
        return [
            'id'          => (int) $c->id,
            'purpose'     => (string) $c->purpose,
            'granted'     => (bool) $c->granted,
            'source'      => (string) $c->source,
            'occurred_at' => (string) $c->occurred_at,
        ];
    }

    /**
     * Uncapped personal data bundle for a DSAR export. Returns only the
     * donor's own data, not the admin-insights aggregate.
     *
     * @return array<string,mixed>|null
     *
     * @since 1.0.0
     */
    public function exportData(int $donorId): ?array
    {
        $donor = $this->donors->findById($donorId);
        if (! $donor) {
            return null;
        }

        $live = $donor->redacted_at === null;

        $recurringPlans = RecurringPlan::query()
            ->where('donor_id', $donorId)
            ->orderBy('status', 'ASC')
            ->orderBy('started_at', 'DESC')
            ->getAll();

        $receipts = Receipt::query()
            ->where('donor_id', $donorId)
            ->orderBy('issued_at', 'DESC')
            ->getAll();

        $events = Event::query()
            ->where('donor_id', $donorId)
            ->orderBy('occurred_at', 'DESC')
            ->getAll();

        $consents = Consent::query()
            ->where('donor_id', $donorId)
            ->orderBy('occurred_at', 'DESC')
            ->getAll();

        $consentCurrent = [];
        foreach ($consents as $c) {
            if (! isset($consentCurrent[$c->purpose])) {
                $consentCurrent[$c->purpose] = [
                    'purpose'            => (string) $c->purpose,
                    'granted'            => (bool) $c->granted,
                    'source'             => (string) $c->source,
                    'occurred_at'        => (string) $c->occurred_at,
                    'source_form_id'     => $c->source_form_id !== null ? (int) $c->source_form_id : null,
                    'source_donation_id' => $c->source_donation_id !== null ? (int) $c->source_donation_id : null,
                ];
            }
        }

        return [
            'donor' => [
                'id'                => (int) $donor->id,
                'reference'         => sprintf('DONOR_%04d', $donor->id),
                'name'              => $this->donorName($donor),
                'email'             => $live ? $this->donorService->decryptEmail($donor) : null,
                'phone'             => $live ? $this->donorService->decryptPhone($donor) : null,
                'address'           => $live ? $this->donorService->decryptAddress($donor) : null,
                'country'           => $donor->country,
                'donor_type'        => $donor->donor_type,
                'company'           => $donor->company,
                'first_name'        => $donor->first_name,
                'last_name'         => $donor->last_name,
                'first_donation_at' => $donor->first_donation_at,
                'last_donation_at'  => $donor->last_donation_at,
                'created_at'        => $donor->created_at,
                'redacted_at'       => $donor->redacted_at,
                'is_anonymous'      => $this->isAnonymous($donor),
            ],
            'donations' => $this->donationsForExport($donorId),
            'recurring' => [
                'plans' => array_map(fn (RecurringPlan $p) => $this->mapRecurringPlanRow($p), $recurringPlans),
            ],
            'receipts'  => $this->mapReceiptRows($receipts),
            'events'    => array_map(fn (Event $e) => $this->mapEventRow($e), $events),
            'consents'  => [
                'current' => array_values($consentCurrent),
                'history' => array_map(fn (Consent $c) => $this->mapConsentRow($c), $consents),
            ],
            // Staff notes are in DSAR scope; uncap (admin UI uses default-capped listForDonor()).
            'notes' => $this->notes->listForDonor($donorId, 100000),
        ];
    }
}
