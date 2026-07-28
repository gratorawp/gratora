<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donations\Donation;
use Dono\Donations\DonationRepository;
use Dono\Foundation\Plugin;
use Dono\Gateways\Stripe\StripeAccount;
use Dono\Recurring\RecurringPlan;
use WP_REST_Request;

/**
 * When the first PaymentIntent for a recurring donation succeeds but the
 * follow-on Customer/Subscription chain fails, the donation must be flagged
 * (so admins see + retry) and the admin retry endpoint must reconvert cleanly.
 */
final class SubscriptionRetryTest extends IntegrationTestCase
{
    /** @var list<array{method:string,url:string,body:?string}> */
    private array $stripeCalls = [];

    /** First-pass: the Stripe Subscription create call returns 500. Customer + PI succeed. */
    private bool $failSubscriptionOnce = true;

    protected function setUp(): void
    {
        parent::setUp();

        update_option('dono_gateway_config', [
            'test_mode' => true,
            'stripe'    => ['webhook_secret' => 'whsec_retry'],
        ]);

        $c = Plugin::instance()->container;
        $stripeAcct = $c->get(StripeAccount::class);
        $stripeAcct->saveKeys(true, 'sk_test_retry', 'pk_test_seed');
        $stripeAcct->saveKeys(false, 'sk_live_retry', 'pk_live_seed');
        $stripeAcct->refresh(['id' => 'acct_retry', 'charges_enabled' => true]);

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

        $this->mockStripeApi();
    }

    public function test_failure_flags_donation_and_retry_creates_plan(): void
    {
        // 1. Create a recurring donation. The Customer chain fails on first pass.
        $reference = $this->createMonthlyDonation();
        $repo      = Plugin::instance()->container->get(DonationRepository::class);
        $donation  = $repo->findByReference($reference);

        // 2. Webhook flips the donation to paid; subscription creation throws,
        //    which our handler catches and records as a failure.
        $this->postWebhook('payment_intent.succeeded', [
            'id'                  => $donation->gateway_intent_id,
            'status'              => 'succeeded',
            'customer'            => 'cus_retry',
            'payment_method'      => 'pm_retry',
            'payment_method_types'=> ['card'],
            'latest_charge'       => 'ch_retry',
        ]);

        $afterFailure = $repo->findByReference($reference);
        $this->assertSame('paid', $afterFailure->status, 'First charge still landed');
        $this->assertNull($afterFailure->recurring_plan_id, 'No plan yet');
        $this->assertNotEmpty(
            $afterFailure->flags['subscription_creation_failed'] ?? null,
            'subscription_creation_failed flag set'
        );
        $this->assertNotEmpty($afterFailure->flags['subscription_creation_failed_reason'] ?? null);
        $this->assertNotEmpty($afterFailure->flags['subscription_creation_failed_at'] ?? null);

        // 3. Admin hits the retry endpoint. Stripe is healthy on the second pass.
        $this->failSubscriptionOnce = false;
        $this->stripeCalls          = [];

        $req = new WP_REST_Request('POST', "/dono/v1/admin/donations/{$reference}/retry-subscription");
        $req->set_header('content-type', 'application/json');
        $req->set_body('{}');
        $res = $this->asAdmin(fn () => rest_do_request($req));

        $this->assertSame(200, $res->get_status(), 'Retry succeeds: ' . wp_json_encode($res->get_data()));
        $payload = $res->get_data();
        $this->assertTrue($payload['retried']);
        $this->assertGreaterThan(0, $payload['plan_id']);

        // 4. Plan exists, donation links to it, flags cleared.
        $fresh = $repo->findByReference($reference);
        $this->assertSame((int) $payload['plan_id'], (int) $fresh->recurring_plan_id);
        $this->assertEmpty(
            $fresh->flags['subscription_creation_failed'] ?? null,
            'failure flags cleared after retry'
        );

        $plan = RecurringPlan::query()->find('id', (int) $fresh->recurring_plan_id);
        $this->assertNotNull($plan);
        $this->assertSame('active', $plan->status);
    }

