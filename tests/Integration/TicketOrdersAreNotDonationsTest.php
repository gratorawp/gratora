<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donations\Donation;
use Dono\Donors\Donor;
use Dono\Donors\DonorAggregateSyncer;
use Dono\Foundation\Plugin;
use Dono\Donations\AggregateSyncer;
use Dono\Donations\DonationRepository;
use Dono\Receipts\Receipt;

/**
 * Event ticket orders ride the donations table with kind='order'. They are a
 * purchase, not a gift.
 *
 * The QA sweep found kind filtered in exactly one place in all of core, so a
 * ticket inflated the buyer's donor lifetime total, was issued a donation
 * receipt, and appeared on the year-end tax-deductible statement. The last two
 * are a compliance exposure, not a display bug.
 */
final class TicketOrdersAreNotDonationsTest extends IntegrationTestCase
{
    private int $donorId;

    protected function setUp(): void
    {
        parent::setUp();

        $now = gmdate('Y-m-d H:i:s');
        $d = Donor::make();
        $d->email_hash = hash('sha256', 'ticketbuyer@example.test');
        $d->created_at = $now;
        $d->updated_at = $now;
        $d->save();
        $this->donorId = (int) $d->id;
    }

    private function row(string $kind, int $cents, string $ref): Donation
    {
        $now = gmdate('Y-m-d H:i:s');
        $x = Donation::make();
        $x->reference         = $ref;
        $x->donor_id          = $this->donorId;
        $x->amount_cents      = $cents;
        $x->base_amount_cents = $cents;
        $x->currency          = 'EUR';
        $x->base_currency     = 'EUR';
        $x->status            = 'paid';
        $x->gateway           = 'offline';
        $x->frequency         = 'one_time';
        $x->kind              = $kind;
        $x->is_test           = false;
        $x->paid_at           = $now;
        $x->created_at        = $now;
        $x->updated_at        = $now;
        $x->save();
        return $x;
    }

    private function donor(): Donor
    {
        return Donor::query()->find('id', $this->donorId);
    }

    /** The headline: a €70 ticket must not become €70 of donor giving. */
    public function test_a_ticket_order_does_not_count_towards_donor_lifetime_giving(): void
    {
        $this->row('donation', 1000, 'DONO-GIFT-1');
        $this->row('order', 7000, 'DONO-TICKET-1');

        (new DonorAggregateSyncer())->syncForDonor($this->donorId);

        $donor = $this->donor();
        $this->assertSame(1000, (int) $donor->total_donated_cents, 'only the gift counts');
        $this->assertSame(1, (int) $donor->donations_count);
    }

    /**
     * The live hook and the recompute path used to be two implementations with
     * different rules, so a resync silently reversed the live figure.
     */
    public function test_the_live_path_and_the_resync_path_agree(): void
    {
        $this->row('donation', 1000, 'DONO-GIFT-2');
        $this->row('order', 7000, 'DONO-TICKET-2');

        (new DonorAggregateSyncer())->syncForDonor($this->donorId);
        $live = [(int) $this->donor()->total_donated_cents, (int) $this->donor()->donations_count];

        (new AggregateSyncer())->syncDonor($this->donorId);
        $resync = [(int) $this->donor()->total_donated_cents, (int) $this->donor()->donations_count];

        $this->assertSame($live, $resync, 'a recompute must not change the answer');
        $this->assertSame([1000, 1], $live);
    }

    /** A ticket is goods received; it cannot sit on a tax-deductible statement. */
    public function test_a_ticket_order_is_excluded_from_the_year_end_statement(): void
    {
        $this->row('donation', 1000, 'DONO-GIFT-3');
        $this->row('order', 7000, 'DONO-TICKET-3');

        $repo = Plugin::instance()->container->get(DonationRepository::class);
        $rows = $repo->paidForDonorInYear($this->donorId, (int) gmdate('Y'));

        $refs = array_column($rows, 'reference');
        $this->assertContains('DONO-GIFT-3', $refs);
        $this->assertNotContains('DONO-TICKET-3', $refs, 'a ticket is not tax-deductible');
        $this->assertSame(1000, array_sum(array_column($rows, 'amount_cents')));
    }

    /** No donation receipt is issued for a purchase. */
    public function test_no_donation_receipt_is_issued_for_a_ticket_order(): void
    {
        $order = $this->row('order', 7000, 'DONO-TICKET-4');

        do_action('dono.donation.completed', $order);
        $this->runPendingAsyncJobs();

        $this->assertNull(
            Receipt::query()->where('donation_id', (int) $order->id)->get(),
            'a ticket purchase gets no donation receipt'
        );
    }

    /** But a real donation still gets one. */
    public function test_a_real_donation_still_gets_its_receipt(): void
    {
        $gift = $this->row('donation', 1000, 'DONO-GIFT-4');

        do_action('dono.donation.completed', $gift);
        $this->runPendingAsyncJobs();

        $this->assertNotNull(Receipt::query()->where('donation_id', (int) $gift->id)->get());
    }
}
