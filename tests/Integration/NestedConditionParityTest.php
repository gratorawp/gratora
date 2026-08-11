<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Forms\Form;
use Dono\Forms\FormSubmissionValidator;

/**
 * The server half of the contract the donation form's payload builder keeps
 * (assets/donation-form/state/store.js): a condition is only evaluated against
 * what the payload carries, so a chain of two conditions agrees with the form
 * on screen exactly when the value the second one reads is submitted.
 *
 * @since 1.0.0
 */
final class NestedConditionParityTest extends IntegrationTestCase
{
    private const BLOCKS = <<<BLOCKS
<!-- wp:dono/donation-amount {"presets":[{"cents":2500}]} /-->
<!-- wp:dono/recurring-toggle {"frequencies":["one-time","monthly"]} /-->
<!-- wp:dono/dropdown {"label":"Donor type","field":"donor_type","default":"individual","condition":{"field":"frequency","op":"=","value":"monthly"}} /-->
<!-- wp:dono/text-input {"label":"Organization name","field":"org_name","required":true,"condition":{"field":"custom.donor_type","op":"!=","value":"individual"}} /-->
<!-- wp:dono/submit-button /-->
BLOCKS;

    private function form(): Form
    {
        $f = Form::make();
        $f->blocks = self::BLOCKS;
        return $f;
    }

    public function test_a_one_time_donation_carrying_the_condition_source_is_accepted(): void
    {
        // The donor never saw the donor-type dropdown: it is monthly-only. The
        // form hid the organization name on the dropdown's default, and submits
        // that default so this side reaches the same answer.
        $this->assertNull(
            (new FormSubmissionValidator())->validate($this->form(), [
                'amount_cents' => 2500,
                'frequency'    => 'one_time',
                'custom'       => ['donor_type' => 'individual'],
            ])
        );
    }

    public function test_a_missing_condition_source_demands_a_field_the_donor_cannot_see(): void
    {
        // Without it, `donor_type` reads as empty, empty is not 'individual',
        // and the organization name is required on a form that never shows it.
        $this->assertNotNull(
            (new FormSubmissionValidator())->validate($this->form(), [
                'amount_cents' => 2500,
                'frequency'    => 'one_time',
                'custom'       => [],
            ])
        );
    }

    public function test_the_organization_name_is_still_required_of_an_organization(): void
    {
        $base = ['amount_cents' => 2500, 'frequency' => 'monthly'];

        $this->assertNotNull(
            (new FormSubmissionValidator())->validate(
                $this->form(),
                $base + ['custom' => ['donor_type' => 'organization']]
            )
        );
        $this->assertNull(
            (new FormSubmissionValidator())->validate(
                $this->form(),
                $base + ['custom' => ['donor_type' => 'organization', 'org_name' => 'Acme Trust']]
            )
        );
    }
}
