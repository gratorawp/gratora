<?php

declare(strict_types=1);

namespace FundKit\Tests\Integration;

use FundKit\Campaigns\CampaignTemplates;
use FundKit\Forms\Form;
use FundKit\Forms\FormTemplates;
use WP_REST_Request;

final class CampaignStarterFormTest extends IntegrationTestCase
{
    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function createCampaign(array $input): array
    {
        $req = new WP_REST_Request('POST', '/fundkit/v1/admin/campaigns');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) json_encode($input + ['status' => 'published']));

        return (array) rest_do_request($req)->get_data();
    }

    private function formBlocksFor(array $campaign): string
    {
        $form = Form::query()->find('id', (int) $campaign['default_form_id']);
        $this->assertNotNull($form, 'the campaign has no form');

        return (string) $form->blocks;
    }

    public function test_a_template_gets_the_form_it_names(): void
    {
        foreach (CampaignTemplates::all() as $template) {
            $wanted = FormTemplates::find(CampaignTemplates::formTemplate($template['id']));
            $this->assertNotNull($wanted, $template['id'] . ' names a form template that does not exist');

            $campaign = $this->createCampaign([
                'title'         => 'Form ' . $template['id'],
                'page_template' => $template['id'],
            ]);

            $this->assertSame(
                trim((string) $wanted['blocks']),
                trim($this->formBlocksFor($campaign)),
                $template['id'] . ' did not get the form it names'
            );
        }
    }

    /**
     * The point of the change: not every campaign gets the same form. Without
     * this the mapping could be one id repeated fifteen times and every other
     * assertion here would still pass.
     */
    public function test_the_templates_do_not_all_ask_for_the_same_form(): void
    {
        $forms = [];
        foreach (CampaignTemplates::all() as $template) {
            $forms[] = CampaignTemplates::formTemplate($template['id']);
        }

        $this->assertGreaterThan(2, count(array_unique($forms)), 'the templates barely differ in what they ask for');
    }

    public function test_some_templates_ask_across_steps_rather_than_all_at_once(): void
    {
        $stepped = [];
        foreach (CampaignTemplates::all() as $template) {
            $form = FormTemplates::find(CampaignTemplates::formTemplate($template['id']));
            if (is_array($form) && str_contains((string) $form['blocks'], 'wp:fundkit/steps')) {
                $stepped[] = $template['id'];
            }
        }

        $this->assertNotEmpty($stepped, 'no template offers a form split across steps');
    }

    /**
     * A template naming a form nobody registered must still produce a working
     * form rather than an empty one that cannot be published.
     */
    public function test_a_form_nobody_registered_falls_back_to_a_working_one(): void
    {
        add_filter('fundkit.campaign.starter_form_template', static fn (): string => 'no-such-form');

        $campaign = $this->createCampaign(['title' => 'Unknown form']);
        $blocks   = $this->formBlocksFor($campaign);

        remove_all_filters('fundkit.campaign.starter_form_template');

        $this->assertStringContainsString('wp:fundkit/donation-amount', $blocks);
        $this->assertStringContainsString('wp:fundkit/email', $blocks);
        $this->assertStringContainsString('wp:fundkit/submit-button', $blocks);
    }

    public function test_an_add_on_can_name_the_form_for_its_own_template(): void
    {
        add_filter('fundkit.campaign.starter_form_template', static fn (): string => 'guided');

        $campaign = $this->createCampaign(['title' => 'Add-on form']);
        $blocks   = $this->formBlocksFor($campaign);

        remove_all_filters('fundkit.campaign.starter_form_template');

        $this->assertStringContainsString('wp:fundkit/steps', $blocks);
    }
}
