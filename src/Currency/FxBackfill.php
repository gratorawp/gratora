<?php

declare(strict_types=1);

namespace Dono\Currency;

use Dono\Donations\Donation;
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
 * Nothing put those rows right afterwards. The rate is captured per donation at
 * write time, so configuring the missing currency later changed nothing that
 * had already happened, and Recalculate rebuilt the same totals from the same
 * nulls. This is the missing half: once a rate exists, the money joins the
 * numbers.
 *
 * Today's rate, not the rate on the day of the gift, which nobody recorded.
 * That is an approximation, and it is the same one the live path makes for
 * every donation it converts.
 *
 * @version 1.0.0
 */
final class FxBackfill
{
    /** Rows held in memory at once. */
    private const CHUNK = 500;

    public function __construct(private FxRates $fx)
    {
    }

    /**
     * @return array{converted:int, plans:int, unconvertible:int, currencies:array<int,string>}
     *   currencies lists what is still missing a rate, so the caller can name it.
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
        // donation, and MRR scores a foreign plan with no base as zero. Left
        // alone, a rate added today fixed the donation totals while every
        // renewal-driven figure stayed understated until the plan next charged.
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
     * @return array<int,array{currency:string, count:int, amount_cents:int}>
     */
    public static function pending(): array
    {
        // Counted by the database. This runs on every load of the screen, and
        // hydrating a model per stranded donation to add up two numbers is the
        // one shape guaranteed to be slowest exactly where the backlog is
        // largest.
        $rows = Donation::query()
            ->selectRaw('UPPER(currency) AS currency, COUNT(*) AS cnt, COALESCE(SUM(amount_cents), 0) AS total')
            ->whereNull('base_amount_cents')
            ->groupByRaw('UPPER(currency)')
            ->orderByRaw('total DESC')
            ->getAll();

        return array_map(static fn ($r): array => [
            'currency'     => (string) $r['currency'],
            'count'        => (int) $r['cnt'],
            'amount_cents' => (int) $r['total'],
        ], $rows);
    }
}
