<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Async\AsyncDispatcher;
use Dono\Currency\FxRates;
use Dono\Currency\FxRatesUpdater;
use Dono\Foundation\Helpers\Money;
use Dono\Settings\SettingsService;
use WP_Error;
use WP_REST_Request;

final class CurrencyFxTest extends IntegrationTestCase
{
    private function seedRates(string $base, array $rates): void
    {
        update_option(FxRates::OPTION, [
            'base'       => $base,
            'date'       => gmdate('Y-m-d'),
            'fetched_at' => gmdate('c'),
            'rates'      => $rates,
        ], false);
    }

    private function postDonation(array $body): \WP_REST_Response
    {
        $req = new WP_REST_Request('POST', '/dono/v1/donations');
        $req->set_header('content-type', 'application/json');
        $req->set_body(json_encode($body));
        return rest_do_request($req);
    }

    private function confirm(string $reference): void
    {
        $req = new WP_REST_Request('POST', "/dono/v1/donations/{$reference}/confirm");
        $req->set_header('content-type', 'application/json');
        $req->set_body('{}');
        $res = rest_do_request($req);
        $this->assertSame(200, $res->get_status(), 'confirm: ' . json_encode($res->get_data()));
    }

    private function makeDefaultFund(): \Dono\Funds\Fund
    {
        $f = \Dono\Funds\Fund::make();
        $f->code       = 'genfx';
        $f->name       = 'General FX';
        $f->is_active  = true;
        $f->is_default = true;
        $f->created_at = gmdate('Y-m-d H:i:s');
        $f->updated_at = $f->created_at;
        $f->save();
        return $f;
    }

    public function test_fxrates_conversion_and_staleness(): void
    {
        $this->seedRates('EUR', ['USD' => 1.0843, 'GBP' => 0.8521]);
        $fx = new FxRates();

        $this->assertSame(1.0, $fx->rate('USD', 'USD'));
        $this->assertNull($fx->rate('USD', 'JPY'), 'No rate for absent currency');
        $this->assertEqualsWithDelta(1 / 1.0843, $fx->rate('USD', 'EUR'), 1e-9);
        $this->assertSame(
            (int) round(10000 * (1 / 1.0843)),
            $fx->convertCents(10000, 'USD', 'EUR')
        );
        $this->assertFalse($fx->isStale());

        update_option(FxRates::OPTION, [
            'base' => 'EUR', 'date' => gmdate('Y-m-d', time() - 5 * 86400),
            'rates' => ['USD' => 1.1],
        ], false);
        $this->assertTrue((new FxRates())->isStale(), 'Snapshot older than 2 days is stale');

        delete_option(FxRates::OPTION);
        $this->assertNull((new FxRates())->rate('USD', 'EUR'));
        $this->assertTrue((new FxRates())->isStale());
    }

    public function test_donation_snapshots_base_amount(): void
    {
        $base  = Money::defaultCurrency();
        $other = $base === 'USD' ? 'GBP' : 'USD';
        $this->seedRates($base, [$other => 1.25]); // other -> base = 1 / 1.25 = 0.8

        $ref = $this->postDonation([
            'email' => 'fx@x.com', 'amount_cents' => 10000, 'currency' => $other, 'gateway' => 'offline',
        ])->get_data()['reference'];

        $row = self::$wpdb->get_row(self::$wpdb->prepare(
            "SELECT base_amount_cents, base_currency, fx_rate FROM " . self::$prefix . "dono_donations WHERE reference = %s",
            $ref
        ));
        $this->assertSame($base, $row->base_currency);
        $this->assertSame(8000, (int) $row->base_amount_cents);
        $this->assertNotNull($row->fx_rate);
    }

