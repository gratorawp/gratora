<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Campaigns\Campaign;
use Gratora\Campaigns\Styling\CampaignStyleResolver;
use Gratora\Forms\Form;

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
        $c->style = ['preset_id' => 'classic', 'tokens' => ['gratora-accent' => $hex]];
        return $c;
    }

    public function test_campaign_inline_tokens_apply_when_form_has_no_own_preset(): void
    {
        $campaign = $this->campaignWithInlineAccent('#ff0000');
        $form = Form::make();
        $form->settings = [];

        $resolved = (new CampaignStyleResolver())->resolve($form, $campaign);

        $this->assertSame('#ff0000', $resolved['tokens']['gratora-accent']);
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
        $this->assertSame('#0F3D5C', $resolved['tokens']['gratora-accent']);
        $this->assertNotSame('#ff0000', $resolved['tokens']['gratora-accent']);
        $this->assertSame('bold', $resolved['preset_id']);
    }

    public function test_default_accent_soft_is_dropped_so_runtime_derives_it(): void
    {
        // Campaign customizes the accent but not the soft tint; nothing pairs
        // a soft with it, so the resolver must drop the catalogue-default soft
        // and let the stylesheet color-mix derive it from --gratora-accent.
        $campaign = $this->campaignWithInlineAccent('#ff0000');
        $form = Form::make();
        $form->settings = [];

        $resolved = (new CampaignStyleResolver())->resolve($form, $campaign);

        $this->assertArrayNotHasKey('gratora-accent-soft', $resolved['tokens']);
    }

    public function test_preset_paired_accent_soft_is_kept(): void
    {
        // Bold pairs its own accent-soft; it must survive (not be dropped).
        $form = Form::make();
        $form->settings = ['style' => ['preset_id' => 'bold']];

        $resolved = (new CampaignStyleResolver())->resolve($form, null);

        $this->assertSame('#dde6ed', $resolved['tokens']['gratora-accent-soft']);
    }

    /**
     * A preset deleted while a form still named it is not a choice the form
     * made. Treating it as one kept gating out the campaign's overrides, so the
     * page rendered the campaign's colour and the form beside it the default.
     */
    public function test_a_form_pinned_to_a_deleted_preset_lets_the_campaign_through(): void
    {
        $campaign = $this->campaignWithInlineAccent('#c62828');
        $form = Form::make();
        $form->settings = ['style' => ['preset_id' => 'gone-forever']];

        $resolved = (new CampaignStyleResolver())->resolve($form, $campaign);

        $this->assertSame('#c62828', $resolved['tokens']['gratora-accent']);
    }

    /**
     * The form wrapper paints nothing, so the text on it is the page's ink;
     * the card it paints when framed restates its ink from --gratora-on-bg.
     */
    public function test_the_rendered_form_keeps_page_ink_and_carries_card_ink(): void
    {
        update_option('gratora_org_brand', [
            'presets'    => [['id' => 'midnight', 'name' => 'Midnight', 'tokens' => ['gratora-bg' => '#15142b', 'gratora-accent' => '#fde68a']]],
            'default_id' => 'midnight',
        ]);
        $campaign = Campaign::make();
        $campaign->title      = 'Cascade ' . uniqid();
        $campaign->slug       = 'cascade-' . uniqid();
        $campaign->status     = 'published';
        $campaign->currency   = 'USD';
        $campaign->created_at = gmdate('Y-m-d H:i:s');
        $campaign->updated_at = $campaign->created_at;
        $campaign->save();

        $form = Form::make();
        $form->title       = 'Cascade ' . uniqid();
        $form->slug        = 'cascade-' . uniqid();
        $form->status      = 'published';
        $form->campaign_id = (int) $campaign->id;
        $form->blocks     = '<!-- wp:gratora/donation-amount {"presets":[1000]} /-->';
        $form->settings   = [];
        $form->created_at = gmdate('Y-m-d H:i:s');
        $form->updated_at = $form->created_at;
        $form->save();

        $html = do_shortcode('[gratora_donation_form slug="' . $form->slug . '"]');

        $this->assertSame(1, preg_match('/<form class="gratora-donation-form[^"]*"[^>]* style="([^"]*)"/', $html, $m));
        $this->assertStringContainsString('--gratora-text:#111827;', $m[1]);
        $this->assertStringContainsString('--gratora-on-bg:#ffffff;', $m[1]);
        $this->assertStringContainsString('--gratora-on-bg-accent:var(--gratora-accent);', $m[1]);
    }

    /** A preset that does exist still gates them out, which is the contract. */
    public function test_a_form_pinned_to_a_real_preset_still_gates_them_out(): void
    {
        $campaign = $this->campaignWithInlineAccent('#c62828');
        $form = Form::make();
        $form->settings = ['style' => ['preset_id' => 'bold']];

        $resolved = (new CampaignStyleResolver())->resolve($form, $campaign);

        $this->assertNotSame('#c62828', $resolved['tokens']['gratora-accent']);
    }
}
