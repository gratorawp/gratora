<?php

declare(strict_types=1);

namespace FundKit\Tests\Integration;

use FundKit\Campaigns\Campaign;
use FundKit\Campaigns\Styling\CampaignStyleVars;
use FundKit\Forms\Form;

/**
 * The two radii are deliberately absent from the catalogue so they inherit
 * radius-sm. Unset is a working answer for one surface; nested inside a page
 * that already declared them for another preset, it hands that page's value to
 * a form or a block that never chose it.
 */
final class PassThroughRadiiAreStatedTest extends IntegrationTestCase
{
    private function campaign(string $presetId = ''): Campaign
    {
        $c = Campaign::make();
        $c->title      = 'Radii ' . uniqid();
        $c->slug       = 'radii-' . uniqid();
        $c->status     = 'published';
        $c->currency   = 'USD';
        $c->goal_cents = 100000;
        $c->style      = $presetId !== '' ? ['preset_id' => $presetId] : null;
        $c->created_at = gmdate('Y-m-d H:i:s');
        $c->updated_at = $c->created_at;
        $c->save();

        return $c;
    }

    private function form(int $campaignId, string $presetId): Form
    {
        $f = Form::make();
        $f->title       = 'Radii form ' . uniqid();
        $f->slug        = 'radii-form-' . uniqid();
        $f->status      = 'published';
        $f->campaign_id = $campaignId;
        $f->blocks      = '<!-- wp:fundkit/donation-amount {"presets":[1000]} /-->';
        $f->settings    = ['style' => ['preset_id' => $presetId]];
        $f->created_at  = gmdate('Y-m-d H:i:s');
        $f->updated_at  = $f->created_at;
        $f->save();

        return $f;
    }

    public function test_a_form_on_a_square_preset_states_its_own_button_radius(): void
    {
        $campaign = $this->campaign('classic');
        $form     = $this->form((int) $campaign->id, 'quiet');

        $html = do_shortcode('[fundkit_donation_form slug="' . $form->slug . '"]');

        $this->assertStringContainsString('--fundkit-button-radius:var(--fundkit-radius-sm, 8px)', $html);
        $this->assertStringContainsString('--fundkit-switcher-radius:var(--fundkit-radius-sm, 8px)', $html);
        $this->assertStringNotContainsString('--fundkit-button-radius:999px', $html);
    }

    public function test_a_form_on_the_pill_preset_keeps_the_pill(): void
    {
        $campaign = $this->campaign('classic');
        $form     = $this->form((int) $campaign->id, 'classic');

        $html = do_shortcode('[fundkit_donation_form slug="' . $form->slug . '"]');

        $this->assertStringContainsString('--fundkit-button-radius:999px', $html);
        $this->assertStringNotContainsString('--fundkit-button-radius:var(', $html);
    }

    public function test_a_block_for_another_campaign_states_the_fall_through(): void
    {
        $other = $this->campaign('quiet');
        CampaignStyleVars::flush();

        $css = CampaignStyleVars::forCampaign($other);

        $this->assertStringContainsString('--fundkit-button-radius:var(--fundkit-radius-sm, 8px)', $css);
        $this->assertStringContainsString('--fundkit-switcher-radius:var(--fundkit-radius-sm, 8px)', $css);
    }

    public function test_a_pill_campaign_still_emits_the_pill(): void
    {
        $classic = $this->campaign('classic');
        CampaignStyleVars::flush();

        $css = CampaignStyleVars::forCampaign($classic);

        $this->assertStringContainsString('--fundkit-button-radius:999px', $css);
        $this->assertStringNotContainsString('--fundkit-button-radius:var(', $css);
    }
}