    public function test_retry_returns_422_when_no_failure_recorded(): void
    {
        $reference = $this->createMonthlyDonation();

        $req = new WP_REST_Request('POST', "/dono/v1/admin/donations/{$reference}/retry-subscription");
        $req->set_body('{}');
        $res = $this->asAdmin(fn () => rest_do_request($req));

        $this->assertSame(422, $res->get_status());
        $data = $res->get_data();
        $this->assertSame('dono_no_retry_needed', $data['code']);
    }

    private function createMonthlyDonation(): string
    {
        $req = new WP_REST_Request('POST', '/dono/v1/donations');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode([
            'email'        => 'retry+' . bin2hex(random_bytes(3)) . '@example.com',
            'amount_cents' => 2500,
            'currency'     => 'USD',
            'gateway'      => 'stripe',
            'frequency'    => 'monthly',
            'profile'      => ['first_name' => 'Retry', 'last_name' => 'Donor'],
        ]));
        $res = rest_do_request($req);
        $data = $res->get_data();
        if (! isset($data['reference'])) {
            $this->fail('Donation creation failed: ' . wp_json_encode($data));
        }
        return $data['reference'];
    }

    private function postWebhook(string $type, array $object): void
    {
        $secret = 'whsec_retry';
        $event  = [
            'id'   => 'evt_' . bin2hex(random_bytes(6)),
            'type' => $type,
            'data' => ['object' => $object],
        ];
        $payload   = (string) wp_json_encode($event);
        $timestamp = (string) time();
        $sig       = hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);

        $req = new WP_REST_Request('POST', '/dono/v1/webhooks/stripe');
        $req->set_header('content-type', 'application/json');
        $req->set_header('stripe_signature', "t={$timestamp},v1={$sig}");
        $req->set_body($payload);
        rest_do_request($req);
    }

    private function mockStripeApi(): void
    {
        $self = $this;
        add_filter('pre_http_request', static function ($pre, $args, $url) use ($self) {
            if (! is_string($url) || ! str_starts_with($url, 'https://api.stripe.com/')) return $pre;

            $path = (string) (parse_url($url)['path'] ?? '');
            $self->stripeCalls[] = [
                'method' => (string) ($args['method'] ?? 'POST'),
                'url'    => $url,
                'body'   => (string) ($args['body'] ?? ''),
            ];

            // First-pass failure: POST /v1/subscriptions errors so the chain
            // (Customer + PI succeed first, then subscription creation throws
            // during the webhook). That's the exact scenario we want to
            // recover from via the retry endpoint.
            if ($self->failSubscriptionOnce && $path === '/v1/subscriptions') {
                return [
                    'headers'  => [],
                    'body'     => (string) wp_json_encode(['error' => ['message' => 'simulated subscription failure']]),
                    'response' => ['code' => 500, 'message' => 'Server Error'],
                    'cookies'  => [],
                    'filename' => null,
                ];
            }

            return [
                'headers'  => [],
                'body'     => (string) wp_json_encode($self->cannedResponseFor($path)),
                'response' => ['code' => 200, 'message' => 'OK'],
                'cookies'  => [],
                'filename' => null,
            ];
        }, 10, 3);
    }

    private function cannedResponseFor(string $path): array
    {
        return match (true) {
            $path === '/v1/customers'                       => ['id' => 'cus_retry'],
            str_starts_with($path, '/v1/customers/')        => ['id' => 'cus_retry'],
            $path === '/v1/payment_intents'                 => [
                'id'             => 'pi_retry',
                'client_secret'  => 'pi_retry_secret',
                'status'         => 'requires_confirmation',
                'customer'       => 'cus_retry',
            ],
            str_starts_with($path, '/v1/payment_intents/')  => [
                'id'             => 'pi_retry',
                'status'         => 'succeeded',
                'customer'       => 'cus_retry',
                'payment_method' => 'pm_retry',
                'latest_charge'  => 'ch_retry',
            ],
            $path === '/v1/products'                        => ['id' => 'prod_retry'],
            $path === '/v1/prices'                          => ['id' => 'price_retry'],
            $path === '/v1/subscriptions'                   => ['id' => 'sub_retry', 'status' => 'active'],
            default                                         => ['id' => 'unknown'],
        };
    }

    private function asAdmin(callable $fn)
    {
        $user = $this->factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($user);
        try {
            return $fn();
        } finally {
            wp_set_current_user(1);
        }
    }
}
