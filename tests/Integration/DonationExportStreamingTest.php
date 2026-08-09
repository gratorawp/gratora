<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donations\Donation;
use Dono\Donations\DonationRepository;
use Dono\Foundation\Plugin;

/**
 * The export used to build the whole CSV as one string: 313MB of memory for
 * 8.8MB of output at 50,000 rows, which exhausted a 256MB limit. It streams in
 * pages now, and the ordering has to survive that.
 */
final class DonationExportStreamingTest extends IntegrationTestCase
{
    private function repo(): DonationRepository
    {
        return Plugin::instance()->container->get(DonationRepository::class);
    }

    private function seed(int $n, string $gateway = 'stripe'): Donation
    {
        $now = gmdate('Y-m-d H:i:s');
        $last = null;
        for ($i = 0; $i < $n; $i++) {
            $d = Donation::make();
            $d->reference         = 'EXP-' . strtoupper(uniqid()) . $i;
            $d->donor_id          = 1000 + $i;
            $d->amount_cents      = ($i + 1) * 100;
            $d->net_cents         = ($i + 1) * 100;
            $d->currency          = 'EUR';
            $d->base_amount_cents = ($i + 1) * 100;
            $d->base_currency     = 'EUR';
            $d->fx_rate           = '1.00000000';
            $d->gateway           = $gateway;
            $d->status            = 'paid';
            $d->is_test           = false;
            // Identical timestamps on purpose: paging is only safe if the sort
            // is total, and created_at alone is not.
            $d->paid_at           = $now;
            $d->created_at        = $now;
            $d->updated_at        = $now;
            $d->save();
            $last = $d;
        }

        return $last;
    }

    public function test_the_export_streams_rather_than_returning_a_body(): void
    {
        $this->seed(3);

        $res = rest_do_request(new \WP_REST_Request('GET', '/dono/v1/admin/donations/export.csv'));

        $this->assertSame(200, $res->get_status());
        $this->assertNull($res->get_data(), 'a streamed route holds no body in memory');
    }

    public function test_every_row_survives_being_paged(): void
    {
        $this->seed(5);

        $csv   = $this->serveBody('/dono/v1/admin/donations/export.csv');
        $lines = array_filter(preg_split('/\r?\n/', trim($csv)) ?: []);

        $this->assertCount(6, $lines, 'header plus every donation');
    }

    /**
     * Order is fixed once, before any paging, so the pages cannot disagree
     * about it. Every seeded row shares a created_at, which is the case that
     * would have shifted rows between pages under OFFSET.
     */
    public function test_the_order_is_settled_once_and_repeats(): void
    {
        $this->seed(6);

        $first  = $this->repo()->listIdsForExport(['orderby' => 'created_at', 'order' => 'desc']);
        $second = $this->repo()->listIdsForExport(['orderby' => 'created_at', 'order' => 'desc']);

        $this->assertSame($first, $second, 'the same export twice lists rows in the same order');
        $this->assertSame(count($first), count(array_unique($first)), 'no row appears twice');
        $this->assertNotSame(
            $first,
            $this->repo()->listIdsForExport(['orderby' => 'created_at', 'order' => 'asc']),
            'and the direction is honoured'
        );
    }

    public function test_a_page_boundary_neither_drops_nor_duplicates_a_row(): void
    {
        $this->seed(4);

        $ids  = $this->repo()->listIdsForExport([]);
        $rows = [];
        foreach (array_chunk($ids, 2) as $chunk) {
            foreach ($this->repo()->findManyDonationsByIds($chunk) as $id => $_) {
                $rows[] = $id;
            }
        }

        sort($ids);
        sort($rows);
        $this->assertSame($ids, $rows);
    }

    public function test_filters_still_narrow_the_export(): void
    {
        $this->seed(3);
        $only = $this->seed(1, 'paypal');

        $ids = $this->repo()->listIdsForExport(['gateway' => 'paypal']);

        $this->assertSame([(int) $only->id], $ids);
    }
}
