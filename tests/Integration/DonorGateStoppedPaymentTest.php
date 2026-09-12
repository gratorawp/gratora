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
 * Deleting a donor, and the payments their donations were holding open.
 *
 * Nothing is refused for having taken money. What refuses is a payment still
 * in flight that this site cannot reach the processor to stop, because letting
 * the row go would leave a charge landing with nothing to attach it to and
 * nobody able to say it was ever expected.
 */
final class DonorGateStoppedPaymentTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    private function attempt(string $gateway = 'offline'): Donation
    {
        return Plugin::instance()->container->get(DonationService::class)->createPending(new DonationIntent(
            email:        'gate-' . uniqid() . '@example.test',
            amount_cents: 2500,
            currency:     'USD',
            gateway:      $gateway,
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

    /**
     * One trashed attempt, stopped at the gateway, and the donor goes in one
     * action rather than in thirty days.
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

    /** A checkout that can be closed is closed, and then the donor goes. */
    public function test_a_live_attempt_is_closed_and_the_donor_goes_with_it(): void
    {
        $donation = $this->attempt();
        $donorId  = (int) $donation->donor_id;

        $this->donors()->delete($this->donorFor($donation));

        $this->assertNull(Donor::query()->find('id', $donorId));
        $this->assertNull(Donation::query()->find('id', (int) $donation->id));
    }

    /**
     * A processor this site holds no credentials for cannot be asked to stop
     * anything, and the row is too young for the abandon rule to speak for it.
     */
    public function test_a_payment_nothing_here_can_stop_refuses_the_delete(): void
    {
        $donation = $this->attempt('razorpay');

        try {
            $this->donors()->delete($this->donorFor($donation));
            $this->fail('a payment in flight that cannot be stopped must refuse the delete');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('razorpay', $e->getMessage());
        }

        $this->assertNotNull(Donor::query()->find('id', (int) $donation->donor_id));
    }

    /**
     * Nothing is in flight on a row that already settled, so there is nothing
     * to ask the processor for. Asking anyway refuses every donor who ever
     * gave through a gateway this site is no longer connected to, with a
     * message about stopping a payment that finished months ago.
     */
    public function test_a_settled_donation_is_not_asked_to_stop_anything(): void
    {
        $donation = $this->attempt('razorpay');
        $donation->updateColumns([
            'status'         => 'paid',
            'paid_at'        => gmdate('Y-m-d H:i:s'),
            'gateway_txn_id' => 'ch_it_settled',
        ]);

        $donorId = (int) $donation->donor_id;
        $this->donors()->delete($this->donorFor($donation));

        $this->assertNull(Donor::query()->find('id', $donorId));
        $this->assertNull(Donation::query()->find('id', (int) $donation->id));
    }
}
