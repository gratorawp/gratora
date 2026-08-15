<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Analytics\ErrorLog;
use Dono\Analytics\Event;
use Dono\Async\AsyncDispatcher;
use Dono\Currency\FxRates;
use Dono\Currency\FxRatesUpdater;
use Dono\Foundation\Helpers\Money;
use Dono\Foundation\Plugin;
use Dono\Settings\SettingsService;
use WP_REST_Request;

/**
 * A manual exchange rate is typed against the org's base currency: the settings
 * screen labels the column "1 <org base> =" and the card says the amounts below
 * are what one org-base unit is worth. The stored snapshot is denominated in
 * whatever base the last fetch ran against, and the two part company whenever
 * the org base moves while auto-refresh is off.
 *
 * Read in the snapshot's frame, an override books every donation in that
 * currency at the wrong value and writes the wrong rate into fx_rate for good.
 * Reconciled on the way out of the option instead, it is worse: the settings
 * screen posts back the number it was shown, so the correction lands on its own
 * output at the next save and the override decays by the bridge on every save
 * of the panel, whether or not anyone touched that row.
 *
 * So the frames are made to agree at the base change, where nothing has to be
 * guessed, and the read path is left an identity.
 */
final class FxManualOverrideFrameTest extends IntegrationTestCase
{
    /** Units of the key currency per 1 unit of $base. */
    private function seed(string $base, array $rates, array $manual = []): void
    {
        update_option(FxRates::OPTION, [
            'base'       => $base,
            'date'       => gmdate('Y-m-d'),
            'fetched_at' => gmdate('c'),
            'rates'      => $rates,
            'manual'     => $manual,
            'auto'       => false,
        ], false);
    }

    /** Exactly what the Currency panel posts: auto off, the manual column. */
    private function saveManual(array $manual): void
    {
        (new FxRatesUpdater(new AsyncDispatcher()))->saveSettings(false, $manual);
    }

    private function moveBaseTo(string $code): void
    {
        Plugin::instance()->container->get(SettingsService::class)
            ->update('currency-locale', ['default_currency' => $code]);
    }

    /** A successful daily fetch, denominated in $base. */
    private function fetchReturning(string $base, array $rates): bool
    {
        $snap = fn () => ['response' => ['code' => 200], 'body' => json_encode([
            'base' => $base, 'date' => gmdate('Y-m-d'), 'rates' => $rates,
        ])];

        add_filter('pre_http_request', $snap, 10, 3);
        try {
            return (new FxRatesUpdater(new AsyncDispatcher()))->fetchNow();
        } finally {
            remove_filter('pre_http_request', $snap, 10);
        }
    }

    /** @return list<Event> */
    private function fxErrors(): array
    {
        return Event::query()->whereLike('type', ErrorLog::PREFIX . 'currency.fx')->getAll();
    }

    public function test_the_base_change_restates_the_snapshot_into_the_new_base(): void
    {
        // A snapshot fetched while the org reported in EUR: 1 EUR buys 1.0843
        // USD and 0.8521 GBP.
        $this->seed('EUR', ['USD' => 1.0843, 'GBP' => 0.8521]);

        $this->moveBaseTo('USD');

        $fx = new FxRates();

        $this->assertSame('USD', $fx->base(), 'the snapshot follows the org base');
        $this->assertEqualsWithDelta(0.8521 / 1.0843, $fx->effectiveRate('GBP'), 1e-12);
        $this->assertEqualsWithDelta(1 / 1.0843, $fx->effectiveRate('EUR'), 1e-12);
        $this->assertSame(1.0, $fx->effectiveRate('USD'));

        // Restating divides every entry by the same figure, so no cross rate
        // moves and no rate already stamped onto a donation is contradicted.
        $this->assertEqualsWithDelta(0.8521, $fx->rate('EUR', 'GBP'), 1e-12);
        $this->assertEqualsWithDelta(0.8521 / 1.0843, $fx->rate('USD', 'GBP'), 1e-12);
    }

    public function test_an_override_typed_after_the_change_is_read_as_typed(): void
    {
        $this->seed('EUR', ['USD' => 1.0843, 'GBP' => 0.8521]);
        $this->moveBaseTo('USD');

        // The admin reads "1 USD =" above the GBP row and corrects it.
        $this->saveManual(['GBP' => 0.86]);

        $fx = new FxRates();

        $this->assertSame(0.86, $fx->effectiveRate('GBP'), 'read back in the frame it was typed in');
        $this->assertEqualsWithDelta(1 / 0.86, $fx->rate('GBP', 'USD'), 1e-12);
        $this->assertSame(11628, $fx->convertCents(10000, 'GBP', 'USD'));
    }

    public function test_saving_the_panel_again_does_not_move_the_override(): void
    {
        // Bases apart and no way to bring them together, which is the one state
        // where a reconciliation on read would have anything to do.
        $this->seed('EUR', ['USD' => 1.0843, 'GBP' => 0.8521]);

        $this->saveManual(['GBP' => 0.86]);

        // The panel renders the input from effectiveRate() and posts that same
        // number back on the next save of the settings screen, which is the
        // shared save bar: editing any other row re-sends this one.
        for ($save = 0; $save < 4; $save++) {
            $shown = (new FxRates())->effectiveRate('GBP');
            $this->assertSame(0.86, $shown, sprintf('the override still reads as typed after %d saves', $save));
            $this->saveManual(['GBP' => $shown]);
        }

        $this->assertSame([ 'GBP' => 0.86 ], (new FxRates())->manual());
    }

