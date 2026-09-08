<?php

declare(strict_types=1);

namespace FundKit\Tests\Integration;

use FundKit\Campaigns\Blocks\BlockEditorIntegration;
use FundKit\Campaigns\CampaignTemplates;
use WP_REST_Request;

final class CampaignTemplatesTest extends IntegrationTestCase
{
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
                'wp:fundkit/donation-form',
                (string) $page->post_content,
                $template['id'] . ' has no way to donate on it'
            );
        }
    }

    /**
     * The templates have to differ, or they are one name repeated.
     *
     * Compared on the whole page rather than on which blocks appear: several
     * layouts draw on the same blocks and differ by how they are arranged and
     * coloured, which is the entire point of having more than a few.
     */
    public function test_the_templates_lay_down_different_pages(): void
    {
        $seen = [];

        foreach (CampaignTemplates::all() as $template) {
            $campaign = $this->createCampaign([
                'title'         => 'Distinct ' . $template['id'],
                'page_template' => $template['id'],
            ]);

            // The campaign id differs per page, so take it out of the comparison.
            $content = (string) get_post((int) $campaign['page_id'])->post_content;
            $seen[$template['id']] = preg_replace('/"campaignId":\d+|campaign_id":\d+/', '', $content);
        }

        $this->assertSame(
            count($seen),
            count(array_unique($seen)),
            'two templates produce the same page: ' . implode(', ', array_keys($seen))
        );
    }

    /**
     * Every layout has to parse as blocks.
     *
     * These are written by hand as block markup, where a missed comment or a
     * malformed attribute does not error: WordPress keeps the broken part as
     * raw HTML, and the page renders looking almost right with one section
     * quietly inert. Only parsing catches it.
     *
     * @dataProvider templateIds
     */
    public function test_a_layout_parses_as_blocks(string $id): void
    {
        $campaign = $this->createCampaign(['title' => 'Parse ' . $id, 'page_template' => $id]);
        $blocks   = parse_blocks((string) get_post((int) $campaign['page_id'])->post_content);

        $stray = [];
        $names = [];

        $walk = function (array $list) use (&$walk, &$stray, &$names): void {
            foreach ($list as $block) {
                $name = $block['blockName'] ?? null;

                if ($name === null) {
                    // Whitespace between blocks parses this way and is fine.
                    if (trim((string) ($block['innerHTML'] ?? '')) !== '') {
                        $stray[] = substr(trim((string) $block['innerHTML']), 0, 80);
                    }
                    continue;
                }

                $names[] = $name;
                $walk($block['innerBlocks'] ?? []);
            }
        };
        $walk($blocks);

        $this->assertSame([], $stray, $id . ' left markup outside any block: ' . implode(' | ', $stray));
        $this->assertNotEmpty($names, $id . ' parsed to no blocks at all');
    }

    /**
     * Every fundkit block a layout names is one that exists.
     *
     * A typo in a block name renders as nothing at all, with no error anywhere.
     *
     * @dataProvider templateIds
     */
    public function test_a_layout_only_uses_registered_blocks(string $id): void
    {
        $campaign = $this->createCampaign(['title' => 'Registered ' . $id, 'page_template' => $id]);
        $content  = (string) get_post((int) $campaign['page_id'])->post_content;

        preg_match_all('#wp:(fundkit/[a-z-]+)#', $content, $m);

        $registry = \WP_Block_Type_Registry::get_instance();
        foreach (array_unique($m[1]) as $name) {
            $this->assertNotNull(
                $registry->get_registered($name),
                $id . ' uses ' . $name . ', which is not a registered block'
            );
        }
    }

    /**
     * No layout writes an inline style onto a block it did not author.
     *
     * A static block is validated by re-running its save and comparing the
     * markup, so a hand-written style attribute that does not match what core
     * would emit makes the block invalid: the editor replaces it with "Block
     * contains unexpected or invalid content" and an Attempt recovery button.
     * parse_blocks does not catch this, because the markup parses fine, it
     * simply does not match. Colour and spacing come from classes instead.
     *
     * Column widths are the one exception: flex-basis IS what core writes for
     * a column carrying a width.
     *
     * @dataProvider templateIds
     */
    public function test_a_layout_writes_no_inline_styles_of_its_own(string $id): void
    {
        $campaign = $this->createCampaign(['title' => 'Styles ' . $id, 'page_template' => $id]);
        $content  = (string) get_post((int) $campaign['page_id'])->post_content;

        preg_match_all('/style="([^"]*)"/', $content, $m);

        $unexpected = array_values(array_filter(
            $m[1],
            static fn (string $style): bool => preg_match('/^flex-basis:[0-9.]+%$/', $style) !== 1
        ));

        $this->assertSame(
            [],
            $unexpected,
            $id . ' writes inline styles core would not, so those blocks show as invalid: ' . implode(' | ', $unexpected)
        );
    }

    /** @return array<string,array{string}> */
    /**
     * A placeholder that survives seeding renders as %%ITS_NAME%% on the page, to
     * donors. The templates and the substitution map live in different files, so
     * adding one to only half of the pair is the easy mistake.
     *
     * @dataProvider templateIds
     */
    public function test_a_layout_leaves_no_placeholder_behind(string $id): void
    {
        $campaign = $this->createCampaign(['title' => 'Placeholder ' . $id, 'page_template' => $id]);
        $content  = (string) get_post((int) $campaign['page_id'])->post_content;

        $this->assertDoesNotMatchRegularExpression('/%%[A-Z_]+%%/', $content);
    }

    public static function templateIds(): array
    {
        $out = [];
        foreach (CampaignTemplates::all() as $t) {
            $out[$t['id']] = [$t['id']];
        }

        return $out;
    }

    /** The deadline layout is the only one that shows how long is left. */
    public function test_the_deadline_layout_shows_the_time_remaining(): void
    {
        $campaign = $this->createCampaign(['title' => 'Deadline', 'page_template' => 'deadline']);
        $content  = (string) get_post((int) $campaign['page_id'])->post_content;

        $this->assertStringContainsString('"metric":"days_left"', $content);
    }

    public function test_the_minimal_layout_carries_no_figures_or_donor_lists(): void
    {
        $campaign = $this->createCampaign(['title' => 'Minimal', 'page_template' => 'minimal']);
        $content  = (string) get_post((int) $campaign['page_id'])->post_content;

        foreach (['campaign-stat', 'campaign-progress', 'top-donors', 'recent-donations', 'supporter-wall'] as $block) {
            $this->assertStringNotContainsString(
                'wp:fundkit/' . $block,
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

    public function test_the_templates_are_listed_over_rest(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        $response = rest_do_request(new WP_REST_Request('GET', '/fundkit/v1/admin/campaigns/templates'));
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

        $request = new WP_REST_Request('GET', '/fundkit/v1/admin/campaigns/' . (int) $campaign['id'] . '/layout');
        $request->set_param('template', 'story');
        $response = rest_do_request($request);
        $data     = (array) $response->get_data();

        $this->assertSame(200, $response->get_status());
        $this->assertSame('story', $data['template']);
        $this->assertStringContainsString('wp:fundkit/donation-form', (string) $data['blocks']);
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

    /**
     * Which layouts a campaign can wear depends on its type. A type that lays
     * out its own page brings its own list, and judging an id against the
     * general one both refuses that type's own layouts and accepts the ones
     * that would delete the page it exists for.
     */
    public function test_a_layout_is_judged_against_the_campaign_s_own_list(): void
    {
        $campaign = $this->createCampaign(['title' => 'Own list']);

        // A type that keeps one layout of its own and none of the general ones.
        // Narrowed only when a type is actually asked about, so a caller that
        // forgets to pass one still sees the general list and is caught here.
        add_filter(
            'fundkit.campaign.templates',
            static fn (array $templates, string $type): array => $type === 'standard'
                ? array_values(array_filter($templates, static fn (array $t): bool => $t['id'] === 'minimal'))
                : $templates,
            10,
            2
        );

        $ask = function (string $template) use ($campaign): int {
            $request = new WP_REST_Request('GET', '/fundkit/v1/admin/campaigns/' . (int) $campaign['id'] . '/layout');
            $request->set_param('template', $template);

            return rest_do_request($request)->get_status();
        };

        $this->assertSame(200, $ask('minimal'), 'the type\'s own layout was refused');
        $this->assertSame(400, $ask('cover'), 'a layout this type does not offer was served anyway');
    }

    /**
     * The switcher is offered on the campaign's own page and nowhere else.
     *
     * Other pages carry the campaign's id so the blocks on them resolve against
     * it, and a peer-to-peer campaign has three such pages holding the layouts
     * for its fundraiser, team and start routes. A template dropped on one of
     * those replaces the thing that page is for.
     */
    public function test_templates_are_offered_on_the_campaign_page_and_nowhere_else(): void
    {
        $campaign = $this->createCampaign(['title' => 'Offered where']);

        $other = self::factory()->post->create(['post_type' => 'page']);
        update_post_meta($other, '_fundkit_campaign_id', (int) $campaign['id']);

        $GLOBALS['post'] = null;

        $_GET['post'] = (int) $campaign['page_id'];
        $this->assertTrue(BlockEditorIntegration::pageTemplatesAvailable());

        $_GET['post'] = $other;
        $this->assertFalse(
            BlockEditorIntegration::pageTemplatesAvailable(),
            'a page that only references the campaign was offered its layouts'
        );

        unset($_GET['post']);
    }

    public function test_reading_an_unknown_layout_is_refused(): void
    {
        $campaign = $this->createCampaign(['title' => 'Bad layout']);

        $request = new WP_REST_Request('GET', '/fundkit/v1/admin/campaigns/' . (int) $campaign['id'] . '/layout');
        $request->set_param('template', 'no-such-layout');

        $this->assertSame(400, rest_do_request($request)->get_status());
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function createCampaign(array $input): array
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        $req = new WP_REST_Request('POST', '/fundkit/v1/admin/campaigns');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) json_encode($input + ['status' => 'published']));

        $data = (array) rest_do_request($req)->get_data();
        $this->assertArrayHasKey('page_id', $data, 'campaign was not created: ' . wp_json_encode($data));

        return $data;
    }

    private function blocksOf(array $campaign): string
    {
        $content = (string) get_post((int) $campaign['page_id'])->post_content;
        preg_match_all('#wp:fundkit/[a-z-]+#', $content, $m);

        return implode(',', $m[0]);
    }
}
