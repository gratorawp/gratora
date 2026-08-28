<?php

declare(strict_types=1);

namespace GiveFlow\Tests\Integration;

use GiveFlow\Campaigns\Campaign;
use GiveFlow\Campaigns\CampaignPageTemplate;

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
     * The editor is given the page measure on a campaign page, and nowhere else.
     *
     * Filtered rather than styled: core bakes the measure into the layout rules
     * it generates, so a CSS variable override changes nothing. The filter is
     * the value those rules are generated from.
     */
    public function test_the_editor_measure_is_widened_only_on_a_campaign_page(): void
    {
        $integration = new \GiveFlow\Campaigns\Blocks\BlockEditorIntegration();
        $base = ['__experimentalFeatures' => ['layout' => ['contentSize' => '620px', 'wideSize' => '1000px']]];

        $plain = self::factory()->post->create(['post_type' => 'page']);
        $this->onPost($plain);
        $this->assertSame(
            '620px',
            $integration->widenEditorForCampaignPage($base)['__experimentalFeatures']['layout']['contentSize'],
            'an ordinary page had its editor measure changed'
        );

        $campaign = self::factory()->post->create(['post_type' => 'page']);
        update_post_meta($campaign, '_giveflow_campaign_id', 123);
        $this->onPost($campaign);

        $out = $integration->widenEditorForCampaignPage($base)['__experimentalFeatures']['layout'];
        $this->assertSame(CampaignPageTemplate::MEASURE, $out['contentSize']);
        $this->assertSame(CampaignPageTemplate::MEASURE, $out['wideSize']);
    }

    /** editingCampaignPage reads the post being edited, which in the editor is $_GET. */
    private function onPost(int $postId): void
    {
        global $post;
        $post = get_post($postId);
        setup_postdata($post);
        $_GET['post'] = $postId;
    }

}
