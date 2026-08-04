<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Async\AsyncDispatcher;
use Dono\Donors\DonorService;
use Dono\Foundation\Plugin;
use Dono\Recurring\CampaignCancelRecurringJob;
use Dono\Recurring\RecurringPlan;
use Dono\Foundation\Commands\CommandContext;
use Dono\Foundation\Commands\CommandRegistry;
use Dono\Recurring\RecurringResumer;

/**
 * A paused plan has to be able to come back, a resumed one must not resurrect a
 * cancelled plan, and an archive sweep that loses a tick must not leave half a
 * campaign's donors still being charged.
 */
final class RecurringLifecycleFixesTest extends IntegrationTestCase
{
    private function plan(array $overrides = []): RecurringPlan
    {
        $donor = Plugin::instance()->container->get(DonorService::class)
            ->findOrCreate('life-' . uniqid() . '@example.test');

        $now = gmdate('Y-m-d H:i:s');
        $p = RecurringPlan::make();
        $p->donor_id                = (int) $donor->id;
        $p->gateway                 = 'offline';
        $p->gateway_subscription_id = 'sub_life_' . bin2hex(random_bytes(4));
        $p->status                  = 'active';
        $p->amount_cents            = 2500;
        $p->currency                = 'USD';
        $p->interval_unit           = 'month';
        $p->interval_count          = 1;
        $p->started_at              = $now;
        $p->created_at              = $now;
        $p->updated_at              = $now;
        foreach ($overrides as $k => $v) {
            $p->{$k} = $v;
        }
        $p->save();

        return $p;
    }

    private function adminCtx(): CommandContext
    {
        $admin = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin);

        return new CommandContext($admin, 'rest', 'req-' . uniqid());
    }

    public function test_pausing_from_a_command_schedules_the_resume(): void
    {
        // RecurringResumer keys entirely on resume_at. Without it the plan is
        // stopped forever behind a restart date the admin can see.
        $plan = $this->plan();
        $when = gmdate('Y-m-d H:i:s', strtotime('+2 months'));

        Plugin::instance()->container->get(CommandRegistry::class)
            ->dispatch('recurring.pause', ['plan_id' => (int) $plan->id, 'resumes_at' => $when], $this->adminCtx());

        $fresh = RecurringPlan::query()->where('id', (int) $plan->id)->get();
        $this->assertSame('paused', $fresh->status);
        $this->assertSame($when, (string) $fresh->resume_at, 'the pause has to carry its own end');
    }

    public function test_a_plan_cancelled_mid_sweep_is_not_resurrected(): void
    {
        // The batch is read up front and then blocks on one gateway call per
        // plan, so the donor can cancel while the sweep is partway through it.
        // This gateway does exactly that from inside the resume call.
        Plugin::instance()->container->get(\Dono\Gateways\GatewayManager::class)
            ->register(new CancellingDuringResumeGateway());

        $plan = $this->plan([
            'gateway'   => 'racing',
            'status'    => 'paused',
            'resume_at' => gmdate('Y-m-d H:i:s', strtotime('-1 hour')),
        ]);

        Plugin::instance()->container->get(RecurringResumer::class)->run();

        $fresh = RecurringPlan::query()->where('id', (int) $plan->id)->get();
        $this->assertSame('cancelled', $fresh->status, 'a stale snapshot must not write the cancel away');
        $this->assertNotNull($fresh->cancelled_at);
    }

    public function test_a_still_paused_plan_does_resume(): void
    {
        $plan = $this->plan([
            'status'    => 'paused',
            'resume_at' => gmdate('Y-m-d H:i:s', strtotime('-1 hour')),
        ]);

        Plugin::instance()->container->get(RecurringResumer::class)->run();

        $fresh = RecurringPlan::query()->where('id', (int) $plan->id)->get();
        $this->assertSame('active', $fresh->status);
        $this->assertNull($fresh->resume_at);
    }

    public function test_a_dropped_archive_sweep_is_re_queued(): void
    {
        $async = Plugin::instance()->container->get(AsyncDispatcher::class);

        // A run left mid-flight with its job lost: the only continuation was
        // the job re-enqueuing itself, so nothing else would ever restart it.
        update_option('dono_campaign_cancel_recurring', [4242 => 0], false);

        $this->assertNotSame([], CampaignCancelRecurringJob::pending());

        CampaignCancelRecurringJob::reconcile($async);

        $this->assertTrue(
            \as_has_scheduled_action(CampaignCancelRecurringJob::HOOK, ['campaign_id' => 4242], AsyncDispatcher::GROUP),
            'the sweep is queued again rather than left half done'
        );

        delete_option('dono_campaign_cancel_recurring');
    }
}

/**
 * A gateway whose resume takes long enough for the donor to cancel, which is
 * the window the resumer's batch read leaves open.
 */
final class CancellingDuringResumeGateway implements \Dono\Gateways\PaymentGateway, \Dono\Gateways\SubscriptionAware
{
    public function id(): string { return 'racing'; }
    public function label(): string { return 'Racing'; }
    public function description(): string { return ''; }
    public function frequencies(): array { return ['recurring']; }
    public function paymentMethods(): array { return []; }
    public function countries(): array { return ['*']; }
    public function currencies(): array { return ['USD']; }
    public function canCharge(): bool { return true; }
    public function createIntent(\Dono\Donations\Donation $donation): \Dono\Gateways\GatewayIntentResult { throw new \RuntimeException('unused'); }
    public function confirm(\Dono\Donations\Donation $donation, array $payload = []): \Dono\Gateways\GatewayConfirmResult { throw new \RuntimeException('unused'); }
    public function handleWebhook(\WP_REST_Request $request): \Dono\Gateways\WebhookOutcome { throw new \RuntimeException('unused'); }
    public function refund(\Dono\Donations\Donation $donation, int $amountCents, ?string $reason = null): \Dono\Gateways\RefundResult { throw new \RuntimeException('unused'); }

    public function cancelSubscription(RecurringPlan $plan, ?string $reason = null): void {}
    public function pauseSubscription(RecurringPlan $plan, ?string $resumesAt = null): void {}
    public function updateSubscriptionAmount(RecurringPlan $plan, int $amountCents): void {}

    /** The donor cancels while this call is in flight. */
    public function resumeSubscription(RecurringPlan $plan): void
    {
        RecurringPlan::query()->where('id', (int) $plan->id)->update([
            'status'       => 'cancelled',
            'cancelled_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }
}
