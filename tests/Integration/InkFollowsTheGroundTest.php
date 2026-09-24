<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Campaigns\Campaign;
use Gratora\Campaigns\Styling\CampaignStyleVars;

/**
 * Background is the card behind the form and the panels, not the page. Body and
 * muted text are the page's ink and stand wherever the page shows through; a
 * surface that paints the card reads ink measured against it, which keeps the
 * chosen ink where that reads and replaces it where it does not.
 */
final class InkFollowsTheGroundTest extends IntegrationTestCase
{
    /**
     * @param array<string,string> $tokens
     */
    private function css(array $tokens): string
    {
        $now = gmdate('Y-m-d H:i:s');
        $c = Campaign::make();
        $c->title      = 'Ground';
        $c->slug       = 'ground-' . uniqid();
        $c->status     = 'published';
        $c->style      = ['tokens' => $tokens];
        $c->created_at = $now;
        $c->updated_at = $now;
        $c->save();

        CampaignStyleVars::flush();

        return CampaignStyleVars::forCampaign($c);
    }

    public function test_a_dark_card_gets_light_ink_and_the_page_keeps_its_own(): void
    {
        $css = $this->css(['gratora-bg' => '#101828']);

        $this->assertStringContainsString('--gratora-text:#111827;', $css);
        $this->assertStringContainsString('--gratora-text-muted:#6b7280;', $css);
        $this->assertStringContainsString('--gratora-on-bg:#ffffff;', $css);
        $this->assertStringContainsString('--gratora-on-bg-muted:rgba(255,255,255,.72);', $css);
    }

    /**
     * #6b7280 on this red measures 1.08:1, which is no line at all, and no ink
     * below opaque reaches 4.5:1 on it, so the card's muted line is the ink.
     */
    public function test_the_muted_line_stops_vanishing_on_a_coloured_card(): void
    {
        $css = $this->css(['gratora-bg' => '#ed1212']);

        $this->assertStringContainsString('--gratora-on-bg-muted:#10162a;', $css);
        $this->assertStringContainsString('--gratora-text-muted:#6b7280;', $css);
    }

    public function test_ink_that_reads_on_a_pale_card_is_kept_there(): void
    {
        $css = $this->css(['gratora-bg' => '#ffe066']);

        $this->assertStringContainsString('--gratora-text:#111827;', $css);
        $this->assertStringContainsString('--gratora-on-bg:var(--gratora-text);', $css);
    }

    /** The shipped ground is what the shipped ink was chosen against. */
    public function test_the_shipped_ground_names_the_shipped_ink(): void
    {
        $css = $this->css(['gratora-accent' => '#452ef5']);

        $this->assertStringContainsString('--gratora-text:#111827;', $css);
        $this->assertStringContainsString('--gratora-text-muted:#6b7280;', $css);
        $this->assertStringContainsString('--gratora-on-bg:var(--gratora-text);', $css);
        $this->assertStringContainsString('--gratora-on-bg-muted:var(--gratora-text-muted);', $css);
    }

    /**
     * Body text the org chose always stands on the page. On a card it stands
     * where it reads, and where it does not the card takes measured ink.
     */
    public function test_chosen_ink_stays_on_the_page_and_the_card_is_measured(): void
    {
        $css = $this->css(['gratora-bg' => '#101828', 'gratora-text' => '#111827']);

        $this->assertStringContainsString('--gratora-text:#111827;', $css);
        $this->assertStringContainsString('--gratora-on-bg:#ffffff;', $css);
        $this->assertStringNotContainsString('--gratora-text:#ffffff;', $css);
    }

    /** Muted is decided on its own, so choosing one does not pin the other. */
    public function test_choosing_the_body_ink_alone_still_measures_the_muted_one(): void
    {
        $css = $this->css(['gratora-bg' => '#101828', 'gratora-text' => '#111827']);

        $this->assertStringContainsString('--gratora-on-bg-muted:rgba(255,255,255,.72);', $css);
    }

    public function test_the_soft_ground_travels_with_it(): void
    {
        $css = $this->css(['gratora-bg-soft' => '#101828']);

        $this->assertStringContainsString('--gratora-on-soft:#ffffff;', $css);
        $this->assertStringContainsString('--gratora-on-soft-accent:', $css);
    }

    /**
     * A ground the colour control never stores cannot be measured, and the
     * stylesheet's own value has to stand rather than a guess.
     */
    public function test_a_ground_that_cannot_be_read_changes_nothing(): void
    {
        $css = $this->css(['gratora-bg' => 'inherit']);

        $this->assertStringContainsString('--gratora-text:#111827;', $css);
        $this->assertStringContainsString('--gratora-on-bg:var(--gratora-text);', $css);
    }

    /**
     * Classic's donate button is a pill. The radius is deliberately outside the
     * catalogue so that leaving it unset inherits --gratora-radius-sm, but a
     * sanitizer that only knows the catalogue drops it, and the first save of
     * the brand panel squares the button off.
     */
    public function test_the_pill_button_survives_the_sanitizer(): void
    {
        $this->assertStringContainsString('--gratora-button-radius:999px;', $this->css([]));
    }

    /** And it stays out of the defaults, or nothing could inherit any more. */
    public function test_the_button_radius_is_not_forced_on_every_preset(): void
    {
        $this->assertArrayNotHasKey('gratora-button-radius', \Gratora\Campaigns\Styling\Tokens::defaults());
    }

    /**
     * The fields keep a ground of their own so a coloured card does not paint
     * the boxes a donor types in. On a dark card the card ink is white, and
     * the fields are still white.
     */
    public function test_a_dark_card_does_not_leave_white_ink_in_a_white_field(): void
    {
        $css = $this->css(['gratora-bg' => '#0f172a']);

        $this->assertStringContainsString('--gratora-on-bg:#ffffff;', $css);
        $this->assertStringContainsString('--gratora-on-field:#10162a;', $css);
    }

    public function test_a_field_ground_the_org_darkened_gets_light_ink(): void
    {
        $this->assertStringContainsString(
            '--gratora-on-field:#ffffff;',
            $this->css(['gratora-field-bg' => '#101828'])
        );
    }

    /**
     * The focus ring is paired with the accent the way accent-soft is. One
     * nothing paired is stated as unset, so each ring falls back to its own
     * colour rather than to a ring a surrounding page declared.
     */
    public function test_an_unpaired_focus_ring_is_stated_as_unset(): void
    {
        $css = $this->css(['gratora-accent' => '#c62828']);

        $this->assertStringContainsString('--gratora-focus-ring:initial;', $css);
        $this->assertStringNotContainsString('--gratora-focus-ring:#', $css);
    }

    /** A ring the org paired with something is still theirs. */
    public function test_a_focus_ring_the_org_chose_is_emitted(): void
    {
        $this->assertStringContainsString(
            '--gratora-focus-ring:#00ff00;',
            $this->css(['gratora-focus-ring' => '#00ff00'])
        );
    }
}
