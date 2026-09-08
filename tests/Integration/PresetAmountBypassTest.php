<?php

declare(strict_types=1);

namespace FundKit\Tests\Integration;

use FundKit\Forms\Form;
use FundKit\Forms\FormSubmissionValidator;
use FundKit\Foundation\Plugin;

/**
 * Enforce presets server-side. Currency conversion is allowed only when the form offers a
 * switcher.
 */
final class PresetAmountBypassTest extends IntegrationTestCase
{
    private function validator(): FormSubmissionValidator
    {
        return new FormSubmissionValidator();
    }

    private function form(bool $withSwitcher): Form
    {
        $amount = '<!-- wp:fundkit/donation-amount {"allowCustom":false,"presets":[2500,5000,10000]} /-->';
        $switch = $withSwitcher ? '<!-- wp:fundkit/currency-switcher /-->' : '';

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
