<?php

declare(strict_types=1);

namespace Dono\Tests\Unit\Assets;

use PHPUnit\Framework\TestCase;

/**
 * Pressing the donate button on a long form scrolls to the first invalid
 * field, and the only thing it can find one by is aria-invalid. A validated
 * field that never sets it leaves the donor looking at an unchanged button
 * with the message a screen or two above them, which reads as a dead click.
 *
 * @since 1.0.0
 */
final class DonationFormInvalidFieldFocusTest extends TestCase
{
    private function source(string $file): string
    {
        $path = dirname(__DIR__, 3) . '/assets/donation-form/' . $file;
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /** The body of one `case '<kind>':` arm of the field renderer. */
    private function fieldCase(string $kind): string
    {
        $src   = $this->source('steps/DonorStep.jsx');
        $start = strpos($src, "case '" . $kind . "': {");
        $this->assertIsInt($start, 'DonorStep no longer renders a ' . $kind . ' field.');

        $end = strpos($src, "case '", (int) $start + 8);
        $this->assertIsInt($end, 'the ' . $kind . ' arm is the last one, so its end cannot be found.');

        return substr($src, (int) $start, (int) $end - (int) $start);
    }

    public function test_the_scroll_target_is_still_aria_invalid(): void
    {
        // The assertions below are only worth anything while this holds.
        $this->assertStringContainsString(
            '[aria-invalid="true"]',
            $this->source('runtime.jsx'),
            'focusFirstInvalid finds the field to scroll to by this attribute.'
        );
    }

    public function test_a_required_radio_group_can_be_scrolled_to(): void
    {
        $arm = $this->fieldCase('radio');

        // Proves the arm was isolated, not a span of the whole renderer.
        $this->assertStringContainsString('type="radio"', $arm);
        $this->assertStringNotContainsString("case 'checkbox'", $arm);
        $this->assertStringContainsString(
            'aria-invalid={ !! err[ errKey ] }',
            $arm,
            'a radio group is validated as required, so it has to be findable when it fails.'
        );
    }

    public function test_a_multi_select_can_be_scrolled_to(): void
    {
        $arm = $this->fieldCase('multi-select');

        $this->assertStringContainsString('dono-form__multi-select-option', $arm);
        $this->assertStringNotContainsString("case 'hidden'", $arm);
        $this->assertStringContainsString(
            'aria-invalid={ !! err[ errKey ] }',
            $arm,
            'a multi-select is validated for required, minimum and maximum choices.'
        );
    }

    /**
     * Landing on the group is half of it. The group is a div taking focus
     * programmatically, which matches no :focus-visible rule and draws no
     * outline of its own, so the invalid state has to carry the mark.
     */
    public function test_the_radio_group_the_donor_lands_on_looks_invalid(): void
    {
        $css   = $this->source('runtime.scss');
        $start = strpos($css, '&__radio-options[aria-invalid="true"] {');
        $this->assertIsInt($start, 'nothing marks out an invalid radio group.');

        $end = strpos($css, '}', (int) $start);
        $this->assertIsInt($end);

        $this->assertMatchesRegularExpression(
            '/outline:[^;]*var\(\s*--dono-error-fg/',
            substr($css, (int) $start, (int) $end - (int) $start),
            'the mark has to be visible, and themed like every other error.'
        );
    }

    /**
     * Both kinds are genuinely validated, so the attribute is not decorative.
     */
    public function test_both_kinds_are_validated_client_side(): void
    {
        $store = $this->source('state/store.js');

        $this->assertStringContainsString("f.kind === 'radio'", $store);
        $this->assertStringContainsString("f.kind === 'multi-select'", $store);
    }
}
