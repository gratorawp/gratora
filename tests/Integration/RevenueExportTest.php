<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donations\Donation;
use Dono\Exports\RevenueExporter;
use Dono\Foundation\Plugin;

/**
 * The revenue series is what a finance team charts, so a month with nothing in
 * it has to appear as a zero rather than go missing.
 */
final class RevenueExportTest extends IntegrationTestCase
{
    private function exporter(): RevenueExporter
    {
        return Plugin::instance()->container->get(RevenueExporter::class);
    }

    private function paid(string $when, int $cents): Donation
    {
        $d = Donation::make();
        $d->reference         = 'REF-' . uniqid();
        $d->status            = 'paid';
        $d->gateway           = 'offline';
        $d->kind              = 'donation';
        $d->amount_cents      = $cents;
        $d->base_amount_cents = $cents;
        $d->currency          = 'USD';
        $d->is_test           = false;
        $d->created_at        = $when;
        $d->paid_at           = $when;
        $d->save();

        return $d;
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
}
