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
 * The archive dialog's number is the consent the admin gives to the archive
 * sweep, so it has to count the same rows the sweep cancels: every live plan,
 * not only the active ones. Counting less has the admin authorise N
 * cancellations and get more, and a campaign whose live plans are all paused
 * reports zero, never renders the prompt, and keeps billing donors for a closed
 * campaign.
 */
final class RecurringArchiveLiveCountTest extends IntegrationTestCase
{
    private int $campaignId;

    protected function setUp(): void
    {
        parent::setUp();

        $now = gmdate('Y-m-d H:i:s');
        $campaign = Campaign::make();
        $campaign->title      = 'Archive live count';
        $campaign->slug       = 'archive-live-count-' . uniqid();
        $campaign->status     = 'published';
        $campaign->currency   = 'USD';
        $campaign->created_at = $now;
        $campaign->updated_at = $now;
        $campaign->save();

        $this->campaignId = (int) $campaign->id;
    }

    private function plan(string $status, array $overrides = []): RecurringPlan
    {
        $donor = Plugin::instance()->container->get(DonorService::class)
            ->findOrCreate('live-' . uniqid() . '@example.com', ['first_name' => 'Live']);

        $now = gmdate('Y-m-d H:i:s');
        $p = RecurringPlan::make();
        $p->donor_id          = (int) $donor->id;
        $p->campaign_id       = $overrides['campaign_id'] ?? $this->campaignId;
        // Offline is not SubscriptionAware, so cancelling is a local flip and
        // this file can run the real sweep without touching the network.
        $p->gateway           = 'offline';
        $p->gateway_subscription_id = 'sub_' . bin2hex(random_bytes(6));
        $p->amount_cents      = $overrides['amount_cents'] ?? 2500;
        $p->currency          = 'USD';
        $p->base_amount_cents = $overrides['amount_cents'] ?? 2500;
        $p->interval_unit     = 'month';
        $p->interval_count    = $overrides['interval_count'] ?? 1;
        $p->status            = $status;
        $p->is_test           = $overrides['is_test'] ?? false;
        $p->started_at        = $now;
        $p->created_at        = $now;
        $p->updated_at        = $now;
        $p->save();
        return $p;
    }

    /** Every status the count has to decide about, plus the two exclusions. */
    private function seedMixedStatuses(): void
    {
        $this->plan('active');
        $this->plan('paused');
        $this->plan('past_due');
        $this->plan('cancelled');
        $this->plan('active', ['is_test' => true]);
        $this->plan('active', ['campaign_id' => $this->campaignId + 1]);
    }

    /** The number shown and the number cancelled are one set. */
    public function test_the_count_covers_every_status_the_sweep_cancels(): void
    {
        $this->seedMixedStatuses();

        $summary = $this->repo()->liveForCampaign($this->campaignId);

        $this->assertSame(3, $summary['count'], 'active, paused and past_due all get cancelled, so all three are counted');
        $this->assertSame(7500, $summary['mrr_cents'], 'the monthly value at stake is the whole live set');
        $this->assertSame($summary['count'], $this->sweepWouldCancel());
    }

    /**
     * The dialog reads this route, never the repository, so only a call across
     * the REST boundary pins the number an admin is actually shown.
     */
    public function test_the_recurring_summary_route_reports_the_number_the_sweep_cancels(): void
    {
        $this->seedMixedStatuses();

        $response = rest_do_request(
            new WP_REST_Request('GET', "/dono/v1/admin/campaigns/{$this->campaignId}/recurring-summary")
        );
        $data = $response->get_data();

        $this->assertSame(200, $response->get_status(), (string) wp_json_encode($data));
        $this->assertSame(3, $data['count'], 'the dialog is told about every live plan, not only the active ones');
        $this->assertSame(7500, $data['mrr_cents']);
        $this->assertSame('USD', $data['currency']);
        $this->assertSame($data['count'], $this->sweepWouldCancel());
    }

    /** The gate on the prompt appearing is count > 0. */
    public function test_a_campaign_whose_live_plans_are_all_paused_is_not_reported_as_empty(): void
    {
        $this->plan('paused');
        $this->plan('paused');

        $summary = $this->repo()->liveForCampaign($this->campaignId);

        $this->assertSame(2, $summary['count'], 'paused plans resume and keep billing, so the admin must be offered the choice');
        $this->assertSame($summary['count'], $this->sweepWouldCancel());
    }

    /** A cancelled-only campaign has nothing to offer and must not prompt. */
    public function test_a_campaign_with_no_live_plans_reports_zero(): void
    {
        $this->plan('cancelled');
        $this->plan('expired');

        $this->assertSame(0, $this->repo()->liveForCampaign($this->campaignId)['count']);
        $this->assertSame(0, $this->sweepWouldCancel());
    }

    /**
     * A malformed cadence is still cancelled and the donor is still emailed, so
     * it is still counted; it just cannot contribute a monthly figure.
     */
    public function test_a_plan_with_a_zero_interval_is_counted_because_the_sweep_cancels_it(): void
    {
        $this->plan('active', ['interval_count' => 0]);

        $summary = $this->repo()->liveForCampaign($this->campaignId);

        $this->assertSame(1, $summary['count']);
        $this->assertSame(0, $summary['mrr_cents']);
        $this->assertSame($summary['count'], $this->sweepWouldCancel());
    }

    /**
     * Runs the sweep and reports what it actually cancelled instead of
     * restating its selection here. A filter the sweep loses (the campaign, the
     * statuses, is_test) then changes this number, where a restated copy would
     * agree with the mistake and keep every case green.
     *
     * It cancels the plans, so a case calls it last.
     */
    private function sweepWouldCancel(): int
    {
        $before = $this->cancelledPlanIds();

        Plugin::instance()->container->get(CampaignCancelRecurringJob::class)
            ->start($this->campaignId);
        $this->runPendingAsyncJobs(10);

        return count(array_diff($this->cancelledPlanIds(), $before));
    }

    /**
     * Deliberately unscoped: a sweep that stopped filtering by campaign shows
     * up here as a larger number rather than going unnoticed.
     *
     * @return list<int>
     */
    private function cancelledPlanIds(): array
    {
        return array_map(
            static fn ($plan): int => (int) $plan->id,
            RecurringPlan::query()->where('status', 'cancelled')->getAll()
        );
    }

    private function repo(): RecurringPlanRepository
    {
        return Plugin::instance()->container->get(RecurringPlanRepository::class);
    }
}
