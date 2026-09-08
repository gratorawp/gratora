<?php

declare(strict_types=1);

namespace FundKit\Tests\Integration;

use FundKit\Foundation\Plugin;
use FundKit\Gateways\Stripe\StripeAccount;
use FundKit\Recurring\RecurringCanceller;
use FundKit\Recurring\RecurringPlan;
use RuntimeException;

/**
 * A cancellation Stripe never confirmed must not be reported as one.
 *
 * `No such subscription` proves only that the stored key cannot see the
 * subscription, which is what an org gets after rotating its keys to a
 * different Stripe account. Read as "already cancelled" it flips the plan to
 * cancelled and emails the donor a confirmation while the card keeps being
 * charged, with no local plan left to explain the charges. The only thing that
 * may excuse a failed DELETE is Stripe's own reading of the subscription.
 */
final class StripeCancelConfirmationTest extends IntegrationTestCase
{
    /** Status the stubbed GET /subscriptions/{id} reports, or null for a 404. */
    private ?string $remoteStatus = null;

    /** @var list<string> "METHOD path" the stub was asked for, in order */
    private array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();

        update_option('fundkit_gateway_config', [
            'test_mode' => true,
            'stripe'    => ['webhook_secret_test' => 'whsec_cancel'],
        ]);

        $c    = Plugin::instance()->container;
        $acct = $c->get(StripeAccount::class);
        $acct->saveKeys(true, 'sk_test_cancel', 'pk_test_seed');
        $acct->refresh(['id' => 'acct_cancel', 'charges_enabled' => true]);

        $manager = $c->get(\FundKit\Gateways\GatewayManager::class);
        if (! $manager->get('stripe')) {
            $manager->register(new \FundKit\Gateways\Stripe\StripeGateway(
                $c->get(\FundKit\Gateways\Stripe\StripeApi::class),
                $c->get(\FundKit\Donations\DonationRepository::class),
                $c->get(\FundKit\Donations\DonationService::class),
                $c->get(\FundKit\Gateways\Stripe\StripeAccount::class),
                $c->get(\FundKit\Donors\DonorRepository::class),
                $c->get(\FundKit\Donors\DonorService::class),
                $c->get(\FundKit\Foundation\Time\Clock::class),
                $c->get(\FundKit\Recurring\RecurringPlanRepository::class),
            ));
        }

