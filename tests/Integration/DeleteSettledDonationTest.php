<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Campaigns\Campaign;
use Gratora\Donations\AggregateSyncer;
use Gratora\Donations\Donation;
use Gratora\Donations\DonationDeleter;
use Gratora\Donations\DonationTrasher;
use Gratora\Donations\TrashOutcome;
use Gratora\Donors\DonorService;
use Gratora\Foundation\Plugin;
use Gratora\Analytics\Event;
use Gratora\Receipts\Receipt;
use Gratora\Vendor\Queryable\DB;
use InvalidArgumentException;
use WP_REST_Request;

/**
 * Removing a donation that took money.
 *
 * The receipt sequence is gap-free because a tax authority reads it that way,
 * so the rule is not "never" but "not while a receipt still stands". A refund
 * voids the receipt, which is what leaves a row explaining the number, and the
 * donation can go after that.
 *
 * Trash is deliberately narrower than delete now: it exists to stop a payment
 * that is still open, which is meaningless for one that already settled, and
 * its promise that no total moves holds only while the bin cannot hold money.
 */
final class DeleteSettledDonationTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    private function deleter(): DonationDeleter
    {
        return Plugin::instance()->container->get(DonationDeleter::class);
    }

    private function paid(array $overrides = []): Donation
    {
        $now = gmdate('Y-m-d H:i:s');
        $d   = Donation::make();
        $d->reference         = 'SETTLED-' . bin2hex(random_bytes(4));
        $d->donor_id          = (int) Plugin::instance()->container->get(DonorService::class)
            ->findOrCreate('settled-' . uniqid() . '@example.test', ['first_name' => 'Settled'])->id;
        $d->amount_cents      = 5000;
        $d->base_amount_cents = 5000;
        $d->currency          = 'USD';
        $d->base_currency     = 'USD';
        $d->status            = 'paid';
        // Stripe on purpose, with no credentials stored. offline settles out
        // of band and closes trivially, which hides the case that matters: a
        // settled row has no payment to stop, and asking a gateway this site
        // cannot reach returns a refusal that would block the delete.
        $d->gateway           = 'stripe';
        $d->frequency         = 'one_time';
        $d->kind              = 'donation';
        $d->is_test           = false;
        $d->paid_at           = $now;
        $d->gateway_txn_id    = 'TXN-' . bin2hex(random_bytes(3));
        $d->created_at        = $now;
        $d->updated_at        = $now;
        foreach ($overrides as $k => $v) {
            $d->{$k} = $v;
        }
        $d->save();

        return $d;
    }

    private function receiptFor(Donation $donation, bool $voided): Receipt
    {
        $r = Receipt::make();
        $r->donation_id     = (int) $donation->id;
        $r->renderer_id     = 'receipt';
        $r->receipt_number  = 'R-' . bin2hex(random_bytes(3));
        $r->locale          = 'en_US';
        $r->voided          = $voided;
        $r->voided_at       = $voided ? gmdate('Y-m-d H:i:s') : null;
        $r->issued_at       = gmdate('Y-m-d H:i:s');
        $r->save();

        return $r;
    }

    public function test_a_paid_donation_with_no_receipt_can_be_removed(): void
    {
        $donation = $this->paid();

        $this->deleter()->delete($donation, null, false);

        $this->assertNull(Donation::query()->find('id', (int) $donation->id));
    }

    public function test_a_standing_receipt_refuses_the_delete_and_says_what_to_do(): void
    {
        $donation = $this->paid();
        $this->receiptFor($donation, false);

        try {
            $this->deleter()->delete($donation, null, false);
            $this->fail('a receipted donation must not be removed while the receipt stands');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('receipt', strtolower($e->getMessage()));
        }

        $this->assertNotNull(Donation::query()->find('id', (int) $donation->id));
    }

    public function test_a_voided_receipt_lets_it_go(): void
    {
        $donation = $this->paid();
        $this->receiptFor($donation, true);

        $this->deleter()->delete($donation, null, false);

        $this->assertNull(Donation::query()->find('id', (int) $donation->id));
    }

    public function test_a_refunded_donation_can_be_removed(): void
    {
        $donation = $this->paid(['status' => 'refunded', 'refunded_cents' => 5000]);

        $this->deleter()->delete($donation, null, false);

        $this->assertNull(Donation::query()->find('id', (int) $donation->id));
    }

    /**
     * The money figures are recomputed rather than left describing a row that
     * no longer exists. Nothing listened to the delete before, which was safe
     * only while every deletable row contributed nothing.
     */
    public function test_removing_a_paid_donation_takes_its_money_out_of_the_campaign(): void
    {
        $now            = gmdate('Y-m-d H:i:s');
        $campaign       = Campaign::make();
        $campaign->title      = 'Deletable total';
        $campaign->slug       = 'deletable-' . uniqid();
        $campaign->status     = 'published';
        $campaign->created_at = $now;
        $campaign->updated_at = $now;
        $campaign->save();

        $donation = $this->paid(['campaign_id' => (int) $campaign->id]);
        Plugin::instance()->container->get(AggregateSyncer::class)
            ->syncCampaign((int) $campaign->id);

        $before = (int) Campaign::query()->find('id', (int) $campaign->id)->raised_cents;
        $this->assertSame(5000, $before, 'the donation is in the total to begin with');

        $this->deleter()->delete($donation, null, false);

        $this->assertSame(
            $before - 5000,
            (int) Campaign::query()->find('id', (int) $campaign->id)->raised_cents,
            'the campaign total no longer counts a donation that is gone'
        );
    }

    /**
     * Reachable without the bin, because a settled row cannot enter one.
     * Requiring it would leave the wider gate unusable from the screen.
     */
    public function test_the_route_removes_a_settled_row_that_never_passed_through_the_bin(): void
    {
        $donation = $this->paid();

        $request = new WP_REST_Request('POST', '/gratora/v1/admin/donations/delete');
        $request->set_param('references', [(string) $donation->reference]);
        $request->set_param('confirmation', 'DELETE');
        $request->set_param('delete_donors', false);

        $response = rest_do_request($request);

        $this->assertSame(200, $response->get_status(), (string) wp_json_encode($response->get_data()));
        $this->assertSame([], $response->get_data()['refused']);
        $this->assertNull(Donation::query()->find('id', (int) $donation->id));
    }

    /** An unsettled row still has to go through the bin, which stops it. */
    public function test_an_unsettled_row_is_still_sent_to_the_bin_first(): void
    {
        $donation = $this->paid(['status' => 'pending', 'paid_at' => null, 'gateway_txn_id' => null]);

        $request = new WP_REST_Request('POST', '/gratora/v1/admin/donations/delete');
        $request->set_param('references', [(string) $donation->reference]);
        $request->set_param('confirmation', 'DELETE');
        $request->set_param('delete_donors', false);

        $refused = rest_do_request($request)->get_data()['refused'];

        $this->assertCount(1, $refused);
        $this->assertStringContainsString('trash', strtolower((string) $refused[0]['reason']));
        $this->assertNotNull(Donation::query()->find('id', (int) $donation->id));
    }

    private function refundFor(Donation $donation): int
    {
        DB::table('gratora_refunds')->insert([
            'donation_id'  => (int) $donation->id,
            'amount_cents' => (int) $donation->amount_cents,
            'currency'     => (string) $donation->currency,
            'initiated_by' => 'admin',
            'status'       => 'succeeded',
            'occurred_at'  => gmdate('Y-m-d H:i:s'),
        ]);

        return (int) DB::table('gratora_refunds')
            ->where('donation_id', (int) $donation->id)
            ->count();
    }

    /**
     * A receipt row keyed to an id nothing answers to is unreachable from every
     * screen and is dropped outright by the importer, so it is not a record of
     * anything. The rows go with the donation.
     */
    public function test_deleting_a_donation_takes_its_receipt_and_refund_rows_with_it(): void
    {
        $donation = $this->paid(['status' => 'refunded', 'refunded_cents' => 5000]);
        $this->receiptFor($donation, true);
        $this->refundFor($donation);
        $id = (int) $donation->id;

        $this->deleter()->delete($donation, null, false);

        $this->assertSame(
            0,
            (int) DB::table('gratora_receipts')->where('donation_id', $id)->count(),
            'a receipt pointing at a donation that is gone is not a record'
        );
        $this->assertSame(
            0,
            (int) DB::table('gratora_refunds')->where('donation_id', $id)->count(),
            'and the donor cascade already removed these, so the two paths agreed on nothing'
        );
    }

    /**
     * The counter is never rolled back, so the sequence keeps a gap. The audit
     * row is the only thing that can name the number, and the event that used
     * to carry it is destroyed by this same delete.
     */
    public function test_the_audit_row_names_the_receipt_numbers_it_removed(): void
    {
        $donation = $this->paid(['status' => 'refunded', 'refunded_cents' => 5000]);
        $receipt  = $this->receiptFor($donation, true);
        $number   = (string) $receipt->receipt_number;

        $this->deleter()->delete($donation, null, false);

        $rows = Event::query()->where('type', 'donation.deleted')->getAll();
        $this->assertNotSame([], $rows);

        $payload = (array) $rows[count($rows) - 1]->payload;
        $this->assertArrayHasKey('receipts', $payload, 'the removal names what it took');
        $this->assertStringContainsString(
            $number,
            (string) wp_json_encode($payload['receipts']),
            'an auditor asking about this number has somewhere to find it'
        );
    }

    /** Trash stops a payment. A settled one has nothing left to stop. */
    public function test_a_paid_donation_still_cannot_be_binned(): void
    {
        $donation = $this->paid();

        $outcome = Plugin::instance()->container->get(DonationTrasher::class)->trash($donation);

        $this->assertNotSame(TrashOutcome::TRASHED, $outcome->outcome);
        $this->assertNull(Donation::query()->find('id', (int) $donation->id)->trashed_at);
    }
}
