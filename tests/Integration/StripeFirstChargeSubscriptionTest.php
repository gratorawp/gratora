<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donations\Donation;
use Dono\Donations\DonationRepository;
use Dono\Foundation\Crypto\Crypto;
use Dono\Foundation\Plugin;
use Dono\Gateways\Stripe\StripeAccount;
use Dono\Recurring\RecurringPlan;
use WP_REST_Request;

/**
 * End-to-end: a donor picks `frequency=monthly` on the form, a Stripe Customer
 * is attached to the PaymentIntent, and once the PI succeeds the gateway hands
 * off to a real Stripe Subscription so future renewals are gateway-driven.
 *
 * All Stripe HTTP calls are intercepted via the `pre_http_request` filter so
 * the test never touches the real Stripe API.
 */
final class StripeFirstChargeSubscriptionTest extends IntegrationTestCase
{
    private string $secret;

    /** @var array<int,array{method:string,url:string,body:?string}> */
    private array $stripeCalls = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->secret = 'whsec_test_' . bin2hex(random_bytes(8));
        update_option('dono_gateway_config', [
            'test_mode' => true, // org-wide kill switch so the donation is stamped is_test
            'stripe'    => ['webhook_secret_test' => $this->secret],
        ]);

        $c = Plugin::instance()->container;

        // Use the DI-resolved Crypto so encrypted tokens decrypt back through
        // the same key the gateway will use later.
        $stripeAcct = $c->get(StripeAccount::class);
        $stripeAcct->saveKeys(true, 'sk_test_connected', 'pk_test_seed');
        $stripeAcct->saveKeys(false, 'sk_live_connected', 'pk_live_seed');
        $stripeAcct->refresh(['id' => 'acct_test_123', 'charges_enabled' => true]);

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

    public function test_recurring_donation_attaches_customer_and_creates_subscription(): void
    {
        // 1. Donor creates a monthly donation through the public REST endpoint.
        $reference = $this->createMonthlyDonation();

        $this->assertContains('POST /v1/customers', array_map(
            fn ($c) => $c['method'] . ' ' . $this->stripPath($c['url']),
            $this->stripeCalls
        ), 'Customer is created for the recurring donation');

        $piCall = $this->findStripeCall('/v1/payment_intents');
        $this->assertNotNull($piCall, 'PaymentIntent is created');
        $piBody = $this->parseForm($piCall['body']);
        $this->assertSame('cus_test_seed', $piBody['customer'], 'PI carries the new Customer');
        $this->assertSame('off_session', $piBody['setup_future_usage'], 'setup_future_usage is set for off-session renewals');

        // 2. Stripe posts payment_intent.succeeded. The webhook is signed and
        //    dispatched through the same route Stripe would hit.
        $repo = Plugin::instance()->container->get(DonationRepository::class);
        $donation = $repo->findByReference($reference);
        $this->assertNotNull($donation);

        $this->stripeCalls = []; // reset to assert only the webhook-side calls below

        // Capture exceptions thrown during subscription creation so test output
        // is informative when the chain breaks (production swallows them).
        $caught = null;
        add_action('dono.donation.completed', function () use (&$caught): void {
            // no-op; just to prove confirm() ran.
        });

        $res = $this->postWebhook('payment_intent.succeeded', [
            'id'                  => $donation->gateway_intent_id,
            'status'              => 'succeeded',
            'customer'            => 'cus_test_seed',
            'payment_method'      => 'pm_test_card',
            'payment_method_types'=> ['card'],
            'latest_charge'       => 'ch_test_first',
            'amount_received'     => 2500,
            'currency'            => 'usd',
        ]);
        $this->assertSame(200, $res->get_status(), 'Webhook accepted: ' . wp_json_encode($res->get_data()));

        $fresh0 = Plugin::instance()->container->get(DonationRepository::class)
            ->findByReference($reference);
        $this->assertSame('paid', $fresh0->status, 'Donation was confirmed by webhook');
        $this->assertSame('monthly', $fresh0->frequency);

        // 3. Subscription chain: set default PM, create product (cached after first
        //    run), create price, create subscription.
        $this->assertNotNull($this->findStripeCall('/v1/customers/cus_test_seed'), 'Customer default PM is set');
        $this->assertNotNull($this->findStripeCall('/v1/products'), 'Donation product is provisioned');
        $this->assertNotNull($this->findStripeCall('/v1/prices'), 'Price for amount + interval is created');
        $subCall = $this->findStripeCall('/v1/subscriptions');
        $this->assertNotNull($subCall, 'Subscription is created');

        $subBody = $this->parseForm($subCall['body']);
        $this->assertSame('cus_test_seed', $subBody['customer']);
        $this->assertSame('pm_test_card', $subBody['default_payment_method']);
        $this->assertSame('none', $subBody['proration_behavior']);
        $this->assertArrayHasKey('billing_cycle_anchor', $subBody, 'Future anchor prevents same-day double-charge');
        $this->assertArrayNotHasKey('application_fee_percent', $subBody, 'Renewals settle in full to the organization, no platform fee');

        // 4. Local mirror exists.
        $fresh = $repo->findByReference($reference);
        $this->assertNotNull($fresh->recurring_plan_id, 'Donation links back to the plan');

        $plan = RecurringPlan::query()->find('id', (int) $fresh->recurring_plan_id);
        $this->assertNotNull($plan);
        $this->assertSame('sub_test_seed', $plan->gateway_subscription_id);
        $this->assertSame('cus_test_seed', $plan->gateway_customer_id);
        $this->assertSame('month', $plan->interval_unit);
        $this->assertSame(1, (int) $plan->interval_count);
        $this->assertSame(1, (int) $plan->payments_count, 'First charge counts toward payments_count');
        $this->assertSame('active', $plan->status);
    }

