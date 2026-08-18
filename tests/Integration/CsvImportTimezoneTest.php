<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donations\Donation;
use Dono\Donations\DonationRepository;
use Dono\Donors\Donor;
use Dono\Foundation\Identity\IdentityHasher;
use Dono\Foundation\Plugin;
use Dono\Foundation\Transfer\CsvImporter;

/**
 * The date column of someone else's export is the org's calendar, not UTC.
 *
 * Importing the donation history is the normal first day of Dono, and paid_at is
 * read back through the site timezone everywhere it is shown: the donations list,
 * the revenue report, the receipt and the year-end statement. A cell taken as a
 * UTC instant therefore dates every imported donation a day early anywhere west
 * of UTC, and moves a 1 January donation onto the previous year's tax statement,
 * where the donor and the tax office disagree about the same money.
 */
final class CsvImportTimezoneTest extends IntegrationTestCase
{
    private const MAPPING = [
        'email'  => 'Email',
        'amount' => 'Amount',
        'date'   => 'Date',
    ];

    private ?string $originalTz = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalTz = get_option('timezone_string');
    }

    protected function tearDown(): void
    {
        update_option('timezone_string', $this->originalTz);
        parent::tearDown();
    }

    public function test_a_date_only_cell_keeps_its_day_when_the_org_is_behind_utc(): void
    {
        update_option('timezone_string', 'America/New_York');

        $this->import("Email,Amount,Date\nnewyear@example.test,50,2024-01-01\n");

        $donation = $this->donationFor('newyear@example.test');
        $this->assertSame(
            '2024-01-01',
            wp_date('Y-m-d', strtotime((string) $donation->paid_at)),
            'the receipt and the donations list print the day the file gave'
        );
    }

    public function test_a_january_donation_lands_on_its_own_tax_year(): void
    {
        update_option('timezone_string', 'America/New_York');

        $this->import("Email,Amount,Date\ntaxyear@example.test,50,2024-01-01\n");

        $donorId    = $this->donorId('taxyear@example.test');
        $repository = Plugin::instance()->container->get(DonationRepository::class);

        $this->assertCount(1, $repository->paidForDonorInYear($donorId, 2024), 'on the 2024 statement');
        $this->assertCount(0, $repository->paidForDonorInYear($donorId, 2023), 'and on no other');
    }

    public function test_a_date_only_cell_keeps_its_day_when_the_org_is_ahead_of_utc(): void
    {
        update_option('timezone_string', 'Australia/Sydney');

        $this->import("Email,Amount,Date\nsydney@example.test,50,2024-12-31\n");

        $donation = $this->donationFor('sydney@example.test');
        $this->assertSame(
            '2024-12-31 01:00:00',
            (string) $donation->paid_at,
            'noon in Sydney, stored as the UTC instant, so the day cannot slide either way'
        );
        $this->assertSame('2024-12-31', wp_date('Y-m-d', strtotime((string) $donation->paid_at)));
    }

    public function test_a_cell_carrying_a_time_is_the_org_s_wall_clock(): void
    {
        update_option('timezone_string', 'America/New_York');

        $this->import("Email,Amount,Date\nevening@example.test,50,2024-03-15 21:30:00\n");

        $donation = $this->donationFor('evening@example.test');
        $this->assertSame(
            '2024-03-16 01:30:00',
            (string) $donation->paid_at,
            '21:30 in New York is stored as the UTC instant it happened at'
        );
        $this->assertSame('2024-03-15 21:30', wp_date('Y-m-d H:i', strtotime((string) $donation->paid_at)));
    }

    public function test_an_unreadable_cell_is_still_refused(): void
    {
        update_option('timezone_string', 'America/New_York');

        $result = $this->import("Email,Amount,Date\nnodate@example.test,50,not a date\n");

        $this->assertSame(0, $result['donations_imported']);
        $this->assertSame(1, $result['skipped']['invalid_date'] ?? 0);
    }

    /** @return array<string,mixed> */
    private function import(string $csv): array
    {
        return $this->importer()->import($csv, self::MAPPING, false);
    }

    private function importer(): CsvImporter
    {
        return Plugin::instance()->container->get(CsvImporter::class);
    }

    private function donationFor(string $email): Donation
    {
        $donation = Donation::query()->where('donor_id', $this->donorId($email))->get();
        $this->assertNotNull($donation, "no donation imported for {$email}");

        return $donation;
    }

    private function donorId(string $email): int
    {
        $hasher = Plugin::instance()->container->get(IdentityHasher::class);
        $donor  = Donor::query()->where('email_hash', $hasher->emailHash($email))->get();

        return is_array($donor) ? (int) $donor['id'] : (int) ($donor->id ?? 0);
    }
}
