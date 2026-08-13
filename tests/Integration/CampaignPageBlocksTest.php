<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use WP_REST_Request;

/**
 * Campaign auto-creates a WP page seeded with the full starter layout (hero,
 * stats, progress, inline donation form, top donors, recent donations,
 * campaign grid). This test exercises the full pipeline:
 *
 *   POST /admin/campaigns → wp_insert_post → page meta → do_blocks() render
 */
final class CampaignPageBlocksTest extends IntegrationTestCase
{
    public function test_creating_campaign_makes_a_page_with_campaign_id_meta(): void
    {
        $campaign = $this->createCampaign(['title' => 'Page meta test']);

        $this->assertGreaterThan(0, $campaign['page_id'], 'Campaign should have an attached page.');

        $meta = get_post_meta((int) $campaign['page_id'], '_dono_campaign_id', true);
        $this->assertSame((string) $campaign['id'], (string) $meta);
    }

    public function test_page_content_contains_the_full_starter_layout(): void
    {
        $campaign = $this->createCampaign(['title' => 'Starter block test']);
        $page = get_post((int) $campaign['page_id']);

        $this->assertStringContainsString('wp:dono/campaign-image',    $page->post_content);
        $this->assertStringContainsString('wp:dono/campaign-progress', $page->post_content);
        $this->assertStringContainsString('wp:dono/donation-form',     $page->post_content);
        $this->assertStringContainsString('wp:dono/top-donors',        $page->post_content);
        $this->assertStringContainsString('wp:dono/recent-donations',  $page->post_content);

        // Each figure is its own block, so the seed places several rather than
        // one block that draws them all.
        $this->assertGreaterThanOrEqual(
            3,
            substr_count($page->post_content, 'wp:dono/campaign-stat '),
            'the starter layout places individual stat blocks'
        );

        // The grid sends a visitor away from the page they were asked to give
        // on, which is the wrong default for a page whose job is one campaign.
        // It stays registered for custom layouts.
        $this->assertStringNotContainsString('wp:dono/campaign-grid', $page->post_content);

        // The title is a core Heading block, not markup inside a render
        // callback, so an organiser owns the words.
        $this->assertStringContainsString('wp:heading', $page->post_content);
        $this->assertStringContainsString('wp:columns', $page->post_content);

        // Nothing is seeded as prose. The old story slot shipped its own
        // instructions, and a page nobody edited published them to donors as
        // though the campaign had written them.
        $this->assertStringNotContainsString('Tell the story behind', $page->post_content);

        // The form sits beside the figures, so a donate button in the main
        // column would only scroll to something already on screen.
        $this->assertStringNotContainsString('wp:dono/donate-button', $page->post_content);
    }

    public function test_the_campaign_list_carries_a_link_to_the_page(): void
    {
        $campaign = $this->createCampaign(['title' => 'Viewable campaign']);

        $req = new WP_REST_Request('GET', '/dono/v1/admin/campaigns');
        $rows = (array) rest_do_request($req)->get_data();

        $row = null;
        foreach ((array) ($rows['items'] ?? $rows) as $item) {
            if ((int) ($item['id'] ?? 0) === (int) $campaign['id']) {
                $row = $item;
            }
        }

        // The View action opens this. Only the server can turn a page id into a
        // URL, so if the field stops being sent the action quietly disappears
        // from every row rather than failing.
        $this->assertNotNull($row, (string) wp_json_encode($rows));
        $this->assertNotEmpty($row['permalink'] ?? null);
        $this->assertSame(get_permalink((int) $campaign['page_id']), $row['permalink']);
    }

