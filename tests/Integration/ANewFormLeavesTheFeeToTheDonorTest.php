<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Campaigns\Campaign;
use Gratora\Forms\Form;
use Gratora\Forms\FormTemplates;
use WP_REST_Request;

/**
 * The cover-the-fee box starts unticked on every form Gratora makes, so a $50
 * donation is $50 until the donor chooses otherwise.
 */
final class ANewFormLeavesTheFeeToTheDonorTest extends IntegrationTestCase
{
    /** @return list<array<string,mixed>> */
    private function feeBoxes(string $blocks): array
    {
        $found = [];
        $walk  = static function (array $list) use (&$walk, &$found): void {
            foreach ($list as $block) {
                if (($block['blockName'] ?? '') === 'gratora/cover-fees') {
                    $found[] = (array) $block['attrs'];
                }
                $walk((array) ($block['innerBlocks'] ?? []));
            }
        };
        $walk(parse_blocks($blocks));

        return $found;
    }

    public function test_the_page_the_setup_makes_leaves_the_box_unticked(): void
    {
        $res      = rest_do_request(new WP_REST_Request('POST', '/gratora/v1/admin/onboarding/starter-campaign'));
        $campaign = Campaign::query()->find('id', (int) $res->get_data()['campaign_id']);
        $form     = Form::query()->find('id', (int) $campaign->default_form_id);

        $boxes = $this->feeBoxes((string) $form->blocks);
        $this->assertCount(1, $boxes);
        $this->assertFalse($boxes[0]['defaultOn']);
    }

    public function test_no_form_template_ticks_it(): void
    {
        foreach (FormTemplates::all() as $template) {
            foreach ($this->feeBoxes((string) $template['blocks']) as $attrs) {
                $this->assertFalse((bool) ($attrs['defaultOn'] ?? false), $template['id'] . ' ticks the fee box');
            }
        }
    }
}
