<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Campaigns\Campaign;
use Gratora\Campaigns\Styling\CampaignStyleVars;
use Gratora\Campaigns\Styling\Tokens;
use Gratora\Forms\Form;

/**
 * A block naming another campaign writes that campaign's map on its wrapper,
 * inside the host page's map. A token the guest's map leaves out would be the
 * host's there: on a Quiet host a guest's button took Quiet's transparent
 * ground and dark text, 1.01:1 on the guest's own dark card.
 */
final class GuestWrapperStatesItsWholeLookTest extends IntegrationTestCase
{
    /** @return array<string,string> */
    private static function declarations(string $css): array
    {
        preg_match_all('/--([a-z-]+):([^;]*);/', $css, $m, PREG_SET_ORDER);

        $out = [];
        foreach ($m as $decl) {
            $out[$decl[1]] = $decl[2];
        }

        return $out;
    }

    public function test_every_token_the_map_leaves_out_is_stated_unset(): void
    {
        $css  = CampaignStyleVars::forCampaign($this->saved(['preset_id' => 'bold', 'tokens' => ['gratora-accent' => '#c62828']]));
        $decl = self::declarations($css);

        foreach (array_keys(Tokens::catalogue()) as $key) {
            $this->assertArrayHasKey($key, $decl, $key . ' is left to the host');
        }
        $this->assertSame('initial', $decl['gratora-button-bg']);
        $this->assertSame('initial', $decl['gratora-button-fg']);
        $this->assertSame('initial', $decl['gratora-button-hover-bg']);
        $this->assertSame('initial', $decl['gratora-accent-soft']);
    }

    public function test_a_token_the_map_holds_is_stated_as_it_is(): void
    {
        $decl = self::declarations(CampaignStyleVars::forCampaign($this->saved(['preset_id' => 'quiet'])));

        $this->assertSame('transparent', $decl['gratora-button-bg']);
        $this->assertSame('#111827', $decl['gratora-button-fg']);
        $this->assertSame('#f3f4f6', $decl['gratora-button-hover-bg']);
    }

    /** A guest wrapper's style attribute goes through kses on the way out. */
    public function test_the_unset_tokens_survive_kses_byte_for_byte(): void
    {
        kses_init_filters();

        $css = CampaignStyleVars::forCampaign($this->saved(['preset_id' => 'bold', 'tokens' => ['gratora-accent' => '#c62828']]));

        $this->assertStringContainsString('--gratora-button-bg:initial;', $css);
        $this->assertSame(rtrim($css, ';'), safecss_filter_attr($css));
    }

    /**
     * A form on another campaign's page reads the button colours its own map
     * leaves to the accent from that page. The rest of the catalogue the
     * wrapper's stylesheet declares itself, so only those are stated.
     */
    public function test_a_form_states_the_button_colours_its_map_leaves_to_the_accent(): void
    {
        $campaign = $this->saved([]);
        $form = Form::make();
        $form->title       = 'Guest ' . uniqid();
        $form->slug        = 'guest-' . uniqid();
        $form->status      = 'published';
        $form->campaign_id = (int) $campaign->id;
        $form->blocks      = '<!-- wp:gratora/submit-button /-->';
        $form->settings    = [];
        $form->created_at  = gmdate('Y-m-d H:i:s');
        $form->updated_at  = $form->created_at;
        $form->save();

        $html = do_shortcode('[gratora_donation_form slug="' . $form->slug . '"]');

        $this->assertSame(1, preg_match('/<form class="gratora-donation-form[^"]*"[^>]* style="([^"]*)"/', $html, $m));
        $decl = self::declarations(html_entity_decode($m[1]));
        $this->assertSame('initial', $decl['gratora-button-bg'] ?? null);
        $this->assertSame('initial', $decl['gratora-button-fg'] ?? null);
        $this->assertSame('initial', $decl['gratora-button-hover-bg'] ?? null);
        $this->assertArrayNotHasKey('gratora-accent-soft', $decl);
        $this->assertArrayNotHasKey('gratora-focus-ring', $decl);
    }

    private function saved(array $style): Campaign
    {
        $now = gmdate('Y-m-d H:i:s');
        $c = Campaign::make();
        $c->style      = $style;
        $c->title      = 'Guest';
        $c->slug       = 'guest-' . uniqid();
        $c->status     = 'published';
        $c->currency   = 'USD';
        $c->created_at = $now;
        $c->updated_at = $now;
        $c->save();

        CampaignStyleVars::flush();

        return $c;
    }
}
