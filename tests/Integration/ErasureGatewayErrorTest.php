<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Analytics\ErrorLog;
use Dono\Analytics\Event;
use Dono\Donations\Donation;
use Dono\Donors\Donor;
use Dono\Donors\DonorService;
use Dono\Foundation\Plugin;
use Dono\Gateways\GatewayConfirmResult;
use Dono\Gateways\GatewayIntentResult;
use Dono\Gateways\GatewayManager;
use Dono\Gateways\PaymentGateway;
use Dono\Gateways\RefundResult;
use Dono\Gateways\SubscriptionAware;
use Dono\Gateways\WebhookOutcome;
use Dono\Recurring\RecurringPlan;
use RuntimeException;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Erasure stops the donor's recurring plans before it touches their data, so
 * everything the processor can refuse with happens on a public request the
 * donor made. An absent gateway is only one of those answers: a 500, a
 * timeout or a revoked key come back as a plain RuntimeException, and the
 * donor is owed the same "we could not finish this, contact the organization"
 * rather than a request that dies on them with nothing recorded for the admin.
 */
final class ErasureGatewayErrorTest extends IntegrationTestCase
{
    private const GATEWAY = 'cancel_explodes';

    protected function tearDown(): void
    {
        unset($_COOKIE['dono_donor_session']);
        parent::tearDown();
    }

    /** A processor that is reachable and answers the cancel with an error. */
    private function registerFailingGateway(): void
    {
        $manager = Plugin::instance()->container->get(GatewayManager::class);
        if ($manager->get(self::GATEWAY) !== null) {
            return;
        }

        $manager->register(new class (self::GATEWAY) implements PaymentGateway, SubscriptionAware {
            public function __construct(private string $gatewayId) {}
            public function id(): string { return $this->gatewayId; }
            public function label(): string { return 'Cancel explodes'; }
            public function description(): string { return ''; }
            public function frequencies(): array { return ['one_time']; }
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
                throw new RuntimeException('Stripe: 500 from the API');
            }
        });
    }

    private function donorWithPlanAt(string $gateway): Donor
    {
        $donor = Plugin::instance()->container->get(DonorService::class)
            ->findOrCreate('erase-fail-' . uniqid() . '@example.test', ['first_name' => 'Rae']);

        $now = gmdate('Y-m-d H:i:s');
        $p = RecurringPlan::make();
        $p->donor_id                = (int) $donor->id;
        $p->gateway                 = $gateway;
        $p->gateway_subscription_id = 'sub_' . uniqid();
        $p->status                  = 'active';
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

    private function askToBeForgotten(int $donorId): WP_REST_Response
    {
        $_COOKIE['dono_donor_session'] = $this->portalSession($donorId, 'tok');

        $req = new WP_REST_Request('POST', '/dono/v1/portal/forget');
        $req->set_header('X-Dono-Csrf', 'tok');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode(['confirm' => 'DELETE']));

        return rest_do_request($req);
    }

    /** @return array<int,Event> */
    private function errors(): array
    {
        return Event::query()
            ->whereLike('type', ErrorLog::PREFIX . '%')
            ->orderBy('id', 'DESC')
            ->getAll();
    }

    public function test_a_gateway_error_answers_the_donor_instead_of_killing_the_request(): void
    {
        $this->registerFailingGateway();
        $donor = $this->donorWithPlanAt(self::GATEWAY);

        $res = $this->askToBeForgotten((int) $donor->id);

        $this->assertSame(409, $res->get_status());
        $this->assertSame('dono_erasure_blocked', $res->as_error()->get_error_code());
    }

    public function test_nothing_is_erased_when_the_plan_could_not_be_stopped(): void
    {
        $this->registerFailingGateway();
        $donor = $this->donorWithPlanAt(self::GATEWAY);

        $this->askToBeForgotten((int) $donor->id);

        $fresh = Donor::query()->where('id', (int) $donor->id)->get();
        $this->assertNull($fresh->redacted_at);
        $this->assertNotSame('', (string) $fresh->email_encrypted, 'the donor is still reachable about the plan');
    }

    /**
     * The donor is told to contact the organization, so the organization has to
     * be able to find out what happened.
     */
    public function test_the_admin_is_left_a_record_of_why_it_stopped(): void
    {
        $this->registerFailingGateway();
        $donor = $this->donorWithPlanAt(self::GATEWAY);

        $this->askToBeForgotten((int) $donor->id);

        $types = array_map(static fn (Event $e): string => (string) $e->type, $this->errors());

        $this->assertContains('error.donor.erasure.recurring', $types);
        $this->assertContains('error.portal.forget', $types);
    }

    /** Which plans are already stopped decides what the org has to finish by hand. */
    public function test_the_record_names_the_plan_that_refused(): void
    {
        $this->registerFailingGateway();
        $donor = $this->donorWithPlanAt(self::GATEWAY);
        $plan  = RecurringPlan::query()->where('donor_id', (int) $donor->id)->get();

        $this->askToBeForgotten((int) $donor->id);

        $recorded = null;
        foreach ($this->errors() as $event) {
            if ((string) $event->type === 'error.donor.erasure.recurring') {
                $recorded = $event;
                break;
            }
        }

        $this->assertNotNull($recorded);
        $this->assertSame((int) $plan->id, (int) $recorded->recurring_plan_id);
        $this->assertArrayHasKey('cancelled_first', (array) $recorded->payload);
    }

    /** An erasure with nothing to stop is unaffected. */
    public function test_a_donor_with_no_plan_is_still_erased(): void
    {
        $donor = Plugin::instance()->container->get(DonorService::class)
            ->findOrCreate('erase-plain-' . uniqid() . '@example.test');

        $res = $this->askToBeForgotten((int) $donor->id);

        $this->assertSame(200, $res->get_status());
        $this->assertNotNull(Donor::query()->where('id', (int) $donor->id)->get()->redacted_at);
    }
}
