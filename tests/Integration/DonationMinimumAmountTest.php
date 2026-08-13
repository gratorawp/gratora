<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Forms\Form;
use Dono\Forms\FormSubmissionValidator;

/**
 * The readme promised "a minimum you set" and only a developer filter existed.
 * The block carries one now, and it has to hold on the server: a form that only
 * hides small amounts in the browser has not set a minimum.
 *
 * The figure is authored in the form's own currency, beside the presets, so a
 * donor paying in another one is measured against the converted bar and told
 * the minimum in the currency they are actually paying: raw minor units make a
 * $25 floor mean 25 yen one way and refuse a legitimate amount the other.
 */
final class DonationMinimumAmountTest extends IntegrationTestCase
{
    private function formWithMinimum(int $minCents, bool $withSwitcher = false): Form
    {
        $blocks = '<!-- wp:dono/donation-amount {"allowCustom":true,"minCents":' . $minCents . '} /-->'
            . ($withSwitcher ? '<!-- wp:dono/currency-switcher {"currencies":["GBP","JPY"]} /-->' : '')
            . '<!-- wp:dono/submit-button {"label":"Give"} /-->';

        $form = Form::make();
        $form->title      = 'Minimum ' . uniqid();
        $form->slug       = 'min-' . uniqid();
        $form->status     = 'published';
        $form->blocks     = $blocks;
        $form->created_at = gmdate('Y-m-d H:i:s');
        $form->updated_at = $form->created_at;
        $form->save();

        return $form;
    }

    private function validate(Form $form, array $body): ?\WP_Error
    {
        return (new FormSubmissionValidator())->validate($form, $body);
    }

    public function test_a_donation_under_the_minimum_is_refused(): void
    {
        $form = $this->formWithMinimum(2500);

        $err = $this->validate($form, ['amount_cents' => 1000]);

        $this->assertNotNull($err, 'a gift below the form minimum should not pass');
    }

    public function test_a_donation_on_the_minimum_passes(): void
    {
        $form = $this->formWithMinimum(2500);

        $this->assertNull($this->validate($form, ['amount_cents' => 2500]));
    }

    /**
     * Covering the processing cost is not the donor giving more, so it must not
     * carry them over a bar they did not clear.
     */
    public function test_a_covered_fee_does_not_lift_a_donor_over_the_minimum(): void
    {
        $form = $this->formWithMinimum(2500);

        $err = $this->validate($form, ['amount_cents' => 2600, 'fee_covered_cents' => 200]);

        $this->assertNotNull($err, 'net is 2400, under the 2500 minimum');
    }

    public function test_no_minimum_set_means_no_form_level_floor(): void
    {
        $form = $this->formWithMinimum(0);

        $this->assertNull($this->validate($form, ['amount_cents' => 100]));
    }

    /**
     * Rates chosen so neither conversion is round: GBP lands on an exact bar,
     * JPY lands between two whole yen.
     */
    private function seedRates(): void
    {
        update_option('dono_fx_rates', [
            'base'       => 'USD',
            'date'       => gmdate('Y-m-d'),
            'fetched_at' => gmdate('c'),
            'rates'      => ['USD' => 1.0, 'GBP' => 0.79, 'JPY' => 149.93],
        ], false);
    }

    public function test_a_donor_paying_a_stronger_currency_is_measured_against_the_converted_minimum(): void
    {
        $this->seedRates();
        $form = $this->formWithMinimum(2500, true);

        // GBP 19.75 is the $25 minimum, and it is what the form's own tile
        // converts to, so refusing it refuses the amount the donor was shown.
        $this->assertNull($this->validate($form, ['amount_cents' => 1975, 'currency' => 'GBP']));
    }

    public function test_a_donation_under_the_converted_minimum_is_still_refused(): void
    {
        $this->seedRates();
        $form = $this->formWithMinimum(2500, true);

        $err = $this->validate($form, ['amount_cents' => 1974, 'currency' => 'GBP']);

        $this->assertNotNull($err, 'a penny under the converted bar is under the minimum');
    }

    public function test_a_weaker_currency_cannot_slip_under_the_minimum(): void
    {
        $this->seedRates();
        $form = $this->formWithMinimum(2500, true);

        // 100 yen, about 67 cents, on a form with a $25 minimum. Storage is
        // major x 100 in every currency, so the raw figure clears 2500.
        $err = $this->validate($form, ['amount_cents' => 10000, 'currency' => 'JPY']);

        $this->assertNotNull($err, 'a 67-cent donation must not pass a $25 minimum');
    }

    public function test_the_refusal_quotes_a_figure_the_donor_can_act_on(): void
    {
        $this->seedRates();
        $form = $this->formWithMinimum(2500, true);

        $err = $this->validate($form, ['amount_cents' => 10000, 'currency' => 'JPY']);

        $this->assertNotNull($err);
        $this->assertStringContainsString('3,749', $err->get_error_message());
        $this->assertStringContainsString('¥', $err->get_error_message());
        // The quoted yen figure is a whole one, and it is exactly the bar: a
        // donor who enters what the message says gets through.
        $this->assertNull($this->validate($form, ['amount_cents' => 374900, 'currency' => 'JPY']));
        $this->assertNotNull($this->validate($form, ['amount_cents' => 374800, 'currency' => 'JPY']));
    }

    public function test_naming_another_currency_does_not_lower_the_bar_on_a_form_with_no_switcher(): void
    {
        $this->seedRates();
        $form = $this->formWithMinimum(2500);

        // No switcher, so the donor can only pay the authored currency: the
        // code in the JSON is a crafted claim, not a conversion, and must not
        // buy the GBP bar of 1975.
        $err = $this->validate($form, ['amount_cents' => 2000, 'currency' => 'GBP']);

        $this->assertNotNull($err, 'the authored bar still applies');
    }
}
