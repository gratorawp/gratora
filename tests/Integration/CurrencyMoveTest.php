<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Currency\FxRates;
use Gratora\Currency\FxRatesUpdater;
use Gratora\Donations\Donation;
use Gratora\Foundation\Plugin;
use Gratora\Recurring\RecurringPlan;
use Gratora\Settings\SettingsService;

/**
 * Money that has not settled yet still carries a base figure, and the rates
 * that figure is computed from have to keep arriving.
 */
final class CurrencyMoveTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        update_option(FxRates::OPTION, [
            'base'  => 'USD',
            'rates' => ['GBP' => 0.8, 'EUR' => 0.9],
            'auto'  => true,
        ], false);
    }

    private function settings(): SettingsService
    {
        return Plugin::instance()->container->get(SettingsService::class);
    }

    private function pending(string $currency, int $cents, int $baseCents): Donation
    {
        $now = gmdate('Y-m-d H:i:s');

        $d = Donation::make();
        $d->donor_id          = 1;
        $d->reference         = 'CUR-' . bin2hex(random_bytes(4));
        $d->amount_cents      = $cents;
        $d->base_amount_cents = $baseCents;
        $d->base_currency     = 'USD';
        $d->fx_rate           = '1.25000000';
        $d->currency          = $currency;
        $d->status            = 'pending';
        $d->gateway           = 'offline';
        $d->is_test           = false;
        $d->created_at        = $now;
        $d->updated_at        = $now;
        $d->save();

        return $d;
    }

    /**
     * A pending row deliberately does not lock the base, so it can be sitting
     * there when the base moves: its base figure was computed under the old one
     * and would be counted under the new one the moment the gateway confirms.
     */
    public function test_an_outstanding_donation_is_restated_into_the_new_base(): void
    {
        $donation = $this->pending('GBP', 10000, 12500);

        $this->settings()->update('currency-locale', [
            'default_currency'     => 'GBP',
            'supported_currencies' => ['GBP', 'USD'],
        ]);

        $after = Donation::query()->find('id', (int) $donation->id);

        $this->assertSame('GBP', $after->base_currency);
        $this->assertSame(10000, (int) $after->base_amount_cents, 'GBP 100 is GBP 100');
    }

    public function test_a_currency_with_no_rate_is_left_alone_and_reported(): void
    {
        $donation = $this->pending('JPY', 100000, 90000);

        $this->settings()->update('currency-locale', [
            'default_currency'     => 'GBP',
            'supported_currencies' => ['GBP', 'USD'],
        ]);

        $after = Donation::query()->find('id', (int) $donation->id);
        $this->assertSame(90000, (int) $after->base_amount_cents);

        $logged = \Gratora\Analytics\Event::query()
            ->whereLike('type', 'error.currency.rebase')
            ->get();
        $this->assertNotNull($logged, 'the operator is told which rows are still wrong');
    }

    public function test_a_save_that_does_not_move_the_base_restates_nothing(): void
    {
        $donation = $this->pending('GBP', 10000, 12500);

        $this->settings()->update('currency-locale', [
            'default_currency'     => 'USD',
            'supported_currencies' => ['GBP', 'USD', 'EUR'],
        ]);

        $this->assertSame(
            12500,
            (int) Donation::query()->find('id', (int) $donation->id)->base_amount_cents
        );
    }

    public function test_a_live_foreign_plan_keeps_the_rates_coming(): void
    {
        $this->settings()->update('currency-locale', [
            'default_currency'     => 'USD',
            'supported_currencies' => ['USD'],
        ]);

        $updater = new FxRatesUpdater(Plugin::instance()->container->get(\Gratora\Async\AsyncDispatcher::class));
        $needs   = new \ReflectionMethod($updater, 'needsRates');
        $needs->setAccessible(true);

        $this->assertFalse($needs->invoke($updater), 'a single-currency site fetches nothing');

        $now = gmdate('Y-m-d H:i:s');
        $p = RecurringPlan::make();
        $p->donor_id                = 1;
        $p->gateway                 = 'offline';
        $p->gateway_subscription_id = 'sub_' . uniqid();
        $p->amount_cents            = 2500;
        $p->currency                = 'EUR';
        $p->interval_unit           = 'month';
        $p->interval_count          = 1;
        $p->status                  = 'active';
        $p->is_test                 = false;
        $p->started_at              = $now;
        $p->created_at              = $now;
        $p->updated_at              = $now;
        $p->save();

        $this->assertTrue(
            $needs->invoke($updater),
            'a live EUR plan is money arriving in EUR, whatever the accepted list says'
        );
    }

    public function test_a_paused_plan_counts_too(): void
    {
        $this->settings()->update('currency-locale', [
            'default_currency'     => 'USD',
            'supported_currencies' => ['USD'],
        ]);

        $now = gmdate('Y-m-d H:i:s');
        $p = RecurringPlan::make();
        $p->donor_id                = 1;
        $p->gateway                 = 'offline';
        $p->gateway_subscription_id = 'sub_' . uniqid();
        $p->amount_cents            = 2500;
        $p->currency                = 'EUR';
        $p->interval_unit           = 'month';
        $p->interval_count          = 1;
        $p->status                  = 'paused';
        $p->is_test                 = false;
        $p->started_at              = $now;
        $p->created_at              = $now;
        $p->updated_at              = $now;
        $p->save();

        $updater = new FxRatesUpdater(Plugin::instance()->container->get(\Gratora\Async\AsyncDispatcher::class));
        $needs   = new \ReflectionMethod($updater, 'needsRates');
        $needs->setAccessible(true);

        $this->assertTrue($needs->invoke($updater), 'a paused plan resumes');
    }

    public function test_a_cancelled_plan_does_not(): void
    {
        $this->settings()->update('currency-locale', [
            'default_currency'     => 'USD',
            'supported_currencies' => ['USD'],
        ]);

        $now = gmdate('Y-m-d H:i:s');
        $p = RecurringPlan::make();
        $p->donor_id                = 1;
        $p->gateway                 = 'offline';
        $p->gateway_subscription_id = 'sub_' . uniqid();
        $p->amount_cents            = 2500;
        $p->currency                = 'EUR';
        $p->interval_unit           = 'month';
        $p->interval_count          = 1;
        $p->status                  = 'cancelled';
        $p->is_test                 = false;
        $p->started_at              = $now;
        $p->created_at              = $now;
        $p->updated_at              = $now;
        $p->save();

        $updater = new FxRatesUpdater(Plugin::instance()->container->get(\Gratora\Async\AsyncDispatcher::class));
        $needs   = new \ReflectionMethod($updater, 'needsRates');
        $needs->setAccessible(true);

        $this->assertFalse($needs->invoke($updater), 'nothing will renew in it');
    }
}
