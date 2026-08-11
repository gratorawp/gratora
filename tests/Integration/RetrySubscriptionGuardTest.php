<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donations\Donation;
use Dono\Donations\DonationRepository;
use Dono\Donations\DonationService;
use Dono\Donors\DonorRepository;
use Dono\Donors\DonorService;
use Dono\Foundation\Plugin;
use Dono\Foundation\Time\Clock;
use Dono\Gateways\GatewayManager;
use Dono\Gateways\Stripe\StripeAccount;
use Dono\Gateways\Stripe\StripeApi;
use Dono\Gateways\Stripe\StripeGateway;
use Dono\Recurring\RecurringPlanRepository;
use WP_REST_Request;

/**
 * A recurring donation whose plan was never created is offered a retry, and the
 * retry starts a live schedule at the gateway. So the question the endpoint has
 * to answer is not "did creation fail" but "is this donation still money the org
 * holds". A refunded donor who is signed up to a weekly plan is worse than the
 * missing plan ever was.
 */
final class RetrySubscriptionGuardTest extends IntegrationTestCase
{
    /** @var array<int,string> */
    private array $stripeCalls = [];

    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        update_option('dono_gateway_config', [
            'test_mode' => true,
            'stripe'    => ['webhook_secret_test' => 'whsec_guard'],
        ]);
        update_option('dono_currency_locale', [
            'default_currency'     => 'USD',
            'supported_currencies' => ['USD'],
        ]);

        $c = Plugin::instance()->container;
        $account = $c->get(StripeAccount::class);
        $account->saveKeys(true, 'sk_test_guard', 'pk_test_guard');
        $account->refresh(['id' => 'acct_guard', 'charges_enabled' => true]);

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

