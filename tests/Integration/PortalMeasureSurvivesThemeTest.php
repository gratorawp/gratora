<?php

declare(strict_types=1);

namespace FundKit\Tests\Integration;

/**
 * A block theme's constrained layout caps its children with
 * `.is-layout-constrained > :where(...)`. The :where() contributes no
 * specificity, so that rule ties with a bare `.fundkit-donor-portal` and the
 * theme wins on source order alone, its global styles being printed after the
 * plugin's stylesheet.
 *
 * Nothing errors when that happens. The portal simply renders at the theme's
 * content width, 645px on Twenty Twenty-Five against the 1200 this stylesheet
 * asks for, and only a measurement in a browser tells you.
 */
final class PortalMeasureSurvivesThemeTest extends IntegrationTestCase
{
    private function stylesheet(): string
    {
        $path = FUNDKIT_DIR . 'assets/donor-portal/portal.scss';

        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    public function test_the_portal_outranks_a_constrained_layout(): void
    {
        $this->assertMatchesRegularExpression(
            '/\.is-layout-constrained\s*>\s*\.fundkit-donor-portal\s*\{[^}]*max-width/s',
            $this->stylesheet(),
            'the portal no longer beats a block theme, so it renders at the theme measure'
        );
    }

    public function test_the_measure_stays_overridable_by_the_site(): void
    {
        // Both declarations read the same token, so a site that sets
        // --dp-measure moves the portal without editing the plugin.
        $this->assertSame(
            2,
            preg_match_all('/max-width:\s*var\(--dp-measure/', $this->stylesheet()),
            'the constrained-layout rule and the base rule must read the same token'
        );
    }

    /**
     * The portal is meant to sit at the same measure as the campaign pages;
     * that is why the fallback is written out rather than left to the theme.
     */
    public function test_the_fallback_matches_the_campaign_page_measure(): void
    {
        preg_match_all('/var\(--dp-measure,\s*([0-9]+px)\)/', $this->stylesheet(), $m);

        $this->assertNotEmpty($m[1]);
        foreach ($m[1] as $fallback) {
            $this->assertSame(
                \FundKit\Campaigns\CampaignPageTemplate::MEASURE,
                $fallback,
                'the portal and the campaign pages have drifted apart'
            );
        }
    }
}
