<?php

declare(strict_types=1);

namespace Dono\Currency;

use Dono\Foundation\Helpers\Money;

/**
 * Read access to the daily FX snapshot stored in the dono_fx_rates option.
 *
 * Option shape: { base, date, fetched_at, rates } - units of CCY per 1 base.
 * Conversion is read-only. FxRatesUpdater owns the writes in product code; the
 * e2e fixture builder writes the option directly too.
 *
 * @since 1.0.0
 */
final class FxRates
{
    public const OPTION = 'dono_fx_rates';

    /**
     * Past this age a snapshot is unfit to be stamped onto money.
     *
     * The rate a donation converts at is written into fx_rate and
     * base_amount_cents and never revisited, so an old rate is not a stale
     * screen, it is a wrong figure in the books for good. The donation is still
     * never refused for it: money is not gated on reporting being configured,
     * and declining to convert would leave base_amount_cents null, which every
     * rollup scores as zero and so understates by the whole donation rather
     * than by the drift. What the bound buys is that the site says so, in the
     * log an owner can actually reach, every day it keeps happening.
     *
     * Seven days: the ECB publishes on TARGET business days, so a snapshot
     * legitimately sits out a weekend, and a weekend either side of a holiday
     * closure reaches four. Seven clears every ordinary gap and still catches a
     * fetch that has genuinely stopped within a week of it stopping.
     */
    public const STAMP_MAX_AGE_DAYS = 7;

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
     * Of the currencies given, the ones with no usable rate to the org's base.
     *
     * A donation in such a currency is still accepted (money is never gated on
     * reporting being configured), but it stores no base_amount_cents and so
     * contributes nothing to any base-currency total. That is invisible unless
     * something says so, which is what this is for: the settings screen warns
     * before an admin offers the currency, and the reports say how many rows
     * are missing.
     *
     * Asked the way the money path asks it - rate(code, org base), the same
     * call DonationService converts with - rather than whether the snapshot
     * carries a row for the code. The two answers only part when the snapshot
     * is denominated in some other base, which is where the warning matters
     * most: every rate in it is then unreachable from the org's own currency,
     * so every foreign donation records nothing, and reading the snapshot's own
     * table would report the whole set as healthy.
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
     * Fetched rates + manual overrides (manual wins) + base at unity.
     *
     * Every value here is denominated in the snapshot's base, an override
     * included. An override is typed against the org's base, on a screen
     * labelled with it, so the two have to be the same currency, and that is
     * kept true at each of the three points the base can move rather than
     * assumed: FxRatesUpdater::rebase() restates the whole snapshot in one
     * step, fetchAndStore() refuses to carry overrides into a base they were
     * not typed against, and saveSettings() refuses a write that declares an
     * older frame. Restating an override here instead would not survive the
     * round trip: the settings screen posts back the number it was shown, so
     * the correction lands again on the value it produced at the next save, and
     * each step is stamped into the fx_rate of every donation taken in between.
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
