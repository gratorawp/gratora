<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Forms\FormService;
use WP_REST_Request;

/**
 * Publish-readiness: a published donation form has to contain the blocks the
 * runtime expects. The list is enforced server-side by
 * `FormService::missingRequiredBlocks()` and surfaced read-only to the JS
 * editor via the AdminGlobals `required_blocks` payload.
 */
final class FormPublishReadinessTest extends IntegrationTestCase
{
    private int $campaignId;

    protected function setUp(): void
    {
        parent::setUp();

        $req = new WP_REST_Request('POST', '/dono/v1/admin/campaigns');
        $req->set_header('content-type', 'application/json');
        $req->set_body(json_encode(['title' => 'Readiness campaign', 'status' => 'published']));
        $this->campaignId = (int) rest_do_request($req)->get_data()['id'];
    }

    public function test_required_blocks_list_is_amount_name_email(): void
    {
        $names = array_map(fn (array $r): string => $r['block'], FormService::requiredBlocks());
        $this->assertContains('dono/donation-amount', $names);
        $this->assertContains('dono/name', $names);
        $this->assertContains('dono/email', $names);
    }

    public function test_missing_amount_block_is_reported(): void
    {
        $missing = FormService::missingRequiredBlocks(
            '<!-- wp:dono/name /--><!-- wp:dono/email /-->'
        );
        $names = array_map(fn (array $r): string => $r['block'], $missing);
        $this->assertSame(['dono/donation-amount'], $names);
    }

    public function test_missing_name_and_email_blocks_are_reported(): void
    {
        $missing = FormService::missingRequiredBlocks(
            '<!-- wp:dono/donation-amount /-->'
        );
        $names = array_map(fn (array $r): string => $r['block'], $missing);
        $this->assertSame(['dono/name', 'dono/email'], $names);
    }

    public function test_publishing_a_form_without_amount_is_rejected(): void
    {
        $req = new WP_REST_Request('POST', '/dono/v1/admin/forms');
        $req->set_header('content-type', 'application/json');
        $req->set_body(json_encode([
            'title'       => 'No amount',
            'status'      => 'published',
            'campaign_id' => $this->campaignId,
            'blocks'      => '<!-- wp:dono/name /--><!-- wp:dono/email /-->',
        ]));

        $res = rest_do_request($req);
        $this->assertSame(422, $res->get_status());
        $this->assertStringContainsString('Amount', (string) $res->get_data()['message']);
    }

    public function test_publishing_a_form_with_amount_name_email_succeeds(): void
    {
        $req = new WP_REST_Request('POST', '/dono/v1/admin/forms');
        $req->set_header('content-type', 'application/json');
        $req->set_body(json_encode([
            'title'       => 'Complete form',
            'status'      => 'published',
            'campaign_id' => $this->campaignId,
            'blocks'      => '<!-- wp:dono/donation-amount /--><!-- wp:dono/name /--><!-- wp:dono/email /-->',
        ]));

        $res = rest_do_request($req);
        $this->assertSame(201, $res->get_status());
        $this->assertSame('published', $res->get_data()['status']);
    }
}
