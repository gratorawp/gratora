<?php

declare(strict_types=1);

namespace Gratora\Currency;

use Gratora\Foundation\Helpers\Money;

/**
 * Read access to the daily FX snapshot stored in the gratora_fx_rates option.
 *
 * Option shape: { base, date, fetched_at, rates } - units of CCY per 1 base.
 * Conversion is read-only. FxRatesUpdater owns the writes in product code; the
 * e2e fixture builder writes the option directly too.
 *
 * @since 1.0.0
 */
final class FxRates
{
    public const OPTION = 'gratora_fx_rates';

    /**
     * Warn after seven days without a current rate, allowing normal ECB holiday gaps. Keep
     * accepting and converting donations; report stale rates in the log.
     */
    public const STAMP_MAX_AGE_DAYS = 7;

    /**
     * Past this long with no successful fetch, the daily refresh has stopped.
     *
     * Two days rather than seven: this is the fetch's own health, and the fetch
     * runs every day including weekends, so a gap this size is already
     * abnormal.
     *
     * @since 1.0.0
     */
    public const FETCH_MAX_AGE_DAYS = 2;

    /**
     * @return array{base:string,date:string,fetched_at?:string,rates:array<string,mixed>,manual?:array<string,mixed>,auto?:bool}|null
     *
     * @since 1.0.0
     */
    private function data(): ?array
    {
        $opt = get_option(self::OPTION);
        if (! is_array($opt) || empty($opt['base']) || ! is_array($opt['rates'] ?? null)) {
            return null;
        }
        return $opt;
    }

    /** @since 1.0.0 */
    public function base(): ?string
    {
        $d = $this->data();
        return $d ? strtoupper((string) $d['base']) : null;
    }

    /** @since 1.0.0 */
    public function date(): ?string
    {
        $d = $this->data();
        return $d && ! empty($d['date']) ? (string) $d['date'] : null;
    }

    /** @since 1.0.0 */
    public function fetchedAt(): ?string
    {
        $d = $this->data();
        return $d && ! empty($d['fetched_at']) ? (string) $d['fetched_at'] : null;
    }

    /**
     * True when the daily auto-refresh is enabled (default on).
     *
     * @since 1.0.0
     */
    public function auto(): bool
    {
        $d = $this->data();
        return $d ? (bool) ($d['auto'] ?? true) : true;
    }

    /**
     * Hand-entered overrides (units of CCY per 1 base). These win over the
     * fetched rate for that currency.
     *
     * @return array<string,float>
     *
     * @since 1.0.0
     */
    public function manual(): array
    {
        $d = $this->data();
        return $d ? $this->cleanMap($d['manual'] ?? []) : [];
    }

    /**
     * Last fetched rates only, no manual overlay.
     *
     * @return array<string,float>
     *
     * @since 1.0.0
     */
    public function fetchedRates(): array
    {
        $d = $this->data();
        return $d ? $this->cleanMap($d['rates']) : [];
    }

