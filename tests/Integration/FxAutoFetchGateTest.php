<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Async\AsyncDispatcher;
use Dono\Currency\FxRates;
use Dono\Currency\FxRatesUpdater;
use Dono\Donations\Donation;
use Dono\Foundation\Plugin;

/**
 * The daily rate fetch reaches a third party. A site that converts nothing has
 * no use for it, and an outbound call has to earn its place.
 */
final class FxAutoFetchGateTest extends IntegrationTestCase
{
    /** @var list<string> */
    private array $calls = [];

    private \Closure $spy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->calls = [];
        $this->spy = function ($pre, $args, $url) {
            $this->calls[] = (string) $url;

            // Answering here keeps the suite offline whatever the gate decides.
            return [
                'headers'  => [],
                'response' => ['code' => 200, 'message' => 'OK'],
                'body'     => (string) wp_json_encode([
                    'base'  => 'USD',
                    'date'  => '2026-08-03',
                    'rates' => ['EUR' => 0.9],
                ]),
            ];
        };
        add_filter('pre_http_request', $this->spy, 10, 3);
    }

    protected function tearDown(): void
    {
        remove_filter('pre_http_request', $this->spy, 10);
        parent::tearDown();
    }

    private function updater(): FxRatesUpdater
    {
        return new FxRatesUpdater(Plugin::instance()->container->get(AsyncDispatcher::class));
    }

    private function accepts(array $currencies): void
    {
        $opt = get_option('dono_currency_locale', []);
        $opt = is_array($opt) ? $opt : [];
        $opt['supported_currencies'] = $currencies;
        update_option('dono_currency_locale', $opt);
    }

    private function auto(bool $on): void
    {
        $opt = get_option(FxRates::OPTION, []);
        $opt = is_array($opt) ? $opt : [];
        $opt['auto'] = $on;
        update_option(FxRates::OPTION, $opt, false);
    }

    private function strandedDonation(string $currency): void
    {
        $d = Donation::make();
        $d->reference         = 'REF-' . uniqid();
        $d->status            = 'paid';
        $d->gateway           = 'offline';
        $d->kind              = 'donation';
        $d->amount_cents      = 5000;
        $d->currency          = $currency;
        $d->base_amount_cents = null;
        $d->created_at        = gmdate('Y-m-d H:i:s');
        $d->paid_at           = gmdate('Y-m-d H:i:s');
        $d->save();
    }

    /** The suite's base currency, so the assertions do not hardcode one. */
    private function base(): string
    {
        return strtoupper(\Dono\Foundation\Helpers\Money::defaultCurrency());
    }

    public function test_a_single_currency_site_never_calls_out(): void
    {
        $this->auto(true);
        $this->accepts([$this->base()]);

        $this->updater()->run();

        $this->assertSame([], $this->calls, 'nothing to convert, so nothing to fetch');
    }

    public function test_accepting_a_second_currency_turns_the_fetch_on(): void
    {
        $this->auto(true);
        $this->accepts([$this->base(), 'JPY']);

        $this->updater()->run();

        $this->assertCount(1, $this->calls);
        $this->assertStringContainsString('frankfurter', $this->calls[0]);
    }

    public function test_a_stranded_donation_still_gets_a_rate_fetched(): void
    {
        // The org accepts only its own currency now, but took a donation in
        // another one before a rate existed. Gating on accepted currencies
        // alone would stand that donation outside every total forever.
        $this->auto(true);
        $this->accepts([$this->base()]);
        $this->strandedDonation('NOK');

        $this->updater()->run();

        $this->assertCount(1, $this->calls, 'a stranded donation is a reason to fetch');
    }

    public function test_a_stranded_donation_in_the_base_currency_is_not_a_reason(): void
    {
        $this->auto(true);
        $this->accepts([$this->base()]);
        $this->strandedDonation($this->base());

        $this->updater()->run();

        $this->assertSame([], $this->calls);
    }

    public function test_the_auto_toggle_still_wins(): void
    {
        $this->auto(false);
        $this->accepts([$this->base(), 'JPY']);

        $this->updater()->run();

        $this->assertSame([], $this->calls);
    }

    public function test_fetch_now_ignores_the_gate(): void
    {
        // Pressing the button is a decision, not a schedule.
        $this->auto(false);
        $this->accepts([$this->base()]);

        $this->updater()->fetchNow();

        $this->assertCount(1, $this->calls);
    }
}
