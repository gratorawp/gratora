<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Donations\Donation;
use Gratora\Donations\DonationIntent;
use Gratora\Donations\DonationService;
use Gratora\Donations\DonationTrasher;
use Gratora\Donations\TrashOutcome;
use Gratora\Donors\Donor;
use Gratora\Donors\DonorService;
use Gratora\Foundation\Plugin;
use InvalidArgumentException;

/**
 * The donor gate, and a payment somebody already stopped.
 *
 * The gate asks whether a donor still has a donation that could become money.
 * A trashed spam attempt is pending, which the status test reads as live, so
 * without the stop record the donor who exists only because of that attempt is
 * held for the whole abandon window. That is precisely the case this feature
 * was built for, so the gate has to be able to see the stop.
 */
final class DonorGateStoppedPaymentTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    private function attempt(): Donation
    {
        return Plugin::instance()->container->get(DonationService::class)->createPending(new DonationIntent(
            email:        'gate-' . uniqid() . '@example.test',
            amount_cents: 2500,
            currency:     'USD',
            gateway:      'offline',
            frequency:    'one_time',
        ))['donation'];
    }

    private function donorFor(Donation $donation): Donor
    {
        return Donor::query()->find('id', (int) $donation->donor_id);
    }

    private function donors(): DonorService
    {
        return Plugin::instance()->container->get(DonorService::class);
    }

    public function test_a_live_attempt_still_holds_its_donor(): void
    {
        $donation = $this->attempt();

        $this->expectException(InvalidArgumentException::class);

        try {
            $this->donors()->delete($this->donorFor($donation));
        } finally {
            $this->assertNotNull(
                Donor::query()->find('id', (int) $donation->donor_id),
                'a checkout the donor may still be finishing is not spam'
            );
        }
    }

    /**
     * The headline case. One trashed attempt, stopped at the gateway, and the
     * donor goes in one action rather than in thirty days.
     */
    public function test_a_stopped_attempt_releases_its_donor_at_once(): void
    {
        $donation = $this->attempt();

        $outcome = Plugin::instance()->container->get(DonationTrasher::class)->trash($donation);
        $this->assertSame(TrashOutcome::TRASHED, $outcome->outcome, (string) $outcome->reason);

        $donorId = (int) $donation->donor_id;
        $this->donors()->delete($this->donorFor($donation));

        $this->assertNull(Donor::query()->find('id', $donorId), 'the donor goes');
        $this->assertNull(Donation::query()->find('id', (int) $donation->id), 'and so does the attempt');
    }

    /**
     * The escape hatch that keeps this narrow. Stopping a payment says nothing
     * about one that already moved, so a row carrying either mark money leaves
     * behind still holds its donor however stopped it looks.
     */
    public function test_a_stopped_row_that_saw_money_still_holds_its_donor(): void
    {
        $donation = $this->attempt();
        $donation->updateColumns([
            'payment_stopped_at' => gmdate('Y-m-d H:i:s'),
            'gateway_txn_id'     => 'ch_it_actually_charged',
        ]);

        $this->expectException(InvalidArgumentException::class);

        try {
            $this->donors()->delete($this->donorFor($donation));
        } finally {
            $this->assertNotNull(Donor::query()->find('id', (int) $donation->donor_id));
        }
    }
}
