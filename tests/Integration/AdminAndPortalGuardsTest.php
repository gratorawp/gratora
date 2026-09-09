<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Analytics\Event;
use Gratora\Donations\Donation;
use Gratora\Gateways\GatewayConfirmResult;
use Gratora\Gateways\GatewayIntentResult;
use Gratora\Gateways\GatewayManager;
use Gratora\Gateways\PaymentGateway;
use Gratora\Gateways\RefundResult;
use Gratora\Gateways\SubscriptionAware;
use Gratora\Gateways\WebhookOutcome;
use Gratora\Donors\Donor;
use Gratora\Donors\DonorService;
use Gratora\Foundation\Auth\Capabilities;
use Gratora\Foundation\Commands\CommandContext;
use Gratora\Foundation\Commands\CommandRegistry;
use Gratora\Foundation\Plugin;
use Gratora\Funds\Fund;
use Gratora\Gateways\GatewayTransportException;
use Gratora\Recurring\RecurringPlan;
use WP_REST_Request;

final class AdminAndPortalGuardsTest extends IntegrationTestCase
{

    /** A processor the site cannot reach: the request never leaves this server. */
    private function registerTimingOutGateway(): string
    {
        $gateway = new class implements PaymentGateway, SubscriptionAware {
            public function id(): string { return 'timesout'; }
            public function label(): string { return 'Times out'; }
            public function description(): string { return ''; }
            public function frequencies(): array { return ['recurring']; }
            public function paymentMethods(): array { return []; }
            public function countries(): array { return ['*']; }
            public function currencies(): array { return ['*']; }
            public function canCharge(): bool { return true; }
            public function createIntent(Donation $donation): GatewayIntentResult { return new GatewayIntentResult(); }
            public function confirm(Donation $donation, array $payload = []): GatewayConfirmResult { return new GatewayConfirmResult(success: false); }
            public function handleWebhook(\WP_REST_Request $request): WebhookOutcome { return new WebhookOutcome(signature_ok: false); }
            public function refund(Donation $donation, int $amountCents, ?string $reason = null): RefundResult { return new RefundResult(success: false); }
            public function pauseSubscription(RecurringPlan $plan, ?string $resumesAt = null): void {}
            public function resumeSubscription(RecurringPlan $plan): void {}
            public function updateSubscriptionAmount(RecurringPlan $plan, int $amountCents): void {}

            public function cancelSubscription(RecurringPlan $plan, ?string $reason = null): void
            {
                throw new GatewayTransportException('Stripe API transport error: cURL error 28: Operation timed out after 10001 milliseconds');
            }
        };

        $manager = Plugin::instance()->container->get(GatewayManager::class);
        if (! $manager->get('timesout')) {
            $manager->register($gateway);
        }

        return 'timesout';
    }

    private function planFor(int $donorId, string $gateway = 'offline'): RecurringPlan
    {
        $now  = gmdate('Y-m-d H:i:s');
        $plan = RecurringPlan::make();
        $plan->donor_id                = $donorId;
        $plan->gateway                 = $gateway;
        $plan->gateway_subscription_id = 'sub_guard_' . uniqid();
        $plan->amount_cents            = 2_000;
        $plan->currency                = 'USD';
        $plan->interval_unit           = 'month';
        $plan->interval_count          = 1;
        $plan->status                  = 'active';
        $plan->started_at              = $now;
        $plan->created_at              = $now;
        $plan->updated_at              = $now;
        $plan->save();

        return $plan;
    }

    private function donor(): Donor
    {
        return Plugin::instance()->container->get(DonorService::class)->findOrCreate(
            'guards-' . uniqid() . '@example.test',
            ['first_name' => 'Ada', 'last_name' => 'Lovelace']
        );
    }

    /** @return array{status:int, code:string, message:string} */
    private function portalCancel(RecurringPlan $plan): array
    {
        $_COOKIE['gratora_donor_session'] = $this->portalSession((int) $plan->donor_id, 'tok');

        try {
            $req = new WP_REST_Request('POST', '/gratora/v1/portal/recurring/' . (int) $plan->id . '/action');
            $req->set_param('id', (int) $plan->id);
            $req->set_header('content-type', 'application/json');
            $req->set_header('X-Gratora-Csrf', 'tok');
            $req->set_body('{"action":"cancel"}');

            $res  = rest_do_request($req);
            $data = (array) $res->get_data();

            return [
                'status'  => $res->get_status(),
                'code'    => (string) ($data['code'] ?? ''),
                'message' => (string) ($data['message'] ?? ''),
            ];
        } finally {
            unset($_COOKIE['gratora_donor_session']);
        }
    }

