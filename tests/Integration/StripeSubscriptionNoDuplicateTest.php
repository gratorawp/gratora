<?php

declare(strict_types=1);

namespace FundKit\Tests\Integration;

use FundKit\Donations\Donation;
use FundKit\Donations\DonationRepository;
use FundKit\Donations\DonationService;
use FundKit\Donors\DonorRepository;
use FundKit\Donors\DonorService;
use FundKit\Foundation\Plugin;
use FundKit\Foundation\Time\Clock;
use FundKit\Gateways\GatewayManager;
use FundKit\Gateways\Stripe\StripeAccount;
use FundKit\Gateways\Stripe\StripeApi;
use FundKit\Gateways\Stripe\StripeGateway;
use FundKit\Recurring\RecurringPlanRepository;

/**
 * The only defence against opening a second live subscription was Stripe's
 * idempotency key, which expires after 24 hours. Both ways back into creation
 * outlive that: Stripe redelivers a failed webhook for three days, and the
 * admin retry is offered for ninety.
 *
 * So a response lost on the way back leaves a subscription billing at Stripe
 * with no plan row here, and the retry opens a second one. The donor pays twice
 * every month, and the first is invisible: its renewals find no plan, the
 * webhook answers 200 so Stripe stops mentioning it, and no cancel path in the
 * product can reach a subscription with no row.
 */
final class StripeSubscriptionNoDuplicateTest extends IntegrationTestCase
{
    private const CUSTOMER = 'cus_dup';
    private const EXISTING = 'sub_already_billing';

    /** @var list<array{method:string,url:string,body:string}> */
    private array $calls = [];

    /** Subscriptions the fake Stripe account already holds. */
    private array $remoteSubscriptions = [];

    protected function setUp(): void
    {
        parent::setUp();

        update_option('fundkit_gateway_config', [
            'test_mode' => true,
            'stripe'    => ['webhook_secret_test' => 'whsec_dup'],
        ]);
        update_option('fundkit_currency_locale', [
            'default_currency'     => 'USD',
            'supported_currencies' => ['USD'],
        ]);

        $c       = Plugin::instance()->container;
        $account = $c->get(StripeAccount::class);
        $account->saveKeys(true, 'sk_test_dup', 'pk_test_dup');
        $account->refresh(['id' => 'acct_dup', 'charges_enabled' => true]);

        $manager = $c->get(GatewayManager::class);
        if (! $manager->get('stripe')) {
            $manager->register(new StripeGateway(
                $c->get(StripeApi::class),
                $c->get(DonationRepository::class),
                $c->get(DonationService::class),
                $account,
                $c->get(DonorRepository::class),
                $c->get(DonorService::class),
                $c->get(Clock::class),
                $c->get(RecurringPlanRepository::class),
            ));
        }

        add_filter('pre_http_request', function ($pre, $args, $url) {
            if (! is_string($url) || ! str_contains($url, 'stripe.com')) return $pre;

            $method = strtoupper((string) ($args['method'] ?? 'GET'));
            $this->calls[] = ['method' => $method, 'url' => $url, 'body' => (string) ($args['body'] ?? '')];

            $body = ['id' => 'obj_dup'];

            if (str_contains($url, '/subscriptions?')) {
                $body = ['object' => 'list', 'data' => $this->remoteSubscriptions];
            } elseif (str_contains($url, '/payment_intents/')) {
                $body = [
                    'id'             => 'pi_dup',
                    'status'         => 'succeeded',
                    'customer'       => self::CUSTOMER,
                    'payment_method' => 'pm_dup',
                ];
            } elseif (str_contains($url, '/customers')) {
                $body = ['id' => self::CUSTOMER];
            } elseif (str_contains($url, '/prices')) {
                $body = ['id' => 'price_dup'];
            } elseif (str_contains($url, '/subscriptions')) {
                // A create. Whatever Stripe would mint, it is a NEW one.
                $body = ['id' => 'sub_second_' . count($this->calls), 'status' => 'active'];
            } elseif (str_contains($url, '/products')) {
                $body = ['id' => 'prod_dup'];
            }

            return [
                'headers'  => [],
                'body'     => (string) wp_json_encode($body),
                'response' => ['code' => 200, 'message' => 'OK'],
                'cookies'  => [], 'filename' => null,
            ];
        }, 10, 3);
    }

