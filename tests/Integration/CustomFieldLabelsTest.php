<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Forms\Blocks\CustomFieldLabels;
use Dono\Forms\Blocks\DateBlock;
use Dono\Forms\Blocks\DropdownBlock;

/**
 * Slug => label resolution must use the same slug derivation buildSteps
 * applies, recurse into layout containers, and skip unlabelled fields.
 */
final class CustomFieldLabelsTest extends IntegrationTestCase
{
    public function test_resolves_text_and_choice_fields_with_canonical_slugs(): void
    {
        $blocks = <<<BLOCKS
<!-- wp:dono/text-input {"field":"Donor Org","label":"Your organization"} /-->
<!-- wp:dono/dropdown {"label":"How did you hear?"} /-->
BLOCKS;

        $map = CustomFieldLabels::forBlocks($blocks);

        $textSlug = DateBlock::slugifyField('Donor Org');
        $ddSlug   = DropdownBlock::deriveField('', 'How did you hear?');

        $this->assertSame('donor_org', $textSlug, 'sanity: slug algorithm unchanged');
        $this->assertSame('Your organization', $map[$textSlug] ?? null);
        $this->assertSame('How did you hear?', $map[$ddSlug] ?? null);
    }

    public function test_recurses_into_layout_containers(): void
    {
        $blocks = <<<BLOCKS
<!-- wp:dono/row {"columns":2} -->
<!-- wp:dono/text-input {"field":"city","label":"City"} /-->
<!-- wp:dono/text-input {"field":"region","label":"Region"} /-->
<!-- /wp:dono/row -->
BLOCKS;

        $map = CustomFieldLabels::forBlocks($blocks);

        $this->assertSame('City', $map[DateBlock::slugifyField('city')] ?? null);
        $this->assertSame('Region', $map[DateBlock::slugifyField('region')] ?? null);
    }

    public function test_empty_field_key_derives_the_key_from_the_label(): void
    {
        // A blank field key derives from the label (like dropdown/radio), the
        // same key the runtime submits under, so the answer is labelled.
        $map = CustomFieldLabels::forBlocks(<<<BLOCKS
<!-- wp:dono/text-input {"label":"Free text"} /-->
<!-- wp:dono/number-input {"label":"A number"} /-->
<!-- wp:dono/date {"label":"A date"} /-->
BLOCKS);

        $this->assertSame('Free text', $map['free_text'] ?? null);
        $this->assertSame('A number',  $map['a_number']  ?? null);
        $this->assertSame('A date',    $map['a_date']    ?? null);
    }

    public function test_unlabelled_and_empty_inputs_are_skipped(): void
    {
        // Hidden field with no label contributes no display row.
        $map = CustomFieldLabels::forBlocks(
            '<!-- wp:dono/hidden {"field":"utm_source"} /-->'
        );
        $this->assertArrayNotHasKey('utm_source', $map);

        $this->assertSame([], CustomFieldLabels::forBlocks(''));
        $this->assertSame([], CustomFieldLabels::forBlocks('   '));
    }
}