    public function test_same_currency_is_one_to_one(): void
    {
        $base = Money::defaultCurrency();
        $this->seedRates($base, ['USD' => 1.1]);

        $ref = $this->postDonation([
            'email' => 'fx2@x.com', 'amount_cents' => 5000, 'currency' => $base, 'gateway' => 'offline',
        ])->get_data()['reference'];

        $row = self::$wpdb->get_row(self::$wpdb->prepare(
            "SELECT base_amount_cents, base_currency FROM " . self::$prefix . "dono_donations WHERE reference = %s",
            $ref
        ));
        $this->assertSame(5000, (int) $row->base_amount_cents);
        $this->assertSame($base, $row->base_currency);
    }

    public function test_no_rate_does_not_block_the_donation(): void
    {
        delete_option(FxRates::OPTION);
        $base  = Money::defaultCurrency();
        $other = $base === 'USD' ? 'GBP' : 'USD';

        $res = $this->postDonation([
            'email' => 'fx3@x.com', 'amount_cents' => 7000, 'currency' => $other, 'gateway' => 'offline',
        ]);
        $this->assertSame(201, $res->get_status());

        $row = self::$wpdb->get_row(self::$wpdb->prepare(
            "SELECT base_amount_cents, base_currency FROM " . self::$prefix . "dono_donations WHERE reference = %s",
            $res->get_data()['reference']
        ));
        $this->assertNull($row->base_amount_cents);
        $this->assertNull($row->base_currency);
    }

    public function test_mixed_currency_fund_total_uses_base_amounts(): void
    {
        $base  = Money::defaultCurrency();
        $other = $base === 'USD' ? 'GBP' : 'USD';
        $this->seedRates($base, [$other => 2.0]); // other -> base = 0.5

        $fund = $this->makeDefaultFund();

        $r1 = $this->postDonation([
            'email' => 'a@x.com', 'amount_cents' => 5000, 'currency' => $base, 'gateway' => 'offline',
        ])->get_data()['reference'];
        $this->confirm($r1);

        $r2 = $this->postDonation([
            'email' => 'b@x.com', 'amount_cents' => 4000, 'currency' => $other, 'gateway' => 'offline',
        ])->get_data()['reference'];
        $this->confirm($r2);

        $raised = (int) self::$wpdb->get_var(self::$wpdb->prepare(
            "SELECT raised_cents FROM " . self::$prefix . "dono_funds WHERE id = %d",
            $fund->id
        ));
        // 5000 base + 4000 other*0.5 (2000 base) = 7000, not the raw 9000.
        $this->assertSame(7000, $raised);
    }

    public function test_updater_is_idempotent_and_keeps_last_good_on_failure(): void
    {
        $async = new AsyncDispatcher();
        $async->scheduleRecurring(FxRatesUpdater::HOOK, 86400);
        $async->scheduleRecurring(FxRatesUpdater::HOOK, 86400);
        if (function_exists('as_has_scheduled_action')) {
            $this->assertTrue(as_has_scheduled_action(FxRatesUpdater::HOOK));
        }

        $this->seedRates('EUR', ['USD' => 1.0]);
        $fail = fn () => new WP_Error('http_request_failed', 'down');
        add_filter('pre_http_request', $fail, 10, 3);
        (new FxRatesUpdater($async))->run();
        remove_filter('pre_http_request', $fail, 10);

        $opt = get_option(FxRates::OPTION);
        $this->assertSame('EUR', $opt['base'], 'Failed fetch must not overwrite the snapshot');
        $this->assertSame(['USD' => 1.0], $opt['rates']);
    }

    public function test_manual_override_wins_over_fetched_rate(): void
    {
        $this->seedRates('EUR', ['USD' => 1.10]);
        (new FxRatesUpdater(new AsyncDispatcher()))->saveSettings(true, ['USD' => 2.0]);

        $fx = new FxRates();
        $this->assertSame(2.0, $fx->effectiveRate('USD'));
        $this->assertSame(1.10, $fx->fetchedRates()['USD']);
        $this->assertSame(['USD' => 2.0], $fx->manual());
        $this->assertTrue($fx->auto());
        // rate(USD -> EUR) uses the effective (manual) rate: 1 / 2.0.
        $this->assertEqualsWithDelta(0.5, $fx->rate('USD', 'EUR'), 1e-9);
    }

