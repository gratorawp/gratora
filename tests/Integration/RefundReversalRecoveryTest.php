<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donations\Donation;
use Dono\Donations\DonationService;
use Dono\Donations\Refund;
use Dono\Donors\Donor;
use Dono\Donors\DonorService;
use Dono\Foundation\Plugin;
use Dono\Receipts\Receipt;

/**
 * Undoing a refund has to undo all of it.
 *
 * Winning a chargeback, or a bank refund that failed, means the money never
 * left. The counters recover inline, but the things a refund destroyed on the
 * way in did not come back: the donor's own lifetime total stayed short, and
 * the receipt stayed void, which is the document the donor needs and the one
 * thing here that no later donation repairs.
 */
final class RefundReversalRecoveryTest extends IntegrationTestCase
{
    private function service(): DonationService
    {
        return Plugin::instance()->container->get(DonationService::class);
    }

    private function paidDonation(string $email, int $cents = 5000): Donation
    {
        $now   = gmdate('Y-m-d H:i:s');
        $donor = Plugin::instance()->container->get(DonorService::class)
            ->findOrCreate($email, ['first_name' => 'Rev', 'last_name' => 'Ersal']);

        $d = Donation::make();
        $d->reference         = 'REV-' . strtoupper(bin2hex(random_bytes(4)));
        $d->donor_id          = (int) $donor->id;
        $d->amount_cents      = $cents;
        $d->net_cents         = $cents;
        $d->base_amount_cents = $cents;
        $d->base_currency     = 'USD';
        $d->currency          = 'USD';
        $d->gateway           = 'offline';
        $d->status            = 'paid';
        $d->is_test           = false;
        $d->paid_at           = $now;
        $d->created_at        = $now;
        $d->updated_at        = $now;
        $d->save();

        do_action('dono.donation.completed', $d);

        return $d;
    }

    public function test_a_reversed_refund_puts_the_receipt_back(): void
    {
        $donation = $this->paidDonation('receipt-back@example.test');
        do_action('dono.async.issue_receipt', ['donation_id' => (int) $donation->id]);

        $receipt = Receipt::query()->where('donation_id', (int) $donation->id)->get();
        $this->assertNotNull($receipt, 'precondition: a receipt was issued');

        $this->service()->recordExternalRefund($donation, 5000, 're_rev_1', 'dispute lost', 'dispute');
        $this->assertTrue(
            (bool) Receipt::query()->where('id', (int) $receipt->id)->get()->voided,
            'precondition: the refund voided it'
        );

        $this->service()->reverseExternalRefund($donation, 're_rev_1');

        $after = Receipt::query()->where('id', (int) $receipt->id)->get();
        $this->assertFalse((bool) $after->voided, 'the money never left, so the document stands again');
        $this->assertNull($after->voided_at);
    }

    /** A refund still standing means the issued figure is still wrong. */
    public function test_a_partial_refund_still_standing_keeps_the_receipt_void(): void
    {
        $donation = $this->paidDonation('still-refunded@example.test');
        do_action('dono.async.issue_receipt', ['donation_id' => (int) $donation->id]);
        $receipt = Receipt::query()->where('donation_id', (int) $donation->id)->get();

        $this->service()->recordExternalRefund($donation, 2000, 're_part_a', null, 'gateway');
        $this->service()->recordExternalRefund($donation, 1000, 're_part_b', null, 'gateway');

        $this->service()->reverseExternalRefund($donation, 're_part_a');

        $this->assertTrue(
            (bool) Receipt::query()->where('id', (int) $receipt->id)->get()->voided,
            'one refund is still standing, so the figure on the receipt is still wrong'
        );
    }

    public function test_a_reversed_refund_puts_the_donors_lifetime_total_back(): void
    {
        $donation = $this->paidDonation('lifetime@example.test');
        $donorId  = (int) $donation->donor_id;

        $before = (int) Donor::query()->find('id', $donorId)->total_donated_cents;
        $this->assertSame(5000, $before, 'precondition: the donation counted');

        $this->service()->recordExternalRefund($donation, 5000, 're_life', null, 'dispute');
        $this->assertSame(0, (int) Donor::query()->find('id', $donorId)->total_donated_cents);

        $this->service()->reverseExternalRefund($donation, 're_life');

        $this->assertSame(
            5000,
            (int) Donor::query()->find('id', $donorId)->total_donated_cents,
            'the donor gave this money and the reversal says they still have'
        );
    }

    /**
     * A gateway that takes the money a second time has to be able to say so.
     * Read as already handled, the second taking is a silent no-op and the
     * donation stays on the books at its full amount.
     */
    public function test_the_same_refund_id_can_be_recorded_again_after_a_reversal(): void
    {
        $donation = $this->paidDonation('again@example.test');

        $this->service()->recordExternalRefund($donation, 5000, 're_twice_id', null, 'dispute');
        $this->service()->reverseExternalRefund($donation, 're_twice_id');
        $this->assertSame('paid', (string) Donation::query()->find('id', (int) $donation->id)->status);

        $this->service()->recordExternalRefund(
            Donation::query()->find('id', (int) $donation->id),
            5000,
            're_twice_id',
            null,
            'dispute'
        );

        $fresh = Donation::query()->find('id', (int) $donation->id);
        $this->assertSame('refunded', (string) $fresh->status, 'the money went back again and the books say so');
        $this->assertSame(5000, (int) $fresh->refunded_cents);
        $this->assertSame(
            'succeeded',
            (string) Refund::query()->where('gateway_refund_id', 're_twice_id')->get()->status
        );
    }
}
