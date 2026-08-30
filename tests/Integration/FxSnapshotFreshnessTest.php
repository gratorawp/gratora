<?php

declare(strict_types=1);

namespace FundKit\Tests\Integration;

use FundKit\Analytics\Event;
use FundKit\Async\AsyncDispatcher;
use FundKit\Currency\FxRates;
use FundKit\Currency\FxRatesUpdater;
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

        $state = (array) rest_do_request(new WP_REST_Request('GET', '/fundkit/v1/admin/currency/fx'))->get_data();

        $this->assertNull($state['date']);
        $this->assertTrue($state['stale']);
    }

    /**
     * The ECB publishes only on TARGET business days, so a healthy snapshot
     * carries Friday's date until Monday's publication: three days, every
     * single week, and five over a holiday closure.
     *
     * The currency screen's pill was isStale(), which measures that publication
     * date, so it went amber every weekend. The one FX health signal in the
     * admin was noise, and a refresh that had genuinely stopped looked exactly
     * like a Sunday.
     */
    public function test_a_healthy_weekend_snapshot_is_not_reported_stale(): void
    {
        update_option(FxRates::OPTION, [
            'base'       => 'USD',
            // Friday's publication, read on Sunday.
            'date'       => gmdate('Y-m-d', time() - 3 * DAY_IN_SECONDS),
            // The cron ran this morning, as it does every day.
            'fetched_at' => gmdate('Y-m-d H:i:s', time() - HOUR_IN_SECONDS),
            'auto'       => true,
            'rates'      => ['EUR' => 0.9],
        ], false);

        $fx = \FundKit\Foundation\Plugin::instance()->container->get(FxRates::class);

        $this->assertFalse($fx->fetchHasStopped(), 'the fetch ran an hour ago');
        $this->assertFalse(
            $fx->fetchHasStopped() || $fx->isUnfitToStamp(),
            'so the screen has nothing to tell the admin to act on'
        );
    }

    /**
     * The pill the admin actually sees, not just the predicate behind it.
     *
     * Pinning only fetchHasStopped() left the controller free to go on asking
     * isStale(), which is the question that cried wolf: the fix would have
     * looked done while the screen behaved exactly as before.
     */
    public function test_the_currency_screen_is_quiet_on_a_healthy_weekend(): void
    {
        update_option(FxRates::OPTION, [
            'base'       => 'USD',
            'date'       => gmdate('Y-m-d', time() - 3 * DAY_IN_SECONDS),
            'fetched_at' => gmdate('Y-m-d H:i:s', time() - HOUR_IN_SECONDS),
            'auto'       => true,
            'rates'      => ['EUR' => 0.9],
        ], false);

        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        $res = rest_do_request(new \WP_REST_Request('GET', '/fundkit/v1/admin/currency/fx'));
        $this->assertSame(200, $res->get_status());

        $this->assertFalse(
            (bool) ((array) $res->get_data())['stale'],
            'Friday\'s publication read on Sunday is a healthy site, not a warning'
        );
    }

    /** And it does say so when the refresh has genuinely stopped. */
    public function test_the_currency_screen_reports_a_stopped_refresh(): void
    {
        update_option(FxRates::OPTION, [
            'base'       => 'USD',
            'date'       => gmdate('Y-m-d', time() - 5 * DAY_IN_SECONDS),
            'fetched_at' => gmdate('Y-m-d H:i:s', time() - 5 * DAY_IN_SECONDS),
            'auto'       => true,
            'rates'      => ['EUR' => 0.9],
        ], false);

        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        $res = rest_do_request(new \WP_REST_Request('GET', '/fundkit/v1/admin/currency/fx'));

        $this->assertTrue((bool) ((array) $res->get_data())['stale']);
    }

    /** And a refresh that really has stopped is still reported. */
    public function test_a_refresh_that_stopped_is_reported(): void
    {
        update_option(FxRates::OPTION, [
            'base'       => 'USD',
            'date'       => gmdate('Y-m-d', time() - 4 * DAY_IN_SECONDS),
            'fetched_at' => gmdate('Y-m-d H:i:s', time() - 4 * DAY_IN_SECONDS),
            'auto'       => true,
            'rates'      => ['EUR' => 0.9],
        ], false);

        $this->assertTrue(
            \FundKit\Foundation\Plugin::instance()->container->get(FxRates::class)->fetchHasStopped(),
            'four days with no successful fetch is the thing worth saying'
        );
    }

    /** A site that has never fetched has nothing on file at all. */
    public function test_a_site_that_never_fetched_is_reported(): void
    {
        update_option(FxRates::OPTION, ['base' => 'USD', 'auto' => true, 'rates' => []], false);

        $this->assertTrue(
            \FundKit\Foundation\Plugin::instance()->container->get(FxRates::class)->fetchHasStopped(),
            'no fetched_at is not a fresh fetch'
        );
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
        update_option('fundkit_currency_locale', [
            'default_currency'     => 'USD',
            'supported_currencies' => ['USD', 'EUR'],
        ]);

        $fail = fn () => new WP_Error('http_request_failed', 'down');
        add_filter('pre_http_request', $fail, 10, 3);
        $this->updater()->run();
        remove_filter('pre_http_request', $fail, 10);
    }
}
