<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Forms\Form;
use WP_HTML_Tag_Processor;
use WP_REST_Request;

/**
 * Use the block-renderer route and post edit permission to select previews. Leave REST_REQUEST
 * undefined: it also covers public REST renders and affects the entire test process.
 */
final class DonationFormBlockRestRenderTest extends IntegrationTestCase
{
    private const BLOCK_RENDERER_ROUTE = '/wp/v2/block-renderer/gratora/donation-form';

    private int $campaignId;

    protected function setUp(): void
    {
        parent::setUp();

        $req = new WP_REST_Request('POST', '/gratora/v1/admin/campaigns');
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
        $req = new WP_REST_Request('POST', '/gratora/v1/admin/forms');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode([
            'title'       => 'Form block REST probe form',
            'campaign_id' => $this->campaignId,
            'blocks'      => '<!-- wp:gratora/donation-amount {"presets":[1000],"currency":"EUR"} /-->'
                . '<!-- wp:gratora/email /-->'
                . '<!-- wp:gratora/submit-button /-->',
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

        $html = do_blocks('<!-- wp:gratora/donation-form {"campaignId":' . $this->campaignId . '} /-->');

        unset($GLOBALS['wp']->query_vars['rest_route']);

        return $html;
    }

    public function test_a_page_only_editor_gets_the_preview_core_let_them_ask_for(): void
    {
        add_role('gratora_form_page_only', 'Gratora form page only', ['read' => true, 'edit_pages' => true]);

        $userId = self::factory()->user->create(['role' => 'gratora_form_page_only']);
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
            remove_role('gratora_form_page_only');
        }

        // The preview is an iframe with its own browsing context, carrying a
        // throwaway preview form. The front branch instead drops the live form,
        // real form token and all, straight into the canvas, where none of the
        // scripts that would make it work ever run.
        $this->assertStringContainsString(
            'gratora-donation-form__editor-preview',
            $html,
            'the editor gets the preview core let it ask for'
        );
        $this->assertStringNotContainsString(
            'data-block="gratora/submit-button"',
            $html,
            'and no live form is rendered into the canvas itself'
        );
    }

    /**
     * The preview frame runs same-origin with wp-admin, and its document is an
     * attribute value: whatever the document escaped must stay text once the
     * browser decodes the attribute, or a field label becomes markup in the
     * editor of everyone who opens the page.
     */
    public function test_text_the_preview_escaped_stays_text_inside_the_frame(): void
    {
        $form         = Form::query()->where('campaign_id', $this->campaignId)->get();
        $form->blocks = '<!-- wp:gratora/text-input {"label":"<img src=x onerror=window.gratoraProbe=1>"} /-->' . $form->blocks;
        $form->save();

        $pageId = self::factory()->post->create(['post_type' => 'page', 'post_status' => 'draft']);
        $_GET['post_id'] = (string) $pageId;

        try {
            $html = $this->renderFormOn(self::BLOCK_RENDERER_ROUTE);
        } finally {
            unset($_GET['post_id']);
        }

        $frame = new WP_HTML_Tag_Processor($html);
        $this->assertTrue($frame->next_tag(['class_name' => 'gratora-donation-form__editor-preview']), 'the editor gets the preview frame');

        $document = (string) $frame->get_attribute('srcdoc');
        $this->assertStringContainsString('&lt;img src=x onerror', $document, 'the label reaches the frame as the text the preview escaped');

        $inside = new WP_HTML_Tag_Processor($document);
        while ($inside->next_tag('IMG')) {
            $this->assertNull($inside->get_attribute('onerror'), 'and never as an element');
        }
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

            // Quoted on purpose: the preview carries a form of its own, so
            // data-form-slug= is on both branches, and only the srcdoc escaping
            // of the quotes tells them apart.
            $this->assertStringContainsString('data-block="gratora/submit-button"', $html, 'a page read is not the block editor');
            $this->assertStringNotContainsString('gratora-donation-form__editor-preview', $html);
        }
    }

    public function test_a_reader_who_cannot_edit_never_reaches_the_preview_branch(): void
    {
        wp_set_current_user(0);

        $html = $this->renderFormOn(self::BLOCK_RENDERER_ROUTE);

        $this->assertStringContainsString('data-block="gratora/submit-button"', $html, 'the real form renders instead');
        $this->assertStringNotContainsString('gratora-donation-form__editor-preview', $html);
    }
}
