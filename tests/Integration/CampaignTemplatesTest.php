<?php

declare(strict_types=1);

namespace GiveFlow\Tests\Integration;

use GiveFlow\Campaigns\CampaignTemplates;
use WP_REST_Request;

/**
 * A campaign page is built from a chosen starter layout rather than the one
 * hardcoded layout there used to be.
 */
final class CampaignTemplatesTest extends IntegrationTestCase
{
    /** Every registered template has to produce a page, not just the default. */
    public function test_each_template_seeds_a_page_with_a_donation_form(): void
    {
        foreach (CampaignTemplates::all() as $template) {
            $campaign = $this->createCampaign([
                'title'         => 'Layout ' . $template['id'],
                'page_template' => $template['id'],
            ]);

            $page = get_post((int) $campaign['page_id']);

            $this->assertNotNull($page, $template['id'] . ' produced no page');
            $this->assertStringContainsString(
                'wp:giveflow/donation-form',
                (string) $page->post_content,
                $template['id'] . ' has no way to donate on it'
            );
        }
    }

    /**
     * The templates have to differ, or they are one template with five names.
     *
     * Checked on the blocks each lays down rather than on the id, since the id
     * reaching the page proves nothing about what the page contains.
     */
    public function test_the_templates_lay_down_different_blocks(): void
    {
        $seen = [];

        foreach (CampaignTemplates::all() as $template) {
            $campaign = $this->createCampaign([
                'title'         => 'Distinct ' . $template['id'],
                'page_template' => $template['id'],
            ]);

            $content = (string) get_post((int) $campaign['page_id'])->post_content;
            preg_match_all('#wp:giveflow/[a-z-]+#', $content, $m);
            $blocks = $m[0];
            sort($blocks);
            $seen[$template['id']] = implode(',', $blocks);
        }

        $this->assertSame(
            count($seen),
            count(array_unique($seen)),
            'two templates lay down the same blocks: ' . wp_json_encode($seen)
        );
    }

    /** The deadline layout is the only one that shows how long is left. */
    public function test_the_deadline_layout_shows_the_time_remaining(): void
    {
        $campaign = $this->createCampaign(['title' => 'Deadline', 'page_template' => 'deadline']);
        $content  = (string) get_post((int) $campaign['page_id'])->post_content;

        $this->assertStringContainsString('"metric":"days_left"', $content);
    }

    /** Just the form means nothing on the page needs data to look right. */
    public function test_the_minimal_layout_carries_no_figures_or_donor_lists(): void
    {
        $campaign = $this->createCampaign(['title' => 'Minimal', 'page_template' => 'minimal']);
        $content  = (string) get_post((int) $campaign['page_id'])->post_content;

        foreach (['campaign-stat', 'campaign-progress', 'top-donors', 'recent-donations', 'supporter-wall'] as $block) {
            $this->assertStringNotContainsString(
                'wp:giveflow/' . $block,
                $content,
                'minimal should carry nothing that needs donations to render, but has ' . $block
            );
        }
    }

    /**
     * layout() answers for an id it does not know.
     *
     * The service normalises an unknown id before it gets here, so this guard
     * is the second of two. Asserted directly because going through create()
     * only ever exercises the first, which left this branch free to return an
     * empty string undetected.
     */
    public function test_layout_falls_back_rather_than_returning_nothing(): void
    {
        $standard = CampaignTemplates::layout(CampaignTemplates::DEFAULT_ID);

        $this->assertNotSame('', $standard);
        $this->assertSame($standard, CampaignTemplates::layout('no-such-template'));
    }

    /**
     * An id nobody registered leaves a usable page.
     *
     * A removed template or a typo from an API caller must not produce a blank
     * campaign page, which is the failure nobody notices until a donor arrives.
     */
    public function test_an_unknown_template_falls_back_to_the_standard_layout(): void
    {
        $unknown  = $this->createCampaign(['title' => 'Unknown', 'page_template' => 'no-such-template']);
        $standard = $this->createCampaign(['title' => 'Standard', 'page_template' => 'standard']);

        $this->assertSame(
            $this->blocksOf($standard),
            $this->blocksOf($unknown),
            'an unrecognised template did not fall back to the standard layout'
        );
    }

    /** A campaign created without naming a template still gets the standard one. */
    public function test_omitting_the_template_gives_the_standard_layout(): void
    {
        $omitted  = $this->createCampaign(['title' => 'Omitted']);
        $standard = $this->createCampaign(['title' => 'Named', 'page_template' => 'standard']);

        $this->assertSame($this->blocksOf($standard), $this->blocksOf($omitted));
    }

    /** The picker is fed over REST, so the route has to answer. */
    public function test_the_templates_are_listed_over_rest(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        $response = rest_do_request(new WP_REST_Request('GET', '/giveflow/v1/admin/campaigns/templates'));
        $data     = $response->get_data();

        $this->assertSame(200, $response->get_status());
        $this->assertNotEmpty($data);
        $this->assertSame(
            array_column(CampaignTemplates::all(), 'id'),
            array_column((array) $data, 'id')
        );
    }

    /**
     * The editor asks for a layout's blocks so it can put them in the canvas.
     *
     * The campaign id has to be interpolated or every block on the page renders
     * for no campaign, which is the failure that looks like an empty page.
     */
    public function test_a_layout_can_be_read_without_writing_anything(): void
    {
        $campaign = $this->createCampaign(['title' => 'Read a layout']);
        $before   = (string) get_post((int) $campaign['page_id'])->post_content;

        $request = new WP_REST_Request('GET', '/giveflow/v1/admin/campaigns/' . (int) $campaign['id'] . '/layout');
        $request->set_param('template', 'story');
        $response = rest_do_request($request);
        $data     = (array) $response->get_data();

        $this->assertSame(200, $response->get_status());
        $this->assertSame('story', $data['template']);
        $this->assertStringContainsString('wp:giveflow/donation-form', (string) $data['blocks']);
        $this->assertStringContainsString(
            '"campaignId":' . (int) $campaign['id'],
            (string) $data['blocks'],
            'the campaign id was not interpolated, so every block would render for no campaign'
        );

        $this->assertSame(
            $before,
            (string) get_post((int) $campaign['page_id'])->post_content,
            'reading a layout changed the page'
        );
    }

    /** An unknown layout is refused rather than quietly serving the standard one. */
    public function test_reading_an_unknown_layout_is_refused(): void
    {
        $campaign = $this->createCampaign(['title' => 'Bad layout']);

        $request = new WP_REST_Request('GET', '/giveflow/v1/admin/campaigns/' . (int) $campaign['id'] . '/layout');
        $request->set_param('template', 'no-such-layout');

        $this->assertSame(400, rest_do_request($request)->get_status());
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function createCampaign(array $input): array
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        $req = new WP_REST_Request('POST', '/giveflow/v1/admin/campaigns');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) json_encode($input + ['status' => 'published']));

        $data = (array) rest_do_request($req)->get_data();
        $this->assertArrayHasKey('page_id', $data, 'campaign was not created: ' . wp_json_encode($data));

        return $data;
    }

    private function blocksOf(array $campaign): string
    {
        $content = (string) get_post((int) $campaign['page_id'])->post_content;
        preg_match_all('#wp:giveflow/[a-z-]+#', $content, $m);

        return implode(',', $m[0]);
    }
}
