<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Donations\Donation;
use Gratora\Donors\Donor;
use Gratora\Donors\DonorService;
use Gratora\Foundation\Plugin;
use Gratora\Gateways\GatewayConfirmResult;
use Gratora\Gateways\GatewayIntentResult;
use Gratora\Gateways\GatewayManager;
use Gratora\Gateways\PaymentGateway;
use Gratora\Gateways\RefundResult;
use Gratora\Gateways\SubscriptionAware;
use Gratora\Gateways\WebhookOutcome;
use Gratora\Recurring\RecurringPlan;
use InvalidArgumentException;
use RuntimeException;
use WP_REST_Request;

/**
 * Deleting a donor has to stop their mandate first.
 *
 * A plan row holds the only handle that can cancel the billing, so removing it
 * without a cancel leaves the card charged every month with nothing on the
 * site able to stop it. That is the one delete whose cost lands on somebody
 * outside the organisation, which is why it refuses rather than proceeding
 * when the processor will not answer.
 */
final class DonorDeleteStopsMandateTest extends IntegrationTestCase
{
    private const RECORDS  = 'cancel_records';
    private const REFUSES  = 'cancel_refuses';

    /** @var object{cancelled:list<int>}|null */
    private ?object $recorder = null;

    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    private function gateway(string $id, bool $refuses): object
    {
        return new class ($id, $refuses) implements PaymentGateway, SubscriptionAware {
            /** @var list<int> */
            public array $cancelled = [];

            public function __construct(private string $gatewayId, private bool $refuses) {}
            public function id(): string { return $this->gatewayId; }
            public function label(): string { return 'Probe'; }
            public function description(): string { return ''; }
            public function frequencies(): array { return ['one_time', 'monthly']; }
            public function paymentMethods(): array { return []; }
            public function countries(): array { return []; }
            public function currencies(): array { return []; }
            public function canCharge(): bool { return false; }
            public function createIntent(Donation $donation): GatewayIntentResult { return new GatewayIntentResult(); }
            public function confirm(Donation $donation, array $payload = []): GatewayConfirmResult { return new GatewayConfirmResult(success: false); }
            public function handleWebhook(WP_REST_Request $request): WebhookOutcome { return new WebhookOutcome(signature_ok: false); }
            public function refund(Donation $donation, int $amountCents, ?string $reason = null): RefundResult { return new RefundResult(success: false); }
            public function pauseSubscription(RecurringPlan $plan, ?string $resumesAt = null): void {}
            public function resumeSubscription(RecurringPlan $plan): void {}
            public function updateSubscriptionAmount(RecurringPlan $plan, int $amountCents): void {}

            public function cancelSubscription(RecurringPlan $plan, ?string $reason = null): void
            {
                if ($this->refuses) {
                    throw new RuntimeException('The processor answered 500.');
                }
                $this->cancelled[] = (int) $plan->id;
            }
        };
    }

    private function register(string $id, bool $refuses): object
    {
        $manager = Plugin::instance()->container->get(GatewayManager::class);
        $existing = $manager->get($id);
        if ($existing !== null) {
            return $existing;
        }

        $gateway = $this->gateway($id, $refuses);
        $manager->register($gateway);

        return $gateway;
    }

    private function donorWithPlan(string $gateway, string $status = 'active'): Donor
    {
        $donor = Plugin::instance()->container->get(DonorService::class)
            ->findOrCreate('mandate-' . uniqid() . '@example.test', ['first_name' => 'Mandate']);

        $now = gmdate('Y-m-d H:i:s');
        $p = RecurringPlan::make();
        $p->donor_id                = (int) $donor->id;
        $p->gateway                 = $gateway;
        $p->gateway_subscription_id = 'sub_' . uniqid();
        $p->status                  = $status;
        $p->amount_cents            = 2500;
        $p->currency                = 'USD';
        $p->interval_unit           = 'month';
        $p->interval_count          = 1;
        $p->started_at              = $now;
        $p->created_at              = $now;
        $p->updated_at              = $now;
        $p->save();

        return $donor;
    }

    private function donors(): DonorService
    {
        return Plugin::instance()->container->get(DonorService::class);
    }

    private function plansOf(int $donorId): int
    {
        return (int) RecurringPlan::query()->where('donor_id', $donorId)->count();
    }

    public function test_the_mandate_is_cancelled_at_the_processor_and_the_row_goes(): void
    {
        $this->recorder = $this->register(self::RECORDS, false);
        $donor = $this->donorWithPlan(self::RECORDS);
        $id    = (int) $donor->id;
        $plan  = (int) RecurringPlan::query()->where('donor_id', $id)->get()->id;

        $this->donors()->delete($donor);

        $this->assertContains($plan, $this->recorder->cancelled, 'the processor was told to stop billing');
        $this->assertNull(Donor::query()->find('id', $id));
        $this->assertSame(0, $this->plansOf($id), 'and the plan row did not outlive the donor');
    }

    /** The one delete whose cost lands on the donor if it goes ahead anyway. */
    public function test_a_processor_that_will_not_stop_the_billing_refuses_the_delete(): void
    {
        $this->register(self::REFUSES, true);
        $donor = $this->donorWithPlan(self::REFUSES);
        $id    = (int) $donor->id;

        try {
            $this->donors()->delete($donor);
            $this->fail('a mandate that could not be stopped must refuse the delete');
        } catch (RuntimeException | InvalidArgumentException $e) {
            $this->assertNotSame('', $e->getMessage());
        }

        $this->assertNotNull(Donor::query()->find('id', $id), 'nothing is half removed');
        $this->assertSame(1, $this->plansOf($id), 'and the handle that can still stop it is kept');
    }

    /**
     * A gateway this site has no credentials for is not registered at all, so
     * there is nothing to ask and nothing that can stop the billing.
     */
    public function test_an_absent_gateway_refuses_the_delete(): void
    {
        $donor = $this->donorWithPlan('stripe');
        $id    = (int) $donor->id;

        try {
            $this->donors()->delete($donor);
            $this->fail('an unreachable processor must refuse the delete');
        } catch (RuntimeException | InvalidArgumentException $e) {
            $this->assertNotSame('', $e->getMessage());
        }

        $this->assertNotNull(Donor::query()->find('id', $id));
    }

    /**
     * The row is not proof. An importer writes 'cancelled' over statuses it has
     * no state for, so the processor is asked whatever the column says.
     */
    public function test_a_plan_the_row_calls_cancelled_is_still_put_to_the_processor(): void
    {
        $this->recorder = $this->register(self::RECORDS, false);
        $donor = $this->donorWithPlan(self::RECORDS, 'cancelled');
        $id    = (int) $donor->id;
        $plan  = (int) RecurringPlan::query()->where('donor_id', $id)->get()->id;

        $this->donors()->delete($donor);

        $this->assertContains($plan, $this->recorder->cancelled, 'the column was not taken at its word');
        $this->assertSame(0, $this->plansOf($id));
    }

    public function test_a_donor_with_no_plan_is_unaffected(): void
    {
        $donor = $this->donors()->findOrCreate('mandate-none-' . uniqid() . '@example.test');
        $id    = (int) $donor->id;

        $this->donors()->delete($donor);

        $this->assertNull(Donor::query()->find('id', $id));
    }
}
