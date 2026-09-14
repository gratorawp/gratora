<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Donations\Donation;
use Gratora\Donors\DonorService;
use Gratora\Donors\DonorMetricsService;
use Gratora\Donors\DonorRepository;
use Gratora\Foundation\Plugin;
use Gratora\Foundation\Time\Clock;
use Gratora\Foundation\Time\FrozenClock;
use Gratora\Foundation\Time\SystemClock;
use DateTimeImmutable;

/**
 * The last cell of every cohort row is the month now running, counted over the
 * days elapsed so far and drawn exactly like the months behind it.
 *
 * On one site that read 7.1% after eleven months between 75 and 79, on every
 * cohort at once, which is retention appearing to collapse rather than a month
 * that is two weeks old. It happens every day of every month, and the heatmap
 * shaded it palest, so the eye found it first.
 *
 * The count is real and stays. What it needed was to say it is not finished.
 */
final class ACohortMonthStillRunningSaysSoTest extends IntegrationTestCase
{
    /**
     * The container is one object for the whole run, so a binding swapped here
     * is swapped for every test after it.
     */
    protected function tearDown(): void
    {
        $c = Plugin::instance()->container;
        $c->bind(Clock::class, static fn (): Clock => new SystemClock());
        $c->bind(DonorMetricsService::class, static fn ($c) => new DonorMetricsService(
            $c->get(DonorRepository::class),
            $c->get(DonorService::class),
            $c->get(\Gratora\Recurring\RecurringPlanRepository::class),
            $c->get(\Gratora\Donors\DonorNoteRepository::class),
            $c->get(\Gratora\Donors\MagicLinkService::class),
            $c->get(Clock::class),
            $c->get(\Gratora\Gateways\GatewayManager::class),
            $c->get(\Gratora\Donors\DonorAvatars::class)
        ));

        parent::tearDown();
    }

    private function repo(): DonorRepository
    {
        return Plugin::instance()->container->get(DonorRepository::class);
    }

    private function donor(string $email): int
    {
        $d = Plugin::instance()->container->get(DonorService::class)
            ->findOrCreate($email, ['first_name' => 'A', 'last_name' => 'B']);

        return (int) $d->id;
    }

    private function donation(int $donorId, string $paidAt): void
    {
        $row = Donation::make();
        $row->donor_id     = $donorId;
        $row->reference    = 'DON-' . bin2hex(random_bytes(4));
        $row->amount_cents = 5000;
        $row->currency     = 'USD';
        $row->status       = 'paid';
        $row->is_test      = false;
        $row->paid_at      = $paidAt;
        $row->created_at   = $paidAt;
        $row->save();
    }

    /** @return array<int,array<string,mixed>> the cohort's cells by offset */
    private function cellsFor(string $cohortMonth, ?string $today = null): array
    {
        foreach ($this->repo()->donorCohortRetention(12, 12, $today)['cohorts'] as $row) {
            if ($row['month'] === $cohortMonth) {
                return $row['retention'];
            }
        }

        $this->fail($cohortMonth . ' is not in the grid');
    }

    public function test_the_month_now_running_is_marked(): void
    {
        $cohortMonth = gmdate('Y-m', strtotime('-2 months'));
        $donor       = $this->donor('running@example.test');

        $this->donation($donor, gmdate('Y-m-05 12:00:00', strtotime('-2 months')));
        $this->donation($donor, gmdate('Y-m-05 12:00:00'));

        $cells = $this->cellsFor($cohortMonth);

        $this->assertTrue($cells[2]['partial'], 'offset 2 is this month, which has not finished');
        $this->assertSame(gmdate('Y-m'), $cells[2]['month']);
    }

    public function test_a_month_that_is_over_is_not_marked(): void
    {
        $cohortMonth = gmdate('Y-m', strtotime('-2 months'));
        $donor       = $this->donor('finished@example.test');

        $this->donation($donor, gmdate('Y-m-05 12:00:00', strtotime('-2 months')));
        $this->donation($donor, gmdate('Y-m-05 12:00:00', strtotime('-1 month')));

        $cells = $this->cellsFor($cohortMonth);

        $this->assertFalse($cells[0]['partial'], 'the cohort month itself is behind us');
        $this->assertFalse($cells[1]['partial'], 'and so is the month after it');
    }

