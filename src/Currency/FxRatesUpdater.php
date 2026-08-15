<?php

declare(strict_types=1);

namespace Dono\Currency;

use Dono\Analytics\ErrorLog;
use Dono\Async\AsyncDispatcher;
use Dono\Foundation\Helpers\Money;

/**
 * Daily refresh of dono_fx_rates from Frankfurter (ECB reference rates).
 *
 * On any failure the previous snapshot is left intact so conversion never
 * breaks on a bad fetch.
 *
 * @since 1.0.0
 */
final class FxRatesUpdater
{
    public const HOOK = 'dono.cron.fx_rates';
    private const DAILY = 86400;
    private const ENDPOINT = 'https://api.frankfurter.app/latest';

    /** @since 1.0.0 */
    public function __construct(private AsyncDispatcher $async)
    {
    }

    /** @since 1.0.0 */
    public function register(): void
    {
        add_action(self::HOOK, [$this, 'run']);
        add_action('init', fn () => $this->async->scheduleRecurring(self::HOOK, self::DAILY));
        add_action('dono.settings.updated', [$this, 'onSettingsUpdated'], 10, 2);
    }

    /**
     * The org's base currency is the frame every manual override is typed in,
     * so the snapshot follows it the moment it moves.
     *
     * @param array<string,mixed> $next
     *
     * @since 1.0.0
     */
    public function onSettingsUpdated(string $group, array $next): void
    {
        if ($group !== 'currency-locale') {
            return;
        }

        $base = strtoupper(trim((string) ($next['default_currency'] ?? '')));
        if ($base !== '') {
            // From $next, not Money::defaultCurrency(): that memoises per
            // request and still holds the base being left.
            $this->rebase($base);
        }
    }

    /**
     * Scheduled daily run. No-op when the org turned auto-refresh off, and no-op
     * when the site has nothing to convert.
     *
     * @since 1.0.0
     */
    public function run(): void
    {
        if (! (new FxRates())->auto()) {
            return;
        }
        if (! $this->needsRates()) {
            return;
        }
        if (! $this->fetchAndStore()) {
            $this->reportFailure();
        }
    }

    /**
     * A failed fetch is not the same event on day one as on day thirty. The
     * previous snapshot is kept either way, but past FxRates::STAMP_MAX_AGE_DAYS
     * it is being written permanently into every donation's fx_rate, so the log
     * has to say that rather than repeat a line about a retry.
     *
     * @since 1.0.0
     */
    private function reportFailure(): void
    {
        $fx  = new FxRates();
        $age = $fx->ageDays();

        if (! $fx->isUnfitToStamp()) {
            ErrorLog::record('currency.fx', 'Rate fetch failed; keeping the previous snapshot.', [
                'age_days' => $age,
            ]);
            return;
        }

        ErrorLog::record('currency.fx', sprintf(
            'Rate fetch has been failing for long enough that the stored rates are %s days old, and every donation taken in another currency is being converted at them and stamped with that rate for good. Fix the fetch or set the rates by hand on Settings > Currency.',
            $age === null ? 'an unknown number of' : (string) $age
        ), ['age_days' => $age, 'max_age_days' => FxRates::STAMP_MAX_AGE_DAYS]);
    }

    /**
     * Whether any money on this site is in a currency other than the org's.
     *
     * A single-currency site converts nothing, so the daily call to a third
     * party buys it nothing and still has to be disclosed and justified.
     *
     * Two sources, not one. Accepted currencies cover what donors can give
     * next; donations already recorded without a rate cover what is stranded
     * now, and those are not necessarily in a currency the org still accepts.
     *
     * @since 1.0.0
     */
    private function needsRates(): bool
    {
        $base = strtoupper(Money::defaultCurrency());

        $opt       = get_option('dono_currency_locale', []);
        $supported = is_array($opt) ? (array) ($opt['supported_currencies'] ?? []) : [];

        foreach ($supported as $code) {
            if (strtoupper((string) $code) !== $base) {
                return true;
            }
        }

        foreach (FxBackfill::strandedCurrencies() as $code) {
            if ($code !== $base) {
                return true;
            }
        }

        return false;
    }

    /**
     * Manual "Fetch now" from settings: ignores the auto toggle.
     *
     * @since 1.0.0
     */
    public function fetchNow(): bool
    {
        return $this->fetchAndStore();
    }

