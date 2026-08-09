<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Forms\Form;
use Dono\Forms\FormSubmissionValidator;

/**
 * The readme promised "a minimum you set" and only a developer filter existed.
 * The block carries one now, and it has to hold on the server: a form that only
 * hides small amounts in the browser has not set a minimum.
 */
final class DonationMinimumAmountTest extends IntegrationTestCase
{
    private function formWithMinimum(int $minCents): Form
    {
        $blocks = '<!-- wp:dono/donation-amount {"allowCustom":true,"minCents":' . $minCents . '} /-->'
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
}
