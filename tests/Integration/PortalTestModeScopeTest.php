<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donors\DonorService;
use Dono\Foundation\Plugin;
use Dono\Recurring\RecurringPlan;
use WP_REST_Request;

/**
 * The portal shows a donor what they actually gave.
 *
 * Donations and receipts already exclude test-mode rows, on the stated grounds
 * that the Overview totals and the Annual Statement are live-only. Recurring
 * plans did not, so a rehearsal subscription appeared in a real donor's portal
 * -- and because the action route only checked ownership, they could pause,
 * change or cancel it.
 */
final class PortalTestModeScopeTest extends IntegrationTestCase
{
    private function seedPlan(int $donorId, bool $isTest): RecurringPlan
    {
        $plan = RecurringPlan::make();
        $plan->donor_id                = $donorId;
        $plan->gateway                 = 'stripe';
        $plan->gateway_subscription_id = 'sub_scope_' . bin2hex(random_bytes(3));
        $plan->amount_cents            = 2500;
        $plan->currency                = 'USD';
        $plan->interval_unit           = 'month';
        $plan->interval_count          = 1;
        $plan->status                  = 'active';
        $plan->is_test                 = $isTest;
        $plan->started_at              = '2026-01-01 00:00:00';
        $plan->next_payment_at         = '2026-06-01 00:00:00';
        $plan->created_at              = '2026-01-01 00:00:00';
        $plan->updated_at              = '2026-01-01 00:00:00';
        $plan->save();

        return $plan;
    }

    private function openPortalFor(int $donorId): string
    {
        $sid  = bin2hex(random_bytes(32));
        $csrf = bin2hex(random_bytes(16));
        set_transient(
            'dono_portal_' . hash('sha256', $sid),
            ['donor_id' => $donorId, 'csrf' => $csrf],
            HOUR_IN_SECONDS
        );
        $_COOKIE['dono_donor_session'] = $sid;
        return $csrf;
    }

    private function donorId(): int
    {
        return (int) Plugin::instance()->container
            ->get(DonorService::class)
            ->findOrCreate('scope-tester@example.com', ['first_name' => 'Scope', 'last_name' => 'Tester'])
            ->id;
    }

    public function test_a_test_plan_is_not_listed_in_the_portal(): void
    {
        $donorId = $this->donorId();
        $live    = $this->seedPlan($donorId, false);
        $test    = $this->seedPlan($donorId, true);
        $this->openPortalFor($donorId);

        $data = rest_do_request(new WP_REST_Request('GET', '/dono/v1/portal/recurring'))->get_data();
        $ids  = array_map(static fn ($p) => (int) ($p['id'] ?? 0), is_array($data) ? $data : []);

        $this->assertContains((int) $live->id, $ids, 'the live plan should be listed');
        $this->assertNotContains((int) $test->id, $ids, 'the test plan should not be listed');
    }

    /**
     * The admin profile keeps showing the plan -- an admin testing wants to see
     * it -- but its money must match the Subscriptions totals, which exclude
     * test plans.
     */
    public function test_a_test_plan_is_kept_out_of_the_donor_mrr(): void
    {
        $donorId = $this->donorId();
        $this->seedPlan($donorId, true);

        $metrics  = Plugin::instance()->container->get(\Dono\Donors\DonorMetricsService::class);
        $profile  = $metrics->profile($donorId);
        $lifetime = (array) $profile['lifetime'];

        $this->assertSame(0, (int) $lifetime['mrr_cents'], 'a test plan must not contribute MRR');
        $this->assertSame(0, (int) $lifetime['active_plan_count'], 'nor be counted as an active plan');
        $this->assertCount(1, (array) $profile['recurring']['plans'], 'but it stays visible in the table');
        $this->assertTrue((bool) $profile['recurring']['plans'][0]['is_test'], 'flagged so the table can label it');
    }

    /** Not listed has to mean not actionable, or the gap just moves. */
    public function test_a_test_plan_cannot_be_acted_on_from_the_portal(): void
    {
        $donorId = $this->donorId();
        $test    = $this->seedPlan($donorId, true);
        $csrf    = $this->openPortalFor($donorId);

        $request = new WP_REST_Request('POST', '/dono/v1/portal/recurring/' . $test->id . '/action');
        $request->set_header('content-type', 'application/json');
        $request->set_header('x-dono-csrf', $csrf);
        $request->set_body((string) wp_json_encode(['action' => 'cancel']));
        $response = rest_do_request($request);

        $this->assertSame(404, $response->get_status());
        // Specifically the ownership/scope refusal, not a missing route.
        $this->assertSame('dono_not_found', (string) ($response->as_error()?->get_error_code() ?? ''));
        $this->assertSame(
            'active',
            RecurringPlan::query()->find('id', (int) $test->id)->status,
            'the test plan must be untouched'
        );
    }
}
