<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Analytics\Event;
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

    public function test_profile_timeline_carries_the_note_left_with_a_gift(): void
    {
        $container = Plugin::instance()->container;
        $donor = $container->get(DonorService::class)
            ->findOrCreate('noted@example.com', ['first_name' => 'Noted', 'last_name' => 'Donor']);

        $now = gmdate('Y-m-d H:i:s');
        $d = Donation::make();
        $d->reference    = 'DONO-NOTE-TL';
        $d->donor_id     = $donor->id;
        $d->amount_cents = 2500;
        $d->net_cents    = 2500;
        $d->currency     = 'EUR';
        $d->gateway      = 'offline';
        $d->status       = 'paid';
        $d->note_to_org  = 'In memory of my mother.';
        $d->created_at   = $now;
        $d->updated_at   = $now;
        $d->save();

        $e = Event::make();
        $e->type        = 'donation.paid';
        $e->donor_id    = (int) $donor->id;
        $e->donation_id = (int) $d->id;
        $e->amount_cents = 2500;
        $e->currency    = 'EUR';
        $e->occurred_at = $now;
        $e->save();

        $events = $container->get(DonorMetricsService::class)->profile($donor->id)['events'];
        $paid   = null;
        foreach ($events as $row) {
            if (($row['donation_id'] ?? null) === (int) $d->id) { $paid = $row; break; }
        }

        $this->assertNotNull($paid, 'expected the donation.paid event on the timeline');
        $this->assertSame('In memory of my mother.', $paid['note']);
    }

    public function test_events_page_paginates_the_full_activity_log(): void
    {
        $container = Plugin::instance()->container;
        $donor = $container->get(DonorService::class)
            ->findOrCreate('busy@example.com', ['first_name' => 'Busy', 'last_name' => 'Donor']);

        // Three events at distinct, increasing times so newest-first is testable.
        foreach ([1, 2, 3] as $i) {
            $e = Event::make();
            $e->type        = 'donation.completed';
            $e->donor_id    = (int) $donor->id;
            $e->occurred_at = sprintf('2026-07-2%d 10:00:00', $i);
            $e->save();
        }

        $metrics = $container->get(DonorMetricsService::class);

        $first = $metrics->eventsPage((int) $donor->id, 1, 2, 'desc');
        $this->assertSame(3, $first['total'], 'total counts every event, not just the page');
        $this->assertCount(2, $first['items'], 'per_page caps the page');
        $this->assertSame('2026-07-23 10:00:00', $first['items'][0]['occurred_at'], 'newest first');

        $second = $metrics->eventsPage((int) $donor->id, 2, 2, 'desc');
        $this->assertCount(1, $second['items'], 'the remainder lands on page two');
        $this->assertSame('2026-07-21 10:00:00', $second['items'][0]['occurred_at']);
    }

    public function test_export_data_is_null_for_unknown_donor(): void
    {
        $metrics = Plugin::instance()->container->get(DonorMetricsService::class);
        $this->assertNull($metrics->exportData(99999999));
    }
}
