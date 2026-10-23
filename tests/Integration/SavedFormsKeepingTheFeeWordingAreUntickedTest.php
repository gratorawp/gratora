<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Forms\FeeBoxDefaults;
use Gratora\Forms\Form;

/**
 * An installed site's forms made from a released template ticked the fee box.
 * On the first request after the update, each one that still carries that
 * template's wording is unticked and reads the new label; a label its owner
 * wrote is left alone.
 */
final class SavedFormsKeepingTheFeeWordingAreUntickedTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        delete_option(FeeBoxDefaults::DONE);
    }

    private function savedForm(string $label, bool $ticked = true): Form
    {
        $f = Form::make();
        $f->title  = 'Saved';
        $f->slug   = 'saved-' . uniqid();
        $f->status = 'published';
        // As a 1.2.3 template wrote it.
        $f->blocks = "<!-- wp:gratora/donation-amount {\"presets\":[{\"cents\":5000}]} /-->\n"
            . '<!-- wp:gratora/cover-fees ' . wp_json_encode(['percent' => 2.9, 'fixed' => 30, 'label' => $label, 'defaultOn' => $ticked]) . " /-->\n"
            . "<!-- wp:gratora/payment-gateways {\"style\":\"cards\"} /-->\n";
        $f->created_at = gmdate('Y-m-d H:i:s');
        $f->updated_at = $f->created_at;
        $f->save();

        return $f;
    }

    private function blocksOf(Form $form): string
    {
        return (string) Form::query()->find('id', (int) $form->id)->blocks;
    }

    /** @return array<string,mixed> */
    private function feeBox(Form $form): array
    {
        foreach (parse_blocks($this->blocksOf($form)) as $block) {
            if ($block['blockName'] === 'gratora/cover-fees') {
                return (array) $block['attrs'];
            }
        }

        return [];
    }

    public function test_a_form_that_kept_the_template_wording_is_unticked_and_relabelled(): void
    {
        $form = $this->savedForm('Cover the processing fee so 100% reaches the campaign');

        FeeBoxDefaults::maybeRun();

        $this->assertFalse($this->feeBox($form)['defaultOn']);
        $this->assertSame('Add {fee} to cover processing fees', $this->feeBox($form)['label']);
        $this->assertStringStartsWith('<!-- wp:gratora/donation-amount {"presets":[{"cents":5000}]} /-->', $this->blocksOf($form));
        $this->assertStringEndsWith("<!-- wp:gratora/payment-gateways {\"style\":\"cards\"} /-->\n", $this->blocksOf($form));
    }

    public function test_the_wording_as_it_was_stored_in_german_or_spanish_is_recognised_and_kept_in_that_language(): void
    {
        $german  = $this->savedForm('Transaktionsgebühr übernehmen, damit 100 % bei der Kampagne ankommen');
        $spanish = $this->savedForm("Cubriré las comisiones para que el 100\u{a0}% llegue a la causa");

        FeeBoxDefaults::maybeRun();

        $this->assertFalse($this->feeBox($german)['defaultOn']);
        $this->assertSame('{fee} hinzufügen, um die Transaktionsgebühren zu übernehmen', $this->feeBox($german)['label']);
        $this->assertFalse($this->feeBox($spanish)['defaultOn']);
        $this->assertSame('Añadir {fee} para cubrir las comisiones de procesamiento', $this->feeBox($spanish)['label']);
    }

    public function test_a_label_its_owner_changed_is_left_exactly_as_it_is(): void
    {
        $form   = $this->savedForm('Help us cover the card fee');
        $before = $this->blocksOf($form);

        FeeBoxDefaults::maybeRun();

        $this->assertSame($before, $this->blocksOf($form));
    }

    public function test_it_runs_once_and_running_it_again_changes_nothing(): void
    {
        $form = $this->savedForm("I'll cover the fees so 100% goes to the cause");

        FeeBoxDefaults::maybeRun();
        $after = $this->blocksOf($form);
        $this->assertNotFalse(get_option(FeeBoxDefaults::DONE));

        delete_option(FeeBoxDefaults::DONE);
        FeeBoxDefaults::maybeRun();
        $this->assertSame($after, $this->blocksOf($form), 'a second run, as after an interruption, changed the form again');

        $later = $this->savedForm('Cover the processing fee on each payment');
        FeeBoxDefaults::maybeRun();
        $this->assertTrue($this->feeBox($later)['defaultOn'], 'ran again although it had recorded that it ran');
    }

    public function test_it_runs_on_the_first_request_after_the_update(): void
    {
        $this->assertSame(100, has_action('wp_loaded', [FeeBoxDefaults::class, 'maybeRun']));
    }
}