    public function test_every_empty_list_on_a_page_renders_its_own_full_card(): void
    {
        $campaign = $this->createCampaign(['title' => 'Nobody has given yet']);
        $html     = $this->renderPage((int) $campaign['page_id']);

        // Both lists are empty on a new campaign and the starter layout places
        // them together, so one render pass draws both. They must not compete:
        // a page-scoped rule that let the first one win would render the second
        // as a bare line, which the editor cannot reproduce because it previews
        // each block in its own request.
        $this->assertSame(2, substr_count($html, 'dono-empty__title'), 'both lists draw a full card');
        $this->assertStringContainsString('No donations to show yet.', $html);
        $this->assertStringContainsString('No donors to rank yet.', $html);

        // Each says its own thing under its own icon.
        $this->assertStringContainsString('Donations tend to follow the first one.', $html);
        $this->assertStringContainsString('The first donation starts the list.', $html);

        $this->assertStringNotContainsString('dono-empty--quiet', $html);
    }

    public function test_top_level_blocks_are_aligned_wide(): void
    {
        $campaign = $this->createCampaign(['title' => 'Alignment test']);
        $content  = get_post((int) $campaign['page_id'])->post_content;

        // The editor canvas is a constrained layout, so core caps an unaligned
        // top-level block at the theme's contentSize: 645px under Twenty
        // Twenty-Five, whatever the page measure says. Wide alignment is what
        // hands the width back to the stylesheet, and without it the editor
        // shows a narrow column of a page that is wide on the front end.
        $this->assertStringContainsString('"align":"wide","className":"dp-layout"', $content);
        $this->assertStringContainsString('wp-block-columns alignwide dp-layout', $content);

        $this->assertStringContainsString('"level":1,"align":"wide"', $content);
        $this->assertStringContainsString('wp-block-heading alignwide', $content);
    }

    public function test_page_renders_the_campaign_title(): void
    {
        $campaign = $this->createCampaign(['title' => 'Save the bees']);
        $html = $this->renderPage((int) $campaign['page_id']);

        $this->assertStringContainsString('Save the bees', $html);
    }

    public function test_a_stat_block_renders_one_named_figure(): void
    {
        $campaign = $this->createCampaign(['title' => 'Fresh stats']);
        $html = $this->renderBlockPage('dono/campaign-stat', (int) $campaign['id']);

        $this->assertStringContainsString('dono-block--stat', $html);
        $this->assertStringContainsString('data-metric="raised"', $html);
        $this->assertStringContainsString('class="dono-stat__label">Amount raised', $html);
    }

    public function test_progress_block_renders_bar_with_percent_role(): void
    {
        $campaign = $this->createCampaign(['title' => 'Progress test']);
        $html = $this->renderBlockPage('dono/campaign-progress', (int) $campaign['id']);

        $this->assertStringContainsString('dono-block--progress', $html);
        $this->assertMatchesRegularExpression(
            '/role="progressbar"[^>]*aria-valuenow="\d+"/',
            $html
        );
    }

    public function test_donate_button_block_renders_with_form_slug(): void
    {
        $campaign = $this->createCampaign(['title' => 'Button test']);
        $html = $this->renderDonateButtonPage((int) $campaign['id']);

        $this->assertStringContainsString('dono-donate-button', $html);
        $this->assertMatchesRegularExpression('/data-form-slug="[^"]+"/', $html);
        $this->assertStringContainsString('Donate now', $html);
    }

    public function test_donate_button_renders_paired_modal_with_form_html(): void
    {
        $campaign = $this->createCampaign(['title' => 'Modal test']);
        $html = $this->renderDonateButtonPage((int) $campaign['id']);

        // Modal scaffolding present.
        $this->assertMatchesRegularExpression('/<div class="dono-donate-modal" data-form-slug="[^"]+" hidden>/', $html);
        $this->assertStringContainsString('class="dono-donate-modal__backdrop"', $html);
        $this->assertStringContainsString('class="dono-donate-modal__panel"', $html);
        $this->assertStringContainsString('class="dono-donate-modal__close"', $html);

        // Modal contains the actual donation form rendered via the shortcode.
        $this->assertStringContainsString('dono-donation-form--blocks', $html);
    }

