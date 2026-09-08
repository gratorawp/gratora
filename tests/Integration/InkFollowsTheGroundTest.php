<?php

declare(strict_types=1);

namespace FundKit\Tests\Integration;

use FundKit\Campaigns\Campaign;
use FundKit\Campaigns\Styling\CampaignStyleVars;

/**
 * Body and muted ink ship chosen against a white page. An org that colours the
 * ground and says nothing about the ink used to get #111827 and #6b7280 on
 * whatever it picked: on a mid red that is 3.97:1 for the body and 1.08:1 for
 * the muted line, which is no line at all.
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

    public function test_a_dark_ground_gets_light_ink(): void
    {
        $css = $this->css(['fundkit-bg' => '#101828']);

        $this->assertStringContainsString('--fundkit-text:#ffffff;', $css);
        $this->assertStringContainsString('--fundkit-text-muted:rgba(255,255,255,.72);', $css);
    }

    /**
     * The muted line is the one that disappears: #6b7280 on this red measures
     * 1.08:1, which is no line at all. Ink measured against the ground reaches
     * 3.9:1 on it, the most any ink can do at this luminance.
     */
    public function test_the_muted_line_stops_vanishing_on_a_coloured_ground(): void
    {
        $css = $this->css(['fundkit-bg' => '#ed1212']);

        $this->assertStringContainsString('--fundkit-text-muted:rgba(16,22,42,.62);', $css);
        $this->assertStringNotContainsString('--fundkit-text-muted:#6b7280;', $css);
    }

    public function test_a_pale_ground_keeps_dark_ink(): void
    {
        $this->assertStringContainsString('--fundkit-text:#10162a;', $this->css(['fundkit-bg' => '#ffe066']));
    }

    /** The shipped ground is what the shipped ink was chosen against. */
    public function test_the_shipped_ground_leaves_the_shipped_ink_alone(): void
    {
        $css = $this->css(['fundkit-accent' => '#452ef5']);

        $this->assertStringContainsString('--fundkit-text:#111827;', $css);
        $this->assertStringContainsString('--fundkit-text-muted:#6b7280;', $css);
    }

    /** An org that chose its ink keeps it, however it reads. */
    public function test_ink_the_org_chose_wins(): void
    {
        $css = $this->css(['fundkit-bg' => '#101828', 'fundkit-text' => '#111827']);

        $this->assertStringContainsString('--fundkit-text:#111827;', $css);
        $this->assertStringNotContainsString('--fundkit-text:#ffffff;', $css);
    }

    /** Muted is decided on its own, so choosing one does not pin the other. */
    public function test_choosing_the_body_ink_alone_still_lifts_the_muted_one(): void
    {
        $css = $this->css(['fundkit-bg' => '#101828', 'fundkit-text' => '#111827']);

        $this->assertStringContainsString('--fundkit-text-muted:rgba(255,255,255,.72);', $css);
    }

    public function test_the_soft_ground_travels_with_it(): void
    {
        $css = $this->css(['fundkit-bg-soft' => '#101828']);

        $this->assertStringContainsString('--fundkit-on-soft:#ffffff;', $css);
        $this->assertStringContainsString('--fundkit-on-soft-accent:', $css);
    }

    /**
     * A ground the colour control never stores cannot be measured, and the
     * stylesheet's own value has to stand rather than a guess.
     */
    public function test_a_ground_that_cannot_be_read_changes_nothing(): void
    {
        $css = $this->css(['fundkit-bg' => 'inherit']);

        $this->assertStringContainsString('--fundkit-text:#111827;', $css);
    }

    /**
     * Classic's donate button is a pill. The radius is deliberately outside the
     * catalogue so that leaving it unset inherits --fundkit-radius-sm, but a
     * sanitizer that only knows the catalogue drops it, and the first save of
     * the brand panel squares the button off.
     */
    public function test_the_pill_button_survives_the_sanitizer(): void
    {
        $this->assertStringContainsString('--fundkit-button-radius:999px;', $this->css([]));
    }

    /** And it stays out of the defaults, or nothing could inherit any more. */
    public function test_the_button_radius_is_not_forced_on_every_preset(): void
    {
        $this->assertArrayNotHasKey('fundkit-button-radius', \FundKit\Campaigns\Styling\Tokens::defaults());
    }

    /**
     * The fields keep a ground of their own so a coloured page does not paint
     * the boxes a donor types in. The page ink knows nothing about that ground:
     * on a dark page it turns white, and the fields are still white.
     */
    public function test_a_dark_page_does_not_leave_white_ink_in_a_white_field(): void
    {
        $css = $this->css(['fundkit-bg' => '#0f172a']);

        $this->assertStringContainsString('--fundkit-text:#ffffff;', $css);
        $this->assertStringContainsString('--fundkit-on-field:#10162a;', $css);
    }

    public function test_a_field_ground_the_org_darkened_gets_light_ink(): void
    {
        $this->assertStringContainsString(
            '--fundkit-on-field:#ffffff;',
            $this->css(['fundkit-field-bg' => '#101828'])
        );
    }

    /**
     * The focus ring is paired with the accent the way accent-soft is. Left at
     * the shipped value it never tracked the brand, and on the shipped navy
     * background it was drawn in the ground's own colour: no visible focus.
     */
    public function test_an_unpaired_focus_ring_falls_through_to_the_accent(): void
    {
        $this->assertStringNotContainsString('--fundkit-focus-ring:', $this->css(['fundkit-accent' => '#c62828']));
    }

    /** A ring the org paired with something is still theirs. */
    public function test_a_focus_ring_the_org_chose_is_emitted(): void
    {
        $this->assertStringContainsString(
            '--fundkit-focus-ring:#00ff00;',
            $this->css(['fundkit-focus-ring' => '#00ff00'])
        );
    }
}
