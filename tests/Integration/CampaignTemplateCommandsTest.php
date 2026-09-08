<?php

declare(strict_types=1);

namespace FundKit\Tests\Integration;

use FundKit\Analytics\EventRecorder;
use FundKit\Campaigns\CampaignTemplates;
use FundKit\Core\Commands\CoreCommandProvider;
use FundKit\Foundation\Commands\CommandContext;
use FundKit\Foundation\Commands\CommandRegistry;
use FundKit\Foundation\Plugin;

final class CampaignTemplateCommandsTest extends IntegrationTestCase
{
    private function registry(): CommandRegistry
    {
        $c = Plugin::instance()->container;
        $r = new CommandRegistry($c->get(EventRecorder::class));
        (new CoreCommandProvider())->register($r, $c);

        return $r;
    }

    private function context(): CommandContext
    {
        return new CommandContext(
            self::factory()->user->create(['role' => 'administrator']),
            'rest',
            'req-' . uniqid()
        );
    }

    /** @return array<string,mixed> */
    private function definition(string $id): array
    {
        foreach ($this->registry()->manifest() as $command) {
            if (($command['id'] ?? '') === $id) {
                return $command;
            }
        }

        $this->fail($id . ' is not registered');
    }

    public function test_the_layouts_a_type_offers_can_be_listed(): void
    {
        $res = $this->registry()->dispatch('campaign.templates', [], $this->context());

        $this->assertTrue($res->ok);
        $ids = array_column($res->data['templates'], 'id');
        $this->assertSame(
            array_column(CampaignTemplates::all('standard'), 'id'),
            $ids
        );
    }

    /**
     * The listing names the form each layout builds, because that is the half
     * of the choice a caller cannot see from the layout's own name.
     */
    public function test_the_listing_says_which_form_each_layout_builds(): void
    {
        $res = $this->registry()->dispatch('campaign.templates', [], $this->context());

        foreach ($res->data['templates'] as $template) {
            $this->assertSame(
                CampaignTemplates::formTemplate((string) $template['id'], 'standard'),
                $template['form'],
                $template['id'] . ' does not say which form it builds'
            );
        }

        $this->assertGreaterThan(
            1,
            count(array_unique(array_column($res->data['templates'], 'form'))),
            'every layout reports the same form, so the field says nothing'
        );
    }

    public function test_a_campaign_is_built_from_the_layout_it_was_asked_for(): void
    {
        $res = $this->registry()->dispatch('campaign.create', [
            'title'         => 'Asked for a layout ' . uniqid(),
            'status'        => 'draft',
            'page_template' => 'minimal',
        ], $this->context());

        $this->assertTrue($res->ok, (string) ($res->error ?? ''));

        $campaign = \FundKit\Campaigns\Campaign::query()->find('id', (int) $res->data['campaign_id']);
        $page     = get_post((int) $campaign->page_id);
        $form     = \FundKit\Forms\Form::query()->find('id', (int) $campaign->default_form_id);

        $default = $this->registry()->dispatch('campaign.create', [
            'title'  => 'Default layout ' . uniqid(),
            'status' => 'draft',
        ], $this->context());
        $other = \FundKit\Campaigns\Campaign::query()->find('id', (int) $default->data['campaign_id']);

        $this->assertNotNull($page);
        $this->assertNotSame(
            (string) get_post((int) $other->page_id)->post_content,
            (string) $page->post_content,
            'the page was built from the default layout, not the one that was asked for'
        );

        // The layout carries the form with it, which is the half of the choice
        // a caller cannot see from the layout's name.
        $this->assertSame(
            trim((string) \FundKit\Forms\FormTemplates::find(CampaignTemplates::formTemplate('minimal'))['blocks']),
            trim((string) $form->blocks)
        );
    }

    /**
     * An id this install does not offer never reaches the handler: the schema
     * enum stops it. That is the outer of two guards, and the only one this
     * suite can exercise, because a layout belonging to another type needs two
     * types registered and core's suite runs with no add-ons. The inner guard,
     * for an id that is in the enum but belongs to a different type, is held by
     * AssistantKnowsP2pTemplatesTest in the peer-to-peer add-on.
     */
    public function test_a_layout_this_install_does_not_offer_is_refused_rather_than_swapped(): void
    {
        $before = \FundKit\Campaigns\Campaign::query()->count();

        $res = $this->registry()->dispatch('campaign.create', [
            'title'         => 'Wrong layout ' . uniqid(),
            'status'        => 'draft',
            'campaign_type' => 'standard',
            'page_template' => 'p2p-editorial',
        ], $this->context());

        $this->assertFalse($res->ok, 'a layout from another campaign type was accepted');
        $this->assertSame($before, \FundKit\Campaigns\Campaign::query()->count(), 'a campaign was created anyway');
    }

    /** Build layout enums from all registered types; validate type/layout pairs in the handler. */
    public function test_the_schema_offers_every_registered_type_s_layouts(): void
    {
        $schema = $this->definition('campaign.create')['inputSchema']['properties']['page_template'];
        $types  = array_keys((array) apply_filters('fundkit.campaign.types', ['standard' => '']));

        $this->assertNotEmpty($types);

        foreach ($types as $type) {
            foreach (CampaignTemplates::all((string) $type) as $template) {
                $this->assertContains(
                    $template['id'],
                    $schema['enum'],
                    $template['id'] . ' is offered by ' . $type . ' but not by the command'
                );
            }
            $this->assertStringContainsString($type . ':', (string) $schema['description']);
        }
    }
}
