<?php

declare(strict_types=1);

namespace FundKit\Tests\Integration;

use FundKit\Campaigns\Campaign;
use FundKit\Campaigns\Styling\CampaignStyleVars;
use FundKit\Reports\CampaignReportBuilder;
use FundKit\Reports\RevenueReportBuilder;
use FundKit\Settings\SettingsService;
use FundKit\Foundation\Plugin;

/**
 * A donor who gave on a branded page and then downloads the paperwork should
 * recognise it. The receipt took the org default whichever campaign the
 * donation was made to, and every other document took no colour at all.
 */
final class DocumentsCarryTheBrandTest extends IntegrationTestCase
{
    private function orgAccent(string $hex): void
    {
        Plugin::instance()->container->get(SettingsService::class)->update('org-brand', [
            'presets'    => [['id' => 'classic', 'name' => 'Classic', 'tokens' => ['fundkit-accent' => $hex]]],
            'default_id' => 'classic',
        ]);
        CampaignStyleVars::flush();
    }

    private function campaign(string $accent): Campaign
    {
        $now = gmdate('Y-m-d H:i:s');
        $c = Campaign::make();
        $c->title      = 'Branded';
        $c->slug       = 'branded-' . uniqid();
        $c->status     = 'published';
        $c->currency   = 'USD';
        $c->style      = ['tokens' => ['fundkit-accent' => $accent]];
        $c->created_at = $now;
        $c->updated_at = $now;
        $c->save();

        CampaignStyleVars::flush();

        return $c;
    }

    /** Dompdf returns bytes nothing can read back, so the markup is the seam. */
    private function markupOf(callable $build): string
    {
        $seen = '';
        $spy  = static function (string $html) use (&$seen): string {
            $seen = $html;

            return $html;
        };
        add_filter('fundkit.pdf.html', $spy, 10);

        try {
            $build();
        } finally {
            remove_filter('fundkit.pdf.html', $spy, 10);
        }

        return $seen;
    }

    public function test_the_campaign_report_takes_the_campaign_accent_not_the_org_default(): void
    {
        $this->orgAccent('#211d3f');
        $campaign = $this->campaign('#7c3aed');
        $builder  = Plugin::instance()->container->get(CampaignReportBuilder::class);

        $html = $this->markupOf(static fn () => $builder->build($campaign, 'all-time'));

        $this->assertStringContainsString('#7c3aed', $html);
        $this->assertStringNotContainsString('#211d3f', $html);
    }

    /** An org-wide document has no campaign behind it, so it takes the default. */
    public function test_an_org_document_falls_back_to_the_org_accent(): void
    {
        $this->orgAccent('#0f766e');
        $builder = Plugin::instance()->container->get(RevenueReportBuilder::class);

        $html = $this->markupOf(static fn () => $builder->build(2026));

        $this->assertStringContainsString('#0f766e', $html);
    }
}