    private function createMonthlyDonation(): string
    {
        $req = new WP_REST_Request('POST', '/dono/v1/donations');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode([
            'email'        => 'monthly@example.com',
            'amount_cents' => 2500,
            'currency'     => 'USD',
            'gateway'      => 'stripe',
            'frequency'    => 'monthly',
            'profile'      => ['first_name' => 'Monthly', 'last_name' => 'Donor'],
        ]));
        $res = rest_do_request($req);
        $data = $res->get_data();
        if (! isset($data['reference'])) {
            $this->fail('Donation creation failed: ' . wp_json_encode($data));
        }
        return $data['reference'];
    }

    private function postWebhook(string $type, array $object): \WP_REST_Response
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
        return rest_do_request($req);
    }

    /**
     * Intercept every wp_remote_request to api.stripe.com and return canned
     * responses keyed on URL path. Records each call for later assertion.
     */
    private function mockStripeApi(): void
    {
        add_filter('pre_http_request', function ($pre, $args, $url) {
            if (! str_starts_with((string) $url, 'https://api.stripe.com/')) return $pre;

            $body   = (string) ($args['body'] ?? '');
            $method = (string) ($args['method'] ?? 'POST');
            $path   = $this->stripPath((string) $url);

            $this->stripeCalls[] = [
                'method' => $method,
                'url'    => (string) $url,
                'body'   => $body,
            ];

            $response = $this->cannedResponseFor($path);
            return [
                'headers'  => [],
                'body'     => (string) wp_json_encode($response),
                'response' => ['code' => 200, 'message' => 'OK'],
                'cookies'  => [],
                'filename' => null,
            ];
        }, 10, 3);
    }

    private function cannedResponseFor(string $path): array
    {
        return match (true) {
            $path === '/v1/customers' => [
                'id'    => 'cus_test_seed',
                'email' => 'monthly@example.com',
            ],
            str_starts_with($path, '/v1/customers/') => [
                'id' => 'cus_test_seed',
                'invoice_settings' => ['default_payment_method' => 'pm_test_card'],
            ],
            $path === '/v1/payment_intents' => [
                'id'             => 'pi_test_seed',
                'client_secret'  => 'pi_test_seed_secret_xxx',
                'status'         => 'requires_confirmation',
                'livemode'       => false,
            ],
            $path === '/v1/products' => ['id' => 'prod_test_seed'],
            $path === '/v1/prices'   => ['id' => 'price_test_seed'],
            $path === '/v1/subscriptions' => [
                'id'     => 'sub_test_seed',
                'status' => 'active',
            ],
            default => ['id' => 'unknown'],
        };
    }

    private function stripPath(string $url): string
    {
        $parts = parse_url($url);
        return (string) ($parts['path'] ?? '');
    }

    private function findStripeCall(string $pathPrefix): ?array
    {
        foreach ($this->stripeCalls as $c) {
            if (str_starts_with($this->stripPath($c['url']), $pathPrefix)) return $c;
        }
        return null;
    }

    private function parseForm(?string $body): array
    {
        if ($body === null || $body === '') return [];
        parse_str($body, $out);
        return $out;
    }
}
