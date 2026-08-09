<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Campaigns\Campaign;

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
            ['dono/campaign-image'],
            ['dono/campaign-progress'],
            ['dono/campaign-stat'],
            ['dono/donate-button'],
            ['dono/top-donors'],
            ['dono/recent-donations'],
            ['dono/supporter-wall'],
            ['dono/campaign-grid'],
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
            '<!-- wp:dono/campaign-progress {"campaignId":' . $id
            . ',"style":{"color":{"background":"#ff0000","text":"#0000ff"}}} /-->'
        );

        $this->assertStringContainsString('background-color:#ff0000', $html);
        $this->assertStringContainsString('color:#0000ff', $html);
    }

    public function test_padding_chosen_in_the_editor_reaches_the_markup(): void
    {
        $id = (int) $this->campaign()->id;

        $html = do_blocks(
            '<!-- wp:dono/campaign-progress {"campaignId":' . $id
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
            '<!-- wp:dono/campaign-progress {"campaignId":' . $id
            . ',"style":{"color":{"background":"#ff0000"}}} /-->'
        );

        $this->assertStringContainsString('dono-block--progress', $html);
        $this->assertStringContainsString('data-block="dono/campaign-progress"', $html);
    }
}
