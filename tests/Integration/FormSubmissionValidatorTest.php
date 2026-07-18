<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Forms\Form;
use Dono\Forms\FormSubmissionValidator;

/**
 * Server-side submit-time validation: required donor/custom fields, formats,
 * required-by-law consent, and frequency-against-offered. These are client-only
 * otherwise and bypassable by a crafted POST.
 */
final class FormSubmissionValidatorTest extends IntegrationTestCase
{
    private function form(string $blocks): Form
    {
        $f = Form::make();
        $f->blocks = $blocks;
        return $f;
    }

    private function validator(): FormSubmissionValidator
    {
        return new FormSubmissionValidator();
    }

    private const BLOCKS = <<<BLOCKS
<!-- wp:dono/donation-amount {"presets":[{"cents":2500}]} /-->
<!-- wp:dono/email {"required":true} /-->
<!-- wp:dono/name {"requireFirst":true} /-->
<!-- wp:dono/text-input {"label":"Nickname","required":true} /-->
<!-- wp:dono/consent {"purposes":[{"id":"gdpr","label":"I agree to the terms","requiredByLaw":true}]} /-->
<!-- wp:dono/submit-button /-->
BLOCKS;

    private function validPayload(): array
    {
        return [
            'amount_cents' => 2500,
            'frequency'    => 'one_time',
            'profile'      => ['first_name' => 'Ada'],
            'custom'       => ['nickname' => 'Countess'],
            'consents'     => ['gdpr' => true],
        ];
    }

    public function test_valid_payload_passes(): void
    {
        $this->assertNull($this->validator()->validate($this->form(self::BLOCKS), $this->validPayload()));
    }

    public function test_missing_required_first_name_is_rejected(): void
    {
        $p = $this->validPayload();
        $p['profile']['first_name'] = '';
        $this->assertNotNull($this->validator()->validate($this->form(self::BLOCKS), $p));
    }

    public function test_missing_required_custom_field_is_rejected(): void
    {
        $p = $this->validPayload();
        unset($p['custom']['nickname']);
        $this->assertNotNull($this->validator()->validate($this->form(self::BLOCKS), $p));
    }

    public function test_missing_required_by_law_consent_is_rejected(): void
    {
        $p = $this->validPayload();
        $p['consents'] = ['gdpr' => false];
        $this->assertNotNull($this->validator()->validate($this->form(self::BLOCKS), $p));
    }

    public function test_recurring_frequency_on_a_one_time_form_is_rejected(): void
    {
        $p = $this->validPayload();
        $p['frequency'] = 'monthly';
        $this->assertNotNull($this->validator()->validate($this->form(self::BLOCKS), $p));
    }

    public function test_offered_frequency_passes_when_the_form_has_a_recurring_toggle(): void
    {
        $blocks = <<<BLOCKS
<!-- wp:dono/donation-amount {"presets":[{"cents":2500}]} /-->
<!-- wp:dono/email {"required":true} /-->
<!-- wp:dono/recurring-toggle {"frequencies":["one-time","monthly"]} /-->
<!-- wp:dono/submit-button /-->
BLOCKS;
        $base = ['amount_cents' => 2500, 'custom' => [], 'consents' => []];

        $this->assertNull($this->validator()->validate($this->form($blocks), $base + ['frequency' => 'monthly']));
        $this->assertNotNull($this->validator()->validate($this->form($blocks), $base + ['frequency' => 'yearly']));
    }

    public function test_overlong_comment_is_rejected_server_side(): void
    {
        $blocks = <<<BLOCKS
<!-- wp:dono/donation-amount {"presets":[{"cents":2500}]} /-->
<!-- wp:dono/comment {"maxLength":50} /-->
<!-- wp:dono/submit-button /-->
BLOCKS;
        $base = ['amount_cents' => 2500, 'frequency' => 'one_time', 'custom' => [], 'consents' => []];

        $this->assertNull(
            $this->validator()->validate($this->form($blocks), $base + ['note_to_org' => 'short note']),
            'a note within the limit passes'
        );
        $this->assertNotNull(
            $this->validator()->validate($this->form($blocks), $base + ['note_to_org' => str_repeat('x', 51)]),
            'a note over the block maxLength is rejected'
        );
    }

    public function test_presets_only_form_enforces_its_presets(): void
    {
        $blocks = <<<BLOCKS
<!-- wp:dono/donation-amount {"presets":[{"cents":2500},{"cents":5000}],"allowCustom":false} /-->
<!-- wp:dono/submit-button /-->
BLOCKS;
        $base = ['frequency' => 'one_time', 'custom' => [], 'consents' => []];

        $this->assertNull(
            $this->validator()->validate($this->form($blocks), $base + ['amount_cents' => 2500]),
            'a listed preset is accepted'
        );
        $this->assertNotNull(
            $this->validator()->validate($this->form($blocks), $base + ['amount_cents' => 700]),
            'an off-preset amount is rejected on a presets-only form'
        );
    }

