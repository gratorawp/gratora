<?php

declare(strict_types=1);

namespace Gratora\Currency;

use Gratora\Donations\Donation;
use Gratora\Donations\DonationQueries;
use Gratora\Foundation\Helpers\Money;
use Gratora\Recurring\RecurringPlan;

/**
 * Backfill null base amounts using today’s rate, an approximation because no historical rate
 * was recorded. Configuring rates alone does not repair these donations or their aggregates.
 *
 * @since 1.0.0
 */
final class FxBackfill
{
    /** Rows held in memory at once. */
    private const CHUNK = 500;

    /** @since 1.0.0 */
    public function __construct(private FxRates $fx)
    {
    }

    /**
     * Process the FX backlog by ID within the time budget, returning a resume cursor.
     *
     * @param int   $after last donation id already handled, 0 to start
     * @param float $until microtime to stop at, INF for no budget
     *
     * @return array{converted:int, plans:int, unconvertible:int, currencies:array<int,string>, after:int, done:bool}
     *   currencies lists what is still missing a rate, so the caller can name it.
     *
     * @since 1.0.0
     */
    public function run(int $after = 0, float $until = INF): array
    {
        $base = strtoupper(Money::defaultCurrency());

        $converted     = 0;
        $unconvertible = [];

        // Paged by id rather than loaded in one go. The backlog is unbounded by
        // definition -- it is every donation in a currency nobody had a rate
        // for, which on an imported history can be the whole history -- and
        // this runs inside a REST request.
        $afterId = $after;
        $done    = true;
        while (true) {
            $rows = Donation::query()
                ->whereNull('base_amount_cents')
                ->where('id', $afterId, '>')
                ->orderBy('id', 'ASC')
                ->limit(self::CHUNK)
                ->getAll();

            if ($rows === []) {
                break;
            }

            foreach ($rows as $donation) {
                $afterId = (int) $donation->id;

                $currency = strtoupper((string) $donation->currency);
                if ($currency === '') {
                    continue;
                }

                $rate = $currency === $base ? 1.0 : $this->fx->rate($currency, $base);

                if ($rate === null) {
                    // Counted per row, not per currency: the number a screen
                    // reports is how much money is missing from the totals.
                    $unconvertible[$currency] = ($unconvertible[$currency] ?? 0) + 1;
                    continue;
                }

                $donation->base_currency     = $base;
                $donation->fx_rate           = sprintf('%.8F', $rate);
                $donation->base_amount_cents = (int) round((int) $donation->amount_cents * $rate);
                $donation->updateColumns([
                    'base_currency'     => $donation->base_currency,
                    'fx_rate'           => $donation->fx_rate,
                    'base_amount_cents' => $donation->base_amount_cents,
                ]);
                $converted++;
            }

            // After the rows, never before: a pass that returned having done
            // nothing would hand the caller the same work for ever.
            if (microtime(true) >= $until) {
                $done = false;
                break;
            }
        }

        // Recurring plans carry their own base amount, copied from the first
        // donation, and MRR scores a foreign plan with no base as zero. Only
        // once the donations are through, so one cursor covers the pass.
        $plans = $done ? $this->runForPlans($base, $unconvertible) : 0;

        return [
            'converted'     => $converted,
            'plans'         => $plans,
            'unconvertible' => array_sum($unconvertible),
            'currencies'    => array_keys($unconvertible),
            'after'         => $afterId,
            'done'          => $done,
        ];
    }

    /**
     * @param array<string,int> $unconvertible collected across both passes
     *
     * @since 1.0.0
     */
    private function runForPlans(string $base, array &$unconvertible): int
    {
        $converted = 0;
        $afterId   = 0;

        while (true) {
            $plans = RecurringPlan::query()
                ->whereNull('base_amount_cents')
                ->where('id', $afterId, '>')
                ->orderBy('id', 'ASC')
                ->limit(self::CHUNK)
                ->getAll();

            if ($plans === []) {
                break;
            }

            foreach ($plans as $plan) {
                $afterId = (int) $plan->id;

                $currency = strtoupper((string) $plan->currency);
                if ($currency === '') {
                    continue;
                }

                $rate = $currency === $base ? 1.0 : $this->fx->rate($currency, $base);
                if ($rate === null) {
                    $unconvertible[$currency] = ($unconvertible[$currency] ?? 0) + 1;
                    continue;
                }

                // No base_currency column here: a plan is always valued in the
                // org base, and the rate it was struck at is the audit trail.
                $plan->fx_rate           = sprintf('%.8F', $rate);
                $plan->base_amount_cents = (int) round((int) $plan->amount_cents * $rate);
                $plan->save();
                $converted++;
            }
        }

        return $converted;
    }

    /**
     * Report unconverted donations within the same scope as totals. Own-base rows need
     * backfilling but no rate configuration; needs_rate distinguishes them.
     *
     * @return array<int,array{currency:string, count:int, amount_cents:int, needs_rate:bool}>
     *
     * @since 1.0.0
     */
    public static function pending(): array
    {
        // Counted by the database. This runs on every load of the screen, and
        // hydrating a model per stranded donation to add up two numbers is the
        // one shape guaranteed to be slowest exactly where the backlog is
        // largest.
        $rows = DonationQueries::donationsOnly(Donation::query())
            ->selectRaw('UPPER(currency) AS currency, COUNT(*) AS cnt, COALESCE(SUM(amount_cents), 0) AS total')
            ->whereNull('base_amount_cents')
            ->whereIn('status', ['paid', 'partial_refund'])
            ->groupByRaw('UPPER(currency)')
            ->orderByRaw('total DESC')
            ->getAll();

        $base = strtoupper(Money::defaultCurrency());

        return array_map(static fn ($r): array => [
            'currency'     => (string) $r['currency'],
            'count'        => (int) $r['cnt'],
            'amount_cents' => (int) $r['total'],
            'needs_rate'   => strtoupper((string) $r['currency']) !== $base,
        ], $rows);
    }

    /**
     * Currencies of every donation still without a base amount, in any state.
     *
     * Not pending(): that answers what the totals are short by, and no total
     * counts a pending row, a test row or a ticket order. This answers whether
     * a rate is still worth fetching, and the answer covers everything run()
     * repairs. A pending donation is stamped with a rate when it is created and
     * nothing restates it when it is paid, so a site that stops fetching while
     * one is outstanding strands it for good.
     *
     * @return list<string>
     *
     * @since 1.0.0
     */
    public static function strandedCurrencies(): array
    {
        $rows = Donation::query()
            ->selectRaw('UPPER(currency) AS currency')
            ->whereNull('base_amount_cents')
            ->groupByRaw('UPPER(currency)')
            ->getAll();

        $out = [];
        foreach ($rows as $r) {
            $code = strtoupper((string) ($r['currency'] ?? ''));
            if ($code !== '') {
                $out[] = $code;
            }
        }

        return $out;
    }
}
