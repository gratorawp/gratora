<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Forms\Form;
use WP_REST_Request;

/**
 * The donation-form block answers the block editor with an iframe preview and
 * everyone else with the live form. Which one it draws is decided from the
 * route being served, never from REST_REQUEST: WP defines that for every
 * /wp-json call, and core serves content.rendered from the same the_content ->
 * do_blocks run it serves a browser.
 *
 * The route's own permission check accepts whoever can edit the post being
 * previewed, and ServerSideRender always names one. A stricter test here fails
 * for someone core already let through, an editor of pages but not of posts,
 * and answers them with the front-end form: a live form token in a canvas that
 * never runs the scripts that would make it work.
 *
 * REST_REQUEST is deliberately never defined here. It is process-wide and true
 * for every /wp-json call, so a test that defined it would silently flip other
 * blocks into their editor branch for the rest of the run.
 */
final class DonationFormBlockRestRenderTest extends IntegrationTestCase
{
    private const BLOCK_RENDERER_ROUTE = '/wp/v2/block-renderer/dono/donation-form';

    private int $campaignId;

    protected function setUp(): void
    {
        parent::setUp();

        $req = new WP_REST_Request('POST', '/dono/v1/admin/campaigns');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode(['title' => 'Form block REST probe', 'status' => 'published']));
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
            'title'       => 'Form block REST probe form',
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
    private function renderFormOn(string $route): string
    {
        $GLOBALS['wp']->query_vars['rest_route'] = $route;

        $html = do_blocks('<!-- wp:dono/donation-form {"campaignId":' . $this->campaignId . '} /-->');

        unset($GLOBALS['wp']->query_vars['rest_route']);

        return $html;
    }

    public function test_a_page_only_editor_gets_the_preview_core_let_them_ask_for(): void
    {
        add_role('dono_form_page_only', 'Dono form page only', ['read' => true, 'edit_pages' => true]);

        $userId = self::factory()->user->create(['role' => 'dono_form_page_only']);
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
            $html = $this->renderFormOn(self::BLOCK_RENDERER_ROUTE);
        } finally {
            unset($_GET['post_id']);
            remove_role('dono_form_page_only');
        }

        // The preview is an iframe with its own browsing context, carrying a
        // throwaway preview form. The front branch instead drops the live form,
        // real form token and all, straight into the canvas, where none of the
        // scripts that would make it work ever run.
        $this->assertStringContainsString(
            'dono-donation-form__editor-preview',
            $html,
            'the editor gets the preview core let it ask for'
        );
        $this->assertStringNotContainsString(
            'data-block="dono/submit-button"',
            $html,
            'and no live form is rendered into the canvas itself'
        );
    }

    public function test_a_page_read_gets_the_live_form_for_reader_and_editor_alike(): void
    {
        $route  = '/wp/v2/pages/' . self::factory()->post->create(['post_type' => 'page']);
        $editor = get_current_user_id();

        // The editor half is what keeps the route specific: an author reading a
        // page over REST can edit posts, so capability alone would let the
        // preview branch through.
        foreach ([$editor, 0] as $userId) {
            wp_set_current_user($userId);

            $html = $this->renderFormOn($route);

            $this->assertStringContainsString('data-form-slug=', $html, 'a page read is not the block editor');
            $this->assertStringNotContainsString('dono-donation-form__editor-preview', $html);
        }
    }

    public function test_a_reader_who_cannot_edit_never_reaches_the_preview_branch(): void
    {
        wp_set_current_user(0);

        $html = $this->renderFormOn(self::BLOCK_RENDERER_ROUTE);

        $this->assertStringContainsString('data-form-slug=', $html, 'the real form renders instead');
        $this->assertStringNotContainsString('dono-donation-form__editor-preview', $html);
    }
}
