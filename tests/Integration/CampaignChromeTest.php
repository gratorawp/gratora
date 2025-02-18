<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Campaigns\Campaign;
use Dono\Campaigns\CampaignChrome;
use Dono\Campaigns\CampaignRepository;
use WP_REST_Request;

/**
 * The Appearance "hide header/footer" toggles persist and the render filter drops
 * the theme's header/footer template parts on a campaign's pages when enabled.
 */
final class CampaignChromeTest extends IntegrationTestCase
{
    private function makeCampaign(bool $hideHeader, bool $hideFooter): Campaign
    {
        $now = gmdate('Y-m-d H:i:s');
        $c = Campaign::make();
        $c->title       = 'Chrome';
        $c->slug        = 'chrome-' . uniqid();
        $c->status      = 'published';
        $c->hide_header = $hideHeader;
        $c->hide_footer = $hideFooter;
        $c->created_at  = $now;
        $c->updated_at  = $now;
        $c->save();
        return $c;
    }

    private function pageFor(int $campaignId): int
    {
        return (int) wp_insert_post([
            'post_type'   => 'page',
            'post_status' => 'publish',
            'post_title'  => 'Chrome page',
            'meta_input'  => ['_dono_campaign_id' => $campaignId],
        ]);
    }

    public function test_update_persists_the_chrome_flags(): void
    {
        $c = $this->makeCampaign(false, false);

        $req = new WP_REST_Request('PUT', '/dono/v1/admin/campaigns/' . $c->id);
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) json_encode(['hide_header' => true, 'hide_footer' => true]));
        $data = rest_do_request($req)->get_data();

        $this->assertTrue($data['hide_header']);
        $this->assertTrue($data['hide_footer']);

        $reloaded = Campaign::query()->where('id', $c->id)->get();
        $this->assertTrue((bool) $reloaded->hide_header);
        $this->assertTrue((bool) $reloaded->hide_footer);
    }

    public function test_filter_hides_header_and_footer_when_enabled(): void
    {
        $c = $this->makeCampaign(true, true);
        $this->go_to('/?page_id=' . $this->pageFor((int) $c->id));

        $chrome = new CampaignChrome(new CampaignRepository());

        $this->assertSame('', $chrome->hideChrome('HEADER', ['attrs' => ['slug' => 'header']]));
        $this->assertSame('', $chrome->hideChrome('FOOTER', ['attrs' => ['slug' => 'footer']]));
        // A different template part is untouched.
        $this->assertSame('SIDE', $chrome->hideChrome('SIDE', ['attrs' => ['slug' => 'sidebar']]));
    }

    public function test_filter_leaves_chrome_when_disabled(): void
    {
        $c = $this->makeCampaign(false, false);
        $this->go_to('/?page_id=' . $this->pageFor((int) $c->id));

        $chrome = new CampaignChrome(new CampaignRepository());

        $this->assertSame('HEADER', $chrome->hideChrome('HEADER', ['attrs' => ['slug' => 'header']]));
        $this->assertSame('FOOTER', $chrome->hideChrome('FOOTER', ['attrs' => ['slug' => 'footer']]));
    }

    public function test_filter_ignores_pages_without_a_campaign(): void
    {
        $pageId = (int) wp_insert_post([
            'post_type'   => 'page',
            'post_status' => 'publish',
            'post_title'  => 'Plain page',
        ]);
        $this->go_to('/?page_id=' . $pageId);

        $chrome = new CampaignChrome(new CampaignRepository());

        $this->assertSame('HEADER', $chrome->hideChrome('HEADER', ['attrs' => ['slug' => 'header']]));
    }

    public function test_open_document_renders_a_minimal_shell_when_header_hidden(): void
    {
        ob_start();
        CampaignChrome::openDocument(true);
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('<!DOCTYPE html>', $html);
        $this->assertStringContainsString('<body', $html);
    }

    public function test_classic_template_passes_through_non_campaign_pages(): void
    {
        $pageId = (int) wp_insert_post([
            'post_type'   => 'page',
            'post_status' => 'publish',
            'post_title'  => 'Plain page',
        ]);
        $this->go_to('/?page_id=' . $pageId);

        $chrome = new CampaignChrome(new CampaignRepository());

        $this->assertSame('orig.php', $chrome->classicTemplate('orig.php'));
    }
}
