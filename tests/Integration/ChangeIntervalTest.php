<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Foundation\Plugin;
use Gratora\Gateways\GatewayManager;
use Gratora\Gateways\GatewayConfirmResult;
use Gratora\Gateways\GatewayIntentResult;
use Gratora\Gateways\PaymentGateway;
use Gratora\Gateways\RefundResult;
use Gratora\Gateways\Sandbox\SandboxGateway;
use Gratora\Gateways\SubscriptionAware;
use Gratora\Recurring\FrequencyMap;
use Gratora\Recurring\RecurringPlan;
use Gratora\Recurring\RecurringPlanActions;
use Gratora\Recurring\RecurringPlanChange;
use Gratora\Gateways\SubscriptionSchedule;
use Gratora\Gateways\SupportsScheduleChange;
use InvalidArgumentException;
use RuntimeException;

/**
 * Changing how often a donor is charged. The cadence is taken from a named
 * frequency rather than an arbitrary interval pair, because a processor
 * accepts any pair and this product can only name five.
 */
final class ChangeIntervalTest extends IntegrationTestCase
{
    /** The date a talkative processor reports after the change. */
    public const REPORTED = '2027-03-01 09:00:00';

    protected function setUp(): void
    {
        parent::setUp();
        // The sandbox is the one gateway that answers a schedule change without
        // reaching a processor, so it stands in for one here.
        $c       = Plugin::instance()->container;
        $manager = $c->get(GatewayManager::class);

        // The manager lives for the whole run, so a second register throws.
        if ($manager->get('sandbox') === null) {
            $manager->register(new SandboxGateway(
                $c->get(\Gratora\Foundation\Time\Clock::class),
                $c->get(\Gratora\Recurring\RecurringPlanRepository::class)
            ));
        }
    }

    private function actions(): RecurringPlanActions
    {
        return Plugin::instance()->container->get(RecurringPlanActions::class);
    }

    private function plan(array $attrs = []): RecurringPlan
    {
        $now = gmdate('Y-m-d H:i:s');

        $p = RecurringPlan::make();
        $p->donor_id                = 1;
        $p->gateway                 = 'sandbox';
        $p->gateway_subscription_id = 'sub_' . uniqid();
        $p->amount_cents            = 2500;
        $p->currency                = 'EUR';
        $p->interval_unit           = 'month';
        $p->interval_count          = 1;
        $p->status                  = 'active';
        $p->is_test                 = false;
        $p->next_payment_at         = gmdate('Y-m-d H:i:s', time() + 10 * DAY_IN_SECONDS);
        $p->created_at              = $now;
        $p->updated_at              = $now;
        foreach ($attrs as $k => $v) {
            $p->{$k} = $v;
        }
        $p->save();

        return $p;
    }

    private function change(): RecurringPlanChange
    {
        return new RecurringPlanChange('change_interval', RecurringPlanChange::BY_ADMIN);
    }

    private function reload(RecurringPlan $p): RecurringPlan
    {
        return RecurringPlan::query()->find('id', (int) $p->id);
    }

    public function test_the_cadence_moves(): void
    {
        $plan = $this->plan();

        $this->actions()->changeInterval($plan, 'yearly', $this->change());

        $after = $this->reload($plan);
        $this->assertSame('year', $after->interval_unit);
        $this->assertSame(1, (int) $after->interval_count);
    }

    public function test_a_multi_count_frequency_carries_its_count(): void
    {
        $plan = $this->plan();

        $this->actions()->changeInterval($plan, 'quarterly', $this->change());

        $after = $this->reload($plan);
        $this->assertSame('month', $after->interval_unit);
        $this->assertSame(3, (int) $after->interval_count);
    }

    public function test_a_cadence_this_product_cannot_name_is_refused(): void
    {
        $plan = $this->plan();

        $this->expectException(InvalidArgumentException::class);
        $this->actions()->changeInterval($plan, 'every_37_days', $this->change());
    }

    public function test_a_cancelled_plan_is_refused(): void
    {
        $plan = $this->plan(['status' => 'cancelled']);

        $this->expectException(RuntimeException::class);
        $this->actions()->changeInterval($plan, 'yearly', $this->change());
    }

    public function test_no_change_is_a_no_op(): void
    {
        $plan = $this->plan();
        $before = (string) $this->reload($plan)->updated_at;

        $this->actions()->changeInterval($plan, 'monthly', $this->change());

        $this->assertSame($before, (string) $this->reload($plan)->updated_at);
    }

