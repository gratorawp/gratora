<?php

declare(strict_types=1);

namespace FundKit\Tests\Integration;

use FundKit\Campaigns\Campaign;
use FundKit\Campaigns\Styling\CampaignStyleVars;
use FundKit\Forms\Blocks\SectionBlock;

/**
 * A campaign page is an ordinary page, so its blocks answer to the editor's own
 * colour, spacing and type controls. Declaring the supports is only half of it:
 * without get_block_wrapper_attributes() on the root element the controls appear
 * in the inspector and then do nothing.
 */
final class CampaignBlocksStyleSupportTest extends IntegrationTestCase
{
    /** @return list<array{0:string}> */
    public function campaignBlockNames(): array
    {
        return [
            ['fundkit/campaign-image'],
            ['fundkit/campaign-progress'],
            ['fundkit/campaign-stat'],
            ['fundkit/donate-button'],
            ['fundkit/top-donors'],
            ['fundkit/recent-donations'],
            ['fundkit/supporter-wall'],
            ['fundkit/campaign-grid'],
        ];
    }

    /**
     * @dataProvider campaignBlockNames
     */
    public function test_campaign_block_declares_the_style_groups(string $blockName): void
    {
        $blockType = \WP_Block_Type_Registry::get_instance()->get_registered($blockName);
        $this->assertNotNull($blockType, "Block {$blockName} is registered");

        foreach (['color', 'spacing', 'typography'] as $group) {
            $this->assertNotFalse(
                block_has_support($blockType, $group, false),
                "Block {$blockName} declares {$group} support"
            );
        }
    }

    private function campaign(): Campaign
    {
        $c = Campaign::make();
        $c->title      = 'Styled ' . uniqid();
        $c->slug       = 'styled-' . uniqid();
        $c->status     = 'published';
        $c->currency   = 'USD';
        $c->goal_cents = 100000;
        $c->created_at = gmdate('Y-m-d H:i:s');
        $c->updated_at = $c->created_at;
        $c->save();

        return $c;
    }

    public function test_a_colour_chosen_in_the_editor_reaches_the_markup(): void
    {
        $id = (int) $this->campaign()->id;

        $html = do_blocks(
            '<!-- wp:fundkit/campaign-progress {"campaignId":' . $id
            . ',"style":{"color":{"background":"#ff0000","text":"#0000ff"}}} /-->'
        );

        $this->assertStringContainsString('background-color:#ff0000', $html);
        $this->assertStringContainsString('color:#0000ff', $html);
    }

    public function test_padding_chosen_in_the_editor_reaches_the_markup(): void
    {
        $id = (int) $this->campaign()->id;

        $html = do_blocks(
            '<!-- wp:fundkit/campaign-progress {"campaignId":' . $id
            . ',"style":{"spacing":{"padding":{"top":"40px"}}}} /-->'
        );

        $this->assertStringContainsString('padding-top:40px', $html);
    }

    /**
     * The block's own classes are what every stylesheet in the plugin hangs off,
     * so merging in core's must not cost them.
     */
    public function test_the_blocks_own_classes_survive_the_merge(): void
    {
        $id = (int) $this->campaign()->id;

        $html = do_blocks(
            '<!-- wp:fundkit/campaign-progress {"campaignId":' . $id
            . ',"style":{"color":{"background":"#ff0000"}}} /-->'
        );

        $this->assertStringContainsString('fundkit-block--progress', $html);
        $this->assertStringContainsString('data-block="fundkit/campaign-progress"', $html);
    }

    public function test_a_chosen_shadow_reaches_a_block_for_another_campaign(): void
    {
        $c = $this->campaign();
        $c->style = ['tokens' => [
            'fundkit-accent'      => '#7c3aed',
            'fundkit-card-shadow' => '0 1px 2px rgba(15, 23, 42, .04)',
        ]];
        $c->save();
        CampaignStyleVars::flush();

        $html = do_blocks('<!-- wp:fundkit/campaign-progress {"campaignId":' . (int) $c->id . '} /-->');

        $this->assertStringContainsString('--fundkit-accent:#7c3aed', $html);
        $this->assertStringContainsString('--fundkit-card-shadow:0 1px 2px rgba(15, 23, 42, .04)', $html);
    }

    public function test_a_section_keeps_the_shadow_and_the_panel_colour_its_author_picked(): void
    {
        $style = SectionBlock::sectionStyle([
            'shadow'     => '0 4px 14px rgba(15,23,42,.10)',
            'background' => 'rgba(255,255,255,.6)',
        ]);

        $this->assertStringContainsString('box-shadow:0 4px 14px rgba(15,23,42,.10)', $style);
        $this->assertStringContainsString('background-color:rgba(255,255,255,.6)', $style);
    }

    public function test_a_declaration_core_rejects_is_still_rejected(): void
    {
        $style = SectionBlock::sectionStyle([
            'shadow' => '0 0 0 rgb(url(javascript:alert(1)))',
        ]);

        $this->assertSame('', $style);
    }
}
