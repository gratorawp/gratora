<?php

declare(strict_types=1);

namespace FundKit\Tests\Integration;

use FundKit\Currency\FxRates;
use FundKit\Forms\Form;
use FundKit\Forms\FormSubmissionValidator;

/**
 * A presets-only form is a fixed menu, and a currency switcher must not turn it
 * into an open box.
 *
 * The exact converted figure is not reproducible here, because the rate moves
 * between the form rendering and the donation arriving. That is why membership
 * used to be waived outright for a converted amount, which let a crafted
 * payload name a currency and pay whatever it liked.
 */
final class PresetAmountsInAnotherCurrencyTest extends IntegrationTestCase
{
    private const BLOCKS = <<<BLOCKS
<!-- wp:fundkit/donation-amount {"allowCustom":false,"presets":[{"cents":2500},{"cents":5000},{"cents":10000}]} /-->
<!-- wp:fundkit/currency-switcher /-->
<!-- wp:fundkit/submit-button /-->
BLOCKS;

    /** No switcher, so the form can only be paid in the authored currency. */
    private const FIXED_BLOCKS = <<<BLOCKS
<!-- wp:fundkit/donation-amount {"allowCustom":false,"presets":[{"cents":2500},{"cents":5000}]} /-->
<!-- wp:fundkit/submit-button /-->
BLOCKS;

    protected function setUp(): void
    {
        parent::setUp();

        update_option('fundkit_currency_locale', [
            'default_currency'     => 'USD',
            'supported_currencies' => ['USD', 'EUR'],
        ]);

        update_option(FxRates::OPTION, [
            'base'       => 'USD',
            'date'       => gmdate('Y-m-d'),
            'fetched_at' => gmdate('c'),
            'rates'      => ['USD' => 1.0, 'EUR' => 0.90],
        ], false);
    }

    private function form(string $blocks = self::BLOCKS): Form
    {
        $f = Form::make();
        $f->blocks = $blocks;

        return $f;
    }

    private function submit(int $cents, string $currency, string $blocks = self::BLOCKS): mixed
    {
        return (new FormSubmissionValidator())->validate($this->form($blocks), [
            'amount_cents' => $cents,
            'currency'     => $currency,
            'frequency'    => 'one_time',
        ]);
    }

    public function test_a_listed_amount_in_the_authored_currency_is_accepted(): void
    {
        $this->assertNull($this->submit(5000, 'USD'));
    }

    public function test_an_unlisted_amount_in_the_authored_currency_is_refused(): void
    {
        $this->assertNotNull($this->submit(100, 'USD'));
    }

    /** $50 at 0.90 is €45, which is what the donor was offered. */
    public function test_a_converted_listed_amount_is_accepted(): void
    {
        $this->assertNull($this->submit(4500, 'EUR'));
    }

    /**
     * The rate moves between render and submit, and the form rounds what it
     * shows, so near enough has to pass or real donors are turned away.
     */
    public function test_a_converted_amount_a_little_off_the_rate_is_accepted(): void
    {
        $this->assertNull($this->submit(4600, 'EUR'), 'a day of rate movement must not refuse a donor');
        $this->assertNull($this->submit(4400, 'EUR'));
    }

    /** The attack: name a currency the form offers, then pay what you like. */
    public function test_naming_a_currency_does_not_buy_any_amount(): void
    {
        $this->assertNotNull($this->submit(100, 'EUR'), 'a euro is not one of the offered amounts');
        $this->assertNotNull($this->submit(1000, 'EUR'));
    }

    /** And a currency the form never offered still cannot skip the menu. */
    public function test_a_currency_the_form_does_not_offer_cannot_skip_the_menu(): void
    {
        $this->assertNotNull($this->submit(100, 'EUR', self::FIXED_BLOCKS));
    }

    /**
     * No rate is a fact about this site, not about the donor. Refusing them
     * would close the form over an outage they did not cause.
     */
    public function test_an_unconvertible_currency_is_let_through(): void
    {
        update_option(FxRates::OPTION, [
            'base'       => 'USD',
            'date'       => gmdate('Y-m-d'),
            'fetched_at' => gmdate('c'),
            'rates'      => ['USD' => 1.0],
        ], false);

        $this->assertNull($this->submit(1234, 'EUR'));
    }
}
