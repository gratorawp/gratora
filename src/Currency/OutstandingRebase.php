<?php

declare(strict_types=1);

namespace Gratora\Currency;

use Gratora\Analytics\ErrorLog;
use Gratora\Donations\Donation;

/**
 * Restates donations that had not settled yet when the org changed its base
 * currency.
 *
 * A pending row was stamped with a base amount under the old base, deliberately
 * without locking the base against a change. Once the gateway confirms it, that
 * figure is counted under the new one, so a GBP 100 donation stamped 12500 (USD)
 * lands in a GBP-based book as GBP 125.
 *
 * @since 1.0.0
 */
final class OutstandingRebase
{
    /** Rows whose money has not moved yet, so their base figure is still notional. */
    private const OUTSTANDING = ['pending', 'processing', 'failed'];

    /** @since 1.0.0 */
    public function __construct(private FxRates $fx = new FxRates())
    {
    }

    /** @since 1.0.0 */
    public function register(): void
    {
        // After FxRatesUpdater::rebase, which runs at 10: the snapshot has to
        // be in the new base before a rate into it can be asked for.
        add_action('gratora.settings.updated', [$this, 'onSettingsUpdated'], 20, 3);
    }

    /**
     * @param array<string,mixed> $next
     * @param array<string,mixed> $previous
     *
     * @since 1.0.0
     */
    public function onSettingsUpdated(string $group, array $next, array $previous = []): void
    {
        if ($group !== 'currency-locale') {
            return;
        }

        // From $next, never Money::defaultCurrency(): that memoises per request
        // and still holds the base being left.
        $to   = strtoupper(trim((string) ($next['default_currency'] ?? '')));
        $from = strtoupper(trim((string) ($previous['default_currency'] ?? '')));

        if ($to === '' || $to === $from || preg_match('/^[A-Z]{3}$/', $to) !== 1) {
            return;
        }

        $this->restate($to);
    }

    /** @since 1.0.0 */
    private function restate(string $to): void
    {
        $stranded = [];

        foreach ($this->outstandingCurrencies() as $code) {
            $rate = $this->fx->rate($code, $to);
            if ($rate === null) {
                $stranded[] = $code;
                continue;
            }

            $factor = sprintf('%.8F', $rate);

            // where, not whereRaw: the builder adds no AND before a raw
            // clause. The column collation is case-insensitive.
            Donation::query()
                ->whereIn('status', self::OUTSTANDING)
                ->where('currency', $code)
                ->updateRaw(
                    "base_currency = '" . esc_sql($to) . "',"
                    . " fx_rate = '{$factor}',"
                    . " base_amount_cents = ROUND(amount_cents * {$factor})"
                );
        }

        if ($stranded !== []) {
            ErrorLog::record(
                'currency.rebase',
                sprintf(
                    'Outstanding donations in %s could not be restated into %s: there is no rate for them, so they still carry the base amount they were stamped with under the previous base currency, and one that settles is counted at that amount.',
                    implode(', ', $stranded),
                    $to
                ),
                ['base' => $to, 'currencies' => $stranded]
            );
        }
    }

    /** @return list<string> */
    private function outstandingCurrencies(): array
    {
        $out = [];

        foreach (Donation::query()
            ->selectRaw('UPPER(currency) AS currency')
            ->whereIn('status', self::OUTSTANDING)
            ->groupByRaw('UPPER(currency)')
            ->getAll() as $row) {
            $code = strtoupper((string) ($row['currency'] ?? ''));
            if ($code !== '') $out[] = $code;
        }

        return $out;
    }
}
