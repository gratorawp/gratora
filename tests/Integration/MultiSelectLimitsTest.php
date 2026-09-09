<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Forms\Form;
use Gratora\Forms\FormSubmissionValidator;

/**
 * The editor let a minimum be set above the option count, or above the
 * maximum. Clamping only in the editor would leave every already-published
 * form with a field no donor can satisfy, so the readers clamp too.
 */
final class MultiSelectLimitsTest extends IntegrationTestCase
{
    private function form(string $blocks): Form
    {
        $f = Form::make();
        $f->blocks = $blocks;

        return $f;
    }

    /** @param array<string,mixed> $custom */
    private function validate(string $attrs, array $custom): ?string
    {
        $blocks = '<!-- wp:gratora/donation-amount {"presets":[{"cents":2500}]} /-->'
            . '<!-- wp:gratora/multi-select ' . $attrs . ' /-->'
            . '<!-- wp:gratora/submit-button /-->';

        $result = (new FormSubmissionValidator())->validate($this->form($blocks), [
            'amount_cents' => 2500,
            'frequency'    => 'one_time',
            'profile'      => ['first_name' => 'Ada'],
            'custom'       => $custom,
        ]);

        return $result === null ? null : (string) $result->get_error_message();
    }

    private const ONE_OPTION = '{"label":"Extras","options":[{"label":"Tote","value":"tote"}]';

    public function test_a_minimum_above_the_option_count_is_brought_down(): void
    {
        $this->assertNull(
            $this->validate(self::ONE_OPTION . ',"minSelections":5}', ['extras' => ['tote']]),
            'picking everything there is has to be enough'
        );
    }

    public function test_a_minimum_above_the_maximum_is_brought_down(): void
    {
        $attrs = '{"label":"Extras","options":['
            . '{"label":"a","value":"a"},{"label":"b","value":"b"},{"label":"c","value":"c"}'
            . '],"minSelections":3,"maxSelections":2}';

        $this->assertNull($this->validate($attrs, ['extras' => ['a', 'b']]));
    }

    public function test_a_reachable_minimum_is_still_enforced(): void
    {
        $attrs = '{"label":"Extras","options":['
            . '{"label":"a","value":"a"},{"label":"b","value":"b"},{"label":"c","value":"c"}'
            . '],"minSelections":2}';

        $this->assertNotNull($this->validate($attrs, ['extras' => ['a']]));
        $this->assertNull($this->validate($attrs, ['extras' => ['a', 'b']]));
    }

    public function test_a_maximum_is_still_enforced(): void
    {
        $attrs = '{"label":"Extras","options":['
            . '{"label":"a","value":"a"},{"label":"b","value":"b"},{"label":"c","value":"c"}'
            . '],"maxSelections":2}';

        $this->assertNotNull($this->validate($attrs, ['extras' => ['a', 'b', 'c']]));
    }

    public function test_the_rendered_field_carries_the_clamped_limits(): void
    {
        $html = (new \Gratora\Forms\Blocks\MultiSelectBlock())->render([
            'label'         => 'Extras',
            'options'       => [['label' => 'Tote', 'value' => 'tote']],
            'minSelections' => 5,
            'maxSelections' => 9,
        ], '');

        $this->assertStringContainsString('data-min="1"', $html);
        $this->assertStringContainsString('data-max="1"', $html);
    }
}
