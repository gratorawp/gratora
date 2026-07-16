<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donations\Donation;
use Dono\Donations\DonationRepository;
use Dono\Donations\DonationService;
use Dono\Donations\Refund;
use Dono\Donors\DonorService;
use Dono\Foundation\Plugin;
use Dono\Vendor\Queryable\DB;
use Dono\Vendor\Queryable\QueryException;

/**
 * A redelivered or concurrent gateway refund webhook must record the refund
 * exactly once. The pre-transaction SELECT dedups sequential redeliveries; a
 * UNIQUE(gateway_refund_id) index is the backstop for the concurrent race that
 * the SELECT cannot catch. Double-recording would double-subtract from every
 * money total and re-fire the refund email/event.
 */
final class RefundIdempotencyTest extends IntegrationTestCase
{
    public function test_redelivered_refund_records_once_and_does_not_double_subtract(): void
    {
        $svc      = Plugin::instance()->container->get(DonationService::class);
        $donation = $this->seedPaidDonation(10000);

        $first = $svc->recordExternalRefund($donation, 4000, 'rf_dupe', 'requested_by_customer');

        // Redelivery: re-load (status is now partial_refund) and record again.
        $donation = Plugin::instance()->container->get(DonationRepository::class)->findByReference($donation->reference);
        $second = $svc->recordExternalRefund($donation, 4000, 'rf_dupe', 'requested_by_customer');

        $this->assertSame((int) $first->id, (int) $second->id, 'redelivery returns the existing refund row');
        $this->assertSame(1, (int) Refund::query()->where('gateway_refund_id', 'rf_dupe')->count(), 'exactly one refund row');

        $total = (int) Refund::query()
            ->where('donation_id', (int) $donation->id)
            ->where('status', 'succeeded')
            ->sum('amount_cents');
        $this->assertSame(4000, $total, 'refund is not double-counted');
    }

    public function test_external_refund_clamps_to_the_remaining_refundable_balance(): void
    {
        $svc  = Plugin::instance()->container->get(DonationService::class);
        $repo = Plugin::instance()->container->get(DonationRepository::class);
        $donation = $this->seedPaidDonation(5000);

        $svc->recordExternalRefund($donation, 3000, 'rf_a', 'requested_by_customer');

        // Out-of-order event reports more than the 2000 remaining; it must clamp
        // so cumulative refunded never exceeds the 5000 principal (which would
        // drive net base aggregates negative).
        $donation = $repo->findByReference($donation->reference);
        $svc->recordExternalRefund($donation, 4000, 'rf_b', 'requested_by_customer');

        $total = (int) Refund::query()
            ->where('donation_id', (int) $donation->id)
            ->where('status', 'succeeded')
            ->sum('amount_cents');
        $this->assertSame(5000, $total, 'cumulative refunded is capped at the principal');

        $donation = $repo->findByReference($donation->reference);
        $this->assertSame('refunded', $donation->status, 'reaching the principal marks it fully refunded');

        // Once fully refunded the donation is no longer locally refundable, so
        // a further gateway event is rejected and records no new row.
        $before = (int) Refund::query()->where('donation_id', (int) $donation->id)->count();
        try {
            $svc->recordExternalRefund($donation, 1000, 'rf_c', 'requested_by_customer');
            $this->fail('a fully-refunded donation must not accept another refund');
        } catch (\RuntimeException $e) {
            // expected
        }
        $after = (int) Refund::query()->where('donation_id', (int) $donation->id)->count();
        $this->assertSame($before, $after, 'no refund row recorded once fully refunded');
    }

    public function test_refunded_cents_counter_guards_over_refund_against_a_stale_view(): void
    {
        $svc  = Plugin::instance()->container->get(DonationService::class);
        $repo = Plugin::instance()->container->get(DonationRepository::class);
        $donation = $this->seedPaidDonation(5000);

        // The counter advances in lockstep with SUM(succeeded refunds).
        $svc->recordExternalRefund($donation, 2000, 'rf_track_a', 'requested_by_customer');
        $fresh = $repo->findByReference($donation->reference);
        $this->assertSame(2000, (int) $fresh->refunded_cents, 'counter mirrors the refunded total');

        // Simulate a concurrent refund committing the rest of the balance while
        // this caller still holds a copy that believes 3000 is refundable. The
        // SUM-of-refund-rows pre-clamp cannot see it, but the counter can.
        $stale = $repo->findByReference($donation->reference);
        DB::table('dono_donations')
            ->whereRaw('id = ' . (int) $donation->id)
            ->update(['refunded_cents' => 5000, 'status' => 'refunded']);

        $before = (int) Refund::query()->where('donation_id', (int) $donation->id)->count();
        try {
            $svc->recordExternalRefund($stale, 3000, 'rf_track_b', 'requested_by_customer');
            $this->fail('the atomic counter guard must reject an over-refund on a stale view');
        } catch (\RuntimeException $e) {
            // expected: refunded_cents + 3000 no longer fits the principal
        }

        $after = (int) Refund::query()->where('donation_id', (int) $donation->id)->count();
        $this->assertSame($before, $after, 'no refund row recorded past the principal');
        $final = $repo->findByReference($donation->reference);
        $this->assertSame(5000, (int) $final->refunded_cents, 'counter never exceeds the principal');
    }

    public function test_unique_index_blocks_a_second_row_with_the_same_gateway_id(): void
    {
        $donation = $this->seedPaidDonation(5000);
        $now = gmdate('Y-m-d H:i:s');

        $make = function () use ($donation, $now): void {
            $r = Refund::make();
            $r->donation_id       = (int) $donation->id;
            $r->amount_cents      = 1000;
            $r->currency          = 'USD';
            $r->initiated_by      = 'gateway';
            $r->status            = 'succeeded';
            $r->gateway_refund_id = 'rf_unique';
            $r->occurred_at       = $now;
            $r->save();
        };

        $make();
        $this->expectException(QueryException::class);
        $make(); // same gateway_refund_id -> unique-constraint violation
    }

    private function seedPaidDonation(int $cents): Donation
    {
        $donor = Plugin::instance()->container->get(DonorService::class)
            ->findOrCreate('refund@example.com', ['first_name' => 'R', 'last_name' => 'F']);

        $now = gmdate('Y-m-d H:i:s');
        $d = Donation::make();
        $d->reference         = 'DONO-RF-' . substr(md5((string) $cents . uniqid()), 0, 8);
        $d->donor_id          = (int) $donor->id;
        $d->amount_cents      = $cents;
        $d->net_cents         = $cents;
        $d->currency          = 'USD';
        $d->base_amount_cents = $cents;
        $d->base_currency     = 'USD';
        $d->fx_rate           = '1.00000000';
        $d->gateway           = 'stripe';
        $d->status            = 'paid';
        $d->is_test           = false;
        $d->paid_at           = $now;
        $d->created_at        = $now;
        $d->updated_at        = $now;
        $d->save();
        return $d;
    }
}
