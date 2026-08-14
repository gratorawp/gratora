<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Campaigns\Blocks\DonateButtonBlock;
use Dono\Campaigns\Campaign;
use Dono\Forms\Form;
use ReflectionMethod;
use WP_REST_Request;

/**
 * The donate button skips its form, and with it the whole closed-campaign
 * check, whenever it decides it is drawing the block editor's preview.
 *
 * REST_REQUEST cannot make that decision: WP defines it for every /wp-json
 * call, and core serves content.rendered from the same the_content ->
 * do_blocks run it serves a browser. An anonymous read of a campaign page
 * would otherwise come back with a button and no modal behind it, and a
 * finished campaign with a live-looking button instead of its explanation.
 *
 * REST_REQUEST is deliberately never defined here. It is process-wide and true
 * for every /wp-json call, so a test that defined it would silently flip other
 * blocks into their editor branch for the rest of the run.
 */
final class DonateButtonRestRenderTest extends IntegrationTestCase
{
    private const BLOCK_RENDERER_ROUTE = '/wp/v2/block-renderer/dono/donate-button';

    private int $campaignId;

    protected function setUp(): void
    {
        parent::setUp();

        $req = new WP_REST_Request('POST', '/dono/v1/admin/campaigns');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode(['title' => 'Button REST probe', 'status' => 'published']));
        $this->campaignId = (int) rest_do_request($req)->get_data()['id'];

        $this->publishedForm();
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wp']->query_vars['rest_route']);
        parent::tearDown();
    }

    /**
     * Created as a draft through REST then published on the row: the REST
     * publish path runs a readiness check these minimal blocks would fail.
     */
    private function publishedForm(): void
    {
        $req = new WP_REST_Request('POST', '/dono/v1/admin/forms');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode([
            'title'       => 'Button REST probe form',
            'campaign_id' => $this->campaignId,
            'blocks'      => '<!-- wp:dono/donation-amount {"presets":[1000],"currency":"EUR"} /-->'
                . '<!-- wp:dono/email /-->'
                . '<!-- wp:dono/submit-button /-->',
        ]));
        $created = rest_do_request($req)->get_data();

        $form         = Form::query()->find('id', (int) $created['id']);
        $form->status = 'published';
        $form->save();
    }

    /**
     * WP puts the route of the request it is serving on the global query vars
     * before dispatching, so naming one models a real /wp-json call. Tests go
     * through rest_do_request(), which skips parse_request and leaves it unset.
     */
    private function renderButtonOn(string $route): string
    {
        $GLOBALS['wp']->query_vars['rest_route'] = $route;

        $html = do_blocks('<!-- wp:dono/donate-button {"campaignId":' . $this->campaignId . '} /-->');

        unset($GLOBALS['wp']->query_vars['rest_route']);

        return $html;
    }

    public function test_the_editor_preview_draws_the_button_without_booting_a_form(): void
    {
        $html = $this->renderButtonOn(self::BLOCK_RENDERER_ROUTE);

        $this->assertStringContainsString('dono-donate-button', $html, 'the editor still sees its button');
        $this->assertStringNotContainsString(
            'dono-donate-modal',
            $html,
            'and no form runtime is booted inside the editor frame'
        );
    }

    public function test_a_page_read_gets_the_modal_for_reader_and_editor_alike(): void
    {
        $route  = '/wp/v2/pages/' . self::factory()->post->create(['post_type' => 'page']);
        $editor = get_current_user_id();

        // The editor half is what keeps the route specific: an author reading a
        // page over REST can edit posts, so capability alone would let the
        // preview branch through.
        foreach ([$editor, 0] as $userId) {
            wp_set_current_user($userId);
            $html = $this->renderButtonOn($route);

            $this->assertStringContainsString('dono-donate-modal', $html, 'a page read is not the block editor');
            $this->assertStringContainsString('data-form-slug=', $html);
        }
    }

    public function test_a_reader_who_cannot_edit_never_reaches_the_preview_branch(): void
    {
        wp_set_current_user(0);

        $html = $this->renderButtonOn(self::BLOCK_RENDERER_ROUTE);

        $this->assertStringContainsString('dono-donate-modal', $html, 'the real button and its form render instead');
    }

    /**
     * The sharpest case: on the preview branch the closed-campaign check is
     * skipped entirely, so a finished campaign would answer a page read with a
     * button that opens nothing and says nothing.
     */
    public function test_a_finished_campaign_explains_itself_over_rest_too(): void
    {
        $campaign = Campaign::query()->find('id', $this->campaignId);
        $campaign->ends_at = gmdate('Y-m-d', strtotime('-1 day'));
        $campaign->save();

        wp_set_current_user(0);
        $html = $this->renderButtonOn('/wp/v2/pages/' . self::factory()->post->create(['post_type' => 'page']));

        $this->assertStringContainsString('This campaign has finished accepting donations.', $html);
        $this->assertStringNotContainsString('dono-donate-button', $html);
    }

    /**
     * The route's own check accepts whoever can edit the post being previewed,
     * and ServerSideRender always names one. A user who can edit pages but not
     * posts passes core and fails a bare edit_posts test here, and is answered
     * with the live front-end form dropped into the editor canvas: a real form
     * token, none of the scripts that make it work.
     */
    public function test_a_page_only_editor_gets_the_preview_core_let_them_ask_for(): void
    {
        add_role('dono_page_only', 'Dono page only', ['read' => true, 'edit_pages' => true]);

        $userId = self::factory()->user->create(['role' => 'dono_page_only']);
        $pageId = self::factory()->post->create([
            'post_type'   => 'page',
            'post_status' => 'draft',
            'post_author' => $userId,
        ]);

        wp_set_current_user($userId);
        $this->assertFalse(current_user_can('edit_posts'), 'the role this models cannot edit posts');
        $this->assertTrue(current_user_can('edit_post', $pageId), 'but core lets it ask for this preview');

        $_GET['post_id'] = (string) $pageId;

        try {
            $html = $this->renderButtonOn(self::BLOCK_RENDERER_ROUTE);
        } finally {
            unset($_GET['post_id']);
            remove_role('dono_page_only');
        }

        $this->assertStringNotContainsString('dono-donate-modal', $html, 'no live form in the editor canvas');
        $this->assertStringContainsString('dono-donate-button', $html, 'the editor still sees its button');
    }

    /**
     * Read off the source because nothing else in this class can say it. The
     * constant is process-wide and true for every /wp-json call, so defining it
     * to prove the point would flip other blocks into their editor branch for
     * the rest of the run; leaving it undefined lets a
     * `REST_REQUEST || $this->isBlockRendererRequest()` restore the entire
     * defect with every other test here still green.
     */
    public function test_the_render_decision_never_reads_rest_request(): void
    {
        foreach (['render', 'isBlockRendererRequest'] as $method) {
            $ref = new ReflectionMethod(DonateButtonBlock::class, $method);

            // getStartLine() begins at the declaration, so the docblocks that
            // explain why the constant is wrong are not part of what is read.
            $body = implode('', array_slice(
                (array) file((string) $ref->getFileName()),
                $ref->getStartLine() - 1,
                $ref->getEndLine() - $ref->getStartLine() + 1
            ));

            $this->assertStringNotContainsString(
                'REST_REQUEST',
                $body,
                $method . '() decides on the route it is serving, not on the constant'
            );
        }
    }
}
