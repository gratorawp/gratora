<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Campaigns\Campaign;
use Dono\Donors\DonorService;
use Dono\Foundation\Plugin;
use Dono\Recurring\CampaignCancelRecurringJob;
use Dono\Recurring\RecurringPlan;
use Dono\Recurring\RecurringPlanRepository;
use WP_REST_Request;

/**
 * Archiving a campaign is non-destructive to subscriptions by default: active
 * recurring plans keep renewing (and stay credited to the campaign). The admin
 * can opt to stop them too via the archive dialog's cancel_recurring flag.
 */
final class CampaignArchiveRecurringTest extends IntegrationTestCase
{
    private function makeCampaign(): Campaign
    {
        $now = gmdate('Y-m-d H:i:s');
        $c = Campaign::make();
        $c->title      = 'Archive test';
        $c->slug       = 'archive-test-' . uniqid();
        $c->status     = 'published';
        $c->currency   = 'USD';
        $c->created_at = $now;
        $c->updated_at = $now;
        $c->save();
        return $c;
    }

    private function seedActivePlan(int $campaignId): RecurringPlan
    {
        return $this->seedPlan($campaignId, 'active');
    }

    private function seedPlan(int $campaignId, string $status): RecurringPlan
    {
        $donor = Plugin::instance()->container->get(DonorService::class)
            ->findOrCreate('arch-' . uniqid() . '@example.com', ['first_name' => 'Arch']);

        $now = gmdate('Y-m-d H:i:s');
        $plan = RecurringPlan::make();
        $plan->donor_id         = (int) $donor->id;
        $plan->campaign_id      = $campaignId;
        // Offline isn't SubscriptionAware, so the cancel is local-only (no
        // gateway API call) - keeps the test off the network.
        $plan->gateway          = 'offline';
        $plan->gateway_subscription_id = 'sub_arch_' . bin2hex(random_bytes(4));
        $plan->amount_cents     = 2000;
        $plan->currency         = 'USD';
        $plan->interval_unit    = 'month';
        $plan->interval_count   = 1;
        $plan->status           = $status;
        $plan->payments_count   = 1;
        $plan->total_paid_cents = 2000;
        $plan->started_at       = $now;
        $plan->created_at       = $now;
        $plan->updated_at       = $now;
        $plan->save();
        return $plan;
    }

