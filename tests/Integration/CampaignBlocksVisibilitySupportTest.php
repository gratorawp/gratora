<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

/**
 * Verifies each campaign block opts into the WP 7.0 responsive visibility
 * control, so site authors can hide them per viewport from the inspector.
 *
 * The render-side proof is `block_has_support`: WordPress's own checker
 * returns true only when the block's `supports.visibility` flag is set.
 */
final class CampaignBlocksVisibilitySupportTest extends IntegrationTestCase
{
    /** @return list<array{0:string}> */
    public function campaignBlockNames(): array
    {
        return [
            ['dono/campaign-hero'],
            ['dono/campaign-progress'],
            ['dono/campaign-stats'],
            ['dono/donate-button'],
            ['dono/top-donors'],
            ['dono/recent-donations'],
            ['dono/supporter-wall'],
        ];
    }

    /**
     * @dataProvider campaignBlockNames
     */
    public function test_campaign_block_supports_responsive_visibility(string $blockName): void
    {
        if (! function_exists('block_has_support')) {
            $this->markTestSkipped('WP 7.0 block_has_support() not present.');
        }

        $blockType = \WP_Block_Type_Registry::get_instance()->get_registered($blockName);
        $this->assertNotNull($blockType, "Block {$blockName} is registered");

        $this->assertTrue(
            block_has_support($blockType, 'visibility', false),
            "Block {$blockName} opts into responsive visibility"
        );
    }
}
