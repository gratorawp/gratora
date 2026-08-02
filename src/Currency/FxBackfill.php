<?php

declare(strict_types=1);

namespace Dono\Currency;

use Dono\Donations\Donation;
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
    public function __construct(private FxRates $fx)
    {
    }

    /**
     * @return array{converted:int, unconvertible:int, currencies:array<int,string>}
     *   currencies lists what is still missing a rate, so the caller can name it.
     */
    public function run(): array
    {
        $base = strtoupper(Money::defaultCurrency());

        $rows = Donation::query()
            ->whereNull('base_amount_cents')
            ->getAll();

        $converted     = 0;
        $unconvertible = [];

        foreach ($rows as $donation) {
            $currency = strtoupper((string) $donation->currency);
            if ($currency === '') {
                continue;
            }

            if ($currency === $base) {
                $rate = 1.0;
            } else {
                $rate = $this->fx->rate($currency, $base);
            }

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

        return [
            'converted'     => $converted,
            'unconvertible' => count($unconvertible),
            'currencies'    => array_keys($unconvertible),
        ];
    }

    /**
     * What is sitting outside the totals right now, for a screen that wants to
     * say so. Grouped by currency because that is the thing an admin fixes.
     *
     * @return array<int,array{currency:string, count:int, amount_cents:int}>
     */
    public static function pending(): array
    {
        $rows = Donation::query()
            ->whereNull('base_amount_cents')
            ->getAll();

        $out = [];
        foreach ($rows as $donation) {
            $currency = strtoupper((string) $donation->currency);
            if (! isset($out[$currency])) {
                $out[$currency] = ['currency' => $currency, 'count' => 0, 'amount_cents' => 0];
            }
            $out[$currency]['count']++;
            $out[$currency]['amount_cents'] += (int) $donation->amount_cents;
        }

        return array_values($out);
    }
}
