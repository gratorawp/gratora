<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Campaigns\Campaign;
use Dono\Campaigns\Styling\CampaignStyleResolver;
use Dono\Forms\Form;

/**
 * The style cascade contract the form editor preview mirrors: campaign inline
 * token overrides apply only when the form has NOT chosen its own preset. A
 * form that picks its own preset ignores the campaign's inline overrides.
 */
final class FormStyleCascadeTest extends IntegrationTestCase
{
    private function campaignWithInlineAccent(string $hex): Campaign
    {
        $c = Campaign::make();
        $c->style = ['preset_id' => 'classic', 'tokens' => ['dono-accent' => $hex]];
        return $c;
    }

    public function test_campaign_inline_tokens_apply_when_form_has_no_own_preset(): void
    {
        $campaign = $this->campaignWithInlineAccent('#ff0000');
        $form = Form::make();
        $form->settings = [];

        $resolved = (new CampaignStyleResolver())->resolve($form, $campaign);

        $this->assertSame('#ff0000', $resolved['tokens']['dono-accent']);
        $this->assertSame('#ff0000', $resolved['accent']);
        $this->assertSame('classic', $resolved['preset_id']);
    }

    public function test_form_own_preset_gates_out_campaign_inline_tokens(): void
    {
        $campaign = $this->campaignWithInlineAccent('#ff0000');
        $form = Form::make();
        $form->settings = ['style' => ['preset_id' => 'bold']];

        $resolved = (new CampaignStyleResolver())->resolve($form, $campaign);

        // Bold preset's own accent wins; the campaign inline override is gated
        // out because the form picked its own preset.
        $this->assertSame('#0F3D5C', $resolved['tokens']['dono-accent']);
        $this->assertNotSame('#ff0000', $resolved['tokens']['dono-accent']);
        $this->assertSame('bold', $resolved['preset_id']);
    }

    public function test_default_accent_soft_is_dropped_so_runtime_derives_it(): void
    {
        // Campaign customizes the accent but not the soft tint; nothing pairs
        // a soft with it, so the resolver must drop the catalogue-default soft
        // and let the stylesheet color-mix derive it from --dono-accent.
        $campaign = $this->campaignWithInlineAccent('#ff0000');
        $form = Form::make();
        $form->settings = [];

        $resolved = (new CampaignStyleResolver())->resolve($form, $campaign);

        $this->assertArrayNotHasKey('dono-accent-soft', $resolved['tokens']);
    }

    public function test_preset_paired_accent_soft_is_kept(): void
    {
        // Bold pairs its own accent-soft; it must survive (not be dropped).
        $form = Form::make();
        $form->settings = ['style' => ['preset_id' => 'bold']];

        $resolved = (new CampaignStyleResolver())->resolve($form, null);

        $this->assertSame('#dde6ed', $resolved['tokens']['dono-accent-soft']);
    }
}
