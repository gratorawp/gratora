<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donations\Donation;
use Dono\Donations\DonationRepository;
use Dono\Foundation\Plugin;
use Dono\Recurring\RecurringPlan;
use WP_REST_Request;

/**
 * Refunding one charge does not end a schedule, and an admin refunding almost
 * always means make this stop. The donation screen is where that decision gets
 * made, so it has to say the schedule is still running and be able to end it,
 * without ever ending one nobody asked about.
 */
final class RefundCancelsPlanTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    private function plan(string $status = 'active'): RecurringPlan
    {
        static $n = 0;
        $n++;

        $p = RecurringPlan::make();
        $p->donor_id                = 1;
        $p->gateway                 = 'offline';
        $p->gateway_subscription_id = 'sub_refund_' . $n;
        $p->amount_cents            = 2500;
        $p->currency                = 'USD';
        $p->interval_unit           = 'month';
        $p->interval_count          = 1;
        $p->status                  = $status;
        $p->is_test                 = false;
        $p->started_at              = '2026-08-01 00:00:00';
        $p->next_payment_at         = '2026-09-01 00:00:00';
        $p->created_at              = '2026-08-01 00:00:00';
        $p->save();

        return $p;
    }

    private function donation(?RecurringPlan $plan): Donation
    {
        $create = new WP_REST_Request('POST', '/dono/v1/donations');
        $create->set_header('content-type', 'application/json');
        $create->set_body((string) wp_json_encode([
            'email'        => 'refunder@example.test',
            'amount_cents' => 2500,
            'currency'     => 'USD',
            'gateway'      => 'offline',
            // Offline holds no payment method, so it only offers one_time and
            // refuses anything else at creation. The plan link below is what
            // this is about, and it is set on the row rather than asked for.
            'frequency'    => 'one_time',
            'profile'      => ['first_name' => 'Ree', 'last_name' => 'Fund'],
        ]));
        $created = (array) rest_do_request($create)->get_data();
        $this->assertArrayHasKey('reference', $created, (string) wp_json_encode($created));
        $reference = (string) $created['reference'];

        $confirm = new WP_REST_Request('POST', "/dono/v1/donations/{$reference}/confirm");
        $confirm->set_header('content-type', 'application/json');
        $confirm->set_body('{}');
        rest_do_request($confirm);

        $repo     = Plugin::instance()->container->get(DonationRepository::class);
        $donation = $repo->findByReference($reference);
        if ($plan) {
            $donation->recurring_plan_id = (int) $plan->id;
            $donation->frequency         = 'monthly';
            $donation->save();
        }

        return $repo->findByReference($reference);
    }

    /** @param array<string,mixed> $body */
    private function refund(string $reference, array $body = []): \WP_REST_Response|\WP_Error
    {
        $req = new WP_REST_Request('POST', "/dono/v1/admin/donations/{$reference}/refund");
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode($body));

        return rest_do_request($req);
    }

    private function statusOf(int $planId): string
    {
        return (string) RecurringPlan::query()->find('id', $planId)->status;
    }

    public function test_the_screen_is_told_the_schedule_is_still_running(): void
    {
        $plan     = $this->plan();
        $donation = $this->donation($plan);

        $res  = rest_do_request(new WP_REST_Request('GET', "/dono/v1/admin/donations/{$donation->reference}"));
        $body = (array) $res->get_data();

        // Without this the dialog cannot warn, and the warning is the whole
        // point: the endpoint working is no use if nothing offers it.
        $this->assertArrayHasKey('recurring_plan', $body);
        $this->assertSame('active', $body['recurring_plan']['status'] ?? null);
        $this->assertSame('2026-09-01 00:00:00', $body['recurring_plan']['next_payment_at'] ?? null);
    }

    public function test_a_one_time_donation_carries_no_plan(): void
    {
        $donation = $this->donation(null);

        $body = (array) rest_do_request(
            new WP_REST_Request('GET', "/dono/v1/admin/donations/{$donation->reference}")
        )->get_data();

        // A warning that fires where it does not apply is ignored where it does.
        $this->assertNull($body['recurring_plan']);
    }

    public function test_refunding_alone_leaves_the_schedule_running(): void
    {
        $plan     = $this->plan();
        $donation = $this->donation($plan);

        $res = $this->refund($donation->reference);

        // The default, and the behaviour a site has today. Ending someone's
        // ongoing support because one charge came back is not ours to decide.
        $this->assertSame(200, $res->get_status(), (string) wp_json_encode($res->get_data()));
        $this->assertSame('active', $this->statusOf((int) $plan->id));
        $this->assertArrayNotHasKey('plan', (array) $res->get_data());
    }

    public function test_asking_for_it_ends_the_schedule_too(): void
    {
        $plan     = $this->plan();
        $donation = $this->donation($plan);

        $res  = $this->refund($donation->reference, ['cancel_plan' => true]);
        $data = (array) $res->get_data();

        $this->assertSame(200, $res->get_status(), (string) wp_json_encode($data));
        $this->assertSame('cancelled', $this->statusOf((int) $plan->id));
        $this->assertTrue((bool) ($data['plan']['stopped'] ?? false));
    }

    public function test_the_refund_is_never_reported_as_a_failure_when_only_the_cancel_fails(): void
    {
        $plan     = $this->plan();
        $donation = $this->donation($plan);

        // Already ended by the time the refund runs, which is the shape of any
        // cancel that cannot proceed.
        $plan->status = 'cancelled';
        $plan->save();

        $res  = $this->refund($donation->reference, ['cancel_plan' => true]);
        $data = (array) $res->get_data();

        // The money has moved. A response that reads as failure invites a
        // second refund, which is worse than the schedule outliving the first.
        $this->assertSame(200, $res->get_status(), (string) wp_json_encode($data));
        $this->assertSame(
            'refunded',
            Plugin::instance()->container->get(DonationRepository::class)
                ->findByReference($donation->reference)->status
        );
    }

    public function test_asking_to_cancel_a_plan_that_is_not_there_refunds_nothing(): void
    {
        $donation = $this->donation(null);

        $res = $this->refund($donation->reference, ['cancel_plan' => true]);

        // Refused before the gateway call, because after it the money is gone
        // and the request cannot be taken back.
        $this->assertGreaterThanOrEqual(400, $res->get_status());
        $this->assertSame(
            'paid',
            Plugin::instance()->container->get(DonationRepository::class)
                ->findByReference($donation->reference)->status,
            'no refund was issued'
        );
    }
}