    public function test_custom_amount_form_still_accepts_any_amount(): void
    {
        $blocks = <<<BLOCKS
<!-- wp:dono/donation-amount {"presets":[{"cents":2500}],"allowCustom":true} /-->
<!-- wp:dono/submit-button /-->
BLOCKS;
        $base = ['frequency' => 'one_time', 'custom' => [], 'consents' => []];

        $this->assertNull(
            $this->validator()->validate($this->form($blocks), $base + ['amount_cents' => 743]),
            'a custom amount is accepted when the form allows custom'
        );
    }

    public function test_fund_picker_rejects_a_fund_outside_its_allowlist(): void
    {
        $blocks = <<<BLOCKS
<!-- wp:dono/donation-amount {"presets":[{"cents":2500}]} /-->
<!-- wp:dono/fund-picker {"fundIds":[7,8]} /-->
<!-- wp:dono/submit-button /-->
BLOCKS;
        $base = ['amount_cents' => 2500, 'frequency' => 'one_time', 'custom' => [], 'consents' => []];

        $this->assertNull(
            $this->validator()->validate($this->form($blocks), $base + ['fund_id' => 7]),
            'a fund in the allowlist is accepted'
        );
        $this->assertNotNull(
            $this->validator()->validate($this->form($blocks), $base + ['fund_id' => 99]),
            'a fund outside the allowlist is rejected'
        );
        $this->assertNull(
            $this->validator()->validate($this->form($blocks), $base + ['fund_id' => 0]),
            'a cleared fund choice falls through to the default'
        );
    }

    public function test_default_recurring_toggle_accepts_the_frequencies_it_offers(): void
    {
        // Gutenberg omits `frequencies` when it equals the block default, so a
        // default-configured toggle serialises with no attrs. The validator
        // must still offer one-time + monthly, matching what the form renders.
        $blocks = <<<BLOCKS
<!-- wp:dono/donation-amount {"presets":[{"cents":2500}]} /-->
<!-- wp:dono/email {"required":true} /-->
<!-- wp:dono/recurring-toggle /-->
<!-- wp:dono/submit-button /-->
BLOCKS;
        $base = ['amount_cents' => 2500, 'custom' => [], 'consents' => []];

        $this->assertNull(
            $this->validator()->validate($this->form($blocks), $base + ['frequency' => 'monthly']),
            'a default recurring-toggle must accept the monthly donation it offers'
        );
        $this->assertNotNull(
            $this->validator()->validate($this->form($blocks), $base + ['frequency' => 'yearly']),
            'a frequency outside the default set is still rejected'
        );
    }

    public function test_required_field_hidden_by_its_condition_is_not_enforced(): void
    {
        $blocks = <<<BLOCKS
<!-- wp:dono/donation-amount {"presets":[{"cents":2500}]} /-->
<!-- wp:dono/text-input {"label":"Company","field":"company","required":true,"condition":{"field":"custom.donor_type","op":"=","value":"business"}} /-->
<!-- wp:dono/submit-button /-->
BLOCKS;
        $form = $this->form($blocks);
        $base = ['amount_cents' => 2500, 'frequency' => 'one_time'];

        // Condition false: the field is hidden client-side and submits nothing,
        // so the server must not enforce its required rule.
        $this->assertNull($this->validator()->validate($form, $base + ['custom' => ['donor_type' => 'individual']]));

        // Condition true but the required field is empty: reject.
        $this->assertNotNull($this->validator()->validate($form, $base + ['custom' => ['donor_type' => 'business']]));

        // Condition true and filled: pass.
        $this->assertNull($this->validator()->validate($form, $base + ['custom' => ['donor_type' => 'business', 'company' => 'Acme']]));
    }

    public function test_number_and_pattern_constraints_are_enforced(): void
    {
        $blocks = <<<BLOCKS
<!-- wp:dono/number-input {"label":"Age","min":18,"max":120} /-->
<!-- wp:dono/text-input {"label":"Code","pattern":"[A-Z]{3}"} /-->
<!-- wp:dono/submit-button /-->
BLOCKS;
        $form = $this->form($blocks);

        $this->assertNull($this->validator()->validate($form, ['custom' => ['age' => 30, 'code' => 'ABC']]));
        $this->assertNotNull($this->validator()->validate($form, ['custom' => ['age' => 5]]));
        $this->assertNotNull($this->validator()->validate($form, ['custom' => ['code' => 'abc']]));
    }
}
