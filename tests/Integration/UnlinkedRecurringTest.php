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
 * A recurring donation that was charged and produced no plan is a broken
 * integration, not revenue. An org meets it first in test mode, which is exactly
 * when it is cheap to fix, so this count is the one figure on the Subscriptions
 * screen that does not exclude test donations.
 */
final class UnlinkedRecurringTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        // The sweep asks which registered gateways create plans, so without one
        // registered only donations already carrying a recorded failure match.
        $c = Plugin::instance()->container;
        $account = $c->get(StripeAccount::class);
        $account->saveKeys(true, 'sk_test_unlinked', 'pk_test_unlinked');
        $account->refresh(['id' => 'acct_unlinked', 'charges_enabled' => true]);

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
    }

    /** @param array<string,mixed> $overrides */
    private function donation(array $overrides = []): Donation
    {
        $d = Donation::make();
        $d->kind              = 'donation';
        $d->reference         = 'DONO-UNLINKED-' . wp_rand(100000, 999999);
        $d->donor_id          = 1;
        $d->amount_cents      = 2500;
        $d->currency          = 'USD';
        $d->gateway           = 'stripe';
        $d->frequency         = 'weekly';
        $d->status            = 'paid';
        $d->is_test           = true;
        $d->recurring_plan_id = null;
        $d->paid_at           = gmdate('Y-m-d H:i:s', time() - HOUR_IN_SECONDS);
        $d->created_at        = $d->paid_at;
        $d->updated_at        = $d->paid_at;

        foreach ($overrides as $k => $v) {
            $d->{$k} = $v;
        }
        $d->save();

        return $d;
    }

    /** @return array<string,mixed> */
    private function fetch(): array
    {
        $res = rest_do_request(new WP_REST_Request('GET', '/dono/v1/admin/recurring/unlinked'));
        $this->assertSame(200, $res->get_status(), (string) wp_json_encode($res->get_data()));

        return (array) $res->get_data();
    }

    public function test_a_test_mode_donation_with_no_plan_is_counted(): void
    {
        $donation = $this->donation();

        $data = $this->fetch();
        $refs = array_column((array) $data['items'], 'reference');

        // Every money figure on this screen excludes test donations. This one
        // must not, or the warning only ever arrives once it is real money.
        $this->assertContains($donation->reference, $refs, (string) wp_json_encode($data));
        $this->assertGreaterThanOrEqual(1, (int) $data['total']);
    }

    public function test_a_recorded_failure_is_reported_the_moment_it_happens(): void
    {
        $donation = $this->donation([
            'paid_at' => gmdate('Y-m-d H:i:s', time() - 30),
            'flags'   => [
                'subscription_creation_failed'        => true,
                'subscription_creation_failed_reason' => 'Stripe said no.',
            ],
        ]);

        $refs = array_column((array) $this->fetch()['items'], 'reference');

        // The settling delay is for a donation that might still finish. One
        // that already says why it failed is not waiting on anything, and an
        // org watching this screen after a failed test should not be told
        // nothing is wrong for a quarter of an hour.
        $this->assertContains($donation->reference, $refs);
    }

    public function test_a_donation_still_inside_its_own_flow_is_not_counted_yet(): void
    {
        $donation = $this->donation(['paid_at' => gmdate('Y-m-d H:i:s', time() - 60)]);

        $data = $this->fetch();
        $refs = array_column((array) $data['items'], 'reference');

        // The plan is created in the same flow that marks the donation paid, so
        // a donation seconds old is in progress rather than stranded.
        $this->assertNotContains($donation->reference, $refs);
    }

    public function test_a_one_time_donation_is_never_counted(): void
    {
        $donation = $this->donation(['frequency' => 'one_time']);

        $refs = array_column((array) $this->fetch()['items'], 'reference');

        $this->assertNotContains($donation->reference, $refs);
    }

    public function test_a_donation_that_has_a_plan_is_not_counted(): void
    {
        $donation = $this->donation(['recurring_plan_id' => 4242]);

        $refs = array_column((array) $this->fetch()['items'], 'reference');

        $this->assertNotContains($donation->reference, $refs);
    }

    public function test_the_rows_say_whether_they_are_test_donations(): void
    {
        $this->donation();

        $items = (array) $this->fetch()['items'];
        $this->assertNotEmpty($items);

        // So the notice can name them as test donations rather than implying an
        // org has lost real recurring revenue.
        $this->assertArrayHasKey('is_test', (array) $items[0]);
    }
}
