<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Forms\Form;
use Dono\Forms\FormSubmissionValidator;

/**
 * A form must accept the smallest amount it offers. Converted presets are
 * nice-rounded before they are rendered (assets/donation-form/util/fx.js
 * nicePreset), and that rounding can land below the exact conversion of the
 * minimum, so a bar taken straight from the rate refuses the very tile the
 * donor was shown. These are real rates: at SEK 10.5 the $25 tile renders as
 * 260 kr while the exact bar is 262.50 kr.
 */
final class DonationMinimumTileParityTest extends IntegrationTestCase
{
    /**
     * Rate, the amount the form's own tile renders for the $25 preset, and the
     * amount one minor unit below the bar. Verified against fx.js.
     *
     * @return array<string, array{0:string, 1:float, 2:int}>
     */
    public static function tiles(): array
    {
        return [
            'SEK' => ['SEK', 10.5, 26000],
            'DKK' => ['DKK', 6.9, 17000],
            'ZAR' => ['ZAR', 18.5, 46000],
        ];
    }

    /** @dataProvider tiles */
    public function test_the_smallest_tile_the_form_renders_is_accepted(string $code, float $rate, int $tile): void
    {
        $this->seedRate($code, $rate);
        $form = $this->formWithMinimum(2500, true);

        $err = $this->validate($form, ['amount_cents' => $tile, 'currency' => $code]);

        $this->assertNull(
            $err,
            $code . ': the form renders ' . $tile . ' as its smallest amount, so it cannot refuse it'
        );
    }

    /** @dataProvider tiles */
    public function test_the_bar_still_holds_below_that_tile(string $code, float $rate, int $tile): void
    {
        $this->seedRate($code, $rate);
        $form = $this->formWithMinimum(2500, true);

        $this->assertNotNull(
            $this->validate($form, ['amount_cents' => $tile - 1, 'currency' => $code]),
            $code . ': anything under the tile is still under the minimum'
        );
    }

    /**
     * Where the rounding goes the other way the bar stays exact, so the
     * reconciliation never gives away more than the tile needs.
     */
    public function test_a_rate_that_rounds_the_tile_up_keeps_the_exact_bar(): void
    {
        $this->seedRate('GBP', 0.79);
        $form = $this->formWithMinimum(2500, true);

        $this->assertNull($this->validate($form, ['amount_cents' => 1975, 'currency' => 'GBP']));
        $this->assertNotNull($this->validate($form, ['amount_cents' => 1974, 'currency' => 'GBP']));
    }

    /**
     * The figure is authored beside the presets, in the block's own currency.
     * Reading it as the org default instead converts a bar that was never in
     * dollars and lets a donor under it.
     */
    public function test_the_bar_is_the_block_s_own_currency_not_the_org_default(): void
    {
        update_option('dono_fx_rates', [
            'base'       => 'USD',
            'date'       => gmdate('Y-m-d'),
            'fetched_at' => gmdate('c'),
            'rates'      => ['USD' => 1.0, 'EUR' => 0.9],
        ], false);

        $blocks = '<!-- wp:dono/donation-amount {"allowCustom":true,"currency":"EUR","minCents":2500} /-->'
            . '<!-- wp:dono/currency-switcher /-->'
            . '<!-- wp:dono/submit-button {"label":"Donate"} /-->';

        $form = Form::make();
        $form->title      = 'Authored EUR ' . uniqid();
        $form->slug       = 'eur-' . uniqid();
        $form->status     = 'published';
        $form->blocks     = $blocks;
        $form->created_at = gmdate('Y-m-d H:i:s');
        $form->updated_at = $form->created_at;
        $form->save();

        $this->assertNull($this->validate($form, ['amount_cents' => 2500, 'currency' => 'EUR']));
        $this->assertNotNull(
            $this->validate($form, ['amount_cents' => 2499, 'currency' => 'EUR']),
            'the minimum is 25 euro, not 25 dollars converted into euro'
        );
    }

    private function seedRate(string $code, float $rate): void
    {
        // The create path refuses a currency the org does not accept and the
        // switcher never offers one, so a fixture that only seeds the rate
        // describes a donation nobody could make.
        update_option('dono_currency_locale', [
            'default_currency'     => 'USD',
            'supported_currencies' => ['USD', $code],
        ]);
        update_option('dono_fx_rates', [
            'base'       => 'USD',
            'date'       => gmdate('Y-m-d'),
            'fetched_at' => gmdate('c'),
            'rates'      => ['USD' => 1.0, $code => $rate],
        ], false);
    }

    /**
     * The currency attribute is the one the amounts are authored in, so the
     * fixture states it rather than leaning on the org default.
     */
    private function formWithMinimum(int $minCents, bool $withSwitcher = false): Form
    {
        $blocks = '<!-- wp:dono/donation-amount {"allowCustom":true,"currency":"USD","minCents":' . $minCents . '} /-->'
            . ($withSwitcher ? '<!-- wp:dono/currency-switcher /-->' : '')
            . '<!-- wp:dono/submit-button {"label":"Donate"} /-->';

        $form = Form::make();
        $form->title      = 'Tile parity ' . uniqid();
        $form->slug       = 'tile-' . uniqid();
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
}
