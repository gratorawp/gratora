<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Campaigns\Campaign;
use Dono\Donors\DonorService;
use Dono\Foundation\Plugin;
use Dono\Recurring\RecurringPlan;
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
        $plan->status           = 'active';
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

        $fresh = RecurringPlan::query()->where('id', $plan->id)->get();
        $this->assertSame('cancelled', $fresh->status, 'the opt-in flag cancels active subscriptions');
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
}
