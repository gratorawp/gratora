<?php

declare(strict_types=1);

namespace GiveFlow\Tests\Integration;

use GiveFlow\Campaigns\Campaign;
use GiveFlow\Campaigns\CampaignPageTemplate;
use WP_Theme_JSON_Resolver;

/**
 * Campaign pages resolve to the plugin's minimal block template (chrome +
 * content, no theme title banner) so the published page matches what the
 * admin composed in the editor. An explicitly assigned page template wins,
 * and ordinary pages are untouched.
 */
final class CampaignPageTemplateTest extends IntegrationTestCase
{
    private function makeCampaignPage(): int
    {
        $now = gmdate('Y-m-d H:i:s');
        $c = Campaign::make();
        $c->title      = 'Template';
        $c->slug       = 'template-' . uniqid();
        $c->status     = 'published';
        $c->created_at = $now;
        $c->updated_at = $now;
        $c->save();

        return (int) wp_insert_post([
            'post_type'   => 'page',
            'post_status' => 'publish',
            'post_title'  => 'Template page',
            'meta_input'  => ['_giveflow_campaign_id' => (int) $c->id],
        ]);
    }

    /**
     * The front end wraps campaign content in the template's constrained group;
     * the post editor does not, and falls back to the theme's root layout. The
     * two measures have to come out the same or the editor lies about the page.
     */
    public function test_the_editor_measures_a_campaign_page_by_the_template(): void
    {
        $pageId   = $this->makeCampaignPage();
        $baseline = (string) (wp_get_global_settings()['layout']['contentSize'] ?? '');
        $this->assertNotSame(
            CampaignPageTemplate::MEASURE,
            $baseline,
            'the theme already uses our measure, so this proves nothing'
        );

        set_current_screen('post.php');
        $_GET['post'] = $pageId;
        WP_Theme_JSON_Resolver::clean_cached_data();
        wp_cache_flush();

        $this->assertSame(
            CampaignPageTemplate::MEASURE,
            (string) (wp_get_global_settings()['layout']['contentSize'] ?? '')
        );

        $_GET['post'] = wp_insert_post([
            'post_type'   => 'page',
            'post_status' => 'publish',
            'post_title'  => 'An ordinary page',
        ]);
        WP_Theme_JSON_Resolver::clean_cached_data();
        wp_cache_flush();

        $this->assertSame(
            $baseline,
            (string) (wp_get_global_settings()['layout']['contentSize'] ?? ''),
            'every other page keeps the theme measure'
        );

        unset($_GET['post']);
        set_current_screen('front');
        WP_Theme_JSON_Resolver::clean_cached_data();
        wp_cache_flush();
    }

    public function test_campaign_page_gets_the_minimal_template_first(): void
    {
        $pageId = $this->makeCampaignPage();
        $this->go_to('/?page_id=' . $pageId);

        $templates = (new CampaignPageTemplate())->forceTemplate(['page.php']);

        $this->assertSame(CampaignPageTemplate::SLUG, $templates[0]);
        $this->assertContains('page.php', $templates);
    }

    public function test_an_explicit_page_template_wins(): void
    {
        $pageId = $this->makeCampaignPage();
        update_post_meta($pageId, '_wp_page_template', 'page-no-title');
        $this->go_to('/?page_id=' . $pageId);

        $templates = (new CampaignPageTemplate())->forceTemplate(['page.php']);

        $this->assertNotContains(CampaignPageTemplate::SLUG, $templates);
    }

    public function test_a_page_without_a_campaign_is_untouched(): void
    {
        $pageId = (int) wp_insert_post([
            'post_type'   => 'page',
            'post_status' => 'publish',
            'post_title'  => 'Plain page',
        ]);
        $this->go_to('/?page_id=' . $pageId);

        $templates = (new CampaignPageTemplate())->forceTemplate(['page.php']);

        $this->assertSame(['page.php'], $templates);
    }

    /**
     * The template's measure and the stylesheet's have to agree.
     *
     * page.css caps every band at --dp-measure. If the block layout is set
     * narrower it crops them, and campaign pages come out at reading width
     * inside bands built for 1200; wider and the bands stop matching the page
     * around them. Neither fails loudly, so the two are pinned together here.
     */
    public function test_the_layout_measure_matches_the_stylesheet(): void
    {
        $css = (string) file_get_contents(GIVEFLOW_DIR . 'assets/campaign-page/page.css');

        $this->assertSame(
            1,
            preg_match('/--dp-measure:\s*([0-9]+px)/', $css, $m),
            'page.css no longer declares --dp-measure'
        );
        $this->assertSame(
            $m[1],
            CampaignPageTemplate::MEASURE,
            'the campaign page template and page.css disagree about how wide the page is'
        );
    }

    /** Only a constrained layout makes alignwide mean anything. */
    public function test_the_main_group_is_constrained(): void
    {
        $template = get_block_template('giveflow//' . CampaignPageTemplate::SLUG, 'wp_template');

        $this->assertNotNull($template);
        $this->assertStringContainsString('"type":"constrained"', (string) $template->content);
        $this->assertStringContainsString('"wideSize":"' . CampaignPageTemplate::MEASURE . '"', (string) $template->content);
    }

    /**
     * The layouts carry their own width in the editor.
     *
     * Two attempts at this went through Gutenberg, a CSS variable core does
     * not read back and a settings filter that did not reach the canvas, and
     * neither moved the page. The measure now sits on the classes the layouts
     * use, so nothing depends on which measure the canvas settled on.
     *
     * Both halves of the scoping are asserted, because either one missing
     * makes this leak: onto the front end, or onto every other page.
     */
    public function test_the_layouts_carry_their_own_editor_width(): void
    {
        $css = (string) file_get_contents(GIVEFLOW_DIR . 'assets/campaign-page/page.css');

        $this->assertMatchesRegularExpression(
            '/\.editor-styles-wrapper \.alignwide\.dp-\w+/',
            $css,
            'the editor width rule is gone, so campaign layouts sit at the theme measure again'
        );

        preg_match_all('/^\s*(\.[^,{]*alignwide[^,{]*)[,{]/m', $css, $m);
        $this->assertNotEmpty($m[1]);

        foreach ($m[1] as $selector) {
            $this->assertStringContainsString(
                '.editor-styles-wrapper',
                $selector,
                'this rule would reach the front end: ' . trim($selector)
            );
            $this->assertMatchesRegularExpression(
                '/\.dp-\w+/',
                $selector,
                'this rule would reach pages that are not campaigns: ' . trim($selector)
            );
        }
    }

}
