<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donations\Donation;
use Dono\Donations\DonationService;
use Dono\Donations\Refund;
use Dono\Donors\DonorService;
use Dono\Foundation\Plugin;
use RuntimeException;

/**
 * A refund the gateway says it paid can arrive for a donation that can no
 * longer take it: the balance went to a different refund, or a lost dispute
 * consumed it, while this one was still awaiting settlement.
 *
 * Refusing is correct. Refusing by first destroying the awaited row is not: the
 * only record that the gateway moved this money is the row, the org has no
 * other trace of it, and the gateway redelivers into the same refusal forever.
 * So every refusal has to stand before anything is written.
 *
 * Asserted on the rows rather than on the rollback: what protects the org is
 * the awaited row still being there, whichever mechanism leaves it standing.
 */
final class RefundRowSurvivesRefusalTest extends IntegrationTestCase
{
    private function paidDonation(string $email, int $cents = 5000): Donation
    {
        $now   = gmdate('Y-m-d H:i:s');
        $donor = Plugin::instance()->container->get(DonorService::class)
            ->findOrCreate($email, ['first_name' => 'Sur', 'last_name' => 'Vive']);

        $d = Donation::make();
        $d->reference         = 'SUR-' . strtoupper(bin2hex(random_bytes(4)));
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

        return $d;
    }

    private function service(): DonationService
    {
        return Plugin::instance()->container->get(DonationService::class);
    }

    /** The awaited row the gateway has not settled yet. */
    private function awaited(Donation $donation, string $gatewayRefundId, int $cents): Refund
    {
        return $this->service()->recordExternalRefund(
            $donation,
            $cents,
            $gatewayRefundId,
            'awaiting settlement',
            'gateway',
            null,
            false
        );
    }

    private function rowFor(string $gatewayRefundId): ?Refund
    {
        return Refund::query()->where('gateway_refund_id', $gatewayRefundId)->get() ?: null;
    }

    /**
     * The donation is fully refunded by a different refund, then the awaited one
     * settles. It cannot be banked, and it must still be on the record.
     */
    public function test_a_settled_delivery_a_donation_cannot_take_leaves_the_awaited_row_standing(): void
    {
        $donation = $this->paidDonation('refusal-pending@example.test');

        $this->awaited($donation, 'RE-AWAITED-1', 5000);
        $this->assertNotNull($this->rowFor('RE-AWAITED-1'), 'the awaited row was not written');

        // A different refund takes the whole balance, so the donation is done.
        $this->service()->recordExternalRefund(
            $donation,
            5000,
            'RE-OTHER-1',
            'dispute lost',
            'gateway',
            null,
            true
        );

        $donation = Donation::query()->find('id', (int) $donation->id);
        $this->assertSame('refunded', (string) $donation->status);

        $threw = false;
        try {
            $this->service()->recordExternalRefund(
                $donation,
                5000,
                'RE-AWAITED-1',
                'settled at last',
                'gateway',
                null,
                true
            );
        } catch (RuntimeException $e) {
            $threw = true;
        }

        $this->assertTrue($threw, 'a donation with no balance left should refuse the settlement');

        $row = $this->rowFor('RE-AWAITED-1');
        $this->assertNotNull(
            $row,
            'the refusal destroyed the only record that the gateway moved this money'
        );
        $this->assertSame('pending', (string) $row->status);
        $this->assertSame(5000, (int) $row->amount_cents);
    }

    /**
     * The same refusal reached through the reversed status, which the dedup
     * treats as spent in the same way.
     */
    public function test_a_reversed_row_also_survives_a_refused_settlement(): void
    {
        $donation = $this->paidDonation('refusal-reversed@example.test');

        $this->awaited($donation, 'RE-REVERSED-1', 5000);
        Refund::query()
            ->where('gateway_refund_id', 'RE-REVERSED-1')
            ->update(['status' => 'reversed']);

        $this->service()->recordExternalRefund(
            $donation,
            5000,
            'RE-OTHER-2',
            'dispute lost',
            'gateway',
            null,
            true
        );

        $donation = Donation::query()->find('id', (int) $donation->id);

        try {
            $this->service()->recordExternalRefund(
                $donation,
                5000,
                'RE-REVERSED-1',
                'settled at last',
                'gateway',
                null,
                true
            );
        } catch (RuntimeException $e) {
            // The refusal is the point; what it left behind is what is asserted.
        }

        $row = $this->rowFor('RE-REVERSED-1');
        $this->assertNotNull($row, 'the reversed row was destroyed by a refused settlement');
        $this->assertSame('reversed', (string) $row->status);
    }

    /**
     * The replacement itself still has to work, or the guard above would be
     * satisfied by a settlement path that never replaces anything.
     */
    public function test_a_settlement_the_donation_can_take_still_replaces_the_awaited_row(): void
    {
        $donation = $this->paidDonation('refusal-happy@example.test');

        $this->awaited($donation, 'RE-AWAITED-2', 5000);

        $this->service()->recordExternalRefund(
            $donation,
            5000,
            'RE-AWAITED-2',
            'settled',
            'gateway',
            null,
            true
        );

        $rows = Refund::query()->where('gateway_refund_id', 'RE-AWAITED-2')->getAll();
        $this->assertCount(1, $rows, 'settling should leave exactly one row for the gateway refund id');
        $this->assertSame('succeeded', (string) $rows[0]->status);

        $donation = Donation::query()->find('id', (int) $donation->id);
        $this->assertSame('refunded', (string) $donation->status);
        $this->assertSame(5000, (int) $donation->refunded_cents);
    }
}
