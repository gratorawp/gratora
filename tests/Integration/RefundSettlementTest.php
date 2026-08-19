<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donations\Donation;
use Dono\Donations\DonationService;
use Dono\Donations\Refund;
use Dono\Donors\DonorService;
use Dono\Foundation\Plugin;
use Dono\Gateways\RefundResult;
use Dono\Receipts\Receipt;

/**
 * A refund the gateway has accepted is not a refund the gateway has paid.
 *
 * Both gateways create a refund in a state that can still fail: PayPal answers
 * PENDING for an eCheck, Stripe answers pending or requires_action for a bank
 * debit. Until it clears, the org still holds the money. Banked on acceptance,
 * the donation leaves every total, its receipt is voided, the donor is emailed
 * that they have been repaid, and no retry is possible because the donation is
 * no longer refundable, so a refund that later fails cannot even be reissued.
 *
 * What settles it is the gateway saying the money has gone, which arrives at
 * recordExternalRefund and replaces the awaited row.
 */
final class RefundSettlementTest extends IntegrationTestCase
{
    private function paidDonation(string $email): Donation
    {
        $now   = gmdate('Y-m-d H:i:s');
        $donor = Plugin::instance()->container->get(DonorService::class)
            ->findOrCreate($email, ['first_name' => 'Ref', 'last_name' => 'Und']);

        $d = Donation::make();
        $d->reference         = 'REF-' . strtoupper(bin2hex(random_bytes(4)));
        $d->donor_id          = (int) $donor->id;
        $d->amount_cents      = 5000;
        $d->net_cents         = 5000;
        $d->base_amount_cents = 5000;
        $d->base_currency     = 'USD';
        $d->currency          = 'USD';
        $d->gateway           = 'offline';
        $d->status            = 'paid';
        $d->is_test           = false;
        $d->paid_at           = $now;
        $d->created_at        = $now;
        $d->updated_at        = $now;
        $d->save();

        return $d;
    }

    private function service(): DonationService
    {
        return Plugin::instance()->container->get(DonationService::class);
    }

    private function reload(Donation $d): Donation
    {
        return Donation::query()->find('id', (int) $d->id);
    }

    /**
     * The gateway has taken the instruction and nothing more, so the books do
     * not move and the donor is told nothing.
     */
    public function test_a_refund_the_gateway_has_not_paid_leaves_the_donation_alone(): void
    {
        $donation = $this->paidDonation('awaited@example.test');

        $mails = new \ArrayObject();
        add_filter('wp_mail', static function ($args) use ($mails) { $mails[] = $args; return $args; });

        $refund = $this->service()->recordExternalRefund(
            $donation, 5000, 're_awaited', 'bank refund', 'gateway', null, false
        );

        $fresh = $this->reload($donation);
        $this->assertSame('pending', (string) $refund->status, 'the row says what the gateway said');
        $this->assertSame('paid', (string) $fresh->status, 'the donation is not off the books');
        $this->assertSame(0, (int) $fresh->refunded_cents, 'and no money is counted as returned');
        $this->assertCount(0, $mails, 'the donor is not told they have been repaid');
    }

    /** And the gateway confirming it is what takes the money off the books. */
    public function test_the_gateway_confirming_it_settles_the_awaited_refund(): void
    {
        $donation = $this->paidDonation('settles@example.test');

        $this->service()->recordExternalRefund($donation, 5000, 're_settles', null, 'gateway', null, false);
        $this->assertSame('paid', (string) $this->reload($donation)->status);

        $settled = $this->service()->recordExternalRefund($donation, 5000, 're_settles', null, 'gateway', null, true);

        $fresh = $this->reload($donation);
        $this->assertSame('succeeded', (string) $settled->status);
        $this->assertSame('refunded', (string) $fresh->status, 'now the money really has gone back');
        $this->assertSame(5000, (int) $fresh->refunded_cents);
        $this->assertSame(
            1,
            (int) Refund::query()->where('gateway_refund_id', 're_settles')->count(),
            'one refund, not one awaited and one settled'
        );
    }

    /** A redelivery of the awaited event does not stack up rows. */
    public function test_an_awaited_refund_is_idempotent(): void
    {
        $donation = $this->paidDonation('twice@example.test');

        $this->service()->recordExternalRefund($donation, 5000, 're_twice', null, 'gateway', null, false);
        $this->service()->recordExternalRefund($donation, 5000, 're_twice', null, 'gateway', null, false);

        $this->assertSame(1, (int) Refund::query()->where('gateway_refund_id', 're_twice')->count());
        $this->assertSame('paid', (string) $this->reload($donation)->status);
    }

    /** The ordinary case is untouched: a settled refund still banks at once. */
    public function test_a_settled_refund_still_banks_immediately(): void
    {
        $donation = $this->paidDonation('settled@example.test');

        $refund = $this->service()->recordExternalRefund($donation, 5000, 're_now', null, 'gateway');

        $fresh = $this->reload($donation);
        $this->assertSame('succeeded', (string) $refund->status);
        $this->assertSame('refunded', (string) $fresh->status);
        $this->assertSame(5000, (int) $fresh->refunded_cents);
    }

    /**
     * The admin door. An awaited refund leaves the donation refundable, which
     * is the whole point: a bank refund that fails has to be reissuable, and a
     * donation already marked refunded cannot be refunded again.
     */
    public function test_the_admin_door_leaves_an_awaited_refund_reissuable(): void
    {
        $donation = $this->paidDonation('adminawaited@example.test');

        $refund = $this->service()->recordExternalRefund(
            $donation, 2500, 're_admin_awaited', null, 'admin', null, false
        );

        $this->assertSame('pending', (string) $refund->status);
        $this->assertSame('paid', (string) $this->reload($donation)->status, 'still refundable');
    }

    /** Nothing is voided while the money is still with the org. */
    public function test_an_awaited_refund_does_not_void_the_receipt(): void
    {
        $donation = $this->paidDonation('receipt@example.test');
        do_action('dono.async.issue_receipt', ['donation_id' => (int) $donation->id]);

        $receipt = Receipt::query()->where('donation_id', (int) $donation->id)->get();
        $this->assertNotNull($receipt, 'precondition: a receipt was issued');

        $this->service()->recordExternalRefund($donation, 5000, 're_receipt', null, 'gateway', null, false);

        $after = Receipt::query()->where('id', (int) $receipt->id)->get();
        $this->assertFalse((bool) $after->voided, 'the donation is still receipted, because it still stands');
    }

    /** The flag a gateway sets is what decides it. */
    public function test_the_result_carries_whether_the_gateway_settled_it(): void
    {
        $this->assertTrue((new RefundResult(success: true))->settled, 'a gateway that cannot tell keeps its behaviour');
        $this->assertFalse((new RefundResult(success: true, settled: false))->settled);
    }
}
