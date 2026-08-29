<?php

declare(strict_types=1);

namespace GiveFlow\Tests\Integration;

use GiveFlow\Campaigns\Campaign;
use GiveFlow\Campaigns\Styling\CampaignStyleVars;

/**
 * The ink has to survive the whole resolve, not just the maths: a campaign that
 * picks a pale accent must reach the page with dark ink on it, or the filled
 * panel reverses white out of a colour it cannot be read on.
 */
final class CampaignAccentInkTest extends IntegrationTestCase
{
    private function campaignWithAccent(string $accent): Campaign
    {
        $now = gmdate('Y-m-d H:i:s');
        $c = Campaign::make();
        $c->title      = 'Ink';
        $c->slug       = 'ink-' . uniqid();
        $c->status     = 'published';
        $c->style      = ['tokens' => ['giveflow-accent' => $accent]];
        $c->created_at = $now;
        $c->updated_at = $now;
        $c->save();

        CampaignStyleVars::flush();

        return $c;
    }

    public function test_a_pale_accent_gets_dark_ink(): void
    {
        $css = CampaignStyleVars::forCampaign($this->campaignWithAccent('#ffe066'));

        $this->assertStringContainsString('--giveflow-accent:#ffe066', $css);
        $this->assertStringContainsString('--giveflow-on-accent:#10162a', $css);
    }

    public function test_a_dark_accent_gets_light_ink(): void
    {
        $css = CampaignStyleVars::forCampaign($this->campaignWithAccent('#14425f'));

        $this->assertStringContainsString('--giveflow-on-accent:#ffffff', $css);
    }

    /**
     * The stylesheet reads these three together; emitting the ink without the
     * muted and line values leaves a stat legible beside an invisible caption.
     */
    public function test_the_muted_and_line_inks_travel_with_it(): void
    {
        $css = CampaignStyleVars::forCampaign($this->campaignWithAccent('#ffe066'));

        $this->assertStringContainsString('--giveflow-on-accent-muted:rgba(16,22,42,.62)', $css);
        $this->assertStringContainsString('--giveflow-on-accent-line:rgba(16,22,42,.16)', $css);
    }
}
