<?php

declare(strict_types=1);

namespace Dono\Tests\Unit\Donors;

use Dono\Donors\AtRiskReason;
use PHPUnit\Framework\TestCase;

/**
 * Every at-risk donor is 90 to 180 days silent, so the verdict has to say
 * something the date column does not. Two things can go wrong: the priority
 * order lets arithmetic overrule a recorded fact, or the arithmetic runs on a
 * donor who has no rhythm to measure.
 */
final class AtRiskReasonTest extends TestCase
{
    private const TODAY = '2026-08-09';

    /** @param array<string,mixed> $row */
    private function classify(array $row, ?array $plan = null): array
    {
        return AtRiskReason::classify($row + [
            'donations_count'   => 1,
            'first_donation_at' => null,
            'last_donation_at'  => '2026-05-01',
        ], $plan, self::TODAY);
    }

    private function plan(int $failing = 0, int $paused = 0, int $live = 0, ?string $cancelledAt = null): array
    {
        return ['failing' => $failing, 'paused' => $paused, 'live' => $live, 'cancelled_at' => $cancelledAt];
    }

    public function test_a_failing_plan_outranks_a_paused_one(): void
    {
        $out = $this->classify([], $this->plan(failing: 1, paused: 1));

        $this->assertSame(AtRiskReason::PLAN_FAILING, $out['key']);
    }

    /**
     * A donor who arranged their own silence must never be told they are
     * overdue. This is the canary for the whole fact-beats-inference boundary.
     */
    public function test_a_recorded_plan_state_outranks_the_rhythm(): void
    {
        $out = $this->classify([
            'donations_count'   => 12,
            'first_donation_at' => '2025-06-01',
            'last_donation_at'  => '2026-03-12',
        ], $this->plan(paused: 1));

        $this->assertSame(AtRiskReason::PLAN_PAUSED, $out['key']);
        $this->assertNull($out['avg_gap_days'], 'no gap is claimed when none was measured');
    }

    public function test_one_donation_has_no_rhythm_to_measure(): void
    {
        $out = $this->classify([
            'donations_count'   => 1,
            'first_donation_at' => '2026-05-01',
            'last_donation_at'  => '2026-05-01',
        ]);

        $this->assertSame(AtRiskReason::FIRST_GIFT_ONLY, $out['key']);
        $this->assertNull($out['avg_gap_days']);
    }

    public function test_two_widely_spaced_donations_do_give_an_average(): void
    {
        // Nearly half of a real at-risk list has exactly two gifts. With a wide
        // span the mean of one interval is literally their average gap.
        $out = $this->classify([
            'donations_count'   => 2,
            'first_donation_at' => '2025-03-01',
            'last_donation_at'  => '2026-04-05',
        ]);

        $this->assertSame(AtRiskReason::WITHIN_GAP, $out['key']);
        $this->assertSame(400, $out['avg_gap_days']);
    }

    public function test_two_donations_a_day_apart_are_one_episode(): void
    {
        $out = $this->classify([
            'donations_count'   => 2,
            'first_donation_at' => '2026-04-04',
            'last_donation_at'  => '2026-04-05',
        ]);

        $this->assertSame(AtRiskReason::NO_GAP_YET, $out['key'], 'a 1-day gap would read as 126x overdue');
        $this->assertNull($out['avg_gap_days']);
    }

    /**
     * @dataProvider bands
     */
    public function test_the_bands_sit_where_they_claim(int $silentDays, string $expected): void
    {
        // 7 gifts over 360 days = a 60-day average gap.
        $last = date('Y-m-d', strtotime(self::TODAY . " -{$silentDays} days"));
        $first = date('Y-m-d', strtotime($last . ' -360 days'));

        $out = AtRiskReason::classify([
            'donations_count'   => 7,
            'first_donation_at' => $first,
            'last_donation_at'  => $last,
        ], null, self::TODAY);

        $this->assertSame(60, $out['avg_gap_days']);
        $this->assertSame($expected, $out['key']);
    }

    /** @return array<string,array{0:int,1:string}> */
    public function bands(): array
    {
        return [
            'a day short of the average' => [59, AtRiskReason::WITHIN_GAP],
            'exactly the average'        => [60, AtRiskReason::PAST_GAP],
            'a day short of double'      => [119, AtRiskReason::PAST_GAP],
            'double the average'         => [120, AtRiskReason::WELL_PAST_GAP],
        ];
    }

    public function test_a_cancellation_long_before_the_last_gift_is_not_the_cause(): void
    {
        $row = [
            'donations_count'   => 7,
            'first_donation_at' => '2025-04-14',
            'last_donation_at'  => '2026-04-09',
        ];

        $stale = AtRiskReason::classify($row, $this->plan(cancelledAt: '2025-01-01'), self::TODAY);
        $this->assertNotSame(AtRiskReason::PLAN_CANCELLED, $stale['key'], 'they kept giving after it ended');

        $recent = AtRiskReason::classify($row, $this->plan(cancelledAt: '2026-04-01'), self::TODAY);
        $this->assertSame(AtRiskReason::PLAN_CANCELLED, $recent['key']);
    }

    /**
     * The product calls a plan failing when it carries a decline, whatever the
     * gateway currently calls its status: nothing in the plugin ever writes
     * 'past_due', so keying on that status would never fire.
     */
    public function test_a_decline_on_an_active_plan_is_a_failing_plan(): void
    {
        $out = $this->classify([], $this->plan(failing: 1, live: 1));

        $this->assertSame(AtRiskReason::PLAN_FAILING, $out['key']);
    }

    public function test_every_key_has_a_label(): void
    {
        $labels = AtRiskReason::labels();

        foreach ([
            AtRiskReason::PLAN_FAILING, AtRiskReason::PLAN_PAUSED, AtRiskReason::PLAN_CANCELLED,
            AtRiskReason::PLAN_ACTIVE, AtRiskReason::FIRST_GIFT_ONLY, AtRiskReason::NO_GAP_YET,
            AtRiskReason::WELL_PAST_GAP, AtRiskReason::PAST_GAP, AtRiskReason::WITHIN_GAP,
        ] as $key) {
            $this->assertArrayHasKey($key, $labels);
            $this->assertNotSame('', $labels[$key]);
        }
    }
}
