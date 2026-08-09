<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donors\DonorService;
use Dono\Foundation\Plugin;
use Dono\Recurring\RecurringPlan;
use Dono\Recurring\RecurringPlanRepository;
use WP_REST_Request;

/**
 * A renewal landing while the donor has their portal open must survive
 * whatever they press next.
 *
 * Renewals use atomic increments precisely so concurrent writes are not lost
 * (RecurringPlanRepository::recordPayment). The portal then wrote the whole row
 * back from the copy it loaded when the page opened, which put the counters
 * back to what they were before the renewal.
 */
final class PortalPlanConcurrentRenewalTest extends IntegrationTestCase
{
    private function openPortalFor(int $donorId): string
    {
        $sid  = bin2hex(random_bytes(32));
        $csrf = bin2hex(random_bytes(16));
        $sid = $this->portalSession($donorId, $csrf);
        $_COOKIE['dono_donor_session'] = $sid;

        return $csrf;
    }

    private function seedPlan(int $donorId): RecurringPlan
    {
        $plan = RecurringPlan::make();
        $plan->donor_id                = $donorId;
        $plan->gateway                 = 'offline';
        $plan->gateway_subscription_id = 'sub_conc_' . bin2hex(random_bytes(3));
        $plan->amount_cents            = 2500;
        $plan->currency                = 'USD';
        $plan->interval_unit           = 'month';
        $plan->interval_count          = 1;
        $plan->status                  = 'active';
        $plan->started_at              = '2026-01-01 00:00:00';
        $plan->next_payment_at         = '2026-06-01 00:00:00';
        $plan->payments_count          = 3;
        $plan->total_paid_cents        = 7500;
        $plan->created_at              = '2026-01-01 00:00:00';
        $plan->updated_at              = '2026-01-01 00:00:00';
        $plan->save();

        return $plan;
    }

    /** @param array<string,mixed> $body */
    private function act(int $planId, string $csrf, array $body): int
    {
        $req = new WP_REST_Request('POST', "/dono/v1/portal/recurring/{$planId}/action");
        $req->set_header('content-type', 'application/json');
        $req->set_header('X-Dono-Csrf', $csrf);
        $req->set_body((string) wp_json_encode($body));

        return rest_do_request($req)->get_status();
    }

    public function test_a_renewal_between_page_load_and_pause_is_not_rolled_back(): void
    {
        $donor = Plugin::instance()->container->get(DonorService::class)
            ->findOrCreate('conc-' . uniqid() . '@example.com', ['first_name' => 'Conc', 'last_name' => 'Test']);

        $plan = $this->seedPlan((int) $donor->id);
        $csrf = $this->openPortalFor((int) $donor->id);

        // The donor's page is open, holding the row as it was: 3 payments.
        // A renewal lands while they are reading it.
        (new RecurringPlanRepository())->recordPayment($plan, 2500, gmdate('Y-m-d H:i:s'));

        $this->assertSame(200, $this->act((int) $plan->id, $csrf, ['action' => 'pause', 'months' => 2]));

        $fresh = RecurringPlan::query()->where('id', (int) $plan->id)->get();

        $this->assertSame('paused', $fresh->status, 'the donor got what they asked for');
        $this->assertSame(4, (int) $fresh->payments_count, 'and the renewal is still counted');
        $this->assertSame(10000, (int) $fresh->total_paid_cents, 'and its money is still on the plan');
    }

    public function test_the_same_holds_for_a_change_of_amount(): void
    {
        $donor = Plugin::instance()->container->get(DonorService::class)
            ->findOrCreate('conc2-' . uniqid() . '@example.com', ['first_name' => 'Conc', 'last_name' => 'Two']);

        $plan = $this->seedPlan((int) $donor->id);
        $csrf = $this->openPortalFor((int) $donor->id);

        (new RecurringPlanRepository())->recordPayment($plan, 2500, gmdate('Y-m-d H:i:s'));

        $this->assertSame(200, $this->act((int) $plan->id, $csrf, [
            'action'       => 'change_amount',
            'amount_cents' => 5000,
        ]));

        $fresh = RecurringPlan::query()->where('id', (int) $plan->id)->get();

        $this->assertSame(5000, (int) $fresh->amount_cents);
        $this->assertSame(4, (int) $fresh->payments_count);
        $this->assertSame(10000, (int) $fresh->total_paid_cents);
    }
}