        // Every call the retry chain makes answers successfully, so the ONLY
        // thing that can stop a refunded donation reaching /subscriptions is
        // the status gate. A mock that fails anywhere earlier would let the
        // test pass with the gate deleted.
        add_filter('pre_http_request', function ($pre, $args, $url) {
            if (! is_string($url) || ! str_contains($url, 'stripe.com')) return $pre;
            $this->stripeCalls[] = $url;

            $body = ['id' => 'obj_test'];

            if (str_contains($url, '/payment_intents/')) {
                $body = [
                    'id'             => 'pi_stranded',
                    'customer'       => 'cus_stranded',
                    'payment_method' => 'pm_stranded',
                    'status'         => 'succeeded',
                ];
            } elseif (str_contains($url, '/prices')) {
                $body = ['id' => 'price_stranded'];
            } elseif (str_contains($url, '/subscriptions')) {
                $body = [
                    'id'       => 'sub_stranded',
                    'status'   => 'active',
                    'customer' => 'cus_stranded',
                    'items'    => ['data' => [['price' => ['id' => 'price_stranded']]]],
                ];
            } elseif (str_contains($url, '/products')) {
                $body = ['id' => 'prod_stranded'];
            }

            return [
                'headers'  => [],
                'body'     => (string) wp_json_encode($body),
                'response' => ['code' => 200, 'message' => 'OK'],
                'cookies'  => [], 'filename' => null,
            ];
        }, 10, 3);
    }

    private function repo(): DonationRepository
    {
        return Plugin::instance()->container->get(DonationRepository::class);
    }

    /** A paid weekly donation whose subscription creation was recorded as failed. */
    private function strandedDonation(string $status): Donation
    {
        static $n = 0;
        $n++;

        $create = new WP_REST_Request('POST', '/dono/v1/donations');
        $create->set_header('content-type', 'application/json');
        $create->set_body((string) wp_json_encode([
            'email'        => 'stranded@example.test',
            'amount_cents' => 2500,
            'currency'     => 'USD',
            'gateway'      => 'stripe',
            'frequency'    => 'weekly',
            'profile'      => ['first_name' => 'Stran', 'last_name' => 'Ded'],
        ]));
        $data = rest_do_request($create)->get_data();
        $this->assertArrayHasKey('reference', $data, (string) wp_json_encode($data));

        $donation = $this->repo()->findByReference((string) $data['reference']);
        $donation->status            = $status;
        $donation->gateway_intent_id = 'pi_stranded_' . $n;
        $donation->recurring_plan_id = null;
        $donation->flags             = [
            'subscription_creation_failed'        => true,
            'subscription_creation_failed_reason' => 'Stripe said no.',
            'subscription_creation_failed_at'     => '2026-08-11 12:21:56',
        ];
        $donation->save();

        return $this->repo()->findByReference($donation->reference);
    }

    private function retry(string $reference): \WP_REST_Response|\WP_Error
    {
        return rest_do_request(
            new WP_REST_Request('POST', "/dono/v1/admin/donations/{$reference}/retry-subscription")
        );
    }

    public function test_a_refunded_donation_cannot_be_put_on_a_recurring_schedule(): void
    {
        $donation = $this->strandedDonation('refunded');

        $res = $this->retry($donation->reference);

        // The money went back. Starting a weekly schedule on it bills a donor
        // who was made whole, and the PaymentIntent still carries the customer
        // and payment method, so nothing further down the chain would stop it.
        $this->assertGreaterThanOrEqual(400, $res->get_status(), (string) wp_json_encode($res->get_data()));
        $this->assertSame(
            [],
            array_values(array_filter($this->stripeCalls, static fn (string $u): bool => str_contains($u, '/subscriptions'))),
            'no subscription is created at the gateway'
        );
        $this->assertNull($this->repo()->findByReference($donation->reference)->recurring_plan_id);
    }

    public function test_a_disputed_donation_cannot_be_put_on_a_recurring_schedule(): void
    {
        $donation = $this->strandedDonation('disputed');

        $res = $this->retry($donation->reference);

        $this->assertGreaterThanOrEqual(400, $res->get_status(), (string) wp_json_encode($res->get_data()));
        $this->assertSame(
            [],
            array_values(array_filter($this->stripeCalls, static fn (string $u): bool => str_contains($u, '/subscriptions')))
        );
    }

    public function test_an_unpaid_donation_cannot_be_put_on_a_recurring_schedule(): void
    {
        foreach (['pending', 'failed'] as $status) {
            $this->stripeCalls = [];
            $donation = $this->strandedDonation($status);

            $res = $this->retry($donation->reference);

            // Nothing bought a first period, so there are no later ones to sell.
            $this->assertGreaterThanOrEqual(
                400,
                $res->get_status(),
                $status . ': ' . wp_json_encode($res->get_data())
            );
            $this->assertSame(
                [],
                array_values(array_filter($this->stripeCalls, static fn (string $u): bool => str_contains($u, '/subscriptions'))),
                $status . ' must not reach the gateway'
            );
        }
    }

    public function test_a_paid_donation_is_still_allowed_through_to_the_gateway(): void
    {
        $donation = $this->strandedDonation('paid');

        $this->retry($donation->reference);

        // The gate must not be so tight that the case it exists for stops
        // working: this is the donation an org actually needs to recover.
        $this->assertNotSame(
            [],
            array_values(array_filter($this->stripeCalls, static fn (string $u): bool => str_contains($u, 'stripe.com'))),
            'a paid stranded donation still reaches Stripe'
        );
    }

    public function test_a_donation_that_already_has_a_plan_is_refused(): void
    {
        $donation = $this->strandedDonation('paid');
        $donation->recurring_plan_id = 999;
        $donation->save();

        $this->stripeCalls = [];
        $res = $this->retry($donation->reference);

        $this->assertGreaterThanOrEqual(400, $res->get_status());
        $this->assertSame(
            [],
            array_values(array_filter($this->stripeCalls, static fn (string $u): bool => str_contains($u, '/subscriptions')))
        );
    }
}
