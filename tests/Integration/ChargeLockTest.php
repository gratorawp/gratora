<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Donations\Donation;
use Gratora\Gateways\ChargeLock;
use Gratora\Vendor\Queryable\DB;

/**
 * The lock that stops one donation being charged twice.
 *
 * A donor double-clicks, or a slow response makes them reload. Two requests
 * read the same pending donation and both decide to charge it. The lock is what
 * makes exactly one of them win.
 *
 * The lease is the half that decides when a lock may be taken away. It is the
 * holder's, written into the row, because a claimant judging someone else's
 * lock by its own clock is how an in-flight capture gets a second charge
 * alongside it.
 */
final class ChargeLockTest extends IntegrationTestCase
{
    private function donation(): Donation
    {
        $d                    = Donation::make();
        $d->reference         = 'LOCK-' . uniqid();
        $d->amount_cents      = 2500;
        $d->base_amount_cents = 2500;
        $d->currency          = 'USD';
        $d->base_currency     = 'USD';
        $d->status            = 'pending';
        $d->gateway           = 'square';
        $d->frequency         = 'one_time';
        $d->kind              = 'donation';
        $d->is_test           = false;
        $d->save();

        return $d;
    }

    /** Writes a held lock directly, so it can be genuinely old and carry a chosen lease. */
    private function heldSince(Donation $donation, int $secondsAgo, int $lease): void
    {
        DB::table('options')->insert([
            'option_name'  => ChargeLock::keyFor('square', $donation),
            'option_value' => (time() - $secondsAgo) . '.' . $lease . '.abandoned',
            'autoload'     => 'no',
        ]);

        wp_cache_delete(ChargeLock::keyFor('square', $donation), 'options');
        wp_cache_delete('alloptions', 'options');
    }

    public function test_one_claim_wins_and_the_second_loses(): void
    {
        $donation = $this->donation();

        $this->assertTrue((new ChargeLock('square'))->claim($donation));
        $this->assertFalse((new ChargeLock('square'))->claim($donation), 'the second request does not get to charge too');
    }

    public function test_two_gateways_do_not_share_one_row(): void
    {
        $donation = $this->donation();

        $this->assertTrue((new ChargeLock('square'))->claim($donation));
        $this->assertTrue((new ChargeLock('paypal'))->claim($donation), 'a different gateway has its own lock');
    }

    /**
     * The defect this lock exists to not have. The holder took a long lease;
     * a claimant whose own window is shorter must not conclude the lock lapsed
     * and take it while the first request is still on the network.
     */
    public function test_a_live_lock_is_not_taken_from_its_holder_by_a_shorter_window(): void
    {
        $donation = $this->donation();

        // Older than square's own 180s window, still well inside the lease the
        // holder actually took. Judged by the claimant's clock this is expired;
        // judged by the holder's, it is live.
        $this->heldSince($donation, 600, 3600);

        $this->assertFalse(
            (new ChargeLock('square'))->claim($donation),
            'the holder keeps its lock until the lease it took runs out'
        );
    }

    public function test_an_abandoned_lock_is_reclaimed_once_its_lease_runs_out(): void
    {
        $donation = $this->donation();
        $this->heldSince($donation, 600, 300);

        // A crashed request must not hold the lock forever, or the donation can
        // never be completed and the donor is stuck.
        $this->assertTrue((new ChargeLock('square'))->claim($donation));
    }

    public function test_a_release_only_reaches_the_lock_this_request_took(): void
    {
        $donation = $this->donation();

        $holder = new ChargeLock('square');
        $this->assertTrue($holder->claim($donation));

        // Lost its own claim, so it holds no token and has nothing to release.
        // An unconditional delete here would free the holder's lock and let a
        // third request charge alongside it.
        (new ChargeLock('square'))->release($donation);

        $this->assertFalse((new ChargeLock('square'))->claim($donation), 'the holder still holds it');

        $holder->release($donation);
        $this->assertTrue((new ChargeLock('square'))->claim($donation), 'and releases it when it is done');
    }

    public function test_the_lease_is_the_gateways_own(): void
    {
        // The callers that used to name a window sized it per gateway, and the
        // lock has to keep charging routes covered for as long as they run.
        $this->assertSame(180, (new ChargeLock('square'))->lease());
        $this->assertSame(90, (new ChargeLock('gocardless'))->lease());
        $this->assertSame(180, (new ChargeLock('a-gateway-nobody-registered'))->lease());
    }
}