    /**
     * Check rates against the org base, as DonationService does. Snapshot membership alone
     * misses base mismatches; unavailable conversions leave donations outside base-currency
     * totals.
     *
     * @param list<string> $codes
     * @return list<string> upper-cased, in the order given
     *
     * @since 1.0.0
     */
    public function unconvertible(array $codes): array
    {
        $base = strtoupper(Money::defaultCurrency());

        $out = [];
        foreach ($codes as $code) {
            $code = strtoupper(trim((string) $code));
            if ($code === '') continue;
            if ($this->rate($code, $base) === null) {
                $out[] = $code;
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * Units of $code per 1 base, manual override winning. Null if unknown.
     *
     * @since 1.0.0
     */
    public function effectiveRate(string $code): ?float
    {
        $code = strtoupper(trim($code));
        $d = $this->data();
        if (! $d) {
            return null;
        }
        if ($code === strtoupper((string) $d['base'])) {
            return 1.0;
        }
        return $this->effectiveMap($d)[$code] ?? null;
    }

    /**
     * @param array<string,mixed> $raw
     * @return array<string,float>
     *
     * @since 1.0.0
     */
    private function cleanMap(array $raw): array
    {
        $out = [];
        foreach ($raw as $ccy => $r) {
            $ccy = strtoupper((string) $ccy);
            $r   = (float) $r;
            if (preg_match('/^[A-Z]{3}$/', $ccy) && $r > 0.0) {
                $out[$ccy] = $r;
            }
        }
        return $out;
    }

    /**
     * The whole conversion table as it stands: fetched rates, overrides on
     * top, the snapshot base at unity. Denominated in the snapshot base.
     *
     * @return array<string,float>
     *
     * @since 1.0.0
     */
    public function effectiveRates(): array
    {
        $d = $this->data();

        return $d ? $this->effectiveMap($d) : [];
    }

    /**
     * Merge rates in the snapshot base, with manual overrides taking precedence. Rebase and
     * validate overrides on writes; converting on reads would compound adjustments on settings
     * saves.
     *
     * @param array<string,mixed> $d
     * @return array<string,float>
     *
     * @since 1.0.0
     */
    private function effectiveMap(array $d): array
    {
        $map = $this->cleanMap($d['rates']);
        foreach ($this->cleanMap($d['manual'] ?? []) as $ccy => $r) {
            $map[$ccy] = $r;
        }
        // Last, so an override cannot displace unity: a currency is worth one
        // of itself.
        $map[strtoupper((string) $d['base'])] = 1.0;

        return $map;
    }

    /**
     * Units of $to for one unit of $from, or null when either side has no
     * usable rate. The stored base is unity within its own table.
     *
     * @since 1.0.0
     */
    public function rate(string $from, string $to): ?float
    {
        $from = strtoupper(trim($from));
        $to   = strtoupper(trim($to));
        if ($from === '' || $to === '') {
            return null;
        }
        if ($from === $to) {
            return 1.0;
        }

        $d = $this->data();
        if (! $d) {
            return null;
        }

        $rates = $this->effectiveMap($d);

        $rf = $rates[$from] ?? null;
        $rt = $rates[$to] ?? null;
        if ($rf === null || $rt === null || $rf <= 0.0) {
            return null;
        }

        return $rt / $rf;
    }

    /**
     * Converts integer minor units. Null when no rate is available.
     *
     * @since 1.0.0
     */
    public function convertCents(int $cents, string $from, string $to): ?int
    {
        $rate = $this->rate($from, $to);
        if ($rate === null) {
            return null;
        }
        return (int) round($cents * $rate);
    }

    /**
     * How old the snapshot the site is converting with is, in whole days. Null
     * when there is no snapshot or its date is unreadable.
     *
     * @since 1.0.0
     */
    public function ageDays(): ?int
    {
        $ts = $this->dateTimestamp();
        if ($ts === null) {
            return null;
        }
        return (int) floor(max(0, time() - $ts) / DAY_IN_SECONDS);
    }

    /**
     * True when there is no snapshot or it is older than $maxAgeDays.
     *
     * @since 1.0.0
     */
    public function isStale(int $maxAgeDays = 2): bool
    {
        $ts = $this->dateTimestamp();
        if ($ts === null) {
            return true;
        }
        return (time() - $ts) > $maxAgeDays * DAY_IN_SECONDS;
    }

    /**
     * True once the snapshot is too old to keep stamping onto money. See
     * STAMP_MAX_AGE_DAYS.
     *
     * @since 1.0.0
     */
    public function isUnfitToStamp(): bool
    {
        return $this->isStale(self::STAMP_MAX_AGE_DAYS);
    }

    /**
     * Measure refresh health by fetched_at; the ECB publication date legitimately lags over
     * weekends and holidays.
     *
     * @since 1.0.0
     */
    public function fetchHasStopped(): bool
    {
        $at = $this->fetchedAt();
        $ts = $at === null ? false : strtotime($at);

        // Not a dead sentinel: saveSettings() deliberately mints a record with
        // neither date nor fetched_at, because dating it today would report
        // rates as current on a site that has none.
        if ($ts === false) {
            return true;
        }

        return (time() - $ts) > self::FETCH_MAX_AGE_DAYS * DAY_IN_SECONDS;
    }

    /** @since 1.0.0 */
    private function dateTimestamp(): ?int
    {
        $d = $this->data();
        if (! $d || empty($d['date'])) {
            return null;
        }
        $ts = strtotime((string) $d['date'] . ' 00:00:00 UTC');

        return $ts === false ? null : $ts;
    }
}
