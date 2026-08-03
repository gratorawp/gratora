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
 * @version 1.0.0
 */
final class FxRatesUpdater
{
    public const HOOK = 'dono.cron.fx_rates';
    private const DAILY = 86400;
    private const ENDPOINT = 'https://api.frankfurter.app/latest';

    public function __construct(private AsyncDispatcher $async)
    {
    }

    public function register(): void
    {
        add_action(self::HOOK, [$this, 'run']);
        add_action('init', fn () => $this->async->scheduleRecurring(self::HOOK, self::DAILY));
    }

    /** Scheduled daily run. No-op when the org turned auto-refresh off. */
    public function run(): void
    {
        if (! (new FxRates())->auto()) {
            return;
        }
        if (! $this->fetchAndStore()) {
            ErrorLog::record('currency.fx', 'Rate fetch failed; keeping the previous snapshot.');
        }
    }

    /** Manual "Fetch now" from settings: ignores the auto toggle. */
    public function fetchNow(): bool
    {
        return $this->fetchAndStore();
    }

    /**
     * Persist the auto toggle + manual overrides without refetching.
     *
     * @param array<string,mixed> $manual
     */
    public function saveSettings(bool $auto, array $manual): void
    {
        $opt = get_option(FxRates::OPTION);
        $opt = is_array($opt) ? $opt : [];
        if (empty($opt['base'])) {
            $opt['base']  = strtoupper(Money::defaultCurrency());
            $opt['rates'] = is_array($opt['rates'] ?? null) ? $opt['rates'] : [];
            $opt['date']  = (string) ($opt['date'] ?? gmdate('Y-m-d'));
        }
        $opt['auto']   = $auto;
        $opt['manual'] = $this->cleanManual($manual);
        update_option(FxRates::OPTION, $opt, false);
    }

    private function fetchAndStore(): bool
    {
        $snapshot = $this->fetch(strtoupper(Money::defaultCurrency()));
        if ($snapshot === null) {
            return false;
        }
        $prev = get_option(FxRates::OPTION);
        $prev = is_array($prev) ? $prev : [];
        // Preserve sibling settings the fetch does not own.
        $snapshot['manual'] = $this->cleanManual($prev['manual'] ?? []);
        $snapshot['auto']   = array_key_exists('auto', $prev) ? (bool) $prev['auto'] : true;
        update_option(FxRates::OPTION, $snapshot, false);
        return true;
    }

    /**
     * @param array<string,mixed> $raw
     * @return array<string,float>
     */
    private function cleanManual(array $raw): array
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