    public function test_overrides_that_predate_the_change_are_restated_with_everything_else(): void
    {
        $this->seed('EUR', ['USD' => 1.0843, 'GBP' => 0.8521], ['GBP' => 0.90]);

        $this->moveBaseTo('USD');

        $fx = new FxRates();

        // 0.90 GBP per 1 EUR is 0.90/1.0843 GBP per 1 USD. The override stays an
        // override: leaving it at 0.90 would silently reprice it as a USD rate.
        $this->assertEqualsWithDelta(0.90 / 1.0843, $fx->manual()['GBP'] ?? null, 1e-12);
        $this->assertEqualsWithDelta(0.90 / 1.0843, $fx->effectiveRate('GBP'), 1e-12);
        $this->assertEqualsWithDelta(1.0843 / 0.90, $fx->rate('GBP', 'USD'), 1e-12);
    }

    public function test_an_override_can_be_the_bridge_the_new_base_needs(): void
    {
        // NGN is not an ECB reference currency, so it is only ever in the
        // snapshot because someone set it by hand. Ignoring a hand-set rate here
        // strands the base with no rate at all, and every foreign donation then
        // records no base amount and scores as zero in every total.
        $this->seed('USD', ['EUR' => 0.92, 'GBP' => 0.79], ['NGN' => 1500.0]);

        $this->moveBaseTo('NGN');

        $fx = new FxRates();

        $this->assertSame('NGN', $fx->base());
        $this->assertEqualsWithDelta(1500.0, $fx->rate('USD', 'NGN'), 1e-9);
        $this->assertEqualsWithDelta(1500.0 / 0.92, $fx->rate('EUR', 'NGN'), 1e-9);
        $this->assertSame(16304348, $fx->convertCents(10000, 'EUR', 'NGN'));
    }

    public function test_the_new_base_is_left_no_row_of_its_own(): void
    {
        $this->seed('EUR', ['USD' => 1.0843, 'GBP' => 0.8521], ['USD' => 1.10]);

        $this->moveBaseTo('USD');

        $fx = new FxRates();

        // A currency is worth one of itself. A leftover row is a second line
        // reading 1.0000 next to the base, and correcting it is what starts the
        // trouble.
        $this->assertSame([], $fx->manual());
        $this->assertArrayNotHasKey('USD', $fx->fetchedRates());
        $this->assertSame(1.0, $fx->effectiveRate('USD'));
        // The hand-set rate won over the fetched one while it was still a rate.
        $this->assertEqualsWithDelta(0.8521 / 1.10, $fx->effectiveRate('GBP'), 1e-12);
    }

    public function test_a_base_with_nothing_relating_it_keeps_the_snapshot_whole(): void
    {
        $this->seed('USD', ['EUR' => 0.92, 'GBP' => 0.79]);

        $this->moveBaseTo('NGN');

        $fx = new FxRates();

        // Nothing relates USD to NGN, so there is no restatement to make. What
        // there is stays: emptying the snapshot would take every cross rate down
        // with it for no gain.
        $this->assertSame('USD', $fx->base());
        $this->assertSame(['EUR' => 0.92, 'GBP' => 0.79], $fx->fetchedRates());
        $this->assertEqualsWithDelta(0.79 / 0.92, $fx->rate('EUR', 'GBP'), 1e-12);
        $this->assertNull($fx->rate('EUR', 'NGN'));

        $logged = Event::query()->whereLike('type', ErrorLog::PREFIX . 'currency.fx')->getAll();
        $this->assertCount(1, $logged, 'a base the rates cannot reach is not something to fail silently at');
    }

    public function test_a_snapshot_the_org_base_cannot_reach_reports_every_currency_unconvertible(): void
    {
        // The one state the two frames can stay apart in, and the state the
        // settings screen has to be loudest in: the org reports in USD, the
        // snapshot is denominated in EUR and carries no USD, so nothing in it
        // is reachable from the base every total is kept in.
        $this->seed('EUR', ['GBP' => 0.8521]);

        $fx = new FxRates();

        // What a GBP donation would do at the till: no rate, no base amount,
        // and the row scores as zero everywhere.
        $this->assertNull($fx->rate('GBP', 'USD'));
        $this->assertSame(['GBP'], $fx->unconvertible(['USD', 'GBP']));
    }

