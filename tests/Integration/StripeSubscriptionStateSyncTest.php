<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Foundation\Crypto\Crypto;
use Dono\Foundation\Plugin;
use Dono\Gateways\Stripe\StripeAccount;
use Dono\Gateways\Stripe\StripeWebhookProvisioner;
use Dono\Recurring\RecurringPlan;
use ReflectionClass;
use WP_REST_Request;

/**
 * A subscription Stripe has stopped collecting must stop reading as active here.
 *
 * Stripe reports the end of its own dunning as `customer.subscription.updated`
 * with status past_due or unpaid, never as a payment event. Unsubscribed and
 * unhandled, the plan keeps counting toward the active plan count and MRR
 * forever, on money nobody is collecting, and the donor never reaches the
 * past-due state a PayPal donor in the same position does.
 */
final class StripeSubscriptionStateSyncTest extends IntegrationTestCase
{
    private string $secret;

    protected function setUp(): void
    {
        parent::setUp();

        $this->secret = 'whsec_test_' . bin2hex(random_bytes(8));
        update_option('dono_gateway_config', [
            'stripe' => ['webhook_secret_test' => $this->secret, 'test_mode' => true],
        ]);

        $acct = new StripeAccount(new Crypto());
        $acct->saveKeys(true, 'sk_test_connected', 'pk_test_seed');
        $acct->saveKeys(false, 'sk_live_connected', 'pk_live_seed');
        $acct->refresh(['id' => 'acct_test_123', 'charges_enabled' => true]);

        $c       = Plugin::instance()->container;
        $manager = $c->get(\Dono\Gateways\GatewayManager::class);
        if (! $manager->get('stripe')) {
            $manager->register(new \Dono\Gateways\Stripe\StripeGateway(
                $c->get(\Dono\Gateways\Stripe\StripeApi::class),
                $c->get(\Dono\Donations\DonationRepository::class),
                $c->get(\Dono\Donations\DonationService::class),
                $c->get(\Dono\Gateways\Stripe\StripeAccount::class),
                $c->get(\Dono\Donors\DonorRepository::class),
                $c->get(\Dono\Donors\DonorService::class),
                $c->get(\Dono\Foundation\Time\Clock::class),
                $c->get(\Dono\Recurring\RecurringPlanRepository::class),
            ));
        }
    }

    public function test_the_endpoint_subscribes_to_subscription_updates(): void
    {
        $events = (new ReflectionClass(StripeWebhookProvisioner::class))
            ->getConstant('EVENTS');

        $this->assertContains(
            'customer.subscription.updated',
            $events,
            'an event the endpoint never enables is one Stripe never sends'
        );
    }

    public function test_a_subscription_stripe_gave_up_on_stops_reading_active(): void
    {
        $plan = $this->seedPlan();

        $status = $this->postWebhook('customer.subscription.updated', [
            'id'     => (string) $plan->gateway_subscription_id,
            'status' => 'unpaid',
        ]);

        $this->assertSame(200, $status, 'the event reached the handler');
        $fresh = RecurringPlan::query()->find('id', (int) $plan->id);
        $this->assertSame('past_due', (string) $fresh->status, 'a plan Stripe stopped collecting is past due, not active');
    }

    public function test_a_past_due_subscription_stripe_collected_again_reads_active(): void
    {
        $plan = $this->seedPlan();
        $plan->status = 'past_due';
        $plan->save();

        $status = $this->postWebhook('customer.subscription.updated', [
            'id'     => (string) $plan->gateway_subscription_id,
            'status' => 'active',
        ]);

        $this->assertSame(200, $status, 'the event reached the handler');
        $fresh = RecurringPlan::query()->find('id', (int) $plan->id);
        $this->assertSame('active', (string) $fresh->status, 'collection resuming puts the plan back');
    }

    public function test_an_update_never_revives_a_cancelled_plan(): void
    {
        $plan = $this->seedPlan();
        $plan->status = 'cancelled';
        $plan->save();

        $status = $this->postWebhook('customer.subscription.updated', [
            'id'     => (string) $plan->gateway_subscription_id,
            'status' => 'active',
        ]);

        $this->assertSame(200, $status, 'the event reached the handler');
        $fresh = RecurringPlan::query()->find('id', (int) $plan->id);
        $this->assertSame('cancelled', (string) $fresh->status, 'a cancelled plan stays cancelled');
    }

    public function test_a_skipped_payment_is_not_read_back_as_a_pause(): void
    {
        // Skipping one payment pauses collection at Stripe while the plan stays
        // active here on purpose, so the paused subscription it produces must
        // not flip the row.
        $plan = $this->seedPlan();

        $status = $this->postWebhook('customer.subscription.updated', [
            'id'               => (string) $plan->gateway_subscription_id,
            'status'           => 'active',
            'pause_collection' => ['behavior' => 'mark_uncollectible', 'resumes_at' => 1740787200],
        ]);

        $this->assertSame(200, $status, 'the event reached the handler');
        $fresh = RecurringPlan::query()->find('id', (int) $plan->id);
        $this->assertSame('active', (string) $fresh->status);
    }