    public function test_donate_button_modal_form_slug_matches_button_form_slug(): void
    {
        $campaign = $this->createCampaign(['title' => 'Slug match']);
        $html = $this->renderDonateButtonPage((int) $campaign['id']);

        // Pull both slugs out and confirm they reference the same form.
        preg_match('/class="dono-donate-button[^"]*"\s+data-form-slug="([^"]+)"/', $html, $buttonMatch);
        preg_match('/dono-donate-modal" data-form-slug="([^"]+)"/',    $html, $modalMatch);

        $this->assertNotEmpty($buttonMatch[1] ?? null, 'Button should expose data-form-slug.');
        $this->assertNotEmpty($modalMatch[1]  ?? null, 'Modal should expose data-form-slug.');
        $this->assertSame($buttonMatch[1], $modalMatch[1]);
    }

    public function test_blocks_resolve_campaign_via_post_meta_when_attribute_is_zero(): void
    {
        // Create a campaign just so we have a campaign to bind to.
        $campaign = $this->createCampaign(['title' => 'Meta-bound block']);

        // Hand-author a WP page that omits campaignId in the block markup.
        $pageId = wp_insert_post([
            'post_title'   => 'Manual page',
            'post_status'  => 'publish',
            'post_type'    => 'page',
            'post_content' => '<!-- wp:dono/campaign-stat /-->',
            'meta_input'   => ['_dono_campaign_id' => (int) $campaign['id']],
        ]);

        $html = $this->renderPage((int) $pageId);

        // A stat only renders once a campaign resolved, so its presence is the
        // proof the meta was read.
        $this->assertStringContainsString('data-metric="raised"', $html);
    }

    public function test_blocks_show_not_bound_notice_when_no_campaign_can_be_resolved(): void
    {
        $pageId = wp_insert_post([
            'post_title'   => 'Unbound page',
            'post_status'  => 'publish',
            'post_type'    => 'page',
            'post_content' => '<!-- wp:dono/campaign-progress /-->',
        ]);

        wp_set_current_user(1); // editor sees the notice
        $html = $this->renderPage((int) $pageId);

        $this->assertStringContainsString('class="dono-block-notice"', $html);
        $this->assertStringNotContainsString('dono-block--progress', $html);
    }

    public function test_blocks_render_empty_for_visitors_when_unbound(): void
    {
        $pageId = wp_insert_post([
            'post_title'   => 'Unbound page',
            'post_status'  => 'publish',
            'post_type'    => 'page',
            'post_content' => '<!-- wp:dono/campaign-progress /-->',
        ]);

        wp_set_current_user(0);
        $html = $this->renderPage((int) $pageId);

        $this->assertStringNotContainsString('dono-block-notice', $html);
        $this->assertStringNotContainsString('dono-block--progress', $html);
    }

    /** @param array<string,mixed> $input */
    private function createCampaign(array $input): array
    {
        $req = new WP_REST_Request('POST', '/dono/v1/admin/campaigns');
        $req->set_header('content-type', 'application/json');
        $req->set_body(json_encode($input + ['status' => 'published']));
        return rest_do_request($req)->get_data();
    }

    /**
     * The donate button is placeable (no longer part of the starter layout),
     * so render it from a hand-authored page bound to the campaign.
     */
    private function renderDonateButtonPage(int $campaignId): string
    {
        return $this->renderBlockPage('dono/donate-button', $campaignId);
    }

    /**
     * Render one block on a page of its own.
     *
     * Blocks that are registered but not seeded need this: reaching them
     * through the starter layout tied their tests to what the layout happens
     * to contain, so dropping a block from the seed failed tests that were
     * never about the seed.
     */
    private function renderBlockPage(string $block, int $campaignId): string
    {
        $pageId = wp_insert_post([
            'post_title'   => $block . ' page',
            'post_status'  => 'publish',
            'post_type'    => 'page',
            'post_content' => sprintf('<!-- wp:%s {"campaignId":%d} /-->', $block, $campaignId),
            'meta_input'   => ['_dono_campaign_id' => $campaignId],
        ]);

        return $this->renderPage((int) $pageId);
    }

    private function renderPage(int $pageId): string
    {
        // Set $post global so blocks can read post meta for auto-binding.
        global $post;
        $post = get_post($pageId);
        setup_postdata($post);
        try {
            return do_blocks($post->post_content);
        } finally {
            wp_reset_postdata();
        }
    }
}
