<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donors\AtRiskReason;
use Dono\Donors\Donor;
use Dono\Donors\DonorMetricsService;
use Dono\Foundation\Plugin;
use Dono\Recurring\RecurringPlan;

/**
 * The at-risk rows carry why each donor is there. The load fixture has no
 * recurring plans and no single-donation donors at all, so every case here
 * seeds its own rows: a green run against seeded data would prove nothing
 * about the branches that matter.
 */
final class AtRiskReasonsTest extends IntegrationTestCase
{
    private function metrics(): DonorMetricsService
    {
        return Plugin::instance()->container->get(DonorMetricsService::class);
    }

    /** A donor inside the 90-180 day at-risk window. */
    private function atRiskDonor(int $count = 6, int $silentDays = 120, int $spanDays = 360): Donor
    {
        $last  = gmdate('Y-m-d H:i:s', strtotime("-{$silentDays} days"));
        $first = gmdate('Y-m-d H:i:s', strtotime($last . " -{$spanDays} days"));

        $d = Donor::make();
        $d->email_encrypted     = 'enc-' . uniqid();
        $d->email_hash          = hash('sha256', uniqid());
        $d->first_name          = 'At';
        $d->last_name           = 'Risk';
        $d->donations_count     = $count;
        $d->total_donated_cents = 5000 * $count;
        $d->first_donation_at   = $first;
        $d->last_donation_at    = $last;
        $d->created_at          = $first;
        $d->updated_at          = $last;
        $d->save();

        return $d;
    }

    private function planFor(Donor $donor, string $status, int $failed = 0, ?string $cancelledAt = null): RecurringPlan
    {
        $p = RecurringPlan::make();
        $p->donor_id                = (int) $donor->id;
        $p->gateway                 = 'stripe';
        $p->gateway_subscription_id = 'sub_' . uniqid();
        $p->amount_cents            = 2500;
        $p->currency                = 'USD';
        $p->interval_unit           = 'month';
        $p->interval_count          = 1;
        $p->status                  = $status;
        $p->failed_renewals_count   = $failed;
        $p->cancelled_at            = $cancelledAt;
        $p->is_test                 = false;
        $p->started_at              = gmdate('Y-m-d H:i:s', strtotime('-2 years'));
        $p->created_at              = $p->started_at;
        $p->updated_at              = $p->started_at;
        $p->save();

        return $p;
    }

    /** @return array<string,mixed>|null */
    private function rowFor(int $donorId): ?array
    {
        foreach ($this->metrics()->atRisk(1, 100)['rows'] as $row) {
            if ((int) $row['id'] === $donorId) return $row;
        }
        return null;
    }

    public function test_a_donor_with_a_declining_plan_reads_as_failing(): void
    {
        $donor = $this->atRiskDonor();
        $this->planFor($donor, 'active', failed: 2);

        $row = $this->rowFor((int) $donor->id);

        $this->assertNotNull($row);
        $this->assertSame(AtRiskReason::PLAN_FAILING, $row['risk_reason']);
        $this->assertNotSame('', $row['risk_reason_label']);
    }

    public function test_a_paused_plan_is_not_reported_as_overdue(): void
    {
        $donor = $this->atRiskDonor();
        $this->planFor($donor, 'paused');

        $this->assertSame(AtRiskReason::PLAN_PAUSED, $this->rowFor((int) $donor->id)['risk_reason']);
    }

    public function test_a_recent_cancellation_is_named(): void
    {
        $donor = $this->atRiskDonor();
        $this->planFor($donor, 'cancelled', cancelledAt: (string) $donor->last_donation_at);

        $this->assertSame(AtRiskReason::PLAN_CANCELLED, $this->rowFor((int) $donor->id)['risk_reason']);
    }

    public function test_a_test_mode_plan_does_not_speak_for_a_live_donor(): void
    {
        $donor = $this->atRiskDonor();
        $plan  = $this->planFor($donor, 'paused');
        $plan->is_test = true;
        $plan->save();

        $row = $this->rowFor((int) $donor->id);

        $this->assertNotSame(AtRiskReason::PLAN_PAUSED, $row['risk_reason']);
        $this->assertSame(72, $row['avg_gap_days'], 'it falls through to their own rhythm');
    }

    public function test_a_donor_with_no_plan_is_measured_against_their_own_gap(): void
    {
        // 6 donations over 360 days = a 72-day average; silent 120 days.
        $donor = $this->atRiskDonor(count: 6, silentDays: 120, spanDays: 360);

        $row = $this->rowFor((int) $donor->id);

        $this->assertSame(72, $row['avg_gap_days']);
        $this->assertSame(AtRiskReason::PAST_GAP, $row['risk_reason']);
    }

    /**
     * The screen was tuned from 42ms to 8ms by removing per-row work. A reason
     * looked up per row would put it straight back.
     */
    public function test_the_reason_costs_one_query_for_the_whole_page(): void
    {
        for ($i = 0; $i < 12; $i++) {
            $this->planFor($this->atRiskDonor(), 'active', failed: 1);
        }

        $count = 0;
        $counter = static function ($sql) use (&$count) {
            $count++;
            return $sql;
        };

        add_filter('query', $counter);
        $this->metrics()->atRisk(1, 25);
        remove_filter('query', $counter);

        // listAtRisk's count, listAtRisk's page, and one grouped plan lookup.
        $this->assertLessThanOrEqual(3, $count, "12 donors cost {$count} queries; a per-row lookup would be 15");
    }

    public function test_the_csv_appends_the_reason_without_moving_a_column(): void
    {
        $donor = $this->atRiskDonor();
        $this->planFor($donor, 'active', failed: 1);

        $rows = array_map('str_getcsv', array_filter(explode("\n", $this->metrics()->atRiskCsv())));
        $head = $rows[0];
        $head[0] = preg_replace('/^\xEF\xBB\xBF/', '', $head[0]);

        $this->assertSame(
            ['id', 'name', 'email', 'country', 'donations', 'total_donated', 'first_donation_at', 'last_donation_at'],
            array_slice($head, 0, 8),
            'the original eight columns keep their positions'
        );
        $this->assertSame(['risk_reason', 'risk_reason_label', 'avg_gap_days'], array_slice($head, 8));
    }
}
