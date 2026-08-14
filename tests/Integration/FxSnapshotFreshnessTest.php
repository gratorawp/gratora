<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Analytics\Event;
use Dono\Async\AsyncDispatcher;
use Dono\Currency\FxRates;
use Dono\Currency\FxRatesUpdater;
use WP_Error;
use WP_REST_Request;

/**
 * Two claims the currency screen makes about how current the rates are.
 *
 * A snapshot's date is the day a fetch returned rates. Nothing else may write
 * it, because the date is the only thing standing between an admin and the
 * assumption that the figures in front of them are today's.
 *
 * And a rate does not merely go stale on screen: it is written into every
 * donation's fx_rate and base_amount_cents and never revisited, so once the
 * fetch has been failing long enough, the log has to say that rather than
 * repeat a line about a retry.
 */
final class FxSnapshotFreshnessTest extends IntegrationTestCase
{
    private function updater(): FxRatesUpdater
    {
        return new FxRatesUpdater(new AsyncDispatcher());
    }

    /** @return list<string> messages of every error.currency.fx event recorded */
    private function fxErrors(): array
    {
        $out = [];
        foreach (Event::query()->where('type', 'error.currency.fx')->getAll() as $row) {
            $payload = is_array($row->payload) ? $row->payload : (array) json_decode((string) $row->payload, true);
            $out[]   = (string) ($payload['message'] ?? '');
        }

        return $out;
    }

    public function test_saving_settings_on_a_never_fetched_site_mints_no_date(): void
    {
        delete_option(FxRates::OPTION);

        $this->updater()->saveSettings(true, ['GBP' => 0.79]);

        $fx = new FxRates();
        $this->assertNull($fx->date(), 'no fetch has happened, so there is no date to report');
        $this->assertTrue($fx->isStale(), 'the screen must not call rates current when none were fetched');
        $this->assertSame(0.79, $fx->effectiveRate('GBP'), 'the override just saved is still readable');
    }

    public function test_a_real_fetch_still_dates_the_snapshot(): void
    {
        delete_option(FxRates::OPTION);

        $snap = fn () => ['response' => ['code' => 200], 'body' => (string) wp_json_encode([
            'base' => 'USD', 'date' => gmdate('Y-m-d'), 'rates' => ['GBP' => 0.79],
        ])];
        add_filter('pre_http_request', $snap, 10, 3);
        $this->assertTrue($this->updater()->fetchNow());
        remove_filter('pre_http_request', $snap, 10);

        $fx = new FxRates();
        $this->assertSame(gmdate('Y-m-d'), $fx->date());
        $this->assertFalse($fx->isStale());
    }

    public function test_the_panel_reports_no_date_rather_than_today(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        delete_option(FxRates::OPTION);
        $this->updater()->saveSettings(true, []);

        $state = (array) rest_do_request(new WP_REST_Request('GET', '/dono/v1/admin/currency/fx'))->get_data();

        $this->assertNull($state['date']);
        $this->assertTrue($state['stale']);
    }

    public function test_a_briefly_failing_fetch_logs_the_ordinary_line(): void
    {
        update_option(FxRates::OPTION, [
            'base'  => 'USD',
            'date'  => gmdate('Y-m-d', time() - 3 * DAY_IN_SECONDS),
            'auto'  => true,
            'rates' => ['EUR' => 0.9],
        ], false);

        $this->runFailingFetch();

        $messages = $this->fxErrors();
        $this->assertCount(1, $messages);
        $this->assertStringContainsString('keeping the previous snapshot', $messages[0]);
    }

    public function test_past_the_stamping_bound_the_log_says_what_it_costs(): void
    {
        $age = FxRates::STAMP_MAX_AGE_DAYS + 5;
        update_option(FxRates::OPTION, [
            'base'  => 'USD',
            'date'  => gmdate('Y-m-d', time() - $age * DAY_IN_SECONDS),
            'auto'  => true,
            'rates' => ['EUR' => 0.9],
        ], false);

        $this->runFailingFetch();

        $messages = $this->fxErrors();
        $this->assertCount(1, $messages);
        $this->assertStringContainsString(sprintf('%d days old', $age), $messages[0]);
        $this->assertStringContainsString('stamped with that rate for good', $messages[0]);
    }

    public function test_the_snapshot_survives_either_way(): void
    {
        update_option(FxRates::OPTION, [
            'base'  => 'USD',
            'date'  => gmdate('Y-m-d', time() - 90 * DAY_IN_SECONDS),
            'auto'  => true,
            'rates' => ['EUR' => 0.9],
        ], false);

        $this->runFailingFetch();

        $this->assertSame(0.9, (new FxRates())->effectiveRate('EUR'), 'an old rate still beats no rate');
    }

    private function runFailingFetch(): void
    {
        // A second supported currency, or the daily run declines to fetch at all.
        update_option('dono_currency_locale', [
            'default_currency'     => 'USD',
            'supported_currencies' => ['USD', 'EUR'],
        ]);

        $fail = fn () => new WP_Error('http_request_failed', 'down');
        add_filter('pre_http_request', $fail, 10, 3);
        $this->updater()->run();
        remove_filter('pre_http_request', $fail, 10);
    }
}