    public function test_a_test_signed_update_cannot_move_a_live_plan(): void
    {
        $plan = $this->seedPlan();
        $plan->is_test = false;
        $plan->save();

        $status = $this->postWebhook('customer.subscription.updated', [
            'id'     => (string) $plan->gateway_subscription_id,
            'status' => 'unpaid',
        ]);

        $this->assertSame(200, $status, 'the event reached the handler');
        $fresh = RecurringPlan::query()->find('id', (int) $plan->id);
        $this->assertSame('active', (string) $fresh->status, 'a test secret cannot move a live plan');
    }

    public function test_an_update_that_reports_the_subscription_cancelled_cancels_the_plan(): void
    {
        $plan  = $this->seedPlan();
        $mails = $this->captureMails();

        $status = $this->postWebhook('customer.subscription.updated', [
            'id'                   => (string) $plan->gateway_subscription_id,
            'status'               => 'canceled',
            'cancellation_details' => ['reason' => 'payment_failed'],
        ]);

        $this->assertSame(200, $status, 'the event reached the handler');
        $fresh = RecurringPlan::query()->find('id', (int) $plan->id);
        $this->assertSame('cancelled', (string) $fresh->status);
        $this->assertSame('payment_failed', (string) $fresh->cancellation_reason);

        $this->assertCount(1, $this->cancellationMails($mails), 'the donor is told once');
    }

    public function test_the_deleted_event_that_follows_does_not_email_the_donor_again(): void
    {
        // Stripe sends both for the same cancellation, and routing the update
        // into the delete handler is only safe because the second one loses the
        // DB transition that gates the email.
        $plan  = $this->seedPlan();
        $mails = $this->captureMails();

        $this->postWebhook('customer.subscription.updated', [
            'id'                   => (string) $plan->gateway_subscription_id,
            'status'               => 'canceled',
            'cancellation_details' => ['reason' => 'payment_failed'],
        ]);
        $status = $this->postWebhook('customer.subscription.deleted', [
            'id'                   => (string) $plan->gateway_subscription_id,
            'status'               => 'canceled',
            'cancellation_details' => ['reason' => 'payment_failed'],
        ]);

        $this->assertSame(200, $status, 'the event reached the handler');
        $this->assertCount(1, $this->cancellationMails($mails), 'the donor is told once, not once per event');
    }

    /**
     * @param  iterable<array<string,mixed>> $mails
     * @return list<array<string,mixed>>
     */
    private function cancellationMails(iterable $mails): array
    {
        return array_values(array_filter(
            iterator_to_array($mails),
            static fn ($m) => stripos((string) ($m['subject'] ?? ''), 'cancel') !== false,
        ));
    }

    private function seedPlan(): RecurringPlan
    {
        $donor = Plugin::instance()->container
            ->get(\Dono\Donors\DonorService::class)
            ->findOrCreate('state-sync-' . uniqid() . '@example.test', [
                'first_name' => 'State',
                'last_name'  => 'Sync',
            ]);

        $plan = RecurringPlan::make();
        $plan->donor_id                = (int) $donor->id;
        $plan->gateway                 = 'stripe';
        // The harness signs with the test secret, so the plan it may act on is
        // a test plan.
        $plan->is_test                 = true;
        $plan->gateway_subscription_id = 'sub_test_state_' . bin2hex(random_bytes(4));
        $plan->gateway_customer_id     = 'cus_test_state';
        $plan->amount_cents            = 2500;
        $plan->currency                = 'USD';
        $plan->interval_unit           = 'month';
        $plan->interval_count          = 1;
        $plan->status                  = 'active';
        $plan->started_at              = '2026-01-01 00:00:00';
        $plan->next_payment_at         = '2026-02-01 00:00:00';
        $plan->payments_count          = 1;
        $plan->total_paid_cents        = 2500;
        $plan->created_at              = '2026-01-01 00:00:00';
        $plan->updated_at              = '2026-01-01 00:00:00';
        $plan->save();

        return $plan;
    }

    /**
     * The status is returned and asserted because most of these tests assert
     * that nothing moved: a 404 from an unregistered gateway or a renamed route
     * would satisfy them forever.
     *
     * @param array<string,mixed> $object
     */
    private function postWebhook(string $type, array $object): int
    {
        $event = [
            'id'   => 'evt_' . bin2hex(random_bytes(6)),
            'type' => $type,
            'data' => ['object' => $object],
        ];

        $payload   = (string) wp_json_encode($event);
        $timestamp = (string) time();
        $sig       = hash_hmac('sha256', "{$timestamp}.{$payload}", $this->secret);

        $req = new WP_REST_Request('POST', '/dono/v1/webhooks/stripe');
        $req->set_header('content-type', 'application/json');
        $req->set_header('stripe_signature', "t={$timestamp},v1={$sig}");
        $req->set_body($payload);

        return rest_do_request($req)->get_status();
    }
}
