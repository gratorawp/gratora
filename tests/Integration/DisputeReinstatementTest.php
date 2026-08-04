<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donations\Donation;
use Dono\Donations\DonationService;
use Dono\Donations\Refund;
use Dono\Donors\DonorService;
use Dono\Foundation\Plugin;

/**
 * A lost dispute is recorded as a refund, which drops the money out of every
 * total and voids the receipt. Winning it on appeal puts the money back on the
 * Stripe balance, so it has to come back here too.
 */
final class DisputeReinstatementTest extends IntegrationTestCase
{
    private function service(): DonationService
    {
        return Plugin::instance()->container->get(DonationService::class);
    }

    private function paidDonation(int $cents = 10000): Donation
    {
        $d = Donation::make();
        $d->reference         = 'REF-' . uniqid();
        $d->status            = 'paid';
        $d->gateway           = 'stripe';
        $d->kind              = 'donation';
        $d->amount_cents      = $cents;
        $d->base_amount_cents = $cents;
        $d->currency          = 'USD';
        $d->is_test           = false;
        $d->donor_id          = (int) Plugin::instance()->container->get(DonorService::class)
            ->findOrCreate('dispute-' . uniqid() . '@example.test')->id;
        $d->created_at        = gmdate('Y-m-d H:i:s');
        $d->paid_at           = gmdate('Y-m-d H:i:s');
        $d->save();

        return $d;
    }

    private function reload(Donation $d): Donation
    {
        return Donation::query()->where('id', (int) $d->id)->get();
    }

    public function test_winning_the_dispute_restores_the_donation(): void
    {
        $d  = $this->paidDonation();
        $id = 'dp_' . uniqid();

        $this->service()->recordExternalRefund($d, 10000, $id, 'dispute: fraudulent', 'dispute');
        $this->assertSame('refunded', $this->reload($d)->status);

        $this->service()->reverseExternalRefund($this->reload($d), $id);

        $fresh = $this->reload($d);
        $this->assertSame('paid', $fresh->status, 'the money is back, so the donation is paid again');
        $this->assertSame(0, (int) $fresh->refunded_cents);
        $this->assertNull($fresh->refunded_at);
    }

    public function test_the_reversed_refund_stops_counting(): void
    {
        $d  = $this->paidDonation();
        $id = 'dp_' . uniqid();

        $this->service()->recordExternalRefund($d, 10000, $id, null, 'dispute');
        $this->service()->reverseExternalRefund($this->reload($d), $id);

        $stillCounted = (int) Refund::query()
            ->where('donation_id', (int) $d->id)
            ->where('status', 'succeeded')
            ->sum('amount_cents');

        $this->assertSame(0, $stillCounted, 'a reversed refund is not summed into any total');
    }

    public function test_a_partial_dispute_leaves_the_rest_refunded(): void
    {
        $d = $this->paidDonation();
        $this->service()->recordExternalRefund($d, 4000, 're_' . uniqid(), null, 'gateway');

        $disputeId = 'dp_' . uniqid();
        $this->service()->recordExternalRefund($this->reload($d), 3000, $disputeId, null, 'dispute');
        $this->assertSame(7000, (int) $this->reload($d)->refunded_cents);

        $this->service()->reverseExternalRefund($this->reload($d), $disputeId);

        $fresh = $this->reload($d);
        $this->assertSame(4000, (int) $fresh->refunded_cents, 'only the disputed part comes back');
        $this->assertSame('partial_refund', $fresh->status);
    }

    public function test_a_redelivered_reinstatement_changes_nothing(): void
    {
        $d  = $this->paidDonation();
        $id = 'dp_' . uniqid();

        $this->service()->recordExternalRefund($d, 10000, $id, null, 'dispute');
        $this->service()->reverseExternalRefund($this->reload($d), $id);

        $this->assertNull(
            $this->service()->reverseExternalRefund($this->reload($d), $id),
            'nothing left to reverse'
        );
        $this->assertSame(0, (int) $this->reload($d)->refunded_cents);
    }
}
