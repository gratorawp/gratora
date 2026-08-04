<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Forms\Form;
use Dono\Forms\FormSubmissionValidator;
use Dono\Foundation\Plugin;

/**
 * A form with "allow custom amount" off is a fixed-amount form. The server has
 * to enforce that, because the client is the only other thing checking it.
 *
 * The presets are authored in the org's currency, so a donor who converted
 * through a currency switcher pays a value this side cannot reproduce and the
 * membership check has to yield. That exemption was keyed on the currency
 * posted rather than on the form offering the choice, so naming another
 * currency in the JSON skipped the allow-list on a form with no switcher.
 */
final class PresetAmountBypassTest extends IntegrationTestCase
{
    private function validator(): FormSubmissionValidator
    {
        return new FormSubmissionValidator();
    }

    private function form(bool $withSwitcher): Form
    {
        $amount = '<!-- wp:dono/donation-amount {"allowCustom":false,"presets":[2500,5000,10000]} /-->';
        $switch = $withSwitcher ? '<!-- wp:dono/currency-switcher /-->' : '';

        $f = Form::make();
        $f->title      = 'Fixed amounts';
        $f->status     = 'published';
        $f->blocks     = $amount . $switch;
        $f->created_at = gmdate('Y-m-d H:i:s');
        $f->updated_at = gmdate('Y-m-d H:i:s');
        $f->save();

        return $f;
    }

    private function submit(Form $form, array $body): bool
    {
        return $this->validator()->validate($form, $body) === null;
    }

    public function test_a_listed_amount_is_accepted(): void
    {
        $this->assertTrue($this->submit($this->form(false), [
            'amount_cents' => 5000,
            'currency'     => 'USD',
        ]));
    }

    public function test_an_unlisted_amount_is_refused(): void
    {
        $this->assertFalse($this->submit($this->form(false), [
            'amount_cents' => 1,
            'currency'     => 'USD',
        ]));
    }

    public function test_naming_another_currency_does_not_skip_the_list(): void
    {
        // One JSON field changed on a form that offers no currency choice.
        $this->assertFalse($this->submit($this->form(false), [
            'amount_cents' => 1,
            'currency'     => 'JPY',
        ]));
    }

    public function test_a_form_that_does_offer_the_choice_still_yields(): void
    {
        // Here the donor really can convert, and the converted value is not
        // reproducible from the authored presets.
        $this->assertTrue($this->submit($this->form(true), [
            'amount_cents' => 7331,
            'currency'     => 'JPY',
        ]));
    }

    public function test_that_form_still_enforces_the_list_in_the_org_currency(): void
    {
        $this->assertFalse($this->submit($this->form(true), [
            'amount_cents' => 1,
            'currency'     => 'USD',
        ]));
    }
}