    private function archive(int $campaignId, array $extra = []): int
    {
        $req = new WP_REST_Request('PUT', "/dono/v1/admin/campaigns/{$campaignId}");
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode(['status' => 'archived'] + $extra));
        return rest_do_request($req)->get_status();
    }

    public function test_a_plan_the_gateway_refused_is_reported_not_swallowed(): void
    {
        $campaign = $this->makeCampaign();
        $plan     = $this->seedActivePlan((int) $campaign->id);

        // A plan on a gateway this site cannot reach. The cursor steps past it
        // on purpose, so that it can finish, but the org must not be told every
        // donor was stopped while this one is still being billed.
        RecurringPlan::query()->where('id', (int) $plan->id)->update(['gateway' => 'stripe']);

        $reported = null;
        add_action('dono.campaign.recurring_cancelled', static function ($id, $failed = []) use (&$reported): void {
            $reported = $failed;
        }, 10, 2);

        $this->archive((int) $campaign->id, ['cancel_recurring' => true]);
        $this->runPendingAsyncJobs();

        $this->assertSame(
            [(int) $plan->id],
            CampaignCancelRecurringJob::failedFor((int) $campaign->id),
            'the plan that is still billing is on the record'
        );
        $this->assertSame([(int) $plan->id], $reported, 'and the completion event carries it');

        $this->assertSame(
            'active',
            RecurringPlan::query()->find('id', (int) $plan->id)->status,
            'and it is honestly still active, not marked cancelled'
        );
    }

    public function test_archive_leaves_subscriptions_active_by_default(): void
    {
        $c    = $this->makeCampaign();
        $plan = $this->seedActivePlan((int) $c->id);

        $this->assertSame(200, $this->archive((int) $c->id));

        $fresh = RecurringPlan::query()->where('id', $plan->id)->get();
        $this->assertSame('active', $fresh->status, 'archiving alone must not cancel subscriptions');
    }

    public function test_archive_with_flag_cancels_active_subscriptions(): void
    {
        $c    = $this->makeCampaign();
        $plan = $this->seedActivePlan((int) $c->id);

        $this->assertSame(200, $this->archive((int) $c->id, ['cancel_recurring' => true]));

        // Queued rather than run in the request: each plan is a blocking
        // gateway call, and a campaign can have thousands.
        $this->assertSame(
            'active',
            RecurringPlan::query()->where('id', $plan->id)->get()->status,
            'the archive itself returns without waiting on the gateway'
        );

        $this->runPendingAsyncJobs();

        $fresh = RecurringPlan::query()->where('id', $plan->id)->get();
        $this->assertSame('cancelled', $fresh->status, 'the opt-in flag cancels active subscriptions');
    }

    public function test_a_run_larger_than_one_batch_finishes_across_ticks(): void
    {
        $c = $this->makeCampaign();

        // More than the 25 gateway round trips a single tick allows, which is
        // the whole reason this moved off the request.
        $plans = [];
        for ($i = 0; $i < 30; $i++) {
            $plans[] = $this->seedActivePlan((int) $c->id);
        }

        $this->archive((int) $c->id, ['cancel_recurring' => true]);
        $this->runPendingAsyncJobs(10);

        $stillActive = RecurringPlan::query()
            ->where('campaign_id', (int) $c->id)
            ->where('status', 'active')
            ->count();

        $this->assertSame(0, (int) $stillActive, 'every plan is cancelled, not just the first batch');
        $this->assertFalse(
            CampaignCancelRecurringJob::isRunning((int) $c->id),
            'and the run clears itself rather than rescheduling forever'
        );
    }

    public function test_recurring_summary_reports_active_plans(): void
    {
        $c = $this->makeCampaign();
        $this->seedActivePlan((int) $c->id);
        $this->seedActivePlan((int) $c->id);

        $req  = new WP_REST_Request('GET', "/dono/v1/admin/campaigns/{$c->id}/recurring-summary");
        $data = rest_do_request($req)->get_data();

        $this->assertSame(2, $data['count']);
        $this->assertSame(4000, $data['mrr_cents'], 'two $20/mo plans is $40/mo');
    }

    /**
     * The dialog states a number, the admin ticks the box on the strength of
     * it, and the toast reports back what was started. A narrower figure at any
     * one of the three has the admin authorise one set of donors and be told
     * about another.
     */
    public function test_the_archive_reports_back_the_number_the_dialog_authorised(): void
    {
        $c = $this->makeCampaign();
        $this->seedPlan((int) $c->id, 'active');
        $this->seedPlan((int) $c->id, 'paused');
        $this->seedPlan((int) $c->id, 'past_due');
        $this->seedPlan((int) $c->id, 'cancelled');

        $dialog = Plugin::instance()->container->get(RecurringPlanRepository::class)
            ->liveForCampaign((int) $c->id);

        $req = new WP_REST_Request('PUT', "/dono/v1/admin/campaigns/{$c->id}");
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode(['status' => 'archived', 'cancel_recurring' => true]));
        $queued = (int) (rest_do_request($req)->get_data()['recurring_cancel']['queued'] ?? -1);

        $this->assertSame(3, $dialog['count']);
        $this->assertSame($dialog['count'], $queued, 'the toast repeats the dialog figure');
        $this->assertSame(
            CampaignCancelRecurringJob::remainingFor((int) $c->id),
            $queued,
            'and that figure is the work the sweep actually took on'
        );
    }

    /**
     * A campaign whose live plans are all paused still bills donors when they
     * resume, so the archive must not report nothing to do.
     */
    public function test_a_paused_only_campaign_does_not_report_zero_queued(): void
    {
        $c = $this->makeCampaign();
        $this->seedPlan((int) $c->id, 'paused');
        $this->seedPlan((int) $c->id, 'past_due');

        $req = new WP_REST_Request('PUT', "/dono/v1/admin/campaigns/{$c->id}");
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode(['status' => 'archived', 'cancel_recurring' => true]));

        $this->assertSame(2, (int) (rest_do_request($req)->get_data()['recurring_cancel']['queued'] ?? -1));
    }
}
