<?php

declare(strict_types=1);

namespace Dono\Currency;

/**
 * Read access to the daily FX snapshot stored in the dono_fx_rates option.
 *
 * Option shape: { base, date, fetched_at, rates } - units of CCY per 1 base.
 * Conversion is read-only; FxRatesUpdater is the only writer.
 *
 * @since 1.0.0
 */
final class FxRates
{
    public const OPTION = 'dono_fx_rates';

    /**
     * @return array{base:string,date:string,rates:array<string,mixed>,manual?:array<string,mixed>,auto?:bool}|null
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
     * Of the currencies given, the ones with no usable rate to the base.
     *
     * A donation in such a currency is still accepted (money is never gated on
     * reporting being configured), but it stores no base_amount_cents and so
     * contributes nothing to any base-currency total. That is invisible unless
     * something says so, which is what this is for: the settings screen warns
     * before an admin offers the currency, and the reports say how many rows
     * are missing.
     *
     * @param list<string> $codes
     * @return list<string> upper-cased, in the order given
     *
     * @since 1.0.0
     */
    public function unconvertible(array $codes): array
    {
        $out = [];
        foreach ($codes as $code) {
            $code = strtoupper(trim((string) $code));
            if ($code === '') continue;
            if ($this->effectiveRate($code) === null) {
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
     * Fetched rates + manual overrides (manual wins) + base at unity.
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
     * True when there is no snapshot or it is older than $maxAgeDays.
     *
     * @since 1.0.0
     */
    public function isStale(int $maxAgeDays = 2): bool
    {
        $d = $this->data();
        if (! $d || empty($d['date'])) {
            return true;
        }
        $ts = strtotime((string) $d['date'] . ' 00:00:00 UTC');
        if ($ts === false) {
            return true;
        }
        return (time() - $ts) > $maxAgeDays * DAY_IN_SECONDS;
    }
}