    public function test_a_timeout_is_not_reported_to_the_donor_as_a_permanent_refusal(): void
    {
        $plan = $this->planFor((int) $this->donor()->id, $this->registerTimingOutGateway());

        $res = $this->portalCancel($plan);

        $this->assertSame(503, $res['status'], 'a timeout was called permanent, so nothing retried and the card kept being charged');
        $this->assertStringNotContainsString('cURL', $res['message'], 'internal transport detail reached the donor');
        $this->assertStringNotContainsString('Stripe API', $res['message']);
    }

    public function test_the_failure_reaches_the_log_the_organization_reads(): void
    {
        $plan = $this->planFor((int) $this->donor()->id, $this->registerTimingOutGateway());

        $this->portalCancel($plan);

        $this->assertNotNull(
            Event::query()->where('recurring_plan_id', (int) $plan->id)->where('type', 'error.portal.recurring')->get(),
            'the organization never learns the cancel failed'
        );
    }

    public function test_a_plan_that_is_already_cancelled_still_says_so_plainly(): void
    {
        $plan = $this->planFor((int) $this->donor()->id);
        RecurringPlan::query()->where('id', (int) $plan->id)->update(['status' => 'cancelled']);

        $res = $this->portalCancel($plan);

        $this->assertSame(422, $res['status']);
        $this->assertSame('gratora_plan_terminal', $res['code']);
        $this->assertStringContainsString('no longer active', $res['message']);
    }


    private function fund(): Fund
    {
        $now  = gmdate('Y-m-d H:i:s');
        $fund = Fund::make();
        $fund->name       = 'Restricted ' . uniqid();
        $fund->code       = 'R' . substr(uniqid(), -6);
        $fund->created_at = $now;
        $fund->updated_at = $now;
        $fund->save();

        return $fund;
    }

    private function dispatchAs(string $capability, string $command, array $input): object
    {
        $userId = self::factory()->user->create(['role' => 'subscriber']);
        $user   = new \WP_User($userId);
        $user->add_cap($capability);
        $user->add_cap('manage_gratora');
        wp_set_current_user($userId);
        Capabilities::applyMapping(Capabilities::currentMapping());

        $registry = Plugin::instance()->container->get(CommandRegistry::class);

        return $registry->dispatch($command, $input, new CommandContext($userId, 'rest', 'test-' . uniqid()));
    }

    public function test_a_settings_only_role_cannot_delete_a_fund(): void
    {
        $fund = $this->fund();

        $res = $this->dispatchAs('gratora_manage_settings', 'fund.delete', ['fund_id' => (int) $fund->id]);

        $this->assertFalse($res->ok, 'branding and email templates is not a licence to destroy a designation');
        $this->assertNotNull(Fund::query()->where('id', (int) $fund->id)->get());
    }

    public function test_the_role_that_owns_the_funds_screen_can_update_one(): void
    {
        $fund = $this->fund();

        $res = $this->dispatchAs('gratora_manage_campaigns', 'fund.update', [
            'fund_id' => (int) $fund->id,
            'name'    => 'Renamed fund',
        ]);

        $this->assertTrue($res->ok, $res->error ?? '');
        $this->assertSame('Renamed fund', (string) Fund::query()->where('id', (int) $fund->id)->get()->name);
    }


    public function test_minting_a_staff_sign_in_link_leaves_a_record_of_who_did_it(): void
    {
        $admin = self::factory()->user->create(['role' => 'administrator', 'display_name' => 'Sam Staff']);
        wp_set_current_user($admin);

        $donor = $this->donor();
        $req   = new WP_REST_Request('POST', '/gratora/v1/admin/donors/' . (int) $donor->id . '/portal-link');
        $req->set_param('id', (int) $donor->id);

        $this->assertSame(201, rest_do_request($req)->get_status());

        $row = Event::query()
            ->where('type', 'donor.portal_link_issued')
            ->where('donor_id', (int) $donor->id)
            ->get();

        $this->assertNotNull($row, 'an insider takeover of a donor account left no trace anywhere');
        $this->assertSame($admin, (int) ($row->payload['actor_user_id'] ?? 0));
        $this->assertSame('Sam Staff', (string) ($row->payload['actor_name'] ?? ''));
    }

    public function test_that_record_carries_no_handle_back_to_the_donor(): void
    {
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (staff browser)';
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        $donor = $this->donor();
        $req   = new WP_REST_Request('POST', '/gratora/v1/admin/donors/' . (int) $donor->id . '/portal-link');
        $req->set_param('id', (int) $donor->id);
        rest_do_request($req);

        $row = Event::query()->where('type', 'donor.portal_link_issued')->where('donor_id', (int) $donor->id)->get();

        $this->assertNull($row->user_agent_hash);
    }
}
