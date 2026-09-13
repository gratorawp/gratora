<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Donors\DonorMetricsService;
use Gratora\Donors\DonorService;
use Gratora\Foundation\Plugin;
use Gratora\Gateways\GatewayManager;
use Gratora\Recurring\PlanRow;
use Gratora\Recurring\RecurringPlan;
use Gratora\Tests\Support\RetryableGateway;

/**
 * The donor profile and the subscriptions table both answer "a renewal was
 * declined, now what" about the same plan and the same gateway.
 *
 * Held apart they answer differently: the profile's copy grew a fourth case
 * the table's never had, and sent admins to a portal button the gateway does
 * not render. The one an admin reads first should not be the one that decides
 * what they are told.
 */
final class OneAnswerAboutADeclinedRenewalTest extends IntegrationTestCase
{
    private function donor(): int
    {
        return (int) Plugin::instance()->container->get(DonorService::class)
            ->findOrCreate('declined-' . uniqid() . '@example.test', ['first_name' => 'Ada'])->id;
    }

    private function plan(string $gateway, array $overrides = []): RecurringPlan
    {
        return $this->planFor($this->donor(), $gateway, $overrides);
    }

    private function planFor(int $donorId, string $gateway, array $overrides = []): RecurringPlan
    {
        $now = gmdate('Y-m-d H:i:s');

        $p = RecurringPlan::make();
        $p->donor_id                = $donorId;
        $p->gateway                 = $gateway;
        $p->gateway_subscription_id = 'sub_' . uniqid();
        $p->amount_cents            = 2000;
        $p->currency                = 'USD';
        $p->interval_unit           = 'month';
        $p->interval_count          = 1;
        $p->status                  = 'past_due';
        $p->failed_renewals_count   = 2;
        $p->started_at              = $now;
        $p->created_at              = $now;
        $p->updated_at              = $now;

        foreach ($overrides as $column => $value) {
            $p->{$column} = $value;
        }

        $p->save();

        return $p;
    }

    /** @return list<string> */
    private function banners(int $donorId): array
    {
        $profile = Plugin::instance()->container->get(DonorMetricsService::class)
            ->profile($donorId);

        $out = [];
        foreach ((array) ($profile['banners'] ?? []) as $banner) {
            if (($banner['kind'] ?? '') === 'past_due') {
                $out[] = (string) ($banner['message'] ?? '');
            }
        }

        return $out;
    }

    private function banner(RecurringPlan $plan): string
    {
        return $this->banners((int) $plan->donor_id)[0] ?? '';
    }

    private function refusal(RecurringPlan $plan): ?string
    {
        $row = PlanRow::common($plan, Plugin::instance()->container->get(GatewayManager::class));

        return $row['retry_blocked'];
    }

    /**
     * @return array<string, array{0:string}>
     */
    public static function gateways(): array
    {
        return [
            'one this site holds no keys for' => ['stripe'],
            'one that settles out of band'    => ['offline'],
        ];
    }

    /** @dataProvider gateways */
    public function test_the_profile_says_what_the_table_says(string $gateway): void
    {
        $this->makeOfflinePayable();

        $plan = $this->plan($gateway);

        $refusal = $this->refusal($plan);
        $this->assertNotNull($refusal, 'fixture: this gateway cannot be asked to retry');

        $this->assertStringContainsString($refusal, $this->banner($plan));
    }

    /**
     * A declined renewal leaves the plan active: the counter goes up and the
     * status does not move until the gateway gives up. The table complains
     * about that row, so the profile has to speak for it.
     */
    public function test_a_plan_still_active_but_carrying_declines_is_spoken_for(): void
    {
        $plan = $this->plan('stripe', ['status' => 'active']);

        $this->assertStringContainsString((string) $this->refusal($plan), $this->banner($plan));
    }

    /** A plan nobody is owed anything on raises nothing. */
    public function test_a_healthy_plan_raises_no_banner(): void
    {
        $plan = $this->plan('stripe', ['status' => 'active', 'failed_renewals_count' => 0]);

        $this->assertSame('', $this->banner($plan));
    }

    /**
     * The counter is reset on collection, so a cancelled plan's is whatever it
     * was when it ended. Nothing is owed on it.
     */
    public function test_a_cancelled_plan_raises_no_banner(): void
    {
        $plan = $this->plan('stripe', ['status' => 'cancelled']);

        $this->assertSame('', $this->banner($plan));
    }

    /**
     * Two processors failing at once are two different things to go and do,
     * and the banner used to stop at the first plan it found.
     */
    public function test_each_cause_gets_its_own_line(): void
    {
        $this->makeOfflinePayable();

        $donorId = $this->donor();
        $stripe  = $this->planFor($donorId, 'stripe');
        $offline = $this->planFor($donorId, 'offline');

        $said = $this->banners($donorId);

        $this->assertCount(2, $said);
        $this->assertTrue(
            $this->somethingSays($said, (string) $this->refusal($stripe)),
            'the processor this site holds no keys for is named'
        );
        $this->assertTrue(
            $this->somethingSays($said, (string) $this->refusal($offline)),
            'and so is the one that keeps its own schedule'
        );
    }

    /** The lines count causes, not plans: one processor is one thing to do. */
    public function test_two_plans_failing_the_same_way_share_a_line(): void
    {
        $donorId = $this->donor();
        $this->planFor($donorId, 'stripe');
        $this->planFor($donorId, 'stripe');

        $this->assertCount(1, $this->banners($donorId));
    }

    /**
     * A retryable plan's line promises a control on the Recurring tab. Beside
     * a plan that has no such control, saying only that sends the admin to
     * look for it on the wrong row.
     */
    public function test_a_collectable_plan_does_not_speak_for_one_that_is_not(): void
    {
        Plugin::instance()->container->get(GatewayManager::class)
            ->register(new RetryableGateway());

        $donorId = $this->donor();
        $this->planFor($donorId, 'retryable');
        $blocked = $this->planFor($donorId, 'stripe');

        $said = $this->banners($donorId);

        $this->assertCount(2, $said);
        $this->assertTrue(
            $this->somethingSays($said, 'Open the Recurring tab'),
            'the one that can be collected still says so'
        );
        $this->assertTrue(
            $this->somethingSays($said, (string) $this->refusal($blocked)),
            'and the one that cannot is not left to it'
        );
    }

    /** @param list<string> $said */
    private function somethingSays(array $said, string $needle): bool
    {
        foreach ($said as $line) {
            if (str_contains($line, $needle)) {
                return true;
            }
        }

        return false;
    }

    /** And it is the gateway's own name in both, not its slug. */
    public function test_both_name_the_gateway(): void
    {
        $plan = $this->plan('stripe');

        $this->assertStringContainsString('Stripe', (string) $this->refusal($plan));
        $this->assertStringContainsString('Stripe', $this->banner($plan));
    }
}
