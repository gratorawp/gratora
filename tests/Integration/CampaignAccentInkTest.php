<?php

declare(strict_types=1);

namespace FundKit\Tests\Integration;

use FundKit\Campaigns\Campaign;
use FundKit\Campaigns\Styling\CampaignStyleVars;
use FundKit\Campaigns\CampaignService;
use FundKit\Foundation\Plugin;

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
        $c->style      = ['tokens' => ['fundkit-accent' => $accent]];
        $c->created_at = $now;
        $c->updated_at = $now;
        $c->save();

        CampaignStyleVars::flush();

        return $c;
    }

    /**
     * The photo cover paints the campaign's image as its ground. The editor and
     * the front end each render this page their own way, and a background is
     * the one form neither of them lays out, so the token has to carry the URL.
     */
    public function test_the_campaign_image_is_emitted_for_the_cover(): void
    {
        $service  = Plugin::instance()->container->get(CampaignService::class);
        $campaign = $service->create([
            'title' => 'Cover', 'goal_type' => 'amount', 'goal_cents' => 1000, 'currency' => 'USD',
        ]);
        $att = self::factory()->attachment->create_upload_object(DIR_TESTDATA . '/images/canola.jpg');
        $service->update($campaign, ['image_attachment_id' => (int) $att]);
        CampaignStyleVars::flush();

        $css = CampaignStyleVars::forCampaign(
            (new \FundKit\Campaigns\CampaignRepository())->findById((int) $campaign->id)
        );

        $this->assertMatchesRegularExpression('/--fundkit-cover-image:url\(https?:[^)]+\.jpg\);/', $css);
    }

    public function test_a_campaign_with_no_image_emits_no_cover_token(): void
    {
        $css = CampaignStyleVars::forCampaign($this->campaignWithAccent('#14425f'));

        $this->assertStringNotContainsString('--fundkit-cover-image', $css);
    }

    public function test_a_pale_accent_gets_dark_ink(): void
    {
        $css = CampaignStyleVars::forCampaign($this->campaignWithAccent('#ffe066'));

        $this->assertStringContainsString('--fundkit-accent:#ffe066', $css);
        $this->assertStringContainsString('--fundkit-on-accent:#10162a', $css);
    }

    public function test_a_dark_accent_gets_light_ink(): void
    {
        $css = CampaignStyleVars::forCampaign($this->campaignWithAccent('#14425f'));

        $this->assertStringContainsString('--fundkit-on-accent:#ffffff', $css);
    }

    /**
     * The stylesheet reads these three together; emitting the ink without the
     * muted and line values leaves a stat legible beside an invisible caption.
     */
    public function test_the_muted_and_line_inks_travel_with_it(): void
    {
        $css = CampaignStyleVars::forCampaign($this->campaignWithAccent('#ffe066'));

        $this->assertStringContainsString('--fundkit-on-accent-muted:rgba(16,22,42,.62)', $css);
        $this->assertStringContainsString('--fundkit-on-accent-line:rgba(16,22,42,.16)', $css);
    }
}
