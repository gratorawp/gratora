<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use WP_REST_Request;

/**
 * Open Graph / Twitter share meta: emitted through wp_head on a campaign's
 * main page with the campaign's own title/description, absent on unrelated
 * pages and on campaign-bound pages that are not the campaign's main page.
 */
final class SocialMetaTest extends IntegrationTestCase
{
    public function test_campaign_page_head_emits_og_and_twitter_tags(): void
    {
        $campaign = $this->createCampaign([
            'title'       => 'Riverside Library Restoration',
            'description' => 'Help us restore the flooded community library and rebuild its reading rooms.',
        ]);

        $head = $this->headFor('/?page_id=' . (int) $campaign['page_id']);

        $this->assertStringContainsString('<meta property="og:type" content="website">', $head);
        $this->assertStringContainsString('<meta property="og:title" content="Riverside Library Restoration">', $head);
        $this->assertStringContainsString('<meta property="og:site_name"', $head);
        $this->assertStringContainsString(
            '<meta property="og:description" content="Help us restore the flooded community library and rebuild its reading rooms.">',
            $head
        );
        $this->assertStringContainsString('<meta property="og:url"', $head);
        // No campaign image and no page thumbnail: og:image omitted, plain summary card.
        $this->assertStringNotContainsString('og:image', $head);
        $this->assertStringContainsString('<meta name="twitter:card" content="summary">', $head);
        $this->assertStringContainsString('<meta name="twitter:title" content="Riverside Library Restoration">', $head);
        $this->assertStringContainsString(
            '<meta name="twitter:description" content="Help us restore the flooded community library and rebuild its reading rooms.">',
            $head
        );
    }

    public function test_plain_page_emits_no_dono_social_meta(): void
    {
        $pageId = wp_insert_post([
            'post_title'  => 'About us',
            'post_status' => 'publish',
            'post_type'   => 'page',
        ]);

        $head = $this->headFor('/?page_id=' . (int) $pageId);

        $this->assertStringNotContainsString('property="og:', $head);
        $this->assertStringNotContainsString('name="twitter:', $head);
    }

    public function test_campaign_bound_subpage_emits_nothing(): void
    {
        // A layout-style subpage carries the campaign meta but is not the
        // campaign's main page (its id differs from campaign page_id).
        $campaign = $this->createCampaign(['title' => 'Subpage gate']);
        $subpage  = wp_insert_post([
            'post_title'  => 'Fundraiser layout',
            'post_status' => 'publish',
            'post_type'   => 'page',
            'meta_input'  => ['_dono_campaign_id' => (int) $campaign['id']],
        ]);

        $head = $this->headFor('/?page_id=' . (int) $subpage);

        $this->assertStringNotContainsString('property="og:', $head);
        $this->assertStringNotContainsString('name="twitter:', $head);
    }

    public function test_filter_can_blank_the_tags(): void
    {
        $campaign = $this->createCampaign(['title' => 'Filtered away']);

        add_filter('dono.social_meta', '__return_empty_array');
        try {
            $head = $this->headFor('/?page_id=' . (int) $campaign['page_id']);
        } finally {
            remove_filter('dono.social_meta', '__return_empty_array');
        }

        $this->assertStringNotContainsString('property="og:', $head);
    }

    /** @param array<string,mixed> $input */
    private function createCampaign(array $input): array
    {
        $req = new WP_REST_Request('POST', '/dono/v1/admin/campaigns');
        $req->set_header('content-type', 'application/json');
        $req->set_body(json_encode($input + ['status' => 'published']));
        return rest_do_request($req)->get_data();
    }

    private function headFor(string $url): string
    {
        $this->go_to($url);
        ob_start();
        do_action('wp_head');
        return (string) ob_get_clean();
    }
}
