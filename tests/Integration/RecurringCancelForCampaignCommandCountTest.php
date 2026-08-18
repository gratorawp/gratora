<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Analytics\EventRecorder;
use Dono\Campaigns\Campaign;
use Dono\Core\Commands\CoreCommandProvider;
use Dono\Foundation\Commands\CommandContext;
use Dono\Foundation\Commands\CommandRegistry;
use Dono\Foundation\Plugin;
use Dono\Recurring\CampaignCancelRecurringJob;
use Dono\Recurring\RecurringPlan;
use Dono\Recurring\RecurringPlanRepository;

/**
 * recurring.cancel_for_campaign starts the sweep that cancels every live plan,
 * so the figure it hands back is the only account the caller gets of what it
 * did. A narrower count reports fewer donors stopped than were stopped, and it
 * disagrees with the confirmation preview the same command showed a moment
 * earlier.
 */
final class RecurringCancelForCampaignCommandCountTest extends IntegrationTestCase
{
    private function registry(): CommandRegistry
    {
        $c = Plugin::instance()->container;
        $r = new CommandRegistry($c->get(EventRecorder::class));
        (new CoreCommandProvider())->register($r, $c);
        return $r;
    }

    private function ctx(): CommandContext
    {
        $user = self::factory()->user->create(['role' => 'administrator']);
        get_role('administrator')->add_cap('dono_view_donations');
        wp_set_current_user($user);

        return new CommandContext($user, 'rest', 'test-' . uniqid());
    }

    private function campaign(): Campaign
    {
        $now = gmdate('Y-m-d H:i:s');

        $campaign             = Campaign::make();
        $campaign->title      = 'Probe';
        $campaign->slug       = 'probe-' . bin2hex(random_bytes(4));
        $campaign->status     = 'published';
        $campaign->currency   = 'USD';
        $campaign->created_at = $now;
        $campaign->updated_at = $now;
        $campaign->save();

        return $campaign;
    }

    private function plan(int $campaignId, string $status, bool $isTest = false): RecurringPlan
    {
        $now = gmdate('Y-m-d H:i:s');

        $plan                          = RecurringPlan::make();
        $plan->donor_id                = 1;
        $plan->campaign_id             = $campaignId;
        $plan->gateway                 = 'offline';
        $plan->gateway_subscription_id = 'sub_cfc_' . bin2hex(random_bytes(5));
        $plan->amount_cents            = 2500;
        $plan->currency                = 'USD';
        $plan->base_amount_cents       = 2500;
        $plan->interval_unit           = 'month';
        $plan->interval_count          = 1;
        $plan->status                  = $status;
        $plan->is_test                 = $isTest;
        $plan->started_at              = $now;
        $plan->created_at              = $now;
        $plan->updated_at              = $now;
        $plan->save();

        return $plan;
    }

    /** The receipt counts the set the sweep takes, not a subset of it. */
    public function test_queued_counts_every_live_plan_the_sweep_will_cancel(): void
    {
        $campaign = $this->campaign();
        $this->plan((int) $campaign->id, 'active');
        $this->plan((int) $campaign->id, 'paused');
        $this->plan((int) $campaign->id, 'past_due');
        $this->plan((int) $campaign->id, 'cancelled');
        $this->plan((int) $campaign->id, 'active', true);

        $res = $this->registry()->dispatch(
            'recurring.cancel_for_campaign',
            ['campaign_id' => (int) $campaign->id],
            $this->ctx()
        );

        $this->assertTrue($res->ok, $res->error ?? '');
        $this->assertSame(
            3,
            (int) $res->data['queued'],
            'active, paused and past_due are all cancelled, so all three are queued'
        );
        $this->assertSame(
            CampaignCancelRecurringJob::remainingFor((int) $campaign->id),
            (int) $res->data['queued'],
            'the reported figure and the work outstanding are one number'
        );
    }

    /** Preview and receipt are two halves of one command and must agree. */
    public function test_the_preview_and_the_receipt_report_the_same_number(): void
    {
        $campaign = $this->campaign();
        $this->plan((int) $campaign->id, 'active');
        $this->plan((int) $campaign->id, 'paused');
        $this->plan((int) $campaign->id, 'past_due');

        $registry = $this->registry();
        $ctx      = $this->ctx();
        $input    = ['campaign_id' => (int) $campaign->id];

        $rows = $registry->previewFor('recurring.cancel_for_campaign', $input, $ctx);
        $this->assertNotEmpty($rows);
        $this->assertStringContainsString('3', (string) $rows[0]['to']);

        $res = $registry->dispatch('recurring.cancel_for_campaign', $input, $ctx);

        $this->assertSame(3, (int) $res->data['queued']);
    }

    /**
     * A campaign whose live plans are all paused still has donors who will be
     * billed again, and the sweep cancels them, so zero is the wrong receipt.
     */
    public function test_a_campaign_whose_live_plans_are_all_paused_does_not_report_zero(): void
    {
        $campaign = $this->campaign();
        $this->plan((int) $campaign->id, 'paused');
        $this->plan((int) $campaign->id, 'paused');

        $res = $this->registry()->dispatch(
            'recurring.cancel_for_campaign',
            ['campaign_id' => (int) $campaign->id],
            $this->ctx()
        );

        $this->assertSame(2, (int) $res->data['queued']);
    }

    /** The wording the assistant repeats to the org must match the scope. */
    public function test_the_summary_label_and_hint_do_not_narrow_the_scope_to_active(): void
    {
        $manifest = $this->registry()->manifest();
        $byId     = array_column($manifest, null, 'id');
        $command  = $byId['recurring.cancel_for_campaign'];

        $this->assertStringNotContainsStringIgnoringCase('active', (string) $command['summary']);
        $this->assertStringNotContainsStringIgnoringCase('active', (string) $command['meta']['agent_hint']);

        $campaign = $this->campaign();
        $this->plan((int) $campaign->id, 'paused');

        $rows = $this->registry()->previewFor(
            'recurring.cancel_for_campaign',
            ['campaign_id' => (int) $campaign->id],
            $this->ctx()
        );

        $this->assertStringNotContainsStringIgnoringCase('active', (string) $rows[0]['label']);
        $this->assertStringNotContainsStringIgnoringCase('active', (string) $rows[0]['to']);
    }

    /** Drift guard: the command must not carry a fourth definition of live. */
    public function test_the_command_reuses_the_repository_definition_of_live(): void
    {
        $campaign = $this->campaign();
        foreach (RecurringPlanRepository::LIVE_STATUSES as $status) {
            $this->plan((int) $campaign->id, $status);
        }

        $res = $this->registry()->dispatch(
            'recurring.cancel_for_campaign',
            ['campaign_id' => (int) $campaign->id],
            $this->ctx()
        );

        $this->assertSame(count(RecurringPlanRepository::LIVE_STATUSES), (int) $res->data['queued']);
    }
}