    /**
     * Persist the auto toggle + manual overrides without refetching.
     *
     * $frame is the base the overrides were composed against - the currency the
     * screen posting them was showing rates in. An override is units per 1 of
     * that currency and nothing on the way in says so, so a write composed
     * before a base change and landing after one reprices every row it carries
     * by the whole bridge, silently and for good. Declared, it can be refused
     * instead. Empty skips the check: with no overrides in the payload there is
     * no number whose frame could be wrong.
     *
     * @param array<string,mixed> $manual
     * @return bool false when the write was refused because $frame is not the
     *              base the snapshot is denominated in now
     *
     * @since 1.0.0
     */
    public function saveSettings(bool $auto, array $manual, string $frame = ''): bool
    {
        $opt = get_option(FxRates::OPTION);
        $opt = is_array($opt) ? $opt : [];
        if (empty($opt['base'])) {
            // Enough shape for FxRates::data() to accept the option, so the
            // overrides being saved here are readable at all. No date: no fetch
            // has happened, and dating the record today would report rates as
            // current on a site that has none.
            $opt['base']  = strtoupper(Money::defaultCurrency());
            $opt['rates'] = is_array($opt['rates'] ?? null) ? $opt['rates'] : [];
        }

        $manual = $this->cleanRates($manual);
        $frame  = strtoupper(trim($frame));
        if ($manual !== []
            && preg_match('/^[A-Z]{3}$/', $frame)
            && $frame !== strtoupper((string) $opt['base'])
        ) {
            ErrorLog::record('currency.fx', sprintf(
                'Hand-set exchange rates entered against %1$s were not saved: the rates on file are denominated in %2$s now. Reload Settings > Currency and enter them again.',
                $frame,
                strtoupper((string) $opt['base'])
            ), ['from' => $frame, 'to' => strtoupper((string) $opt['base'])]);

            return false;
        }

        $opt['auto']   = $auto;
        $opt['manual'] = $manual;
        update_option(FxRates::OPTION, $opt, false);

        return true;
    }

    /**
     * Restate the whole snapshot in $to, so the base it is denominated in is
     * the org's own again.
     *
     * A manual override is typed against the org base, on a screen labelled
     * with it, while the snapshot is denominated in whatever base the last
     * fetch ran against. Those are the same currency until the org base moves
     * with auto-refresh off, and from then on every override is entered in one
     * frame and read in another. The moment of the change is the only moment
     * the answer is not a guess: everything stored is still in the base being
     * left, so dividing the table through by one number restates all of it at
     * once. Reconciling later, on the way out of the option, cannot work at
     * all - the settings screen posts back the number it was shown, so the
     * correction is reapplied to its own output on every save and compounds.
     *
     * Cross rates are unchanged by construction: dividing every entry by the
     * same figure leaves each ratio where it was, which is why this is safe to
     * run over rates already stamped onto donations.
     *
     * @since 1.0.0
     */
    public function rebase(string $to): void
    {
        $to  = strtoupper(trim($to));
        $opt = get_option(FxRates::OPTION);
        if (! is_array($opt) || ! preg_match('/^[A-Z]{3}$/', $to)) {
            return;
        }

        $from = strtoupper(trim((string) ($opt['base'] ?? '')));
        if ($from === $to || ! preg_match('/^[A-Z]{3}$/', $from)) {
            return;
        }

        // Units of $to per 1 $from, an override counting: a base ECB does not
        // publish is exactly the case someone sets by hand.
        $bridge = (new FxRates())->effectiveRates()[$to] ?? null;
        if ($bridge === null || $bridge <= 0.0) {
            // Nothing relates the two bases, so there is no restatement to
            // make. The snapshot is kept whole rather than emptied: its cross
            // rates are still true and the next fetch replaces it outright.
            ErrorLog::record('currency.fx', sprintf(
                'The base currency is now %1$s, but the stored rates are in %2$s and carry no %1$s rate, so they could not be restated. Until a fetch succeeds or you set a rate by hand on Settings > Currency, donations in other currencies record no %1$s value and count as zero in every total.',
                $to,
                $from
            ), ['from' => $from, 'to' => $to]);
            return;
        }

        $rates = $this->cleanRates(is_array($opt['rates'] ?? null) ? $opt['rates'] : []);
        // The base being left is a rate like any other once it stops being one.
        $rates[$from] = 1.0;

        $manual = $this->cleanRates($opt['manual'] ?? []);
        // An override on the base itself is masked by unity while it is the
        // base, so nobody has seen it and it means nothing. Carried across, it
        // goes live and beats the one rate here that is not a guess.
        unset($manual[$from]);

        $opt['base']   = $to;
        $opt['rates']  = $this->restate($rates, $bridge, $to);
        $opt['manual'] = $this->restate($manual, $bridge, $to);

        update_option(FxRates::OPTION, $opt, false);
    }

