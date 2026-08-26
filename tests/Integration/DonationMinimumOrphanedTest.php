<?php

declare(strict_types=1);

namespace GiveFlow\Tests\Integration;

use GiveFlow\Forms\Form;
use GiveFlow\Forms\FormSubmissionValidator;
use GiveFlow\Forms\Shortcode\DonationFormShortcode;
use ReflectionMethod;

/**
 * The Minimum amount control only shows while the donor can type an amount, so
 * a form that lists preset tiles and nothing else has no minimum on any screen.
 * A stored figure that keeps refusing donors from behind a control nobody can
 * see is a rule the author cannot read, change or turn off.
 */
final class DonationMinimumOrphanedTest extends IntegrationTestCase
{
    private function form(string $attrs): Form
    {
        $form = Form::make();
        $form->title      = 'Orphaned minimum ' . uniqid();
        $form->slug       = 'orphan-min-' . uniqid();
        $form->status     = 'published';
        $form->blocks     = '<!-- wp:giveflow/donation-amount ' . $attrs . ' /-->'
            . '<!-- wp:giveflow/submit-button {"label":"Give"} /-->';
        $form->created_at = gmdate('Y-m-d H:i:s');
        $form->updated_at = $form->created_at;
        $form->save();

        return $form;
    }

    private function validate(Form $form, array $body): ?\WP_Error
    {
        return (new FormSubmissionValidator())->validate($form, $body);
    }

    private function minShownToTheDonor(Form $form): int
    {
        $m = new ReflectionMethod(DonationFormShortcode::class, 'amountBlockMinCents');
        $m->setAccessible(true);

        return (int) $m->invoke(null, $form);
    }

    /** A tile the author listed is an amount the author chose to accept. */
    public function test_a_presets_only_form_accepts_the_amount_it_lists(): void
    {
        $form = $this->form('{"allowCustom":false,"currency":"USD","minCents":5000,"presets":[{"cents":1000},{"cents":2500}]}');

        $this->assertNull(
            $this->validate($form, ['amount_cents' => 1000]),
            'the donor clicked a listed tile, and no screen shows a minimum that would refuse it',
        );
    }

    public function test_a_presets_only_form_does_not_hand_the_donor_a_minimum(): void
    {
        $form = $this->form('{"allowCustom":false,"currency":"USD","minCents":5000,"presets":[{"cents":1000}]}');

        $this->assertSame(0, $this->minShownToTheDonor($form), 'nothing on the form can be typed, so nothing has a floor');
    }

    /** An open-amount block is all typing, which is exactly what a minimum is for. */
    public function test_an_open_amount_form_still_enforces_its_minimum(): void
    {
        $form = $this->form('{"donationType":"fixed","currency":"USD","minCents":5000}');

        $this->assertNotNull($this->validate($form, ['amount_cents' => 1000]));
        $this->assertSame(5000, $this->minShownToTheDonor($form));
    }

    public function test_a_multi_level_form_with_custom_amounts_still_enforces_its_minimum(): void
    {
        $form = $this->form('{"allowCustom":true,"currency":"USD","minCents":5000,"presets":[{"cents":10000}]}');

        $this->assertNotNull($this->validate($form, ['amount_cents' => 1000]));
        $this->assertSame(5000, $this->minShownToTheDonor($form));
    }
}
