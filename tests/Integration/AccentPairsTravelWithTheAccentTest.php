<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Campaigns\Campaign;
use Gratora\Campaigns\Styling\CampaignStyleResolver;
use Gratora\Campaigns\Styling\CampaignStyleVars;
use Gratora\Forms\Form;

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
            'tokens'    => ['gratora-accent' => '#c62828'],
        ]);

        $tokens = (new CampaignStyleResolver())->resolveForCampaign($campaign);

        $this->assertSame('#c62828', $tokens['gratora-accent']);
        $this->assertArrayNotHasKey('gratora-accent-soft', $tokens);
        $this->assertArrayNotHasKey('gratora-focus-ring', $tokens);
    }

    public function test_a_tint_chosen_beside_the_accent_stays(): void
    {
        $campaign = $this->campaign([
            'preset_id' => 'bold',
            'tokens'    => ['gratora-accent' => '#c62828', 'gratora-accent-soft' => '#fbe9e7'],
        ]);

        $tokens = (new CampaignStyleResolver())->resolveForCampaign($campaign);

        $this->assertSame('#fbe9e7', $tokens['gratora-accent-soft']);
        $this->assertArrayNotHasKey('gratora-focus-ring', $tokens);
    }

    public function test_a_preset_nothing_overrides_keeps_the_pair_it_ships(): void
    {
        $campaign = $this->campaign(['preset_id' => 'bold']);

        $tokens = (new CampaignStyleResolver())->resolveForCampaign($campaign);

        $this->assertSame('#dde6ed', $tokens['gratora-accent-soft']);
        $this->assertSame('#0F3D5C', $tokens['gratora-focus-ring']);
    }

    public function test_a_form_that_repaints_the_accent_loses_the_presets_tint(): void
    {
        $campaign = $this->campaign([
            'preset_id' => 'bold',
            'tokens'    => ['gratora-accent' => '#c62828'],
        ]);
        $form = Form::make();
        $form->settings = [];

        $resolved = (new CampaignStyleResolver())->resolve($form, $campaign);

        $this->assertSame('#c62828', $resolved['tokens']['gratora-accent']);
        $this->assertArrayNotHasKey('gratora-accent-soft', $resolved['tokens']);
    }

    public function test_a_built_in_the_org_repainted_in_the_brand_panel_loses_its_tint(): void
    {
        update_option('gratora_org_brand', ['presets' => [[
            'id'     => 'bold',
            'name'   => 'Bold',
            'tokens' => ['gratora-accent' => '#c62828'],
        ]]]);

        $tokens = (new CampaignStyleResolver())->resolveForCampaign(
            $this->campaign(['preset_id' => 'bold'])
        );

        $this->assertSame('#c62828', $tokens['gratora-accent']);
        $this->assertArrayNotHasKey('gratora-accent-soft', $tokens);
        $this->assertArrayNotHasKey('gratora-focus-ring', $tokens);
    }

    /**
     * Dropped from the map is unset on a page of its own, and inherited from
     * the host when the wrapper sits in another campaign's page: its selected
     * tiles took the host's tint. So the drop is stated.
     */
    public function test_a_dropped_pair_is_stated_as_unset_where_the_map_is_written(): void
    {
        $css = CampaignStyleVars::forCampaign($this->saved([
            'preset_id' => 'bold',
            'tokens'    => ['gratora-accent' => '#c62828'],
        ]));

        $this->assertStringContainsString('--gratora-accent-soft:initial;', $css);
        $this->assertStringContainsString('--gratora-focus-ring:initial;', $css);
    }

    public function test_a_pair_that_stands_is_not_unset(): void
    {
        $css = CampaignStyleVars::forCampaign($this->saved(['preset_id' => 'bold']));

        $this->assertStringContainsString('--gratora-accent-soft:#dde6ed;', $css);
        $this->assertStringNotContainsString('--gratora-accent-soft:initial;', $css);
    }

    /** A guest wrapper's style attribute goes through kses on the way out. */
    public function test_the_whole_map_survives_kses_byte_for_byte(): void
    {
        kses_init_filters();

        $css = CampaignStyleVars::forCampaign($this->saved([
            'preset_id' => 'bold',
            'tokens'    => ['gratora-accent' => '#c62828', 'gratora-bg' => '#f55151'],
        ]));

        $this->assertStringContainsString('--gratora-accent-soft:initial;', $css);
        $this->assertStringContainsString('--gratora-on-bg-muted:rgba(16,22,42,.86);', $css);
        $this->assertSame(rtrim($css, ';'), safecss_filter_attr($css));
    }

    private function saved(array $style): Campaign
    {
        $now = gmdate('Y-m-d H:i:s');
        $c = $this->campaign($style);
        $c->title      = 'Pair';
        $c->slug       = 'pair-' . uniqid();
        $c->status     = 'published';
        $c->created_at = $now;
        $c->updated_at = $now;
        $c->save();

        CampaignStyleVars::flush();

        return $c;
    }
}
