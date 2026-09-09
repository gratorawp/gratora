<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Foundation\Plugin;
use Gratora\Recurring\RecurringPlan;
use Gratora\Recurring\RecurringPlanRepository;

/**
 * The edges of a recurring plan's life, where the gateway and this site are
 * each describing the same subscription and can disagree about it.
 */
final class RecurringLifecycleEdgesTest extends IntegrationTestCase
{
    private function plan(array $overrides = []): RecurringPlan
    {
        $now = gmdate('Y-m-d H:i:s');

        $p = RecurringPlan::make();
        $p->donor_id                = 1;
        $p->gateway                 = 'paypal';
        $p->gateway_subscription_id = 'I-EDGE-' . bin2hex(random_bytes(4));
        $p->amount_cents            = 2500;
        $p->currency                = 'USD';
        $p->status                  = 'active';
        $p->is_test                 = false;
        $p->started_at              = $now;
        $p->created_at              = $now;
        $p->updated_at              = $now;

        foreach ($overrides as $k => $v) {
            $p->{$k} = $v;
        }
        $p->save();

        return $p;
    }

    private function repo(): RecurringPlanRepository
    {
        return Plugin::instance()->container->get(RecurringPlanRepository::class);
    }

    /**
     * One decline reported under two event types is one decline. An org
     * subscribed to both PayPal failure events gets two deliveries naming the
     * same sale, and counted per delivery the plan reads as two attempts the
     * donor's card never made.
     */
    public function test_two_deliveries_naming_one_decline_count_once(): void
    {
        $plan = $this->plan();
        $now  = gmdate('Y-m-d H:i:s');

        $this->assertTrue($this->repo()->recordFailedRenewal($plan, $now, 'SALE-1'));
        $this->assertFalse($this->repo()->recordFailedRenewal($plan, $now, 'SALE-1'));

        $this->assertSame(1, (int) RecurringPlan::query()->find('id', (int) $plan->id)->failed_renewals_count);
    }

    /**
     * A gateway retries for days, so a redelivery of an earlier decline can
     * land after a later one. Remembering only the last delivery, the older one
     * looks new again and counts a third failure for two declines.
     */
    public function test_an_older_decline_redelivered_after_a_newer_one_counts_once(): void
    {
        $plan = $this->plan();
        $now  = gmdate('Y-m-d H:i:s');

        $this->repo()->recordFailedRenewal($plan, $now, 'SALE-A');
        $this->repo()->recordFailedRenewal($plan, $now, 'SALE-B');

        $this->assertFalse(
            $this->repo()->recordFailedRenewal($plan, $now, 'SALE-A'),
            'SALE-A was already counted, however late its redelivery arrives'
        );

        $this->assertSame(2, (int) RecurringPlan::query()->find('id', (int) $plan->id)->failed_renewals_count);
    }

    public function test_separate_declines_still_count_separately(): void
    {
        $plan = $this->plan();
        $now  = gmdate('Y-m-d H:i:s');

        foreach (['SALE-1', 'SALE-2', 'SALE-3'] as $sale) {
            $this->repo()->recordFailedRenewal($plan, $now, $sale);
        }

        $this->assertSame(3, (int) RecurringPlan::query()->find('id', (int) $plan->id)->failed_renewals_count);
    }

    public function test_a_pending_plan_is_reachable_by_a_cancellation(): void
    {
        $this->assertContains('pending', RecurringPlanRepository::CANCELLABLE_STATUSES);
        $this->assertNotContains(
            'pending',
            RecurringPlanRepository::LIVE_STATUSES,
            'it is not collecting on this site s account of things, so it is not reported as revenue'
        );

        $campaignId = 4242;
        $this->plan(['status' => 'pending', 'campaign_id' => $campaignId]);

        $this->assertSame(
            1,
            (int) $this->repo()->liveForCampaign($campaignId)['count'],
            'the number the admin authorises a cancellation from counts it, because the sweep will cancel it'
        );
    }

    private function act(RecurringPlan $plan, array $body): \WP_REST_Response
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        $req = new \WP_REST_Request('POST', '/gratora/v1/admin/recurring/' . (int) $plan->id . '/action');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode($body + ['notify_donor' => false]));

        return rest_do_request($req);
    }

    private function reload(RecurringPlan $plan): RecurringPlan
    {
        return RecurringPlan::query()->find('id', (int) $plan->id);
    }

    /**
     * Resume is the word for lifting a pause. Pointed at a plan the gateway
     * declined it writes 'active', and the donor screen's declined banner, the
     * Past due filter and the profile count all key on the status: the plan
     * reads healthy while the card keeps failing.
     */
    public function test_a_declined_plan_cannot_be_resumed_into_looking_healthy(): void
    {
        $plan = $this->plan(['status' => 'past_due', 'gateway' => 'offline', 'failed_renewals_count' => 1]);

        $res = $this->act($plan, ['action' => 'resume']);

        $this->assertSame(422, $res->get_status(), (string) wp_json_encode($res->get_data()));
        $this->assertSame('past_due', (string) $this->reload($plan)->status);
    }

    public function test_a_pause_does_not_collect_the_declined_renewal(): void
    {
        $plan = $this->plan(['status' => 'past_due', 'gateway' => 'offline', 'failed_renewals_count' => 1]);

        $this->assertSame(200, $this->act($plan, ['action' => 'pause', 'months' => 1])->get_status());
        $this->assertSame('paused', (string) $this->reload($plan)->status);

        $this->assertSame(200, $this->act($plan, ['action' => 'resume'])->get_status());
        $this->assertSame('past_due', (string) $this->reload($plan)->status);
    }

    public function test_the_sweep_lifts_a_pause_without_clearing_the_decline(): void
    {
        $plan = $this->plan([
            'status'                => 'paused',
            'gateway'               => 'offline',
            'failed_renewals_count' => 1,
            'resume_at'             => gmdate('Y-m-d H:i:s', time() - 3600),
        ]);

        Plugin::instance()->container->get(\Gratora\Recurring\RecurringResumer::class)->run();

        $fresh = $this->reload($plan);
        $this->assertSame('past_due', (string) $fresh->status);
        $this->assertNull($fresh->resume_at);
    }

    public function test_a_pause_with_nothing_outstanding_still_resumes_to_active(): void
    {
        $plan = $this->plan(['status' => 'active', 'gateway' => 'offline']);

        $this->assertSame(200, $this->act($plan, ['action' => 'pause', 'months' => 1])->get_status());
        $this->assertSame(200, $this->act($plan, ['action' => 'resume'])->get_status());
        $this->assertSame('active', (string) $this->reload($plan)->status);
    }
}
