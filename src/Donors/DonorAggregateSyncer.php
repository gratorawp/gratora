<?php

declare(strict_types=1);

namespace Dono\Donors;

use Dono\Donations\Donation;
use Dono\Donations\DonationQueries;
use Dono\Vendor\Queryable\DB;

/**
 * Keeps pre-aggregated donor columns in sync when donations are paid, refunded
 * or reversed by the bank.
 *
 * @since 1.0.0
 */
final class DonorAggregateSyncer
{
    /** @since 1.0.0 */
    public function register(): void
    {
        add_action('dono.donation.completed', function (Donation $d): void {
            $this->syncForDonor((int) $d->donor_id);
        });

        add_action('dono.donation.refunded', function (Donation $d): void {
            $this->syncForDonor((int) $d->donor_id);
        });

        // A bank taking money back changes what this donor has given just as a
        // refund does. Without these two a charged-back donor keeps the money
        // in their lifetime total and stays in whatever segment it bought them.
        add_action('dono.donation.disputed', function (Donation $d): void {
            $this->syncForDonor((int) $d->donor_id);
        });

        add_action('dono.donation.reversal_reinstated', function (Donation $d): void {
            $this->syncForDonor((int) $d->donor_id);
        });

        // And the other direction. Winning a chargeback, or a bank refund that
        // failed, puts the money back on the books everywhere else inline, so a
        // donor left out of this keeps a lifetime total and a donation count
        // that are short by the amount they were never actually refunded, and
        // the segment that total buys them is wrong until they give again.
        add_action('dono.donation.refund_reversed', function (Donation $d): void {
            $this->syncForDonor((int) $d->donor_id);
        });
    }

    /** @since 1.0.0 */
    public function syncForDonor(int $donorId): void
    {
        if ($donorId <= 0) return;

        $delta = DB::transaction(function () use ($donorId): ?array {
            $before = $this->lockAndReadDonor($donorId);
            if ($before === null) return null;

            $netExpr = DonationQueries::netBaseExpr();

            // donationsOnly, not live: a ticket order is a purchase, not a
            // donation, and counting it would inflate the buyer's lifetime total.
            $row = DonationQueries::donationsOnly(DB::table('dono_donations')
                ->whereIn('status', ['paid', 'partial_refund'])
                ->where('donor_id', $donorId))
                ->selectRaw("
                    COALESCE(SUM({$netExpr}), 0) AS total_cents,
                    COUNT(*)                       AS cnt,
                    MIN(paid_at)                   AS first_paid,
                    MAX(paid_at)                   AS last_paid
                ")
                ->get();

            $after = [
                'total_donated_cents' => (int) ($row['total_cents'] ?? 0),
                'donations_count'     => (int) ($row['cnt']         ?? 0),
                'first_donation_at'   => $row['first_paid'] ?? null,
                'last_donation_at'    => $row['last_paid']  ?? null,
            ];

            DB::table('dono_donors')
                ->where('id', $donorId)
                ->update([
                    ...$after,
                    'updated_at' => gmdate('Y-m-d H:i:s'),
                ]);

            return ['before' => $before, 'after' => $after];
        });

        if ($delta === null) return;

        $this->fireDeltaHooks($donorId, $delta['before'], $delta['after']);
    }

    /**
     * Row-level write lock so concurrent calls for the same donor serialize.
     *
     * @since 1.0.0
     */
    private function lockAndReadDonor(int $donorId): ?array
    {
        $prefix = DB::getPrefix();

        $result = DB::raw(
            "SELECT total_donated_cents, donations_count, first_donation_at, last_donation_at
             FROM {$prefix}dono_donors
             WHERE id = %d
             FOR UPDATE",
            [$donorId]
        );

        $row = $result['rows'][0] ?? null;
        if (! $row) return null;

        return [
            'total_donated_cents' => (int) ($row->total_donated_cents ?? 0),
            'donations_count'     => (int) ($row->donations_count     ?? 0),
            'first_donation_at'   => $row->first_donation_at ?? null,
            'last_donation_at'    => $row->last_donation_at  ?? null,
        ];
    }

    /** @since 1.0.0 */
    private function fireDeltaHooks(int $donorId, array $before, array $after): void
    {
        do_action('dono.donor.aggregates_synced', $donorId, $after, $before);

        if ($before['donations_count'] === 0 && $after['donations_count'] > 0) {
            do_action('dono.donor.first_donation_completed', $donorId, $after);
        }

        $lapsedDays = max(1, (int) apply_filters('dono.donor.lapsed_threshold_days', 180));
        $prevLast = $before['last_donation_at'];
        $nextLast = $after['last_donation_at'];
        if ($prevLast && $nextLast && $prevLast !== $nextLast) {
            $prevTs = strtotime((string) $prevLast);
            $nextTs = strtotime((string) $nextLast);
            if ($prevTs && $nextTs && ($nextTs - $prevTs) >= $lapsedDays * 86400) {
                do_action('dono.donor.recovered', $donorId, $after, [
                    'previous_last_donation_at' => $prevLast,
                    'lapsed_days_threshold'     => $lapsedDays,
                ]);
            }
        }
    }
}
