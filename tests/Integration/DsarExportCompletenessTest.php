<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donations\Donation;
use Dono\Donors\DonorMetricsService;
use Dono\Donors\DonorService;
use Dono\Foundation\Plugin;

/**
 * A DSAR / right-of-access export must be complete. profile() caps lists for
 * the admin UI (donations 25, receipts 25, events 100); exportData() must
 * return everything.
 */
final class DsarExportCompletenessTest extends IntegrationTestCase
{
    public function test_export_returns_all_donations_while_profile_stays_capped(): void
    {
        $container = Plugin::instance()->container;
        $donor = $container->get(DonorService::class)
            ->findOrCreate('many@example.com', ['first_name' => 'Many', 'last_name' => 'Gifts']);

        $now = gmdate('Y-m-d H:i:s');
        for ($i = 0; $i < 30; $i++) {
            $d = Donation::make();
            $d->reference    = sprintf('DONO-TEST-%05d', $i);
            $d->donor_id     = $donor->id;
            $d->amount_cents = 1000 + $i;
            $d->net_cents    = 1000 + $i;
            $d->currency     = 'EUR';
            $d->gateway      = 'offline';
            $d->status       = 'paid';
            $d->created_at   = $now;
            $d->updated_at   = $now;
            $d->save();
        }

        $metrics = $container->get(DonorMetricsService::class);

        $export  = $metrics->exportData($donor->id);
        $profile = $metrics->profile($donor->id);

        $this->assertCount(30, $export['donations'], 'DSAR export is uncapped');
        $this->assertCount(25, $profile['donations'], 'admin profile stays capped at 25');

        // exportData shape carries the sections the bundle needs.
        $this->assertArrayHasKey('donor', $export);
        $this->assertArrayHasKey('recurring', $export);
        $this->assertArrayHasKey('receipts', $export);
        $this->assertArrayHasKey('consents', $export);
        $this->assertArrayHasKey('events', $export);
        $this->assertArrayHasKey('notes', $export);
        $this->assertSame('Many Gifts', $export['donor']['name']);
    }

    public function test_export_data_is_null_for_unknown_donor(): void
    {
        $metrics = Plugin::instance()->container->get(DonorMetricsService::class);
        $this->assertNull($metrics->exportData(99999999));
    }
}