    private function gateway(): StripeGateway
    {
        $g = Plugin::instance()->container->get(GatewayManager::class)->get('stripe');
        $this->assertInstanceOf(StripeGateway::class, $g);

        return $g;
    }

    /** A donation whose subscription creation failed after Stripe had acted. */
    private function orphanedDonation(): Donation
    {
        $now = gmdate('Y-m-d H:i:s');

        $d = Donation::make();
        $d->reference          = 'DUP-' . uniqid();
        $d->donor_id           = 7777;
        $d->amount_cents       = 2500;
        $d->base_amount_cents  = 2500;
        $d->currency           = 'USD';
        $d->base_currency      = 'USD';
        $d->status             = 'paid';
        $d->gateway            = 'stripe';
        $d->gateway_intent_id  = 'pi_dup';
        $d->frequency          = 'monthly';
        $d->kind               = 'donation';
        $d->is_test            = true;
        $d->recurring_plan_id  = null;
        $d->paid_at            = $now;
        $d->created_at         = $now;
        $d->updated_at         = $now;
        $d->save();

        return $d;
    }

    /** @return list<array{method:string,url:string,body:string}> */
    private function subscriptionCreates(): array
    {
        return array_values(array_filter(
            $this->calls,
            static fn (array $c): bool => $c['method'] === 'POST'
                && str_contains($c['url'], '/subscriptions')
                && ! str_contains($c['url'], '/subscriptions?')
        ));
    }

    public function test_a_retry_adopts_the_subscription_stripe_already_opened(): void
    {
        $donation = $this->orphanedDonation();

        // What a lost response leaves behind: live at Stripe, unknown here.
        $this->remoteSubscriptions = [[
            'id'       => self::EXISTING,
            'status'   => 'active',
            'metadata' => ['fundkit_initial_donation_id' => (string) $donation->id],
        ]];

        $plan = $this->gateway()->retrySubscriptionCreation($donation);

        $this->assertSame(
            [],
            $this->subscriptionCreates(),
            'a second subscription was opened while the first was still billing the donor'
        );
        $this->assertSame(self::EXISTING, (string) $plan->gateway_subscription_id, 'the retry adopts it');
    }

    public function test_a_retry_with_nothing_at_stripe_still_creates_one(): void
    {
        $this->remoteSubscriptions = [];

        $plan = $this->gateway()->retrySubscriptionCreation($this->orphanedDonation());

        $this->assertCount(1, $this->subscriptionCreates(), 'the guard must not block a genuine first creation');
        $this->assertStringStartsWith('sub_second_', (string) $plan->gateway_subscription_id);
    }

    public function test_another_donation_subscription_on_the_same_customer_is_not_adopted(): void
    {
        $donation = $this->orphanedDonation();

        // The same donor's other monthly gift. Same customer, different donation.
        $this->remoteSubscriptions = [[
            'id'       => 'sub_someone_elses_gift',
            'status'   => 'active',
            'metadata' => ['fundkit_initial_donation_id' => (string) ((int) $donation->id + 1000)],
        ]];

        $plan = $this->gateway()->retrySubscriptionCreation($donation);

        $this->assertCount(1, $this->subscriptionCreates(), 'this donation has no subscription yet');
        $this->assertNotSame('sub_someone_elses_gift', (string) $plan->gateway_subscription_id);
    }

    public function test_the_lookup_asks_before_it_creates(): void
    {
        $this->remoteSubscriptions = [];
        $this->gateway()->retrySubscriptionCreation($this->orphanedDonation());

        $listAt   = null;
        $createAt = null;
        foreach ($this->calls as $i => $call) {
            if ($listAt === null && str_contains($call['url'], '/subscriptions?')) $listAt = $i;
            if ($createAt === null && $call['method'] === 'POST' && str_contains($call['url'], '/subscriptions') && ! str_contains($call['url'], '/subscriptions?')) $createAt = $i;
        }

        $this->assertNotNull($listAt, 'the customer was never asked what it already has');
        $this->assertNotNull($createAt);
        $this->assertLessThan($createAt, $listAt, 'asking after creating answers nothing');
    }
}