        $this->stubStripe();
    }

    public function test_a_subscription_this_key_cannot_see_does_not_count_as_cancelled(): void
    {
        $plan  = $this->seedPlan();
        $mails = $this->captureMails();

        // What a key rotated to a different Stripe account gets back: the
        // subscription is invisible to it, and still billing on the old one.
        $this->remoteStatus = null;

        $threw = false;
        try {
            Plugin::instance()->container->get(RecurringCanceller::class)->cancel($plan, 'donor asked');
        } catch (RuntimeException $e) {
            $threw = true;
            $this->assertStringContainsString('No such subscription', $e->getMessage());
        }

        $this->assertTrue($threw, 'a cancel Stripe never confirmed must reach the caller');

        $fresh = RecurringPlan::query()->find('id', (int) $plan->id);
        $this->assertSame('active', (string) $fresh->status, 'the plan is still billing, so it must still read active');

        $cancellationMails = array_filter(
            iterator_to_array($mails),
            static fn ($m) => stripos((string) ($m['subject'] ?? ''), 'cancel') !== false,
        );
        $this->assertSame([], $cancellationMails, 'no donor is told a subscription stopped that did not');
    }

    /**
     * Imported and demo plans use placeholder subscription IDs to satisfy uniqueness. Do not
     * send them to Stripe: resource_missing cannot distinguish placeholders from account
     * changes.
     */
    public function test_an_id_stripe_never_issued_cancels_locally(): void
    {
        $plan = $this->seedPlan('give-import-42');

        // Stripe would answer resource_missing, as it does for any unknown id.
        $this->remoteStatus = null;

        $won = Plugin::instance()->container->get(RecurringCanceller::class)->cancel($plan, 'donor asked');

        $this->assertTrue($won, 'nothing is billing at Stripe, so the local cancel is the whole of it');
        $this->assertSame('cancelled', (string) RecurringPlan::query()->find('id', (int) $plan->id)->status);
        $this->assertSame([], $this->calls, 'and Stripe was never asked about an id it never issued');
    }

    public function test_a_seeded_placeholder_cancels_locally_too(): void
    {
        $plan = $this->seedPlan('demo-sub001');
        $this->remoteStatus = null;

        $this->assertTrue(
            Plugin::instance()->container->get(RecurringCanceller::class)->cancel($plan, 'tidying up'),
            'a demo plan is cancellable like any other'
        );
    }

    public function test_a_subscription_stripe_reports_cancelled_is_accepted(): void
    {
        $plan = $this->seedPlan();

        // The benign case the swallow was written for: the delete fails because
        // there is nothing left to delete, and Stripe says so.
        $this->remoteStatus = 'canceled';

        $won = Plugin::instance()->container->get(RecurringCanceller::class)->cancel($plan, 'donor asked');

        $this->assertTrue($won, 'a confirmed terminal subscription still cancels locally');

        $fresh = RecurringPlan::query()->find('id', (int) $plan->id);
        $this->assertSame('cancelled', (string) $fresh->status);
        $this->assertContains(
            'GET /v1/subscriptions/' . $plan->gateway_subscription_id,
            $this->calls,
            'the state was read rather than inferred from the error text'
        );
    }

    public function test_a_subscription_stripe_still_reports_active_is_not_swallowed(): void
    {
        $plan = $this->seedPlan();

        $this->remoteStatus = 'active';

        $this->expectException(RuntimeException::class);

        try {
            Plugin::instance()->container->get(RecurringCanceller::class)->cancel($plan, 'donor asked');
        } finally {
            $fresh = RecurringPlan::query()->find('id', (int) $plan->id);
            $this->assertSame('active', (string) $fresh->status);
        }
    }

    private function seedPlan(?string $subscriptionId = null): RecurringPlan
    {
        $donor = Plugin::instance()->container
            ->get(\FundKit\Donors\DonorService::class)
            ->findOrCreate('cancel-' . uniqid() . '@example.test', [
                'first_name' => 'Cancel',
                'last_name'  => 'Donor',
            ]);

        $plan = RecurringPlan::make();
        $plan->donor_id                = (int) $donor->id;
        $plan->gateway                 = 'stripe';
        $plan->is_test                 = true;
        $plan->gateway_subscription_id = $subscriptionId ?? 'sub_test_cancel_' . bin2hex(random_bytes(4));
        $plan->gateway_customer_id     = 'cus_test_cancel';
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
     * DELETE always fails the way Stripe fails it for an id the key cannot
     * resolve; GET answers with whatever the test says the real state is.
     */
    private function stubStripe(): void
    {
        $self = $this;
        add_filter('pre_http_request', static function ($pre, $args, $url) use ($self) {
            if (! is_string($url) || ! str_starts_with($url, 'https://api.stripe.com/')) {
                return $pre;
            }

            $path   = (string) (parse_url($url)['path'] ?? '');
            $method = strtoupper((string) ($args['method'] ?? 'POST'));
            $self->calls[] = $method . ' ' . $path;

            if ($method === 'DELETE' || $self->remoteStatus === null) {
                return [
                    'headers'  => [],
                    'body'     => (string) wp_json_encode([
                        'error' => [
                            'code'    => 'resource_missing',
                            'message' => 'No such subscription: ' . basename($path),
                        ],
                    ]),
                    'response' => ['code' => 404, 'message' => 'Not Found'],
                    'cookies'  => [],
                    'filename' => null,
                ];
            }

            return [
                'headers'  => [],
                'body'     => (string) wp_json_encode(['id' => basename($path), 'status' => $self->remoteStatus]),
                'response' => ['code' => 200, 'message' => 'OK'],
                'cookies'  => [],
                'filename' => null,
            ];
        }, 10, 3);
    }
}