    public function test_a_fetch_will_not_carry_overrides_typed_against_the_base_it_replaces(): void
    {
        $base = strtoupper(Money::defaultCurrency());
        $left = $base === 'EUR' ? 'SEK' : 'EUR';

        // Exactly where test_a_base_with_nothing_relating_it_keeps_the_snapshot_whole
        // leaves a site: the org base has moved, nothing related the two, so the
        // snapshot is still denominated in the base being left - overrides with
        // it. A fetch sets the base as well as the rates, so it is the second
        // way the frames can part.
        $this->seed($left, ['GBP' => 0.8521], ['GBP' => 0.90]);

        $this->assertTrue($this->fetchReturning($base, ['GBP' => 0.79, $left => 0.92]));

        $fx = new FxRates();
        $this->assertSame($base, $fx->base());
        // 0.90 GBP per 1 $left is not 0.90 GBP per 1 $base. Kept, it would have
        // read as the latter and beaten the fetched rate to every conversion.
        $this->assertSame([], $fx->manual());
        $this->assertSame(0.79, $fx->effectiveRate('GBP'), 'the fetched rate stands, unshadowed');

        $logged = $this->fxErrors();
        $this->assertCount(1, $logged, 'clearing a hand-set rate is not something to do silently');
        $this->assertSame(['GBP'], $logged[0]->payload['cleared'] ?? null);
    }

    public function test_a_fetch_in_the_base_the_overrides_were_typed_in_leaves_them_alone(): void
    {
        $base = strtoupper(Money::defaultCurrency());
        $this->seed($base, ['GBP' => 0.8521], ['GBP' => 0.90]);

        $this->assertTrue($this->fetchReturning($base, ['GBP' => 0.79]));

        $this->assertSame(['GBP' => 0.90], (new FxRates())->manual(), 'same frame, nothing to correct');
        $this->assertSame([], $this->fxErrors());
    }

    public function test_a_rate_write_composed_in_the_base_being_left_is_refused(): void
    {
        $this->seed('EUR', ['USD' => 1.0843, 'GBP' => 0.8521], ['GBP' => 0.90]);

        // What the panel rendered, under a header reading "1 EUR =".
        $shown = (new FxRates())->effectiveRate('GBP');
        $this->assertSame(0.90, $shown);

        // The same click carries a base change. It lands first and restates the
        // whole table; the rate write is the request behind it, still holding
        // the numbers the screen was showing before.
        $this->moveBaseTo('USD');

        $updater = new FxRatesUpdater(new AsyncDispatcher());
        $this->assertFalse($updater->saveSettings(true, ['GBP' => $shown], 'EUR'));

        $fx = new FxRates();
        $this->assertEqualsWithDelta(0.90 / 1.0843, $fx->effectiveRate('GBP'), 1e-12, 'the restatement stands');
        $this->assertFalse($fx->auto(), 'a refused write writes nothing at all, the toggle included');

        // Re-read, and the same numbers go through in the frame they now belong to.
        $this->assertTrue($updater->saveSettings(true, ['GBP' => $fx->effectiveRate('GBP')], 'USD'));
        $this->assertEqualsWithDelta(0.90 / 1.0843, (new FxRates())->effectiveRate('GBP'), 1e-12);
        $this->assertTrue((new FxRates())->auto());
    }

    public function test_the_rate_panel_is_told_which_base_its_rates_are_in(): void
    {
        $base = strtoupper(Money::defaultCurrency());
        $this->seed('EUR', ['USD' => 1.0843, 'GBP' => 0.8521]);

        $state = (array) rest_do_request(new WP_REST_Request('GET', '/dono/v1/admin/currency/fx'))->get_data();
        $this->assertSame($base, $state['base'], 'the column is labelled with the org base');
        $this->assertSame('EUR', $state['frame'], 'the numbers under it are not always in it');

        // Declared as read: accepted.
        $this->assertSame(200, $this->fxPut(['auto' => true, 'manual' => ['GBP' => 0.9], 'frame' => 'EUR'])->get_status());
        $this->assertSame(['GBP' => 0.9], (new FxRates())->manual());

        // A screen that had not been told, assuming the header currency is the
        // one the rates are in: refused rather than repriced by 8%.
        $res = $this->fxPut(['auto' => false, 'manual' => ['GBP' => 0.95], 'frame' => $base]);
        $this->assertSame(409, $res->get_status());
        $this->assertSame(['GBP' => 0.9], (new FxRates())->manual(), 'unchanged');
        $this->assertTrue((new FxRates())->auto(), 'unchanged');
    }

    public function test_an_override_on_the_base_being_left_does_not_outlive_it(): void
    {
        $this->seed('USD', ['EUR' => 0.92, 'GBP' => 0.79], ['USD' => 7.5]);

        // Nobody has ever seen this number: a currency is worth one of itself,
        // and effectiveMap() writes that last.
        $this->assertSame(1.0, (new FxRates())->effectiveRate('USD'));

        $this->moveBaseTo('EUR');

        $fx = new FxRates();
        $this->assertSame([], $fx->manual(), 'it never meant anything, so it does not become a rate');
        $this->assertEqualsWithDelta(1 / 0.92, $fx->effectiveRate('USD'), 1e-12);
    }

    private function fxPut(array $body): \WP_REST_Response
    {
        $req = new WP_REST_Request('PUT', '/dono/v1/admin/currency/fx');
        $req->set_header('content-type', 'application/json');
        $req->set_body(json_encode($body));

        return rest_do_request($req);
    }
}
