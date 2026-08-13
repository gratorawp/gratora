<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Foundation\Plugin;
use Dono\Foundation\Time\Clock;
use Dono\Gateways\GatewayManager;
use Dono\Gateways\Sandbox\SandboxGateway;
use Dono\Gateways\SubscriptionAware;
use Dono\Gateways\SubscriptionCreator;
use Dono\Recurring\RecurringPlan;
use Dono\Recurring\RecurringPlanRepository;
use Dono\Recurring\RecurringResumer;
use WP_REST_Request;

/**
 * The admin flows the whole rehearsal exists for.
 *
 * Creating a plan on the test gateway is only useful if the plan then behaves
 * like a real one: it appears on the Subscriptions screen, and pause, resume
 * and cancel all route through the same RecurringPlanActions path a Stripe
 * plan takes.
 *
 * The flow assertions below cannot see whether the gateway was told. Actions
 * resolve capability with `$gateway instanceof SubscriptionAware ? $gateway :
 * null` and skip the call when it is null, still writing the local row, so
 * every one of them passes against a gateway that implements nothing. The
 * capability test is what covers that, and it is the one that fails if the
 * interface is dropped.
 */
final class SandboxSubscriptionAdminTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        update_option('dono_gateway_config', [
            'test_mode' => true,
            'sandbox'   => ['enabled' => true],
        ]);

        $container = Plugin::instance()->container;
        $manager   = $container->get(GatewayManager::class);
        if (! $manager->get('sandbox')) {
            $manager->register(new SandboxGateway(
                $container->get(Clock::class),
                $container->get(RecurringPlanRepository::class)
            ));
        }

        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    private function plan(): RecurringPlan
    {
        $now = gmdate('Y-m-d H:i:s');
        $p = RecurringPlan::make();
        $p->donor_id       = 1;
        $p->gateway        = 'sandbox';
        $p->gateway_subscription_id = 'sandbox_sub_' . uniqid();
        $p->amount_cents   = 2500;
        $p->currency       = 'EUR';
        $p->interval_unit  = 'week';
        $p->interval_count = 1;
        $p->status         = 'active';
        $p->is_test        = true;
        $p->started_at     = $now;
        $p->next_payment_at = gmdate('Y-m-d H:i:s', time() + 300);
        $p->payments_count = 1;
        $p->total_paid_cents = 2500;
        $p->created_at     = $now;
        $p->updated_at     = $now;
        $p->save();

        return $p;
    }

    private function reload(RecurringPlan $p): RecurringPlan
    {
        return RecurringPlan::query()->find('id', (int) $p->id);
    }

    /** @param array<string,mixed> $body */
    private function act(RecurringPlan $plan, string $action, array $body = []): \WP_REST_Response|\WP_Error
    {
        $req = new WP_REST_Request('POST', '/dono/v1/admin/recurring/' . (int) $plan->id . '/action');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode(['action' => $action] + $body));

        return rest_do_request($req);
    }

    public function test_the_registered_sandbox_gateway_can_manage_subscriptions(): void
    {
        // RecurringPlanActions::subscription() returns null for a gateway that
        // is not SubscriptionAware and the action proceeds anyway, so dropping
        // the interface would leave pause, resume and cancel writing the row
        // and telling nobody. Nothing else in this file would notice.
        $gateway = Plugin::instance()->container->get(GatewayManager::class)->get('sandbox');

        $this->assertInstanceOf(SubscriptionAware::class, $gateway);
        $this->assertInstanceOf(SubscriptionCreator::class, $gateway);
    }

    public function test_a_sandbox_plan_is_hidden_by_default_and_listed_on_request(): void
    {
        $plan = $this->plan();

        $hidden = rest_do_request(new WP_REST_Request('GET', '/dono/v1/admin/recurring'));
        $ids    = array_column((array) $hidden->get_data(), 'id');
        $this->assertNotContains((int) $plan->id, $ids, 'a test plan stays out of the live list');
        $this->assertGreaterThan(0, (int) $hidden->get_headers()['X-Dono-Test-Hidden'], 'and the screen says so');

        $req = new WP_REST_Request('GET', '/dono/v1/admin/recurring');
        $req->set_param('include_test', true);
        $shown = (array) rest_do_request($req)->get_data();

        $row = null;
        foreach ($shown as $item) {
            if ((int) $item['id'] === (int) $plan->id) {
                $row = $item;
            }
        }

        $this->assertNotNull($row, 'including test plans shows it');
        $this->assertTrue($row['simulated'], 'the row admits its cycle is not the donor cadence');
        $this->assertSame(SandboxGateway::CYCLE_MINUTES, $row['simulated_cycle_minutes']);
    }

    public function test_an_admin_can_pause_and_resume_a_sandbox_plan(): void
    {
        $plan = $this->plan();

        $res = $this->act($plan, 'pause', ['months' => 1]);
        $this->assertSame(200, $res->get_status(), (string) wp_json_encode($res->get_data()));

        $paused = $this->reload($plan);
        $this->assertSame('paused', $paused->status);
        $this->assertNotNull($paused->resume_at);

        $res = $this->act($paused, 'resume');
        $this->assertSame(200, $res->get_status(), (string) wp_json_encode($res->get_data()));

        $resumed = $this->reload($plan);
        $this->assertSame('active', $resumed->status);
        $this->assertNull($resumed->resume_at);
    }

    public function test_a_paused_sandbox_plan_is_lifted_by_the_shared_sweep(): void
    {
        $plan = $this->plan();
        $this->act($plan, 'pause', ['months' => 1]);

        // Backdate the window so the sweep sees it as due. This is the proof
        // the sandbox plan is swept like any other rather than needing its own
        // path: RecurringResumer resolves the gateway and calls resumeSubscription.
        RecurringPlan::query()
            ->where('id', (int) $plan->id)
            ->update(['resume_at' => gmdate('Y-m-d H:i:s', time() - 3600)]);

        Plugin::instance()->container->get(RecurringResumer::class)->run();

        $after = $this->reload($plan);
        $this->assertSame('active', $after->status);
        $this->assertNull($after->resume_at);
    }

    public function test_an_admin_can_cancel_a_sandbox_plan(): void
    {
        $plan = $this->plan();

        $res = $this->act($plan, 'cancel');
        $this->assertSame(200, $res->get_status(), (string) wp_json_encode($res->get_data()));

        $after = $this->reload($plan);
        $this->assertSame('cancelled', $after->status);
        $this->assertNotNull($after->cancelled_at);
    }

    public function test_an_admin_can_change_the_amount_of_a_sandbox_plan(): void
    {
        $plan = $this->plan();

        $res = $this->act($plan, 'change_amount', ['amount_cents' => 5000]);
        $this->assertSame(200, $res->get_status(), (string) wp_json_encode($res->get_data()));

        $this->assertSame(5000, (int) $this->reload($plan)->amount_cents);
    }
}
