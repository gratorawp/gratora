<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use Gratora\Campaigns\CampaignService;
use Gratora\Cli\DemoSeeder;
use Gratora\Donations\AggregateSyncer;
use Gratora\Donations\Donation;
use Gratora\Donations\DonationService;
use Gratora\Donors\DonorService;
use Gratora\Foundation\Plugin;
use Gratora\Foundation\Time\Clock;
use Gratora\Foundation\Time\FrozenClock;
use Gratora\Funds\FundService;
use Gratora\Recurring\RecurringPlanRepository;

/**
 * The seed draws a time of day inside the giving hours, which run to 21:59, so
 * the rows it dates today land after the moment it wrote them unless it runs
 * late in the evening. A donation that has not happened yet renders nowhere:
 * the relative formatters decline a non-positive delta and fall back to a bare
 * date, so one row in each feed reads differently from the ones around it.
 *
 * The clock is frozen at 08:00 because that is what makes the assertion mean
 * something: nearly the whole drawing window is then in the future, where an
 * unclamped seed writes a future row every run rather than once in a while.
 */
final class DemoSeedStampsTest extends IntegrationTestCase
{
    private const SEEDED_AT = '2026-05-13 08:00:00';

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/wp-cli-double.php';
        parent::setUpBeforeClass();
    }

    private function seeder(): DemoSeeder
    {
        $c = Plugin::instance()->container;

        return new DemoSeeder(
            $c->get(DonationService::class),
            $c->get(DonorService::class),
            $c->get(CampaignService::class),
            $c->get(FundService::class),
            $c->get(AggregateSyncer::class),
            $c->get(RecurringPlanRepository::class),
            new FrozenClock(new DateTimeImmutable(self::SEEDED_AT, new DateTimeZone('UTC'))),
        );
    }

    public function test_no_seeded_donation_is_dated_after_the_run_that_wrote_it(): void
    {
        $this->seeder()->run(static fn (string $line) => null);

        $ahead = array_map(
            static fn ($row) => (string) $row->created_at,
            Donation::query()->where('created_at', self::SEEDED_AT, '>')->getAll()
        );

        $this->assertSame([], $ahead, 'a seeded donation is dated later than the seed run');
    }

    public function test_the_clamp_leaves_the_year_of_data_spread_out(): void
    {
        $this->seeder()->run(static fn (string $line) => null);

        $days = array_unique(array_map(
            static fn ($row) => substr((string) $row->created_at, 0, 10),
            Donation::query()->getAll()
        ));

        $this->assertGreaterThan(200, count($days), 'the clamp collapsed the spread of dates');
    }
}
