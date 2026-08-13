<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donations\Donation;
use Dono\Donors\DonorService;
use Dono\Exports\RevenueExporter;
use Dono\Foundation\Plugin;
use Dono\Reports\TaxStatementBuilder;

/**
 * The revenue series is what a finance team charts, so a month with nothing in
 * it has to appear as a zero rather than go missing.
 *
 * It is also the org's own account of a year the donor gets a tax statement
 * for. paid_at is stored UTC and the period the org means is a calendar period
 * in its own timezone, so both ends of the window and the month a donation is
 * counted in are resolved there. Cut the year in UTC instead and a 31 December
 * evening donation sits in one year on the board's report and the other on the
 * statement the donor files.
 */
final class RevenueExportTest extends IntegrationTestCase
{
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

    private function exporter(): RevenueExporter
    {
        return Plugin::instance()->container->get(RevenueExporter::class);
    }

    private function statements(): TaxStatementBuilder
    {
        return Plugin::instance()->container->get(TaxStatementBuilder::class);
    }

    private function donor(): int
    {
        return (int) Plugin::instance()->container->get(DonorService::class)
            ->findOrCreate('revenue-' . uniqid() . '@example.test')->id;
    }

    private function paid(string $when, int $cents, ?int $donorId = null): Donation
    {
        $d = Donation::make();
        $d->reference         = 'REF-' . uniqid();
        $d->status            = 'paid';
        $d->gateway           = 'offline';
        $d->kind              = 'donation';
        if ($donorId !== null) {
            $d->donor_id = $donorId;
        }
        $d->amount_cents      = $cents;
        $d->base_amount_cents = $cents;
        $d->currency          = 'USD';
        $d->is_test           = false;
        $d->created_at        = $when;
        $d->paid_at           = $when;
        $d->save();

        return $d;
    }

    /** @return int total cents across the whole series */
    private function seriesTotal(array $series): int
    {
        return array_sum(array_column($series, 'amount_cents'));
    }

    public function test_a_month_with_nothing_is_a_zero_row_not_a_gap(): void
    {
        $this->paid('2026-03-10 12:00:00', 10000);

        $series = $this->exporter()->series('2026-01', '2026-04');

        $this->assertSame(
            ['2026-01', '2026-02', '2026-03', '2026-04'],
            array_column($series, 'month'),
            'every month in the range is present'
        );
        $this->assertSame(0, $series[0]['amount_cents']);
        $this->assertSame(10000, $series[2]['amount_cents']);
    }

    public function test_a_backwards_range_returns_that_range(): void
    {
        // Two month pickers make this trivially reachable, and an empty file
        // reads as "there was no revenue" rather than "you set that backwards".
        $series = $this->exporter()->series('2026-06', '2026-03');

        $this->assertSame('2026-03', $series[0]['month']);
        $this->assertCount(4, $series);
    }

    public function test_a_ticket_order_is_not_donation_revenue(): void
    {
        $this->paid('2026-05-04 12:00:00', 5000);

        $order = $this->paid('2026-05-05 12:00:00', 9900);
        Donation::query()->where('id', (int) $order->id)->update(['kind' => 'order']);

        $series = $this->exporter()->series('2026-05', '2026-05');

        $this->assertSame(5000, $series[0]['amount_cents'], 'a ticket purchase is not a gift');
        $this->assertSame(1, $series[0]['donations_count']);
    }

    public function test_a_test_donation_stays_out(): void
    {
        $this->paid('2026-05-04 12:00:00', 5000);

        $test = $this->paid('2026-05-06 12:00:00', 7700);
        Donation::query()->where('id', (int) $test->id)->update(['is_test' => 1]);

        $series = $this->exporter()->series('2026-05', '2026-05');

        $this->assertSame(5000, $series[0]['amount_cents']);
    }

    public function test_the_average_column_survives_an_empty_month(): void
    {
        $csv = $this->exporter()->toCsv('2026-01', '2026-01');

        // A month with no donations divides by zero unless it is guarded.
        $this->assertStringContainsString('2026-01,0,0.00,0.00', $csv);
    }

    public function test_the_picker_is_bounded_by_the_first_donation(): void
    {
        $this->paid('2026-03-10 12:00:00', 10000);

        $req = new \WP_REST_Request('GET', '/dono/v1/admin/exports/options');
        $opts = rest_do_request($req)->get_data();

        // Offering a fixed span of past years invites an export of months that
        // never had a donation, whose zero rows read as a fault.
        $this->assertSame('2026-03', $opts['first_month']);
        $this->assertSame([2026], array_values($opts['years']));
    }

    public function test_a_site_with_no_donations_still_offers_this_month(): void
    {
        $req = new \WP_REST_Request('GET', '/dono/v1/admin/exports/options');
        $opts = rest_do_request($req)->get_data();

        $this->assertSame((string) wp_date('Y-m'), $opts['first_month']);
    }

    public function test_a_malformed_month_falls_back_rather_than_throwing(): void
    {
        $series = $this->exporter()->series('not-a-month', '2026-13');

        $this->assertNotSame([], $series);
    }

    public function test_a_new_year_s_eve_donation_lands_in_the_year_the_donor_gave_it(): void
    {
        update_option('timezone_string', 'America/New_York');

        $donorId = $this->donor();
        // 23:30 on 31 December 2026 in New York, which is 04:30 on 1 January
        // 2027 UTC. The receipt prints December 2026, so the org's revenue for
        // 2026 has to include it.
        $this->paid('2027-01-01 04:30:00', 25000, $donorId);

        $series    = $this->exporter()->series('2026-01', '2026-12');
        $december  = $series[11];
        $statement = $this->statements()->summary($donorId, 2026);

        $this->assertSame('2026-12', $december['month']);
        $this->assertSame(25000, $december['amount_cents'], 'the report counts it in December 2026');
        $this->assertSame(1, $statement['donation_count'], 'the tax statement counts it in 2026');
        $this->assertSame($this->seriesTotal($series), $statement['total_cents'], 'both documents claim the same money');
    }

    public function test_that_donation_is_on_neither_document_for_the_following_year(): void
    {
        update_option('timezone_string', 'America/New_York');

        $donorId = $this->donor();
        $this->paid('2027-01-01 04:30:00', 25000, $donorId);

        $this->assertSame(0, $this->seriesTotal($this->exporter()->series('2027-01', '2027-12')));
        $this->assertSame(0, $this->statements()->summary($donorId, 2027)['donation_count']);
    }

    public function test_the_csv_month_is_the_org_s_month(): void
    {
        update_option('timezone_string', 'America/New_York');

        // 22:00 on 30 June in New York, which is 02:00 on 1 July UTC. New York
        // is on a different UTC offset in June than in December, so the month
        // a donation is counted in has to follow the offset in force when it
        // was given.
        $this->paid('2026-07-01 02:00:00', 4000);

        $csv = $this->exporter()->toCsv('2026-06', '2026-07');

        $this->assertStringContainsString('2026-06,1,40.00,40.00', $csv);
        $this->assertStringContainsString('2026-07,0,0.00,0.00', $csv);
    }
}
