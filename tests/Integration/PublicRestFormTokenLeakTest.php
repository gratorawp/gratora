<?php

declare(strict_types=1);

namespace FundKit\Tests\Integration;

use FundKit\Donations\Donation;
use FundKit\Forms\Form;
use FundKit\Funds\Fund;
use WP_REST_Request;

/**
 * Editor previews require the block-renderer route and edit permission, and must issue no form
 * token. Form-less submissions must not bypass form restrictions. Leave REST_REQUEST undefined
 * because it affects the whole test process.
 */
final class PublicRestFormTokenLeakTest extends IntegrationTestCase
{
    private const BLOCK_RENDERER_ROUTE = '/wp/v2/block-renderer/fundkit/donation-form';

    private int $campaignId;

    protected function setUp(): void
    {
        parent::setUp();

        $req = new WP_REST_Request('POST', '/fundkit/v1/admin/campaigns');
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
        $req = new WP_REST_Request('POST', '/fundkit/v1/admin/forms');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode([
            'title'       => 'Leak probe form',
            'campaign_id' => $this->campaignId,
            'blocks'      => '<!-- wp:fundkit/donation-amount {"presets":[1000],"currency":"EUR"} /-->'
                . '<!-- wp:fundkit/email /-->'
                . '<!-- wp:fundkit/submit-button /-->',
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

        $html = do_blocks('<!-- wp:fundkit/donation-form {"campaignId":' . $this->campaignId . '} /-->');

        unset($GLOBALS['wp']->query_vars['rest_route']);

        return html_entity_decode($html, ENT_QUOTES);
    }

    public function test_the_editor_preview_is_served_on_the_block_renderer_route(): void
    {
        $html = $this->renderBlockOn(self::BLOCK_RENDERER_ROUTE);

        $this->assertStringContainsString(
            'fundkit-donation-form__editor-preview',
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

    /** The harness signs form-less requests with form ID 0. */
    private function donateWithoutForm(array $extra): ?Donation
    {
        $req = new WP_REST_Request('POST', '/fundkit/v1/donations');
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