    /**
     * @param array<string,float> $map
     * @return array<string,float>
     *
     * @since 1.0.0
     */
    private function restate(array $map, float $bridge, string $newBase): array
    {
        $out = [];
        foreach ($map as $ccy => $rate) {
            // The new base is unity and never a row of its own; leaving one
            // behind invites a table showing two currencies both at 1.0000.
            if ($ccy !== $newBase) {
                $out[$ccy] = $rate / $bridge;
            }
        }

        return $out;
    }

    /** @since 1.0.0 */
    private function fetchAndStore(): bool
    {
        $snapshot = $this->fetch(strtoupper(Money::defaultCurrency()));
        if ($snapshot === null) {
            return false;
        }
        $prev = get_option(FxRates::OPTION);
        $prev = is_array($prev) ? $prev : [];
        // Preserve sibling settings the fetch does not own.
        $snapshot['auto'] = array_key_exists('auto', $prev) ? (bool) $prev['auto'] : true;

        $manual   = $this->cleanRates($prev['manual'] ?? []);
        $prevBase = strtoupper(trim((string) ($prev['base'] ?? '')));
        if ($manual !== [] && $prevBase !== '' && $prevBase !== $snapshot['base']) {
            // A fetch sets the base as well as the rates, so carrying overrides
            // across one is a second way into the frame mismatch rebase() exists
            // to prevent - and this one restates nothing, it just relabels. The
            // bases only differ when a restatement already failed, so there is
            // nothing here to convert with: drop them and say which ones.
            ErrorLog::record('currency.fx', sprintf(
                'Exchange rates were refreshed in %1$s, but the hand-set rates on file were entered against %2$s and nothing relates the two, so they have been cleared: %3$s. Set them again on Settings > Currency if you still need them.',
                $snapshot['base'],
                $prevBase,
                implode(', ', array_keys($manual))
            ), ['from' => $prevBase, 'to' => $snapshot['base'], 'cleared' => array_keys($manual)]);
            $manual = [];
        }
        $snapshot['manual'] = $manual;

        update_option(FxRates::OPTION, $snapshot, false);
        return true;
    }

    /**
     * @param array<string,mixed> $raw
     * @return array<string,float>
     *
     * @since 1.0.0
     */
    private function cleanRates(array $raw): array
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
     * @return array{base:string,date:string,fetched_at:string,rates:array<string,float>}|null
     *
     * @since 1.0.0
     */
    private function fetch(string $base): ?array
    {
        $res = wp_remote_get(
            add_query_arg('from', $base, self::ENDPOINT),
            ['timeout' => 15, 'redirection' => 2]
        );

        if (is_wp_error($res) || (int) wp_remote_retrieve_response_code($res) !== 200) {
            return null;
        }

        $body = json_decode((string) wp_remote_retrieve_body($res), true);
        if (! is_array($body)
            || strtoupper((string) ($body['base'] ?? '')) !== $base
            || empty($body['date'])
            || ! is_array($body['rates'] ?? null)
            || $body['rates'] === []
        ) {
            return null;
        }

        $rates = [];
        foreach ($body['rates'] as $ccy => $rate) {
            $ccy  = strtoupper((string) $ccy);
            $rate = (float) $rate;
            if (preg_match('/^[A-Z]{3}$/', $ccy) && $rate > 0.0) {
                $rates[$ccy] = $rate;
            }
        }
        if ($rates === []) {
            return null;
        }

        return [
            'base'       => $base,
            'date'       => (string) $body['date'],
            'fetched_at' => gmdate('c'),
            'rates'      => $rates,
        ];
    }
}
