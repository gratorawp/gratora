<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Campaigns\Campaign;
use Dono\Forms\Form;
use Dono\Forms\FormService;
use Dono\Foundation\Plugin;
use InvalidArgumentException;

/** A campaign's default form cannot walk off to another campaign. */
class FormMoveGuardTest extends IntegrationTestCase
{
    private function service(): FormService
    {
        return Plugin::instance()->container->get(FormService::class);
    }

    private function makeCampaign(): int
    {
        $now = gmdate('Y-m-d H:i:s');
        $c = Campaign::make();
        $c->title      = 'Move guard';
        $c->slug       = 'move-guard-' . uniqid();
        $c->status     = 'published';
        $c->created_at = $now;
        $c->updated_at = $now;
        $c->save();
        return (int) $c->id;
    }

    public function test_the_default_form_cannot_be_moved_to_another_campaign(): void
    {
        $from = $this->makeCampaign();
        $to   = $this->makeCampaign();
        $form = $this->service()->create(['title' => 'Default', 'campaign_id' => $from]);

        $campaign = Campaign::query()->find('id', $from);
        $campaign->default_form_id = (int) $form->id;
        $campaign->save();

        try {
            $this->service()->update($form, ['campaign_id' => $to]);
            $this->fail('moving the default form should be refused');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('default donation form', $e->getMessage());
        }

        // The campaign still owns it, so its page keeps taking donations.
        $this->assertSame($from, (int) Form::query()->find('id', $form->id)->campaign_id);
    }

    public function test_a_non_default_form_still_moves(): void
    {
        $from = $this->makeCampaign();
        $to   = $this->makeCampaign();
        $keep = $this->service()->create(['title' => 'Keep', 'campaign_id' => $from]);
        $move = $this->service()->create(['title' => 'Move', 'campaign_id' => $from]);

        $campaign = Campaign::query()->find('id', $from);
        $campaign->default_form_id = (int) $keep->id;
        $campaign->save();

        $this->service()->update($move, ['campaign_id' => $to]);

        $this->assertSame($to, (int) Form::query()->find('id', $move->id)->campaign_id);
    }

    /** Saving other fields must not trip the guard just because the id is present. */
    public function test_resubmitting_the_same_campaign_is_not_a_move(): void
    {
        $id   = $this->makeCampaign();
        $form = $this->service()->create(['title' => 'Same', 'campaign_id' => $id]);

        $campaign = Campaign::query()->find('id', $id);
        $campaign->default_form_id = (int) $form->id;
        $campaign->save();

        $this->service()->update($form, ['campaign_id' => $id, 'title' => 'Renamed']);

        $this->assertSame('Renamed', Form::query()->find('id', $form->id)->title);
    }
}
