<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Donations\Donation;
use Gratora\Donations\DonationService;
use Gratora\Donations\Refund;
use Gratora\Donors\Donor;
use Gratora\Donors\DonorService;
use Gratora\Foundation\Plugin;
use Gratora\Receipts\Receipt;

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

        do_action('gratora.donation.completed', $d);

        return $d;
    }

    public function test_a_reversed_refund_puts_the_receipt_back(): void
    {
        $donation = $this->paidDonation('receipt-back@example.test');
        do_action('gratora.async.issue_receipt', ['donation_id' => (int) $donation->id]);

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

    public function test_a_partial_refund_still_standing_leaves_the_receipt_usable(): void
    {
        $donation = $this->paidDonation('still-refunded@example.test');
        do_action('gratora.async.issue_receipt', ['donation_id' => (int) $donation->id]);
        $receipt = Receipt::query()->where('donation_id', (int) $donation->id)->get();

        $this->service()->recordExternalRefund($donation, 2000, 're_part_a', null, 'gateway');
        $this->service()->recordExternalRefund($donation, 1000, 're_part_b', null, 'gateway');

        $this->service()->reverseExternalRefund($donation, 're_part_a');

        $this->assertFalse(
            (bool) Receipt::query()->where('id', (int) $receipt->id)->get()->voided,
            'the org kept 4000 of the 5000, and the receipt renders that'
        );
    }

    public function test_reversing_part_of_a_full_refund_puts_the_receipt_back(): void
    {
        $donation = $this->paidDonation('part-reversed@example.test');
        do_action('gratora.async.issue_receipt', ['donation_id' => (int) $donation->id]);
        $receipt = Receipt::query()->where('donation_id', (int) $donation->id)->get();

        $this->service()->recordExternalRefund($donation, 3000, 're_whole_a', null, 'gateway');
        $this->service()->recordExternalRefund($donation, 2000, 're_whole_b', null, 'gateway');
        $this->assertTrue(
            (bool) Receipt::query()->where('id', (int) $receipt->id)->get()->voided,
            'precondition: nothing was retained, so the receipt was withdrawn'
        );

        $this->service()->reverseExternalRefund($donation, 're_whole_b');

        $this->assertFalse(
            (bool) Receipt::query()->where('id', (int) $receipt->id)->get()->voided,
            '2000 is back with the org, so the donor has a document again'
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
