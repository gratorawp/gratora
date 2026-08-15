<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donations\Donation;
use Dono\Donations\DonationQueries;
use Dono\Donations\DonationRepository;
use Dono\Donors\DonorService;
use Dono\Foundation\Plugin;

/**
 * A year-end statement is a document the donor files with a tax office, so the
 * year it covers has to be the donor's year.
 *
 * paid_at is stored UTC and every printed date goes through wp_date() into the
 * site's timezone. Filtering a local year against UTC timestamps puts a late
 * December donation on the next year's statement while its own line prints
 * December date.
 */
final class StatementYearBoundaryTest extends IntegrationTestCase
{
    private ?string $originalTz = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalTz = get_option('timezone_string');
        update_option('timezone_string', 'America/New_York');
    }

    protected function tearDown(): void
    {
        update_option('timezone_string', $this->originalTz);
        parent::tearDown();
    }

    private function paidAtUtc(int $donorId, string $utc, string $kind = 'donation'): Donation
    {
        $d = Donation::make();
        $d->reference         = 'REF-' . uniqid();
        $d->status            = 'paid';
        $d->gateway           = 'offline';
        $d->kind              = $kind;
        $d->donor_id          = $donorId;
        $d->amount_cents      = 5000;
        $d->base_amount_cents = 5000;
        $d->currency          = 'USD';
        $d->is_test           = false;
        $d->created_at        = $utc;
        $d->paid_at           = $utc;
        $d->save();

        return $d;
    }

    private function donor(): int
    {
        return (int) Plugin::instance()->container->get(DonorService::class)
            ->findOrCreate('stmt-' . uniqid() . '@example.test')->id;
    }

    public function test_the_window_is_the_org_s_year_not_utc_s(): void
    {
        [$start, $end] = DonationQueries::yearBoundsUtc(2025);

        // New York is UTC-5 in January, so the org's year opens at 05:00 UTC.
        $this->assertSame('2025-01-01 05:00:00', $start);
        $this->assertSame('2026-01-01 04:59:59', $end);
    }

    public function test_a_late_december_gift_stays_in_the_year_it_was_given(): void
    {
        $donorId = $this->donor();

        // 22:00 on 31 Dec 2025 in New York, which is 03:00 on 1 Jan 2026 UTC.
        // Its printed line says December 2025, so the statement it lands on
        // has to say 2025 too.
        $this->paidAtUtc($donorId, '2026-01-01 03:00:00');

        $rows = Plugin::instance()->container->get(DonationRepository::class)
            ->paidForDonorInYear($donorId, 2025);

        $this->assertCount(1, $rows, 'the donation belongs to the year the donor gave it in');
    }

    public function test_that_gift_is_not_also_on_the_following_year(): void
    {
        $donorId = $this->donor();
        $this->paidAtUtc($donorId, '2026-01-01 03:00:00');

        $rows = Plugin::instance()->container->get(DonationRepository::class)
            ->paidForDonorInYear($donorId, 2026);

        $this->assertSame([], $rows, 'counted twice is as wrong as counted once in the wrong place');
    }

    public function test_the_donor_s_own_statement_excludes_ticket_orders_too(): void
    {
        // The portal builder is the copy the donor downloads. It read live()
        // while the admin's read donationsOnly(), so the two documents for the
        // same donor and year disagreed, and the donor's was the higher one.
        $donorId = $this->donor();
        $this->paidAtUtc($donorId, '2025-06-02 12:00:00', 'order');

        $donor = \Dono\Donors\Donor::query()->where('id', $donorId)->get();
        $pdf   = Plugin::instance()->container
            ->get(\Dono\Donors\Portal\AnnualStatementBuilder::class)
            ->build($donor, 2025);

        $this->assertSame('', $pdf, 'a year with nothing but a ticket purchase is not a statement');
    }

    public function test_a_ticket_order_is_not_on_a_deductible_statement(): void
    {
        $donorId = $this->donor();
        $this->paidAtUtc($donorId, '2025-06-01 12:00:00');
        $this->paidAtUtc($donorId, '2025-06-02 12:00:00', 'order');

        $rows = Plugin::instance()->container->get(DonationRepository::class)
            ->paidForDonorInYear($donorId, 2025);

        $this->assertCount(1, $rows, 'a gala ticket is goods received, not a donation');
    }
}
