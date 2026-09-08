<?php

declare(strict_types=1);

namespace FundKit\Tests\Integration;

use FundKit\Campaigns\Campaign;
use FundKit\Campaigns\Styling\CampaignStyleResolver;
use FundKit\Forms\Form;

/**
 * Bold pairs a navy tint and a navy focus ring with its navy accent. Repaint
 * the accent and those two are no longer about anything: the stylesheet derives
 * both from the resolved accent, but only while the tokens are absent.
 */
final class AccentPairsTravelWithTheAccentTest extends IntegrationTestCase
{
    private function campaign(array $style): Campaign
    {
        $c = Campaign::make();
        $c->style = $style;
        return $c;
    }

    public function test_a_campaign_that_repaints_a_presets_accent_loses_its_tint(): void
    {
        $campaign = $this->campaign([
            'preset_id' => 'bold',
            'tokens'    => ['fundkit-accent' => '#c62828'],
        ]);

        $tokens = (new CampaignStyleResolver())->resolveForCampaign($campaign);

        $this->assertSame('#c62828', $tokens['fundkit-accent']);
        $this->assertArrayNotHasKey('fundkit-accent-soft', $tokens);
        $this->assertArrayNotHasKey('fundkit-focus-ring', $tokens);
    }

    public function test_a_tint_chosen_beside_the_accent_stays(): void
    {
        $campaign = $this->campaign([
            'preset_id' => 'bold',
            'tokens'    => ['fundkit-accent' => '#c62828', 'fundkit-accent-soft' => '#fbe9e7'],
        ]);

        $tokens = (new CampaignStyleResolver())->resolveForCampaign($campaign);

        $this->assertSame('#fbe9e7', $tokens['fundkit-accent-soft']);
        $this->assertArrayNotHasKey('fundkit-focus-ring', $tokens);
    }

    public function test_a_preset_nothing_overrides_keeps_the_pair_it_ships(): void
    {
        $campaign = $this->campaign(['preset_id' => 'bold']);

        $tokens = (new CampaignStyleResolver())->resolveForCampaign($campaign);

        $this->assertSame('#dde6ed', $tokens['fundkit-accent-soft']);
        $this->assertSame('#0F3D5C', $tokens['fundkit-focus-ring']);
    }

    public function test_a_form_that_repaints_the_accent_loses_the_presets_tint(): void
    {
        $campaign = $this->campaign([
            'preset_id' => 'bold',
            'tokens'    => ['fundkit-accent' => '#c62828'],
        ]);
        $form = Form::make();
        $form->settings = [];

        $resolved = (new CampaignStyleResolver())->resolve($form, $campaign);

        $this->assertSame('#c62828', $resolved['tokens']['fundkit-accent']);
        $this->assertArrayNotHasKey('fundkit-accent-soft', $resolved['tokens']);
    }

    public function test_a_built_in_the_org_repainted_in_the_brand_panel_loses_its_tint(): void
    {
        update_option('fundkit_org_brand', ['presets' => [[
            'id'     => 'bold',
            'name'   => 'Bold',
            'tokens' => ['fundkit-accent' => '#c62828'],
        ]]]);

        $tokens = (new CampaignStyleResolver())->resolveForCampaign(
            $this->campaign(['preset_id' => 'bold'])
        );

        $this->assertSame('#c62828', $tokens['fundkit-accent']);
        $this->assertArrayNotHasKey('fundkit-accent-soft', $tokens);
        $this->assertArrayNotHasKey('fundkit-focus-ring', $tokens);
    }
}
