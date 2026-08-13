<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donations\Donation;
use Dono\Forms\Form;
use Dono\Funds\Fund;
use WP_REST_Request;

/**
 * The donation-form block's editor preview must never reach a reader, and the
 * form-less submission path must never be the permissive one.
 *
 * The preview stub has no form row, so anything it mints is scoped to form id
 * 0, which is the very scope the donations endpoint verifies when a submission
 * carries no form_id. Serving that preview to anyone who is not editing the
 * post therefore hands out a token for the one submission path where none of
 * the form gates run: fund allow-list, block-level validation, and the
 * note_to_org/note_public strip that keeps unmoderated text off the campaign's
 * supporter wall.
 *
 * Three independent things hold that shut, and each is pinned below: only the
 * block-renderer route reaches the preview branch, only a user who can edit
 * does, the preview carries no token at all, and a form-less submission is
 * granted nothing a form would have had to offer.
 *
 * REST_REQUEST is deliberately never defined here. It is process-wide and true
 * for every /wp-json call, so a test that defined it would silently flip other
 * blocks into their editor branch for the rest of the run.
 */
final class PublicRestFormTokenLeakTest extends IntegrationTestCase
{
    private const BLOCK_RENDERER_ROUTE = '/wp/v2/block-renderer/dono/donation-form';

    private int $campaignId;

    protected function setUp(): void
    {
        parent::setUp();

        $req = new WP_REST_Request('POST', '/dono/v1/admin/campaigns');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode(['title' => 'Leak probe', 'status' => 'published']));
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
            'title'       => 'Leak probe form',
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
    private function renderBlockOn(string $route): string
    {
        $GLOBALS['wp']->query_vars['rest_route'] = $route;

        $html = do_blocks('<!-- wp:dono/donation-form {"campaignId":' . $this->campaignId . '} /-->');

        unset($GLOBALS['wp']->query_vars['rest_route']);

        return html_entity_decode($html, ENT_QUOTES);
    }

    public function test_the_editor_preview_is_served_on_the_block_renderer_route(): void
    {
        $html = $this->renderBlockOn(self::BLOCK_RENDERER_ROUTE);

        $this->assertStringContainsString(
            'dono-donation-form__editor-preview',
            $html,
            'ServerSideRender asks on this route, and the editor still gets its iframe preview'
        );
    }

    public function test_a_reader_who_cannot_edit_gets_the_real_form_not_the_preview(): void
    {
        wp_set_current_user(0);

        $html = $this->renderBlockOn(self::BLOCK_RENDERER_ROUTE);

        $this->assertStringNotContainsString('srcdoc', $html, 'no preview document for a logged-out reader');
        $this->assertStringContainsString('data-form-slug=', $html, 'the real front-end form renders instead');
    }

    public function test_a_page_read_gets_the_real_form_for_reader_and_editor_alike(): void
    {
        $route  = '/wp/v2/pages/' . self::factory()->post->create(['post_type' => 'page']);
        $editor = get_current_user_id();

        // The editor half is what keeps the route specific: an author reading a
        // page over REST can edit posts, so capability alone would let the
        // preview through, and core serves content.rendered from the same
        // the_content -> do_blocks run it serves an anonymous reader.
        foreach ([$editor, 0] as $userId) {
            wp_set_current_user($userId);
            $html = $this->renderBlockOn($route);

            $this->assertStringNotContainsString('srcdoc', $html, 'a page read is not the block editor');
            $this->assertStringContainsString('data-form-slug=', $html);
        }
    }

    public function test_the_preview_carries_no_anti_spam_token(): void
    {
        $html = $this->renderBlockOn(self::BLOCK_RENDERER_ROUTE);

        $this->assertStringContainsString('"form_id":0', $html, 'the preview really is the id-0 stub');
        $this->assertStringContainsString(
            '"formToken":""',
            $html,
            'a stub with no row must not mint a token scoped to form id 0'
        );
    }

    public function test_a_submission_naming_no_form_gets_no_fund_choice_and_no_public_message(): void
    {
        $offList = $this->fund('offlist', false);
        $default = $this->fund('general', true);

        $donation = $this->donateWithoutForm([
            'fund_id'     => (int) $offList->id,
            'note_to_org' => 'BUY CHEAP PILLS AT example.test',
            'note_public' => true,
        ]);

        $this->assertNotNull($donation);
        $this->assertSame(
            (int) $default->id,
            (int) $donation->fund_id,
            'nothing offered that fund, so the default chain decides'
        );
        $this->assertSame('', (string) $donation->note_to_org, 'nothing offered a message field');
        $this->assertFalse((bool) $donation->note_public, 'and nothing offered to publish one');
    }

    private function fund(string $code, bool $isDefault): Fund
    {
        $f             = Fund::make();
        $f->code       = $code;
        $f->name       = ucfirst($code);
        $f->is_active  = true;
        $f->is_default = $isDefault;
        $f->created_at = gmdate('Y-m-d H:i:s');
        $f->updated_at = $f->created_at;
        $f->save();

        return $f;
    }

    /**
     * No form_id, so the harness signs the body with a form token scoped to 0,
     * which is exactly the token the preview used to publish.
     */
    private function donateWithoutForm(array $extra): ?Donation
    {
        $req = new WP_REST_Request('POST', '/dono/v1/donations');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode($extra + [
            'email'        => 'leak-' . uniqid() . '@example.test',
            'amount_cents' => 2500,
            'currency'     => 'EUR',
            'gateway'      => 'offline',
            'campaign_id'  => $this->campaignId,
        ]));

        $ref = rest_do_request($req)->get_data()['reference'] ?? null;

        return $ref ? Donation::query()->where('reference', (string) $ref)->get() : null;
    }
}
