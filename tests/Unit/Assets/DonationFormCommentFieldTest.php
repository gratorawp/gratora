<?php

declare(strict_types=1);

namespace Dono\Tests\Unit\Assets;

use PHPUnit\Framework\TestCase;

/**
 * The message field is the one donor field that carries a control of its own:
 * the checkbox that puts the message on the supporter wall. Field renders a
 * <label>, so a checkbox left inside it is a label nested in a label, and the
 * outer one then names two controls at once. A screen reader reading the
 * message box announces the consent sentence as part of its name, and the donor
 * hears a request for permission where the field's own caption should be.
 *
 * So a field holding a second control names its own control by id instead, and
 * the checkbox keeps the label it already had.
 */
final class DonationFormCommentFieldTest extends TestCase
{
    private function source(): string
    {
        $path = dirname(__DIR__, 3) . '/assets/donation-form/steps/DonorStep.jsx';
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /** Every `<Field …>…</Field>` in the donor step, outermost markup included. */
    private function fieldBlocks(): array
    {
        preg_match_all('/<Field\b.*?<\/Field>/s', $this->source(), $m);

        $this->assertNotSame([], $m[0], 'the donor step no longer renders any Field.');

        return $m[0];
    }

    private function commentBlock(): string
    {
        $matched = preg_match(
            "/case 'comment':.*?<\/Field>/s",
            $this->source(),
            $m
        );
        $this->assertSame(1, $matched, 'the message field is no longer a `comment` case.');

        return (string) $m[0];
    }

    public function test_no_field_wraps_a_control_that_carries_its_own_label(): void
    {
        $offenders = [];
        foreach ($this->fieldBlocks() as $block) {
            if (! str_contains($block, '<label')) continue;
            if (preg_match('/<Field\b[^>]*htmlFor=/s', $block)) continue;

            $offenders[] = trim(explode("\n", $block)[0]);
        }

        $this->assertSame(
            [],
            $offenders,
            "A Field holding a <label> must name its own control through htmlFor, "
            . "or the two labels nest:\n" . implode("\n", $offenders)
        );
    }

    public function test_the_message_fields_caption_names_the_textarea(): void
    {
        $block = $this->commentBlock();

        // The id has to be per-field, so both sides are template literals: two
        // forms on one page would otherwise share it.
        $matched = preg_match('/htmlFor=\{\s*(`[^`]+`)\s*\}/', $block, $caption);
        $this->assertSame(1, $matched, 'the message field caption points at nothing.');

        $matched = preg_match('/<textarea\b.*?\bid=\{\s*(`[^`]+`)\s*\}/s', $block, $control);
        $this->assertSame(1, $matched, 'the message box has no id for a caption to point at.');

        $this->assertSame(
            $caption[1],
            $control[1],
            'the caption and the message box must agree on the id, or the caption names nothing.'
        );
    }

    public function test_the_public_message_checkbox_keeps_its_own_label(): void
    {
        $this->assertMatchesRegularExpression(
            '/<label class="dono-form__check">\s*<input\s+type="checkbox"/s',
            $this->commentBlock(),
            'the supporter-wall checkbox lost the label that names it.'
        );
    }

    /**
     * A caption that is a <label> only reaches its control through `for`, so a
     * Field given htmlFor must stop being a label itself: two labels around one
     * textarea is the same defect in the other direction.
     */
    public function test_a_field_that_names_its_control_by_id_is_not_a_label_itself(): void
    {
        $matched = preg_match('/function Field\(.*?\n\}/s', $this->source(), $m);
        $this->assertSame(1, $matched, 'Field is no longer a function in the donor step.');

        $this->assertStringNotContainsString(
            '<label class="dono-form__field">',
            (string) $m[0],
            'Field wraps every field in a label again, so a field with two controls nests them.'
        );
    }

    /**
     * A class with no rule renders unstyled, and this form has shipped one
     * before. Both classes the message field leans on have to exist in the
     * runtime stylesheet.
     */
    public function test_the_classes_the_message_field_renders_are_styled(): void
    {
        $path = dirname(__DIR__, 3) . '/assets/donation-form/runtime.scss';
        $this->assertFileExists($path);
        $css = (string) file_get_contents($path);

        foreach (['__field', '__label', '__check'] as $suffix) {
            $this->assertMatchesRegularExpression(
                '/&' . $suffix . '\s*\{/',
                $css,
                "dono-form{$suffix} has no rule in runtime.scss."
            );
        }
    }
}
