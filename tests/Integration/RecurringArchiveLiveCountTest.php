<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Foundation\Plugin;
use Dono\Recurring\CampaignCancelRecurringJob;
use Dono\Recurring\RecurringPlan;
use Dono\Recurring\RecurringPlanRepository;
use ReflectionClass;

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
    private int $campaignId = 90210;

    private function plan(string $status, array $overrides = []): RecurringPlan
    {
        $now = gmdate('Y-m-d H:i:s');
        $p = RecurringPlan::make();
        $p->donor_id          = 1;
        $p->campaign_id       = $overrides['campaign_id'] ?? $this->campaignId;
        $p->gateway           = 'stripe';
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

    /** The number shown and the number cancelled are one set. */
    public function test_the_count_covers_every_status_the_sweep_cancels(): void
    {
        $this->plan('active');
        $this->plan('paused');
        $this->plan('past_due');
        $this->plan('cancelled');
        $this->plan('active', ['is_test' => true]);
        $this->plan('active', ['campaign_id' => $this->campaignId + 1]);

        $summary = $this->repo()->activeForCampaign($this->campaignId);

        $this->assertSame(3, $summary['count'], 'active, paused and past_due all get cancelled, so all three are counted');
        $this->assertSame($this->sweepWouldCancel(), $summary['count']);
        $this->assertSame(7500, $summary['mrr_cents'], 'the monthly value at stake is the whole live set');
    }

    /** The gate on the prompt appearing is count > 0. */
    public function test_a_campaign_whose_live_plans_are_all_paused_is_not_reported_as_empty(): void
    {
        $this->plan('paused');
        $this->plan('paused');

        $summary = $this->repo()->activeForCampaign($this->campaignId);

        $this->assertSame(2, $summary['count'], 'paused plans resume and keep billing, so the admin must be offered the choice');
        $this->assertSame($this->sweepWouldCancel(), $summary['count']);
    }

    /** A cancelled-only campaign has nothing to offer and must not prompt. */
    public function test_a_campaign_with_no_live_plans_reports_zero(): void
    {
        $this->plan('cancelled');
        $this->plan('expired');

        $this->assertSame(0, $this->repo()->activeForCampaign($this->campaignId)['count']);
    }

    /**
     * A malformed cadence is still cancelled and the donor is still emailed, so
     * it is still counted; it just cannot contribute a monthly figure.
     */
    public function test_a_plan_with_a_zero_interval_is_counted_because_the_sweep_cancels_it(): void
    {
        $this->plan('active', ['interval_count' => 0]);

        $summary = $this->repo()->activeForCampaign($this->campaignId);

        $this->assertSame(1, $summary['count']);
        $this->assertSame($this->sweepWouldCancel(), $summary['count']);
        $this->assertSame(0, $summary['mrr_cents']);
    }

    /** Drift guard: two definitions of "live" would reopen the whole finding. */
    public function test_the_repository_and_the_sweep_define_live_identically(): void
    {
        $sweep = (new ReflectionClass(CampaignCancelRecurringJob::class))->getConstant('LIVE_STATUSES');

        $expected = RecurringPlanRepository::LIVE_STATUSES;
        sort($expected);
        sort($sweep);

        $this->assertSame($expected, $sweep);
    }

    /** The sweep's own selection, minus its resumable cursor. */
    private function sweepWouldCancel(): int
    {
        $statuses = (new ReflectionClass(CampaignCancelRecurringJob::class))->getConstant('LIVE_STATUSES');

        return (int) RecurringPlan::query()
            ->where('campaign_id', $this->campaignId)
            ->whereIn('status', $statuses)
            ->where('is_test', false)
            ->count();
    }

    private function repo(): RecurringPlanRepository
    {
        return Plugin::instance()->container->get(RecurringPlanRepository::class);
    }
}
