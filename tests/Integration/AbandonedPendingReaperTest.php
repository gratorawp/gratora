<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Async\AsyncDispatcher;
use Gratora\Donations\Donation;
use Gratora\Donations\DonationIntent;
use Gratora\Donations\DonationRepository;
use Gratora\Donations\DonationService;
use Gratora\Foundation\Maintenance\AbandonedPendingReaper;
use Gratora\Foundation\Plugin;
use Gratora\Foundation\Time\Clock;

/**
 * Closing checkouts that were begun and never paid.
 *
 * A donation row is written before the gateway is contacted, so every
 * abandoned checkout leaves one and nothing closed it. The set is not only
 * clutter: supersededIds correlates a JSON check against it on every admin
 * list, export and KPI query, and its size is something an unauthenticated
 * caller decides.
 *
 * What it must not touch is the point of most of this.
 */
final class AbandonedPendingReaperTest extends IntegrationTestCase
{
    private function reaper(): AbandonedPendingReaper
    {
        $c = Plugin::instance()->container;

        return new AbandonedPendingReaper($c->get(AsyncDispatcher::class), $c->get(Clock::class));
    }

    private function seed(string $gateway, int $ageDays, array $overrides = []): Donation
    {
        $donation = Plugin::instance()->container->get(DonationService::class)->createPending(new DonationIntent(
            email:        'reap-' . uniqid() . '@example.test',
            amount_cents: 2500,
            currency:     'USD',
            gateway:      $gateway,
            frequency:    'one_time',
        ))['donation'];

        $when = gmdate('Y-m-d H:i:s', time() - ($ageDays * DAY_IN_SECONDS));
        Donation::query()
            ->where('id', (int) $donation->id)
            ->update(['created_at' => $when] + $overrides);

        return $donation;
    }

    private function statusOf(Donation $donation): string
    {
        return (string) Plugin::instance()->container
            ->get(DonationRepository::class)
            ->findById((int) $donation->id)->status;
    }

    public function test_an_old_unpaid_checkout_is_closed(): void
    {
        $old = $this->seed('stripe', 60);

        $this->reaper()->run();

        $this->assertSame('failed', $this->statusOf($old));
    }

    public function test_a_recent_checkout_is_left_open(): void
    {
        $fresh = $this->seed('stripe', 1);

        $this->reaper()->run();

        $this->assertSame('pending', $this->statusOf($fresh), 'a donor may still be at the gateway');
    }

    /**
     * For an out-of-band gateway the pending row IS the queue entry the
     * incoming transfer is matched against, and the donor is quoting its
     * reference. Closing one strands a bank transfer that is merely slow.
     */
    public function test_an_out_of_band_donation_is_never_swept(): void
    {
        $offline = $this->seed('offline', 90);

        $this->reaper()->run();

        $this->assertSame('pending', $this->statusOf($offline), 'a bank transfer is not an abandonment');
    }

    public function test_a_row_that_saw_money_is_left_alone(): void
    {
        $withTxn = $this->seed('stripe', 90, ['gateway_txn_id' => 'ch_real_money']);
        $withPaid = $this->seed('stripe', 90, ['paid_at' => gmdate('Y-m-d H:i:s', time() - 100)]);

        $this->reaper()->run();

        $this->assertSame('pending', $this->statusOf($withTxn), 'a transaction id means the gateway took something');
        $this->assertSame('pending', $this->statusOf($withPaid));
    }

    public function test_a_paid_donation_is_untouched(): void
    {
        $paid = $this->seed('stripe', 90, ['status' => 'paid']);

        $this->reaper()->run();

        $this->assertSame('paid', $this->statusOf($paid), 'money that settled must never be rewritten');
    }

    public function test_the_window_is_filterable(): void
    {
        $donation = $this->seed('stripe', 5);

        add_filter('gratora.donations.abandon_after_days', static fn (): int => 2);
        try {
            $this->reaper()->run();
        } finally {
            remove_all_filters('gratora.donations.abandon_after_days');
        }

        $this->assertSame('failed', $this->statusOf($donation));
    }
}
