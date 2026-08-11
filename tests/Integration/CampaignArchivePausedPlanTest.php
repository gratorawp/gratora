<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Campaigns\Campaign;
use Dono\Donors\DonorService;
use Dono\Foundation\Plugin;
use Dono\Recurring\CampaignCancelRecurringJob;
use Dono\Recurring\RecurringPlan;
use Dono\Recurring\RecurringResumer;
use WP_REST_Request;

/**
 * Archiving with "cancel recurring" has to reach every plan that can still take
 * money, not only the ones currently marked active. A paused plan restarts on
 * its own resume date and a past_due one is recovered by the gateway's dunning,
 * so either left behind charges a card for a campaign the org closed.
 */
final class CampaignArchivePausedPlanTest extends IntegrationTestCase
{
    private function makeCampaign(): Campaign
    {
        $now = gmdate('Y-m-d H:i:s');
        $c = Campaign::make();
        $c->title      = 'Paused sweep';
        $c->slug       = 'paused-sweep-' . uniqid();
        $c->status     = 'published';
        $c->currency   = 'USD';
        $c->created_at = $now;
        $c->updated_at = $now;
        $c->save();
        return $c;
    }

    /** @param array<string,mixed> $overrides */
    private function seedPlan(int $campaignId, array $overrides = []): RecurringPlan
    {
        $donor = Plugin::instance()->container->get(DonorService::class)
            ->findOrCreate('paused-' . uniqid() . '@example.test', ['first_name' => 'Pia']);

        $now = gmdate('Y-m-d H:i:s');
        $plan = RecurringPlan::make();
        $plan->donor_id                = (int) $donor->id;
        $plan->campaign_id             = $campaignId;
        // Offline is not SubscriptionAware, so cancelling is local only and the
        // test never reaches the network.
        $plan->gateway                 = 'offline';
        $plan->gateway_subscription_id = 'sub_paused_' . bin2hex(random_bytes(4));
        $plan->amount_cents            = 2000;
        $plan->currency                = 'USD';
        $plan->interval_unit           = 'month';
        $plan->interval_count          = 1;
        $plan->status                  = 'active';
        $plan->payments_count          = 1;
        $plan->total_paid_cents        = 2000;
        $plan->started_at              = $now;
        $plan->created_at              = $now;
        $plan->updated_at              = $now;
        foreach ($overrides as $key => $value) {
            $plan->{$key} = $value;
        }
        $plan->save();
        return $plan;
    }

    private function archiveAndCancel(int $campaignId): int
    {
        $req = new WP_REST_Request('PUT', "/dono/v1/admin/campaigns/{$campaignId}");
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode(['status' => 'archived', 'cancel_recurring' => true]));
        return rest_do_request($req)->get_status();
    }

    private function statusOf(RecurringPlan $plan): string
    {
        return (string) RecurringPlan::query()->where('id', (int) $plan->id)->get()->status;
    }

    public function test_the_sweep_cancels_paused_and_past_due_plans_too(): void
    {
        $campaign = $this->makeCampaign();
        $active   = $this->seedPlan((int) $campaign->id);
        $paused   = $this->seedPlan((int) $campaign->id, [
            'status'    => 'paused',
            'resume_at' => gmdate('Y-m-d H:i:s', strtotime('+2 months')),
        ]);
        $pastDue  = $this->seedPlan((int) $campaign->id, ['status' => 'past_due']);

        $this->assertSame(200, $this->archiveAndCancel((int) $campaign->id));
        $this->assertSame(
            3,
            CampaignCancelRecurringJob::remainingFor((int) $campaign->id),
            'the run counts every plan that can still be charged'
        );

        $this->runPendingAsyncJobs();

        $this->assertSame('cancelled', $this->statusOf($active));
        $this->assertSame('cancelled', $this->statusOf($paused));
        $this->assertSame('cancelled', $this->statusOf($pastDue));
    }

    public function test_a_paused_plan_does_not_come_back_and_bill_after_the_sweep(): void
    {
        $campaign = $this->makeCampaign();
        // Pause already due: the daily resumer would restart this plan on its
        // next run and charge the donor for an archived campaign.
        $paused = $this->seedPlan((int) $campaign->id, [
            'status'    => 'paused',
            'resume_at' => gmdate('Y-m-d H:i:s', strtotime('-1 hour')),
        ]);

        $this->archiveAndCancel((int) $campaign->id);
        $this->runPendingAsyncJobs();

        Plugin::instance()->container->get(RecurringResumer::class)->run();

        $this->assertSame('cancelled', $this->statusOf($paused));
    }
}
