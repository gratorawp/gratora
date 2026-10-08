<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Donors\Donor;
use Gratora\Donors\DonorQueries;
use Gratora\Donors\DonorRepository;
use Gratora\Foundation\Plugin;
use Gratora\Vendor\Queryable\DB;

/**
 * The insights screen counts donors by lifecycle stage, and a plugin that
 * lists the donors of a stage has to mean the same donors. The stage is cut in
 * one place, and both ask it.
 */
final class DonorLifecyclePredicateTest extends IntegrationTestCase
{
    private const TODAY = '2026-06-26';

    /**
     * A last donation on each side of each cut, at the cut and on the cut's
     * own day: days before TODAY, time of day, stage.
     */
    private const SEEDED = [
        'later today'            => [0, '23:59:59', 'active'],
        'day after active cut'   => [89, '00:00:00', 'active'],
        'on active cut day'      => [90, '12:00:00', 'active'],
        'at active cut'          => [90, '00:00:00', 'active'],
        'second before active'   => [91, '23:59:59', 'at_risk'],
        'day after at-risk cut'  => [179, '00:00:00', 'at_risk'],
        'on at-risk cut day'     => [180, '12:00:00', 'at_risk'],
        'at at-risk cut'         => [180, '00:00:00', 'at_risk'],
        'second before at-risk'  => [181, '23:59:59', 'lapsed'],
        'day after lapsed cut'   => [364, '00:00:00', 'lapsed'],
        'on lapsed cut day'      => [365, '12:00:00', 'lapsed'],
        'at lapsed cut'          => [365, '00:00:00', 'lapsed'],
        'second before lapsed'   => [366, '23:59:59', 'lost'],
        'long ago'               => [1000, '12:00:00', 'lost'],
    ];

    private function repo(): DonorRepository
    {
        return Plugin::instance()->container->get(DonorRepository::class);
    }

    private function seedDonor(string $label, ?string $lastDonationAt): int
    {
        $now = gmdate('Y-m-d H:i:s');

        $donor = Donor::make();
        $donor->email_hash          = hash('sha256', $label . uniqid('', true));
        $donor->email_encrypted     = 'enc:' . $label;
        $donor->donations_count     = $lastDonationAt === null ? 0 : 1;
        $donor->total_donated_cents = $lastDonationAt === null ? 0 : 5000;
        $donor->first_donation_at   = $lastDonationAt;
        $donor->last_donation_at    = $lastDonationAt;
        $donor->created_at          = $now;
        $donor->updated_at          = $now;
        $donor->save();

        return (int) $donor->id;
    }

    /** @return array<string,list<int>> donor ids by stage, ascending */
    private function seedAroundEveryCut(): array
    {
        $byStage = ['active' => [], 'at_risk' => [], 'lapsed' => [], 'lost' => []];

        foreach (self::SEEDED as $label => [$daysAgo, $time, $stage]) {
            $day = (new \DateTimeImmutable(self::TODAY))->modify("-{$daysAgo} days")->format('Y-m-d');
            $byStage[$stage][] = $this->seedDonor($label, "{$day} {$time}");
        }

        return $byStage;
    }

    /** @return list<int> */
    private function idsWhere(string $condition): array
    {
        $rows = Donor::query()->whereRaw($condition)->select('id')->orderBy('id', 'ASC')->getAll();

        return array_map(static fn ($row): int => (int) $row->id, $rows);
    }

    public function test_the_kpi_counts_a_last_donation_on_a_cuts_own_day_in_the_nearer_stage(): void
    {
        $this->seedAroundEveryCut();

        $kpi = $this->repo()->lifecycleKpi(self::TODAY);

        $this->assertSame(
            ['total' => 14, 'active' => 4, 'at_risk' => 4, 'lapsed' => 4, 'lost' => 2],
            array_intersect_key($kpi, array_flip(['total', 'active', 'at_risk', 'lapsed', 'lost']))
        );
    }

    public function test_the_kpi_cuts_where_its_caller_says(): void
    {
        $this->seedAroundEveryCut();

        $kpi = $this->repo()->lifecycleKpi(self::TODAY, 30, 100, 200, 400);

        $this->assertSame(
            ['active' => 5, 'at_risk' => 4, 'lapsed' => 4, 'lost' => 1],
            array_intersect_key($kpi, array_flip(['active', 'at_risk', 'lapsed', 'lost']))
        );
    }

    public function test_the_at_risk_list_holds_the_donors_between_its_two_cuts(): void
    {
        $byStage = $this->seedAroundEveryCut();

        $list = $this->repo()->listAtRisk(self::TODAY);
        $ids  = array_map(static fn (array $row): int => (int) $row['id'], $list['rows']);
        sort($ids);

        $this->assertSame($byStage['at_risk'], $ids);
        $this->assertSame(4, (int) $list['total']);
    }

