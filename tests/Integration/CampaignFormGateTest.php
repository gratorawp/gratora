<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Campaigns\Campaign;
use Dono\Forms\Form;
use WP_REST_Request;

/**
 * Render gate: `[dono_donation_form slug="..."]` returns '' unless BOTH the
 * form and its campaign are status=published. Regression coverage for the
 * "form must live under an active campaign" rule.
 */
final class CampaignFormGateTest extends IntegrationTestCase
{
    private int $campaignId;
    private string $formSlug;
    private int $formId;

    protected function setUp(): void
    {
        parent::setUp();

        // Published campaign.
        $req = new WP_REST_Request('POST', '/dono/v1/admin/campaigns');
        $req->set_header('content-type', 'application/json');
        $req->set_body(json_encode(['title' => 'Gate campaign', 'status' => 'published']));
        $this->campaignId = (int) rest_do_request($req)->get_data()['id'];

        // Form created as draft (default), bumped to published via direct save
        // to bypass the publish-readiness check on minimal test blocks.
        $req = new WP_REST_Request('POST', '/dono/v1/admin/forms');
        $req->set_header('content-type', 'application/json');
        $req->set_body(json_encode([
            'title'       => 'Gate form',
            'campaign_id' => $this->campaignId,
            'blocks'      => '<!-- wp:dono/donation-amount /-->',
        ]));
        $created = rest_do_request($req)->get_data();
        $this->formId   = (int) $created['id'];
        $this->formSlug = (string) $created['slug'];

        $form = Form::query()->find('id', $this->formId);
        $form->status = 'published';
        $form->save();
    }

    public function test_renders_when_both_form_and_campaign_are_published(): void
    {
        $html = do_shortcode('[dono_donation_form slug="' . $this->formSlug . '"]');
        $this->assertStringContainsString('dono-donation-form--blocks', $html);
        $this->assertStringContainsString('data-form-slug="' . $this->formSlug . '"', $html);
    }

    public function test_empty_when_form_is_draft(): void
    {
        $form = Form::query()->find('id', $this->formId);
        $form->status = 'draft';
        $form->save();

        $html = do_shortcode('[dono_donation_form slug="' . $this->formSlug . '"]');
        $this->assertSame('', $html);
    }

    public function test_empty_when_form_is_archived(): void
    {
        $form = Form::query()->find('id', $this->formId);
        $form->status = 'archived';
        $form->save();

        $html = do_shortcode('[dono_donation_form slug="' . $this->formSlug . '"]');
        $this->assertSame('', $html);
    }

    public function test_empty_when_campaign_is_draft(): void
    {
        $campaign = Campaign::query()->find('id', $this->campaignId);
        $campaign->status = 'draft';
        $campaign->save();

        $html = do_shortcode('[dono_donation_form slug="' . $this->formSlug . '"]');
        $this->assertSame('', $html);
    }

    public function test_empty_when_campaign_is_archived(): void
    {
        $campaign = Campaign::query()->find('id', $this->campaignId);
        $campaign->status = 'archived';
        $campaign->save();

        $html = do_shortcode('[dono_donation_form slug="' . $this->formSlug . '"]');
        $this->assertSame('', $html);
    }

    public function test_donation_post_is_rejected_when_the_campaign_is_archived(): void
    {
        // The render gate hides the form, but a stale day-bucket token or a
        // direct POST must also be refused at the write path.
        $campaign = Campaign::query()->find('id', $this->campaignId);
        $campaign->status = 'archived';
        $campaign->save();

        $req = new WP_REST_Request('POST', '/dono/v1/donations');
        $req->set_header('content-type', 'application/json');
        $req->set_body(json_encode([
            'form_id'      => $this->formId,
            'email'        => 'donor@example.com',
            'amount_cents' => 2500,
            'currency'     => 'USD',
            'gateway'      => 'offline',
        ]));
        $res = rest_do_request($req);

        $this->assertSame(403, $res->get_status());
        $this->assertSame('dono_campaign_not_available', $res->get_data()['code'] ?? null);
    }

    public function test_publishing_the_page_publishes_the_campaign(): void
    {
        $service = \Dono\Foundation\Plugin::instance()
            ->container
            ->get(\Dono\Campaigns\CampaignService::class);

        // Drop the campaign to draft; syncPage drops its page to draft too.
        $campaign = Campaign::query()->find('id', $this->campaignId);
        $service->update($campaign, ['status' => 'draft']);

        $campaign = Campaign::query()->find('id', $this->campaignId);
        $pageId   = (int) $campaign->page_id;
        $this->assertGreaterThan(0, $pageId);
        $this->assertSame('draft', (string) $campaign->status);
        $this->assertSame('draft', get_post_status($pageId));
        $this->assertSame('', do_shortcode('[dono_donation_form slug="' . $this->formSlug . '"]'));

        // Publish the page, as the editor "Publish" button does.
        wp_update_post(['ID' => $pageId, 'post_status' => 'publish']);

        // The campaign follows to published (and the sync loop doesn't revert it).
        $campaign = Campaign::query()->find('id', $this->campaignId);
        $this->assertSame('published', (string) $campaign->status);
        $this->assertSame('publish', get_post_status($pageId));

        // With both published, the gated shortcode now renders.
        $html = do_shortcode('[dono_donation_form slug="' . $this->formSlug . '"]');
        $this->assertStringContainsString('dono-donation-form--blocks', $html);
    }

