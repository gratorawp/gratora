<?php

declare(strict_types=1);

namespace FundKit\Tests\Integration;

use FundKit\Donations\DonationRepository;
use FundKit\Donations\DonationService;
use FundKit\Donors\DonorRepository;
use FundKit\Donors\DonorService;
use FundKit\Foundation\Plugin;
use FundKit\Foundation\Time\Clock;
use FundKit\Gateways\Stripe\StripeAccount;
use FundKit\Gateways\Stripe\StripeApi;
use FundKit\Gateways\Stripe\StripeGateway;
use FundKit\Recurring\RecurringPlan;
use FundKit\Recurring\RecurringPlanRepository;

/**
 * gateway_subscription_id is NOT NULL under a unique index, so a plan that
 * never reached Stripe cannot record that absence as an empty string: the Give
 * importer mints 'give-import-<id>' and the demo seeder 'demo-subNNN', and both
 * land on the Stripe gateway. Sending one to Stripe is a 404 the donor reads as
 * a failure, or worse, a collision with somebody else's subscription id.
 *
 * cancel already refused these. pause, resume, retry, the payment-method update
 * and the schedule change did not.
 */
final class ImportedPlanIdsAreNotSentTest extends IntegrationTestCase
{
    /** @var list<string> */
    private array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();

        $account = Plugin::instance()->container->get(StripeAccount::class);
        $account->forget();
        $account->saveKeys(false, 'sk_live_x', 'pk_live_x');
        $account->saveKeys(true, 'sk_test_x', 'pk_test_x');
        $account->refresh(['id' => 'acct_x', 'charges_enabled' => true]);

        $this->calls = [];
        add_filter('pre_http_request', function ($pre, $args, $url) {
            if (is_string($url) && str_contains($url, 'api.stripe.com')) {
                $this->calls[] = $url;

                return [
                    'headers'  => [],
                    'body'     => (string) wp_json_encode(['id' => 'sub_x', 'object' => 'subscription']),
                    'response' => ['code' => 200, 'message' => 'OK'],
                    'cookies'  => [], 'filename' => null,
                ];
            }

            return $pre;
        }, 10, 3);
    }

    protected function tearDown(): void
    {
        remove_all_filters('pre_http_request');
        parent::tearDown();
    }

    private function gateway(): StripeGateway
    {
        $c = Plugin::instance()->container;

        return new StripeGateway(
            $c->get(StripeApi::class),
            $c->get(DonationRepository::class),
            $c->get(DonationService::class),
            $c->get(StripeAccount::class),
            $c->get(DonorRepository::class),
            $c->get(DonorService::class),
            $c->get(Clock::class),
            $c->get(RecurringPlanRepository::class),
        );
    }

    private function importedPlan(): RecurringPlan
    {
        $now = gmdate('Y-m-d H:i:s');

        $p = RecurringPlan::make();
        $p->donor_id                = 1;
        $p->gateway                 = 'stripe';
        // What the Give importer mints for a plan it could not link.
        $p->gateway_subscription_id = 'give-import-4821';
        $p->amount_cents            = 2500;
        $p->currency                = 'USD';
        $p->interval_unit           = 'month';
        $p->interval_count          = 1;
        $p->status                  = 'active';
        $p->is_test                 = false;
        $p->started_at              = $now;
        $p->next_payment_at         = gmdate('Y-m-d H:i:s', time() + 86400);
        $p->created_at              = $now;
        $p->updated_at              = $now;
        $p->save();

        return $p;
    }

    public function test_pause_sends_nothing_to_stripe(): void
    {
        $this->gateway()->pauseSubscription($this->importedPlan(), gmdate('Y-m-d H:i:s', time() + 864000));

        $this->assertSame([], $this->calls, 'an id Stripe never issued is not a subscription to pause');
    }

    public function test_resume_sends_nothing_to_stripe(): void
    {
        $this->gateway()->resumeSubscription($this->importedPlan());

        $this->assertSame([], $this->calls);
    }

    public function test_a_schedule_change_answers_unknown_rather_than_calling(): void
    {
        $out = $this->gateway()->updateSubscriptionSchedule($this->importedPlan(), 5000, 'month', 1);

        $this->assertSame([], $this->calls);
        $this->assertNull($out->nextPaymentAt, 'nothing at Stripe to read a new schedule from');
    }

    public function test_a_retry_says_there_is_nothing_to_collect(): void
    {
        $this->expectException(\FundKit\Gateways\PaymentRetryUnavailable::class);

        try {
            $this->gateway()->retryPayment($this->importedPlan());
        } finally {
            $this->assertSame([], $this->calls);
        }
    }

    /** A real Stripe id still reaches Stripe. */
    public function test_a_real_subscription_is_still_paused_at_stripe(): void
    {
        $plan = $this->importedPlan();
        $plan->gateway_subscription_id = 'sub_real_one';
        $plan->save();

        $this->gateway()->pauseSubscription($plan, gmdate('Y-m-d H:i:s', time() + 864000));

        $this->assertNotSame([], $this->calls);
    }

    /**
     * The card-update flow reads the customer off the subscription when the
     * import did not carry one. Against an id Stripe never issued that read can
     * only answer resource_missing, which is indistinguishable from a key
     * rotated to another account: the donor is told to try again forever.
     */
    public function test_starting_a_card_update_sends_nothing_to_stripe(): void
    {
        $this->expectException(\RuntimeException::class);

        try {
            $this->gateway()->startPaymentMethodUpdate($this->importedPlan());
        } finally {
            $this->assertSame([], $this->calls, 'an id Stripe never issued is not a subscription to read a customer off');
        }
    }

    /** A real subscription still gets its customer read back off Stripe. */
    public function test_a_real_subscription_still_reads_its_customer(): void
    {
        $plan = $this->importedPlan();
        $plan->gateway_subscription_id = 'sub_real_two';
        $plan->save();

        try {
            $this->gateway()->startPaymentMethodUpdate($plan);
        } catch (\Throwable $e) {
            // The stub answers no customer, which is not what is under test.
        }

        $this->assertNotSame([], $this->calls);
    }
}