    public function test_a_paused_plan_keeps_its_resume_pairing(): void
    {
        $resume = gmdate('Y-m-d H:i:s', time() + 60 * DAY_IN_SECONDS);
        $plan   = $this->plan(['status' => 'paused', 'resume_at' => $resume]);
        $was    = (string) $plan->next_payment_at;

        $this->actions()->changeInterval($plan, 'yearly', $this->change());

        $after = $this->reload($plan);
        $this->assertSame('year', $after->interval_unit, 'the cadence still moves');
        $this->assertSame($was, (string) $after->next_payment_at, 'and the paused date is left alone');
        $this->assertSame($resume, (string) $after->resume_at);
    }

    /**
     * A processor that reports its own next charge. The sandbox never does, so
     * without this the write-back branch is never taken and the paused guard
     * below would pass whatever it guarded.
     */
    private function talkativeGateway(): void
    {
        $manager = Plugin::instance()->container->get(GatewayManager::class);
        if ($manager->get('talkative') === null) {
            $manager->register(new TalkativeGateway());
        }
    }

    public function test_the_processors_date_is_written_not_a_local_guess(): void
    {
        $this->talkativeGateway();
        $plan = $this->plan(['gateway' => 'talkative']);

        $this->actions()->changeInterval($plan, 'yearly', $this->change());

        $this->assertSame(self::REPORTED, (string) $this->reload($plan)->next_payment_at);
    }

    public function test_a_paused_plans_date_survives_a_talkative_processor(): void
    {
        $this->talkativeGateway();
        $resume = gmdate('Y-m-d H:i:s', time() + 60 * DAY_IN_SECONDS);
        $plan   = $this->plan(['gateway' => 'talkative', 'status' => 'paused', 'resume_at' => $resume]);
        $was    = (string) $plan->next_payment_at;

        $this->actions()->changeInterval($plan, 'yearly', $this->change());

        $after = $this->reload($plan);
        $this->assertSame('year', $after->interval_unit, 'the cadence still moves');
        $this->assertSame($was, (string) $after->next_payment_at, 'and the paused date is left alone');
        $this->assertNotSame(self::REPORTED, (string) $after->next_payment_at);
    }

    public function test_the_vocabulary_round_trips(): void
    {
        foreach (FrequencyMap::recurringFrequencies() as $frequency) {
            [$unit, $count] = FrequencyMap::toStripe($frequency);
            $this->assertSame($frequency, FrequencyMap::fromInterval($unit, $count));
        }

        $this->assertNull(FrequencyMap::fromInterval('month', 37), 'a pair with no name says so');
    }
}

/** A processor that reports its own next charge date, which the sandbox never does. */
final class TalkativeGateway implements PaymentGateway, SubscriptionAware, SupportsScheduleChange
{
    public function id(): string { return 'talkative'; }
    public function label(): string { return 'Talkative'; }
    public function description(): string { return ''; }
    public function frequencies(): array { return ['monthly', 'yearly']; }
    public function paymentMethods(): array { return []; }
    public function countries(): array { return []; }
    public function currencies(): array { return ['EUR']; }
    public function canCharge(): bool { return true; }

    public function createIntent(\Gratora\Donations\Donation $d): GatewayIntentResult
    {
        return new GatewayIntentResult(ok: false, error: 'not used');
    }
    public function confirm(\Gratora\Donations\Donation $d, array $payload = []): GatewayConfirmResult
    {
        return new GatewayConfirmResult(ok: false, error: 'not used');
    }
    public function handleWebhook(\WP_REST_Request $r): \Gratora\Gateways\WebhookOutcome
    {
        return new \Gratora\Gateways\WebhookOutcome(signature_ok: false, external_id: '', event_type: '', handled: false);
    }
    public function refund(\Gratora\Donations\Donation $d, int $cents, ?string $reason = null): RefundResult
    {
        return new RefundResult(success: false, error: 'not used');
    }

    public function cancelSubscription(RecurringPlan $p, ?string $reason = null): void {}
    public function pauseSubscription(RecurringPlan $p, ?string $resumesAt = null): void {}
    public function resumeSubscription(RecurringPlan $p): void {}
    public function updateSubscriptionAmount(RecurringPlan $p, int $cents): void {}

    public function updateSubscriptionSchedule(
        RecurringPlan $plan,
        int $amountCents,
        string $intervalUnit,
        int $intervalCount
    ): SubscriptionSchedule {
        return SubscriptionSchedule::at(ChangeIntervalTest::REPORTED);
    }
}
