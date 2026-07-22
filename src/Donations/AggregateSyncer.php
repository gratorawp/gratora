<?php

declare(strict_types=1);

namespace Dono\Donations;

use Dono\Vendor\Queryable\DB;

/**
 * Recomputes denormalised donation aggregates for a donor, campaign, or form.
 *
 * @version 1.0.0
 */
final class AggregateSyncer
{
    public function syncDonor(int $donorId): void
    {
        if ($donorId <= 0) return;

        // Lifetime rollups are pure donation history: non-donation kinds
        // (event ticket orders) ride the rails but do not count here.
        $row = DonationQueries::live(DB::table('dono_donations')
            ->whereIn('status', ['paid', 'partial_refund'])
            ->where('kind', 'donation')
            ->where('donor_id', $donorId))
            ->selectRaw("
                COALESCE(SUM(
                    COALESCE(base_amount_cents, 0) - {$this->refundedSubquery()}
                ), 0) AS total_cents,
                COUNT(*)                       AS cnt,
                MIN(paid_at)                   AS first_paid,
                MAX(paid_at)                   AS last_paid
            ")
            ->get();

        DB::table('dono_donors')
            ->where('id', $donorId)
            ->update([
                'total_donated_cents' => (int) ($row['total_cents'] ?? 0),
                'donations_count'     => (int) ($row['cnt']         ?? 0),
                'first_donation_at'   => $row['first_paid'] ?? null,
                'last_donation_at'    => $row['last_paid']  ?? null,
                'updated_at'          => gmdate('Y-m-d H:i:s'),
            ]);
    }

    public function syncCampaign(int $campaignId): void
    {
        if ($campaignId <= 0) return;

        $row = DonationQueries::live(DB::table('dono_donations')
            ->whereIn('status', ['paid', 'partial_refund'])
            ->where('campaign_id', $campaignId))
            ->selectRaw("
                COALESCE(SUM(
                    COALESCE(base_amount_cents, 0) - {$this->refundedSubquery()}
                ), 0) AS raised,
                COUNT(*)                        AS donations,
                COUNT(DISTINCT donor_id)        AS donors
            ")
            ->get();

        DB::table('dono_campaigns')
            ->where('id', $campaignId)
            ->update([
                'raised_cents'    => (int) ($row['raised']    ?? 0),
                'donations_count' => (int) ($row['donations'] ?? 0),
                'donors_count'    => (int) ($row['donors']    ?? 0),
                'updated_at'      => gmdate('Y-m-d H:i:s'),
            ]);
    }

    public function syncFund(int $fundId): void
    {
        if ($fundId <= 0) return;

        $row = DonationQueries::live(DB::table('dono_donations')
            ->whereIn('status', ['paid', 'partial_refund'])
            ->where('fund_id', $fundId))
            ->selectRaw("
                COALESCE(SUM(
                    COALESCE(base_amount_cents, 0) - {$this->refundedSubquery()}
                ), 0) AS raised,
                COUNT(*)                        AS donations,
                COUNT(DISTINCT donor_id)        AS donors,
                MAX(paid_at)                    AS last_paid
            ")
            ->get();

        DB::table('dono_funds')
            ->where('id', $fundId)
            ->update([
                'raised_cents'    => (int) ($row['raised']    ?? 0),
                'donations_count' => (int) ($row['donations'] ?? 0),
                'donors_count'    => (int) ($row['donors']    ?? 0),
                'last_paid_at'    => $row['last_paid'] ?? null,
                'updated_at'      => gmdate('Y-m-d H:i:s'),
            ]);
    }

    public function syncForm(int $formId): void
    {
        if ($formId <= 0) return;

        $row = DonationQueries::live(DB::table('dono_donations')
            ->whereIn('status', ['paid', 'partial_refund'])
            ->where('form_id', $formId))
            ->selectRaw("
                COALESCE(SUM(
                    COALESCE(base_amount_cents, 0) - {$this->refundedSubquery()}
                ), 0) AS raised,
                COUNT(*)                        AS donations,
                COUNT(DISTINCT donor_id)        AS donors,
                MIN(paid_at)                    AS first_paid,
                MAX(paid_at)                    AS last_paid
            ")
            ->get();

        $now = gmdate('Y-m-d H:i:s');

        // Separate table: only donation-type forms have donation aggregates.
        DB::table('dono_form_donation_stats')->upsert(
            [
                'form_id'         => $formId,
                'raised_cents'    => (int) ($row['raised']    ?? 0),
                'donations_count' => (int) ($row['donations'] ?? 0),
                'donors_count'    => (int) ($row['donors']    ?? 0),
                'first_paid_at'   => $row['first_paid'] ?? null,
                'last_paid_at'    => $row['last_paid']  ?? null,
                'updated_at'      => $now,
            ],
            ['form_id'],
            ['raised_cents', 'donations_count', 'donors_count', 'first_paid_at', 'last_paid_at', 'updated_at'],
        );
    }

    // Nets refunded cents out of SUM(amount) so partial refunds reduce the
    // total instead of dropping the whole donation; the refunds table is the
    // source of truth for the netted figure, independent of the donation's
    // refunded_cents over-refund counter.
    // Fully-qualified table name on both sides: unqualified `id` would bind
    // to wp_dono_refunds.id since refunds also has an id column.
    private function refundedSubquery(): string
    {
        return DonationQueries::refundedBaseExpr();
    }
}