    public function test_the_at_risk_list_cuts_where_its_caller_says(): void
    {
        $byStage = $this->seedAroundEveryCut();

        $list = $this->repo()->listAtRisk(self::TODAY, 1, 25, 100, 200);
        $ids  = array_map(static fn (array $row): int => (int) $row['id'], $list['rows']);
        sort($ids);

        $this->assertSame([...array_slice($byStage['at_risk'], 1), $byStage['lapsed'][0]], $ids);
        $this->assertSame(4, (int) $list['total']);
    }

    public function test_each_stage_selects_exactly_the_donors_the_kpi_counts_for_it(): void
    {
        $byStage = $this->seedAroundEveryCut();
        $this->seedDonor('never gave', null);

        $kpi = $this->repo()->lifecycleKpi(self::TODAY);

        $this->assertSame(array_keys($byStage), DonorRepository::LIFECYCLE_STAGES);

        foreach (DonorRepository::LIFECYCLE_STAGES as $stage) {
            $ids = $this->idsWhere(
                DonorQueries::notRedactedPredicate() . ' AND ' . DonorRepository::lifecyclePredicate($stage, self::TODAY)
            );

            $this->assertSame($byStage[$stage], $ids, $stage);
            $this->assertCount((int) $kpi[$stage], $ids, $stage);
        }
    }

    public function test_an_alias_puts_each_condition_on_the_table_it_names(): void
    {
        $byStage = $this->seedAroundEveryCut();
        $prefix  = DB::getPrefix();

        $rows = DB::raw(
            "SELECT a.id AS lapsed_id, b.id AS lost_id
               FROM {$prefix}gratora_donors a
               JOIN {$prefix}gratora_donors b ON b.id <> a.id
              WHERE " . DonorRepository::lifecyclePredicate('lapsed', self::TODAY, 'a')
            . ' AND ' . DonorRepository::lifecyclePredicate('lost', self::TODAY, 'b')
        )['rows'];

        $lapsed = array_values(array_unique(array_map(static fn ($row): int => (int) $row->lapsed_id, $rows)));
        $lost   = array_values(array_unique(array_map(static fn ($row): int => (int) $row->lost_id, $rows)));
        sort($lapsed);
        sort($lost);

        $this->assertCount(count($byStage['lapsed']) * count($byStage['lost']), $rows);
        $this->assertSame($byStage['lapsed'], $lapsed);
        $this->assertSame($byStage['lost'], $lost);
    }

    /** @dataProvider notStages */
    public function test_an_unknown_stage_is_refused(string $stage): void
    {
        $this->expectException(\InvalidArgumentException::class);

        DonorRepository::lifecyclePredicate($stage, self::TODAY);
    }

    /** @return array<string,array{0:string}> */
    public function notStages(): array
    {
        return [
            'new is a count of the kpi, not a stage' => ['new'],
            'total'                                  => ['total'],
            'another case'                           => ['Active'],
            'empty'                                  => [''],
        ];
    }

    /** @dataProvider notDays */
    public function test_a_day_that_is_not_a_date_written_out_is_refused(string $today): void
    {
        $this->expectException(\InvalidArgumentException::class);

        DonorRepository::lifecyclePredicate('active', $today);
    }

    /** @return array<string,array{0:string}> */
    public function notDays(): array
    {
        return [
            'empty, which a date parser reads as now' => [''],
            'now'                                     => ['now'],
            'a relative day'                          => ['yesterday'],
            'not a date at all'                       => ['soon enough'],
            'a day the month does not have'           => ['2026-02-30'],
            'no leading zeros'                        => ['2026-6-26'],
            'another order'                           => ['26.06.2026'],
            'a time after it'                         => ['2026-06-26 10:00:00'],
            'an ISO stamp'                            => ['2026-06-26T00:00:00+00:00'],
            'a space before it'                       => [' 2026-06-26'],
        ];
    }

    public function test_a_donor_who_never_gave_is_in_no_stage(): void
    {
        $neverGave = $this->seedDonor('never gave', null);

        foreach (DonorRepository::LIFECYCLE_STAGES as $stage) {
            $this->assertNotContains(
                $neverGave,
                $this->idsWhere(DonorRepository::lifecyclePredicate($stage, self::TODAY)),
                $stage
            );
        }
    }

    public function test_an_erased_donor_is_left_for_the_caller_to_exclude(): void
    {
        $erased = $this->seedDonor('erased', '2026-03-01 12:00:00');
        Donor::query()->where('id', $erased)->update(['redacted_at' => gmdate('Y-m-d H:i:s')]);

        $atRisk = DonorRepository::lifecyclePredicate('at_risk', self::TODAY);

        $this->assertSame([$erased], $this->idsWhere($atRisk));
        $this->assertSame([], $this->idsWhere(DonorQueries::notRedactedPredicate() . ' AND ' . $atRisk));
        $this->assertSame(0, (int) $this->repo()->lifecycleKpi(self::TODAY)['at_risk']);
    }
}
