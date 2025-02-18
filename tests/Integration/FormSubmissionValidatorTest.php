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