    public function test_auto_off_skips_scheduled_run_but_fetchnow_forces(): void
    {
        $base = Money::defaultCurrency();
        $this->seedRates($base, ['USD' => 1.0]);
        $updater = new FxRatesUpdater(new AsyncDispatcher());
        $updater->saveSettings(false, ['USD' => 1.5]);

        $snap = fn () => ['response' => ['code' => 200], 'body' => json_encode([
            'base' => $base, 'date' => '2026-05-17', 'rates' => ['USD' => 9.9],
        ])];

        add_filter('pre_http_request', $snap, 10, 3);
        $updater->run(); // auto is off -> must skip the fetch entirely
        remove_filter('pre_http_request', $snap, 10);

        $fx = new FxRates();
        $this->assertFalse($fx->auto());
        $this->assertSame(['USD' => 1.5], $fx->manual());
        $this->assertSame(1.0, $fx->fetchedRates()['USD'], 'run() skipped: fetched rates untouched');

        add_filter('pre_http_request', $snap, 10, 3);
        $this->assertTrue($updater->fetchNow(), 'fetchNow forces even with auto off');
        remove_filter('pre_http_request', $snap, 10);

        $fx2 = new FxRates();
        $this->assertSame(9.9, $fx2->fetchedRates()['USD']);
        $this->assertSame(['USD' => 1.5], $fx2->manual(), 'manual preserved across fetchNow');
        $this->assertFalse($fx2->auto(), 'auto flag preserved across fetchNow');
    }

    public function test_fx_controller_get_put_clear_and_fetch(): void
    {
        $base  = Money::defaultCurrency();
        $other = $base === 'USD' ? 'GBP' : 'USD';
        (new SettingsService())->update('currency-locale', [
            'default_currency'     => $base,
            'supported_currencies' => [$base, $other],
        ]);
        $this->seedRates($base, [$other => 1.2]);

        $state = $this->fxGet();
        $this->assertSame($base, $state['base']);
        $this->assertSame($base, $state['rows'][0]['code'], 'base currency is first');
        $this->assertContains($other, array_column($state['rows'], 'code'));

        $state = $this->fxPut(['auto' => false, 'manual' => [$other => 1.55]]);
        $this->assertFalse($state['auto']);
        $row = $this->row($state, $other);
        $this->assertTrue($row['is_manual']);
        $this->assertSame(1.55, $row['rate']);

        $state = $this->fxPut(['auto' => false, 'manual' => [$other => null]]);
        $this->assertFalse($this->row($state, $other)['is_manual'], 'null clears the override');

        $snap = fn () => ['response' => ['code' => 200], 'body' => json_encode([
            'base' => $base, 'date' => '2026-05-17', 'rates' => [$other => 1.33],
        ])];
        add_filter('pre_http_request', $snap, 10, 3);
        $res = $this->fxFetch();
        remove_filter('pre_http_request', $snap, 10);
        $this->assertTrue($res['fetch_ok']);
        $this->assertSame(1.33, $this->row($res, $other)['rate']);
    }

    private function row(array $state, string $code): array
    {
        foreach ($state['rows'] as $r) {
            if ($r['code'] === $code) {
                return $r;
            }
        }
        $this->fail("row {$code} not found");
    }

    private function fxGet(): array
    {
        return rest_do_request(new WP_REST_Request('GET', '/dono/v1/admin/currency/fx'))->get_data();
    }

    private function fxPut(array $body): array
    {
        $r = new WP_REST_Request('PUT', '/dono/v1/admin/currency/fx');
        $r->set_header('content-type', 'application/json');
        $r->set_body(json_encode($body));
        return rest_do_request($r)->get_data();
    }

    private function fxFetch(): array
    {
        return rest_do_request(new WP_REST_Request('POST', '/dono/v1/admin/currency/fx/fetch'))->get_data();
    }
}
