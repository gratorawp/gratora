<?php

declare(strict_types=1);

namespace Dono\Currency;

use Dono\Donations\Donation;
use Dono\Donations\DonationQueries;
use Dono\Recurring\RecurringPlan;
use Dono\Foundation\Helpers\Money;

/**
 * Convert donations that were recorded before a rate existed for their currency.
 *
 * Recording a donation never rejects it for want of an exchange rate: FX is a
 * reporting concern, not a money gate, so a currency the site has no rate for
 * is stored with base_amount_cents null (see DonationService). That is the
 * right call at the till, and it leaves a real payment outside every total,
 * because the aggregates score a null base as zero.
 *
 * The rate is captured per donation at write time, so configuring the missing
 * currency later leaves the existing rows untouched.
 *
 * Today's rate, not the rate on the day of the donation, which nobody recorded.
 * That is an approximation, and it is the same one the live path makes for
 * every donation it converts.
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
     * @return array{converted:int, plans:int, unconvertible:int, currencies:array<int,string>}
     *   currencies lists what is still missing a rate, so the caller can name it.
     *
     * @since 1.0.0
     */
    public function run(): array
    {
        $base = strtoupper(Money::defaultCurrency());

        $converted     = 0;
        $unconvertible = [];

        // Paged by id rather than loaded in one go. The backlog is unbounded by
        // definition -- it is every donation in a currency nobody had a rate
        // for, which on an imported history can be the whole history -- and
        // this runs inside a REST request.
        $afterId = 0;
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
                    $unconvertible[$currency] = true;
                    continue;
                }

                $donation->base_currency     = $base;
                $donation->fx_rate           = sprintf('%.8F', $rate);
                $donation->base_amount_cents = (int) round((int) $donation->amount_cents * $rate);
                $donation->save();
                $converted++;
            }
        }

        // Recurring plans carry their own base amount, copied from the first
        // donation, and MRR scores a foreign plan with no base as zero.
        $plans = $this->runForPlans($base, $unconvertible);

        return [
            'converted'     => $converted,
            'plans'         => $plans,
            'unconvertible' => count($unconvertible),
            'currencies'    => array_keys($unconvertible),
        ];
    }

    /**
     * @param array<string,bool> $unconvertible collected across both passes
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
                    $unconvertible[$currency] = true;
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
     * What is sitting outside the totals right now, for a screen that wants to
     * say so. Grouped by currency because that is the thing an admin fixes.
     *
     * Scoped to exactly what the totals count, which is what the screen claims
     * is missing from them: donationsOnly() drops test-mode rows and ticket
     * orders, and the status filter drops abandoned checkouts and failed
     * attempts. Those rows have no base amount either, but no total was ever
     * going to include them, so naming them turns a healthy site into an alarm
     * and sends an admin looking for money that was never taken. A pending row
     * that does complete is counted from the moment it does.
     *
     * needs_rate separates the two repairs. A row in the org's own base
     * currency converts at unity and wants nothing configured, so telling its
     * owner to add an exchange rate for their own currency is advice that
     * cannot be followed.
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
