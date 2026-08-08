<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donors\Donor;
use Dono\Donors\DonorRepository;
use Dono\Foundation\Plugin;

/**
 * A lifetime-value ranking is a list of people who gave. Without a floor it
 * filled to its limit with everyone else at 0.00, so a site with three donors
 * showed seventeen rows of nobody and the leaderboard said nothing.
 */
final class TopDonorsRankingTest extends IntegrationTestCase
{
    private function donors(): DonorRepository
    {
        return Plugin::instance()->container->get(DonorRepository::class);
    }

    private function donor(string $name, int $cents, int $count = 1): Donor
    {
        $d = Donor::make();
        $d->email_hash      = hash('sha256', uniqid($name, true));
        $d->email_encrypted = 'x';
        $d->first_name      = $name;
        $d->last_name       = 'Ranked';
        $d->total_donated_cents = $cents;
        $d->donations_count = $count;
        $d->created_at      = gmdate('Y-m-d H:i:s');
        $d->updated_at      = $d->created_at;
        $d->save();

        return $d;
    }

    public function test_a_donor_who_gave_nothing_is_not_in_the_ranking(): void
    {
        $this->donor('Gave', 5000);
        $this->donor('Nothing', 0, 0);

        $names = array_column($this->donors()->topByLifetimeValue(20), 'first_name');

        $this->assertContains('Gave', $names);
        $this->assertNotContains('Nothing', $names);
    }

    /** The query has to actually run: two whereRaw fragments do not parse. */
    public function test_the_ranking_query_runs(): void
    {
        $this->donor('Runs', 100);

        $rows = $this->donors()->topByLifetimeValue(5);

        $this->assertNotSame([], $rows, 'a donor with a total should come back');
        foreach ($rows as $row) {
            $this->assertGreaterThan(0, (int) $row['total_donated_cents']);
        }
    }

    public function test_it_still_ranks_by_lifetime_value(): void
    {
        $this->donor('Small', 100);
        $this->donor('Large', 900000);
        $this->donor('Middle', 5000);

        $rows = $this->donors()->topByLifetimeValue(20);
        $totals = array_map(static fn (array $r): int => (int) $r['total_donated_cents'], $rows);
        $sorted = $totals;
        rsort($sorted);

        $this->assertSame($sorted, $totals, 'highest first');
        $this->assertSame('Large', $rows[0]['first_name']);
    }

    /** Erased donors were already excluded; the new floor must not undo that. */
    public function test_an_erased_donor_stays_out(): void
    {
        $donor = $this->donor('Erased', 7500);
        $donor->redacted_at = gmdate('Y-m-d H:i:s');
        $donor->save();

        $this->assertNotContains(
            'Erased',
            array_column($this->donors()->topByLifetimeValue(20), 'first_name')
        );
    }
}
