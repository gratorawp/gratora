<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Campaigns\Campaign;
use Gratora\Forms\Form;
use Gratora\Forms\Shortcode\DonationFormShortcode;
use Gratora\Foundation\Plugin;
use WP_REST_Request;

/**
 * The donation-form and donate-button blocks decide what to draw from the
 * shortcode's gate. Each state reaches its reader: a closed campaign's sentence
 * to everyone, a hidden form's reason to a manager, an empty card to the rest.
 * The tests that render a block are regression pins; the gate tests prove it.
 */
final class CampaignFormBlocksAskTheGateTest extends IntegrationTestCase
{
    private int $campaignId;
    private int $formId;

    protected function setUp(): void
    {
        parent::setUp();

        $req = new WP_REST_Request('POST', '/gratora/v1/admin/campaigns');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode(['title' => 'Gate block probe', 'status' => 'published']));
        $this->campaignId = (int) rest_do_request($req)->get_data()['id'];

        $req = new WP_REST_Request('POST', '/gratora/v1/admin/forms');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode([
            'title'       => 'Gate block probe form',
            'campaign_id' => $this->campaignId,
            'blocks'      => '<!-- wp:gratora/donation-amount {"presets":[1000]} /--><!-- wp:gratora/submit-button /-->',
        ]));
        $this->formId = (int) rest_do_request($req)->get_data()['id'];

        $form         = Form::query()->find('id', $this->formId);
        $form->status = 'published';
        $form->save();
    }

    private function gate(): string
    {
        return Plugin::instance()->container->get(DonationFormShortcode::class)
            ->gate(Form::query()->find('id', $this->formId));
    }

    /** @param array<string, mixed> $fields */
    private function campaign(array $fields): void
    {
        $campaign = Campaign::query()->find('id', $this->campaignId);
        foreach ($fields as $name => $value) {
            $campaign->{$name} = $value;
        }
        $campaign->save();
    }

    private function block(string $name): string
    {
        return do_blocks('<!-- wp:gratora/' . $name . ' {"campaignId":' . $this->campaignId . '} /-->');
    }

    public function test_an_open_campaign_renders(): void
    {
        $this->assertSame('render', $this->gate());
    }

    public function test_a_campaign_past_its_end_or_before_its_start_is_closed(): void
    {
        $this->campaign(['ends_at' => gmdate('Y-m-d', strtotime('-1 day'))]);
        $this->assertSame('closed', $this->gate());

        $this->campaign(['ends_at' => null, 'starts_at' => gmdate('Y-m-d', strtotime('+2 days'))]);
        $this->assertSame('closed', $this->gate());
    }

    public function test_an_unfinished_campaign_or_form_is_hidden(): void
    {
        $this->campaign(['status' => 'draft']);
        $this->assertSame('hidden', $this->gate());

        $this->campaign(['status' => 'published']);
        $form         = Form::query()->find('id', $this->formId);
        $form->status = 'draft';
        $form->save();
        $this->assertSame('hidden', $this->gate());
    }

    public function test_the_editor_preview_opens_the_gate_for_an_editor_and_nobody_else(): void
    {
        $this->campaign(['status' => 'draft']);
        add_filter('gratora.form.editor_preview', '__return_true');

        $this->assertSame('render', $this->gate());

        wp_set_current_user(0);
        $this->assertSame('hidden', $this->gate());
    }

    /** A verdict that rendered would queue the runtime on a page with no form. */
    public function test_asking_the_gate_renders_nothing(): void
    {
        wp_dequeue_script('gratora-donation-form-runtime');
        wp_dequeue_script('gratora-form-cloak-failsafe');

        $this->gate();

        $this->assertFalse(wp_script_is('gratora-donation-form-runtime', 'enqueued'));
        $this->assertFalse(wp_script_is('gratora-form-cloak-failsafe', 'enqueued'));
    }

    /**
     * The stylesheet carries the cloak, so it cannot wait for the block to
     * render after the head. The runtime can, and a closed campaign never needs it.
     */
    public function test_a_page_with_either_block_queues_the_stylesheet_for_the_head_and_not_the_runtime(): void
    {
        $shortcode = Plugin::instance()->container->get(DonationFormShortcode::class);

        foreach (['donation-form', 'donate-button'] as $name) {
            wp_dequeue_style('gratora-donation-form-runtime');
            wp_dequeue_script('gratora-donation-form-runtime');
            wp_dequeue_script('gratora-form-cloak-failsafe');

            $pageId = self::factory()->post->create([
                'post_type'    => 'page',
                'post_status'  => 'publish',
                'post_content' => '<!-- wp:gratora/' . $name . ' {"campaignId":' . $this->campaignId . '} /-->',
            ]);
            $this->go_to('/?page_id=' . $pageId);

            $shortcode->maybeEnqueue();

            $this->assertTrue(wp_style_is('gratora-donation-form-runtime', 'enqueued'), $name);
            $this->assertFalse(wp_script_is('gratora-donation-form-runtime', 'enqueued'), $name);
            $this->assertFalse(wp_script_is('gratora-form-cloak-failsafe', 'enqueued'), $name);
        }
    }

    public function test_a_closed_campaign_tells_a_visitor_through_the_form_block(): void
    {
        $this->campaign(['ends_at' => gmdate('Y-m-d', strtotime('-1 day'))]);
        wp_set_current_user(0);

        $html = $this->block('donation-form');

        $this->assertStringContainsString('gratora-donation-form__closed', $html);
        $this->assertStringContainsString('This campaign has finished accepting donations. Thank you to everyone who gave.', $html);
        $this->assertStringNotContainsString('data-form-slug=', $html);
        $this->assertStringNotContainsString('gratora-block__empty', $html, 'a closed campaign is owed its sentence, not the empty card');
    }

    public function test_a_draft_campaign_tells_a_manager_why_its_form_block_is_empty(): void
    {
        $this->campaign(['status' => 'draft']);

        $html = $this->block('donation-form');

        $this->assertStringContainsString('gratora-donation-form__error', $html);
        $this->assertStringContainsString('This campaign is not accepting donations, so the form is hidden. Publish the campaign to show it.', $html);
        $this->assertStringNotContainsString('data-form-slug=', $html);
    }

    /** Can see a draft campaign's page, and cannot manage Gratora. */
    public function test_a_draft_campaign_gives_a_campaign_editor_the_empty_card_and_its_notice(): void
    {
        add_role('gratora_gate_probe', 'Gate probe', ['read' => true, 'edit_posts' => true, 'gratora_manage_campaigns' => true]);
        wp_set_current_user(self::factory()->user->create(['role' => 'gratora_gate_probe']));
        $this->campaign(['status' => 'draft']);

        try {
            $html = $this->block('donation-form');
        } finally {
            remove_role('gratora_gate_probe');
        }

        $this->assertStringContainsString('gratora-block__empty', $html);
        $this->assertStringContainsString('Donations are not open for this campaign yet.', $html);
        $this->assertStringContainsString('Publish the campaign and check its schedule.', $html);
        $this->assertStringNotContainsString('gratora-donation-form__error', $html);
        $this->assertStringNotContainsString('data-form-slug=', $html);
    }

    public function test_an_open_campaign_draws_the_form_in_the_form_block(): void
    {
        $html = $this->block('donation-form');

        $this->assertStringContainsString('gratora-donation-form--blocks', $html);
        $this->assertNotSame([], $this->formConfigIn($html));
    }

    /** The manager's reason is not something a donate button should open onto. */
    public function test_a_draft_campaign_gives_a_manager_no_donate_button(): void
    {
        $this->campaign(['status' => 'draft']);

        $html = $this->block('donate-button');

        $this->assertStringNotContainsString('gratora-donate-button', $html);
        $this->assertStringNotContainsString('gratora-donation-form__error', $html);
        $this->assertStringContainsString('Donations are not open for this campaign yet.', $html);
        $this->assertStringContainsString('so the donate button is hidden', $html);
    }

    public function test_an_open_campaign_puts_the_form_inside_the_donate_modal(): void
    {
        $html = $this->block('donate-button');

        $this->assertMatchesRegularExpression('/<div class="gratora-donate-modal__body"><form\b[^>]*gratora-donation-form--blocks/', $html);
        $this->assertNotSame([], $this->formConfigIn($html));
    }
}
