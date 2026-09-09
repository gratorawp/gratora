<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Donations\AntiSpamGuard;
use Gratora\Donations\DonationIntent;
use Gratora\Donations\DonationRepository;
use Gratora\Donations\DonationService;
use Gratora\Donors\DonorRepository;
use Gratora\Donors\DonorService;
use Gratora\Foundation\Plugin;
use Gratora\Foundation\Time\Clock;
use Gratora\Gateways\GatewayManager;
use Gratora\Gateways\Stripe\StripeAccount;
use Gratora\Gateways\Stripe\StripeApi;
use Gratora\Gateways\Stripe\StripeGateway;
use Gratora\Recurring\RecurringPlanRepository;
use WP_REST_Request;

/**
 * What a declined PaymentIntent is allowed to become.
 *
 * The donation quotas count rows, and a card tester's unit of work is a
 * confirmation. A failed PaymentIntent returns to requires_payment_method and
 * can be confirmed again, so one row is otherwise an unbounded supply of card
 * probes, and every one of them happens between the caller and Stripe where
 * this site never sees it. The decline counter is the only place it can.
 */
final class CardTestingBoundsTest extends IntegrationTestCase
{
    /** @var list<array{url:string,body:string}> */
    private array $calls = [];

    private string $secret;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calls  = [];
        $this->secret = 'whsec_bounds_' . bin2hex(random_bytes(8));

        // Test mode throughout, so a livemode:false event matches the rows this
        // seeds. The ceiling is unaffected either way: hit() is the raw counter
        // and never consults test mode, only the two quota wrappers do.
        update_option('gratora_gateway_config', [
            'stripe'    => ['webhook_secret_test' => $this->secret],
            'test_mode' => true,
        ]);

        $c       = Plugin::instance()->container;
        $account = $c->get(StripeAccount::class);
        $account->saveKeys(true, 'sk_test_bounds', 'pk_test_bounds');
        $account->saveKeys(false, 'sk_live_bounds', 'pk_live_bounds');
        $account->refresh(['id' => 'acct_bounds', 'charges_enabled' => true]);

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
            if (! is_string($url) || ! str_contains($url, 'stripe.com')) {
                return $pre;
            }

            $this->calls[] = ['url' => $url, 'body' => (string) ($args['body'] ?? '')];

            return [
                'headers'  => [],
                'body'     => (string) wp_json_encode(['id' => 'pi_bounds', 'status' => 'canceled']),
                'response' => ['code' => 200, 'message' => 'OK'],
            ];
        }, 10, 3);
    }
    private function cancels(): array
    {
        return array_values(array_filter(
            $this->calls,
            static fn (array $c) => str_contains($c['url'], '/cancel')
        ));
    }

    /** A pending Stripe donation with a known intent id, as the webhook will find it. */
    private function seedDonation(string $intentId): void
    {
        $donation = Plugin::instance()->container->get(DonationService::class)->createPending(new DonationIntent(
            email:        'probe-' . uniqid() . '@example.test',
            amount_cents: 100,
            currency:     'USD',
            gateway:      'stripe',
            frequency:    'one_time',
        ))['donation'];

        Plugin::instance()->container->get(DonationService::class)
            ->setGatewayIntent($donation, $intentId);
    }

    private function decline(string $intentId): void
    {
        $payload = (string) wp_json_encode([
            'id'   => 'evt_' . bin2hex(random_bytes(6)),
            'type' => 'payment_intent.payment_failed',
            'data' => ['object' => [
                'id'                 => $intentId,
                'livemode'           => false,
                'last_payment_error' => ['message' => 'Your card was declined.'],
            ]],
        ]);
        $timestamp = (string) time();
        $sig       = hash_hmac('sha256', "{$timestamp}.{$payload}", $this->secret);

        $req = new WP_REST_Request('POST', '/gratora/v1/webhooks/stripe');
        $req->set_header('content-type', 'application/json');
        $req->set_header('stripe_signature', "t={$timestamp},v1={$sig}");
        $req->set_body($payload);
        rest_do_request($req);
    }

    public function test_a_donor_retrying_a_declined_card_is_not_cut_off(): void
    {
        $intentId = 'pi_' . uniqid();
        $this->seedDonation($intentId);

        // A mistyped CVC, then a second card. The form supports exactly this.
        $this->decline($intentId);
        $this->decline($intentId);

        // Proves the webhook actually landed. Without it, "no cancel" is also
        // what a refused or misrouted event looks like, and the test would
        // pass while proving nothing.
        $this->assertSame(
            'failed',
            Plugin::instance()->container->get(DonationRepository::class)
                ->findByGatewayIntent('stripe', $intentId)->status,
            'the decline must have been handled at all'
        );

        $this->assertSame([], $this->cancels(), 'a donor must keep the intent they are still using');
    }

    public function test_an_intent_probed_past_the_ceiling_is_cancelled_at_stripe(): void
    {
        $intentId = 'pi_' . uniqid();
        $this->seedDonation($intentId);

        for ($i = 0; $i < 5; $i++) {
            $this->decline($intentId);
        }

        $cancels = $this->cancels();
        $this->assertNotSame([], $cancels, 'a probed intent must be retired, or it stays confirmable forever');
        $this->assertStringContainsString(rawurlencode($intentId), $cancels[0]['url']);
    }

    /**
     * markFailed refuses to re-run once the row reads failed, so the row cannot
     * be the counter. If it were, every intent would probe forever.
     */
    public function test_declines_are_counted_even_though_the_row_only_fails_once(): void
    {
        $intentId = 'pi_' . uniqid();
        $this->seedDonation($intentId);

        for ($i = 0; $i < 5; $i++) {
            $this->decline($intentId);
        }

        $this->assertNotSame(
            [],
            $this->cancels(),
            'the second and later declines apply no row, and must still be counted'
        );
    }

    public function test_two_intents_do_not_share_a_ceiling(): void
    {
        $a = 'pi_' . uniqid();
        $b = 'pi_' . uniqid();
        $this->seedDonation($a);
        $this->seedDonation($b);

        for ($i = 0; $i < 5; $i++) {
            $this->decline($a);
        }
        $this->calls = [];

        $this->decline($b);
        $this->assertSame([], $this->cancels(), 'one probed intent must not retire an unrelated donor\'s');
    }
}