    public function test_publishing_campaign_with_draft_default_form_keeps_page_private(): void
    {
        $service = \Dono\Foundation\Plugin::instance()
            ->container
            ->get(\Dono\Campaigns\CampaignService::class);

        // Make the published Gate form the campaign's default form, then draft it.
        $campaign = Campaign::query()->find('id', $this->campaignId);
        $service->update($campaign, ['default_form_id' => $this->formId]);
        $form = Form::query()->find('id', $this->formId);
        $form->status = 'draft';
        $form->save();

        // Cycle the campaign to published while its default form is draft.
        $campaign = Campaign::query()->find('id', $this->campaignId);
        $service->update($campaign, ['status' => 'draft']);
        $campaign = Campaign::query()->find('id', $this->campaignId);
        $service->update($campaign, ['status' => 'published']);

        $campaign = Campaign::query()->find('id', $this->campaignId);
        $pageId   = (int) $campaign->page_id;
        $this->assertSame('published', (string) $campaign->status);
        $this->assertSame('draft', get_post_status($pageId),
            'page stays private while the default form is draft - donors never reach a form that 403s');

        // Publishing the default form flips the page public (onFormUpdated hook).
        $form = Form::query()->find('id', $this->formId);
        $form->status = 'published';
        $form->save();
        $service->onFormUpdated($form);

        $this->assertSame('publish', get_post_status($pageId),
            'publishing the default form makes the campaign page public');
    }

    public function test_campaign_type_conversion_is_one_way(): void
    {
        add_filter('dono.campaign.types', static fn (array $t): array => $t + ['squad' => 'Squad']);

        $service = \Dono\Foundation\Plugin::instance()
            ->container
            ->get(\Dono\Campaigns\CampaignService::class);

        $campaign = Campaign::query()->find('id', $this->campaignId);
        $this->assertSame('standard', (string) $campaign->campaign_type);

        // standard -> a registered type: allowed.
        $service->update($campaign, ['campaign_type' => 'squad']);
        $campaign = Campaign::query()->find('id', $this->campaignId);
        $this->assertSame('squad', (string) $campaign->campaign_type);

        // a non-standard type -> standard: blocked (never strand the type's data).
        $service->update($campaign, ['campaign_type' => 'standard']);
        $campaign = Campaign::query()->find('id', $this->campaignId);
        $this->assertSame('squad', (string) $campaign->campaign_type, 'cannot revert a non-standard campaign to standard');

        // an unregistered type: ignored.
        $service->update($campaign, ['campaign_type' => 'bogus']);
        $campaign = Campaign::query()->find('id', $this->campaignId);
        $this->assertSame('squad', (string) $campaign->campaign_type, 'unknown types are ignored');
    }

    public function test_restoring_a_trashed_page_relinks_the_campaign(): void
    {
        $campaign = Campaign::query()->find('id', $this->campaignId);
        $pageId   = (int) $campaign->page_id;
        $this->assertGreaterThan(0, $pageId);

        // Trash the page: the campaign drops to draft and detaches from the page.
        wp_trash_post($pageId);
        $campaign = Campaign::query()->find('id', $this->campaignId);
        $this->assertSame('draft', (string) $campaign->status);
        $this->assertSame(0, (int) $campaign->page_id, 'trashing detaches the page');

        // Restore the page: the campaign re-links to it instead of being orphaned.
        wp_untrash_post($pageId);
        $campaign = Campaign::query()->find('id', $this->campaignId);
        $this->assertSame($pageId, (int) $campaign->page_id, 'restoring re-links the page to the campaign');
    }

    public function test_deleting_campaign_cascade_deletes_the_form(): void
    {
        $campaign = Campaign::query()->find('id', $this->campaignId);
        $this->assertNotNull($campaign);

        \Dono\Foundation\Plugin::instance()
            ->container
            ->get(\Dono\Campaigns\CampaignService::class)
            ->delete($campaign);

        $this->assertNull(Form::query()->find('id', $this->formId), 'form row goes away with the campaign');

        $html = do_shortcode('[dono_donation_form slug="' . $this->formSlug . '"]');
        $this->assertStringContainsString('dono-donation-form__error', $html, 'no-such-slug surfaces the admin diagnostic');
    }
}
