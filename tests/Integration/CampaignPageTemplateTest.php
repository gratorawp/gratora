<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Campaigns\Campaign;
use Dono\Campaigns\CampaignPageTemplate;

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
            'meta_input'  => ['_dono_campaign_id' => (int) $c->id],
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
}
