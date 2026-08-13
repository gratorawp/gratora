<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Campaigns\CampaignService;
use Dono\Donations\Donation;
use Dono\Foundation\Plugin;
use Dono\Foundation\Time\Clock;
use Dono\Gateways\GatewayManager;
use Dono\Gateways\Sandbox\SandboxGateway;
use Dono\Recurring\RecurringPlan;
use Dono\Recurring\RecurringPlanRepository;
use WP_REST_Request;

/**
 * A recurring donation on the test gateway has to produce a plan.
 *
 * Stripe and PayPal build the plan inside their own webhook handling, which a
 * gateway that confirms in the same request never reaches. That left a weekly
 * test donation sitting paid with recurring_plan_id NULL: nothing on the
 * Subscriptions screen, nothing to cancel, and no way to rehearse the
 * recurring flows before launch.
 */
final class SandboxRecurringPlanTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        update_option('dono_gateway_config', [
            'test_mode' => true,
            'sandbox'   => ['enabled' => true],
        ]);

        // Boot read test_mode before this option was set, so the sandbox is not
        // registered yet.
        $container = Plugin::instance()->container;
        $manager   = $container->get(GatewayManager::class);
        if (! $manager->get('sandbox')) {
            $manager->register(new SandboxGateway(
                $container->get(Clock::class),
                $container->get(RecurringPlanRepository::class)
            ));
        }
    }

    public function test_a_weekly_sandbox_donation_creates_a_plan_linked_to_it(): void
    {
        $body = $this->donate(['frequency' => 'weekly']);
        $this->assertSame('paid', $body['status'] ?? '');

        $donation = Donation::query()->where('reference', $body['reference'])->get();
        $this->assertNotNull($donation->recurring_plan_id, 'a weekly test donation must carry a plan');

        $plan = RecurringPlan::query()->find('id', (int) $donation->recurring_plan_id);
        $this->assertSame('sandbox', $plan->gateway);
        $this->assertSame(SandboxGateway::SUB_PREFIX . (int) $donation->id, $plan->gateway_subscription_id);
        $this->assertSame('active', $plan->status);
        $this->assertSame(1500, (int) $plan->amount_cents);
        $this->assertSame(1, (int) $plan->payments_count, 'the opening charge is already paid');
        $this->assertSame(1500, (int) $plan->total_paid_cents);
        $this->assertTrue((bool) $plan->is_test);

        // The real cadence is recorded even though the simulated cycle is
        // minutes, so MRR and the "every week" label stay true.
        $this->assertSame('week', $plan->interval_unit);
        $this->assertSame(1, (int) $plan->interval_count);
        $this->assertNotNull($plan->next_payment_at);
    }

    public function test_a_one_time_sandbox_donation_creates_no_plan(): void
    {
        $body = $this->donate(['frequency' => 'one_time']);

        $donation = Donation::query()->where('reference', $body['reference'])->get();
        $this->assertNull($donation->recurring_plan_id, 'a one-off must not create a schedule');
    }

    public function test_the_plan_is_not_duplicated_when_creation_runs_again(): void
    {
        $body     = $this->donate(['frequency' => 'monthly']);
        $donation = Donation::query()->where('reference', $body['reference'])->get();

        $container = Plugin::instance()->container;
        $gateway   = $container->get(GatewayManager::class)->get('sandbox');

        // The controller guards on recurring_plan_id, but a retried request
        // must not leave a second plan behind one donation.
        $again = $gateway->createSubscription($donation);

        $this->assertSame((int) $donation->recurring_plan_id, (int) $again->id);
        $this->assertSame(
            1,
            RecurringPlan::query()->where('gateway', 'sandbox')
                ->where('gateway_subscription_id', SandboxGateway::SUB_PREFIX . (int) $donation->id)
                ->count()
        );
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function donate(array $overrides = []): array
    {
        $req = new WP_REST_Request('POST', '/dono/v1/donations');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode(array_merge([
            'campaign_id'  => $this->seedCampaign(),
            'gateway'      => 'sandbox',
            'amount_cents' => 1500,
            'currency'     => 'EUR',
            'email'        => 'sandbox-rec-' . uniqid() . '@dono.test',
            'profile'      => ['first_name' => 'Sandy', 'last_name' => 'Recurring'],
        ], $overrides)));

        $res = rest_do_request($req);
        $this->assertSame(201, $res->get_status(), (string) wp_json_encode($res->get_data()));

        return (array) $res->get_data();
    }

    private function seedCampaign(): int
    {
        $service  = Plugin::instance()->container->get(CampaignService::class);
        $campaign = $service->create([
            'title'      => 'Sandbox recurring ' . uniqid(),
            'goal_type'  => 'amount',
            'goal_cents' => 100000,
            'currency'   => 'EUR',
        ]);
        $service->update($campaign, ['status' => 'published']);

        return (int) $campaign->id;
    }
}