    /** The figure is real and still reported: only its standing changes. */
    public function test_the_count_is_unchanged(): void
    {
        $cohortMonth = gmdate('Y-m', strtotime('-2 months'));
        $donor       = $this->donor('counted@example.test');

        $this->donation($donor, gmdate('Y-m-05 12:00:00', strtotime('-2 months')));
        $this->donation($donor, gmdate('Y-m-05 12:00:00'));

        $cells = $this->cellsFor($cohortMonth);

        $this->assertSame(1, (int) $cells[2]['count']);
        $this->assertGreaterThan(0, (float) $cells[2]['pct']);
    }

    /**
     * Driven off the clock it is given rather than the machine's, so the mark
     * lands on the month being reported and not on the month of the run.
     */
    public function test_it_follows_the_date_it_is_asked_about(): void
    {
        $cohortMonth = gmdate('Y-m', strtotime('-2 months'));
        $donor       = $this->donor('asked@example.test');

        $this->donation($donor, gmdate('Y-m-05 12:00:00', strtotime('-2 months')));
        $this->donation($donor, gmdate('Y-m-05 12:00:00', strtotime('-1 month')));

        // Reported as though it were still last month: offset 1 is then the
        // month running and offset 2 is in the future.
        $cells = $this->cellsFor($cohortMonth, gmdate('Y-m-10', strtotime('-1 month')));

        $this->assertTrue($cells[1]['partial']);
        $this->assertFalse($cells[2]['partial']);
    }

    /**
     * Through the service the screen reads, on a clock that is not the
     * machine's. Reading the machine date instead would agree with it on every
     * ordinary run and be wrong for exactly the caller that matters.
     */
    public function test_the_screen_is_served_the_mark_on_its_own_clock(): void
    {
        $donor = $this->donor('served@example.test');
        $this->donation($donor, gmdate('Y-m-05 12:00:00', strtotime('-2 months')));
        $this->donation($donor, gmdate('Y-m-05 12:00:00', strtotime('-1 month')));

        $asOf = gmdate('Y-m-10', strtotime('-1 month'));

        $container = Plugin::instance()->container;
        $container->instance(Clock::class, new FrozenClock(new DateTimeImmutable($asOf . ' 12:00:00')));
        $container->bind(
            DonorMetricsService::class,
            static fn ($c) => new DonorMetricsService(
                $c->get(\Gratora\Donors\DonorRepository::class),
                $c->get(\Gratora\Donors\DonorService::class),
                $c->get(\Gratora\Recurring\RecurringPlanRepository::class),
                $c->get(\Gratora\Donors\DonorNoteRepository::class),
                $c->get(\Gratora\Donors\MagicLinkService::class),
                $c->get(Clock::class),
                $c->get(\Gratora\Gateways\GatewayManager::class),
                $c->get(\Gratora\Donors\DonorAvatars::class)
            )
        );

        $insights = $container->get(DonorMetricsService::class)->insights();

        $this->assertSame(
            substr($asOf, 0, 7),
            $insights['retention']['current_month'] ?? null,
            'the grid follows the clock the service holds'
        );

        $marked = false;
        foreach ($insights['retention']['cohorts'] as $row) {
            foreach ($row['retention'] as $cell) {
                $this->assertArrayHasKey('partial', $cell, 'every cell says whether its month is over');
                if ($cell['partial'] && $cell['count'] > 0) {
                    $marked = true;
                }
            }
        }

        $this->assertTrue($marked, 'a donation in that month lands in a cell the screen can mark');
    }

    /** Every cell says which month it covers, marked or not. */
    public function test_each_cell_names_its_month(): void
    {
        $cohortMonth = gmdate('Y-m', strtotime('-2 months'));
        $donor       = $this->donor('named@example.test');

        $this->donation($donor, gmdate('Y-m-05 12:00:00', strtotime('-2 months')));

        $cells = $this->cellsFor($cohortMonth);

        $this->assertSame($cohortMonth, $cells[0]['month']);
        $this->assertSame(gmdate('Y-m', strtotime('-1 month')), $cells[1]['month']);
    }
}
