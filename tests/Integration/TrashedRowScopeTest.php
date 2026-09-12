<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Analytics\Event;
use Gratora\Dashboard\DashboardMetricsService;
use Gratora\Donations\Donation;
use Gratora\Donations\DonationRepository;
use Gratora\Donors\Donor;
use Gratora\Donors\DonorMetricsService;
use Gratora\Donors\DonorRepository;
use Gratora\Donors\DonorService;
use Gratora\Foundation\Plugin;
use Gratora\Foundation\Time\Clock;
use Gratora\Recurring\RecurringPlanRepository;
use WP_REST_Request;

/**
 * Every screen that counts donations, and what a trashed row does to it.
 *
 * The invariant the whole feature rests on is that no trashed row ever moved
 * money, so no total needs recalculating. What does need saying is where a
 * trashed row stops appearing, because a row hidden from the list but still
 * counted in the figure beside it is worse than one that never left.
 */
final class TrashedRowScopeTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    private function donor(): Donor
    {
        return Plugin::instance()->container->get(DonorService::class)
            ->findOrCreate('scope-' . uniqid() . '@example.test', ['first_name' => 'Scope']);
    }

    /** @param array<string,mixed> $overrides */
    private function donation(int $donorId, array $overrides = []): Donation
    {
        $d                    = Donation::make();
        $d->reference         = 'SCOPE-' . strtoupper(bin2hex(random_bytes(4)));
        $d->donor_id          = $donorId;
        $d->amount_cents      = 2500;
        $d->base_amount_cents = 2500;
        $d->currency          = 'USD';
        $d->base_currency     = 'USD';
        $d->status            = 'failed';
        $d->gateway           = 'stripe';
        $d->frequency         = 'one_time';
        $d->kind              = 'donation';
        $d->is_test           = false;
        // Stamped, because the dashboard counts a 24-hour window and an
        // unstamped row falls outside every one of them.
        $d->created_at        = gmdate('Y-m-d H:i:s');
        $d->updated_at        = $d->created_at;

        foreach ($overrides as $col => $value) {
            $d->{$col} = $value;
        }

        $d->save();

        return $d;
    }

    private function trash(Donation $d): void
    {
        $d->updateColumns(['trashed_at' => gmdate('Y-m-d H:i:s'), 'trashed_by' => 1]);
    }

    private function donations(): DonationRepository
    {
        return Plugin::instance()->container->get(DonationRepository::class);
    }

    /** @return array<string,mixed> */
    private function profile(int $donorId): array
    {
        return (array) Plugin::instance()->container
            ->get(DonorMetricsService::class)
            ->profile($donorId);
    }

    public function test_a_trashed_row_leaves_the_list_and_is_counted_in_the_bin(): void
    {
        $donor   = $this->donor();
        $kept    = $this->donation((int) $donor->id);
        $trashed = $this->donation((int) $donor->id);
        $this->trash($trashed);

        $list       = $this->donations()->listAdmin(['per_page' => 50]);
        $references = array_map(static fn (Donation $d): string => (string) $d->reference, $list['items']);

        $this->assertContains((string) $kept->reference, $references);
        $this->assertNotContains((string) $trashed->reference, $references);
        $this->assertSame(1, (int) $this->donations()->countTrashed(), 'and the bin says how many are in it');
    }

    /**
     * The bin is read newest-trashed first, which is the one column the live
     * list has no reason to offer. A sort the repository does not allow falls
     * back to created_at without saying so, and the row an admin just put in
     * the bin is then wherever its creation date happens to place it.
     */
    public function test_the_bin_is_ordered_by_when_rows_were_trashed(): void
    {
        $donor = $this->donor();

        // Created oldest first, trashed newest first, so a silent fallback to
        // created_at returns the exact reverse of what was asked for.
        $first  = $this->donation((int) $donor->id, ['created_at' => '2026-01-01 00:00:00']);
        $second = $this->donation((int) $donor->id, ['created_at' => '2026-01-02 00:00:00']);
        $third  = $this->donation((int) $donor->id, ['created_at' => '2026-01-03 00:00:00']);

        $first->updateColumns(['trashed_at'  => '2026-02-03 00:00:00', 'trashed_by' => 1]);
        $second->updateColumns(['trashed_at' => '2026-02-02 00:00:00', 'trashed_by' => 1]);
        $third->updateColumns(['trashed_at'  => '2026-02-01 00:00:00', 'trashed_by' => 1]);

        $list = $this->donations()->listAdmin([
            'trashed'  => 'only',
            'orderby'  => 'trashed_at',
            'order'    => 'desc',
            'per_page' => 50,
        ]);

        $references = array_map(static fn (Donation $d): string => (string) $d->reference, $list['items']);

        $this->assertSame(
            [(string) $first->reference, (string) $second->reference, (string) $third->reference],
            $references
        );
    }

    /**
     * The list, the CSV and the KPI aggregate share one filter method, and the
     * CSV is the one that leaves the building.
     */
    public function test_a_trashed_row_leaves_the_csv(): void
    {
        $donor   = $this->donor();
        $kept    = $this->donation((int) $donor->id);
        $trashed = $this->donation((int) $donor->id);
        $this->trash($trashed);

        $csv = $this->serveBody('/gratora/v1/admin/donations/export.csv');

        $this->assertStringContainsString((string) $kept->reference, $csv);
        $this->assertStringNotContainsString((string) $trashed->reference, $csv);
    }

    /**
     * A search is not an exemption here, deliberately: the stats route and the
     * CSV take the same term, so exempting a targeted lookup would put the
     * trash back into the KPI strip and the export.
     */
    public function test_searching_for_a_trashed_reference_does_not_bring_it_back(): void
    {
        $donor   = $this->donor();
        $trashed = $this->donation((int) $donor->id);
        $this->trash($trashed);

        $list = $this->donations()->listAdmin(['search' => (string) $trashed->reference]);

        $this->assertSame(0, (int) $list['total']);
        $this->assertSame([], $list['items']);
    }

    public function test_a_gateway_only_on_a_trashed_row_is_not_offered_as_a_filter(): void
    {
        $donor = $this->donor();
        $this->donation((int) $donor->id, ['gateway' => 'stripe']);
        $this->trash($this->donation((int) $donor->id, ['gateway' => 'moneris']));

        $res = rest_do_request(new WP_REST_Request('GET', '/gratora/v1/admin/donations/gateway-options'));
        $this->assertSame(200, $res->get_status(), (string) wp_json_encode($res->get_data()));

        $values = array_column((array) $res->get_data(), 'value');

        $this->assertContains('stripe', $values);
        $this->assertNotContains('moneris', $values, 'a filter that can only return nothing is not offered');
    }

    public function test_the_dashboard_failed_counts_ignore_a_trashed_row(): void
    {
        $donor = $this->donor();
        $live  = $this->donation((int) $donor->id, ['status' => 'failed']);
        $test  = $this->donation((int) $donor->id, ['status' => 'failed', 'is_test' => true]);

        $before = $this->attentionCounts();
        $this->assertSame(1, $before['failed-donations']);
        $this->assertSame(1, $before['failed-test-donations']);

        $this->trash($live);
        $this->trash($test);

        $after = $this->attentionCounts();

        // The count links to a list filtered the same way, so a figure that
        // still counts a trashed row sends the admin to an empty screen.
        $this->assertSame(0, $after['failed-donations']);
        $this->assertSame(0, $after['failed-test-donations'], 'including the raw query that bypasses every scope helper');
    }

    /** @return array<string,int> */
    private function attentionCounts(): array
    {
        $c     = Plugin::instance()->container;
        $items = (new DashboardMetricsService(
            $c->get(Clock::class),
            $c->get(DonationRepository::class),
            $c->get(RecurringPlanRepository::class),
        ))->attention();

        $out = ['failed-donations' => 0, 'failed-test-donations' => 0];
        foreach ($items as $item) {
            $key = (string) ($item['key'] ?? '');
            if (array_key_exists($key, $out)) {
                $out[$key] = (int) ($item['count'] ?? 0);
            }
        }

        return $out;
    }

    public function test_the_donor_profile_card_and_tab_badge_both_drop_a_trashed_row(): void
    {
        $donor   = $this->donor();
        $kept    = $this->donation((int) $donor->id);
        $trashed = $this->donation((int) $donor->id);

        $this->assertSame(2, (int) $this->profile((int) $donor->id)['donations_total']);

        $this->trash($trashed);
        $profile = $this->profile((int) $donor->id);

        // The badge and the list it labels read from the same place on purpose:
        // a badge saying 2 over a tab listing 1 is the drift this pins.
        $this->assertSame(1, (int) $profile['donations_total']);

        $encoded = (string) wp_json_encode($profile['donations']);
        $this->assertStringContainsString((string) $kept->reference, $encoded);
        $this->assertStringNotContainsString((string) $trashed->reference, $encoded);
    }

    public function test_the_timeline_drops_a_trashed_donations_events_and_keeps_the_donors_own(): void
    {
        $donor   = $this->donor();
        $trashed = $this->donation((int) $donor->id);

        $this->seedEvent('donation.failed', (int) $donor->id, (int) $trashed->id);
        // Pointing at no donation at all: magic links, consents and portal
        // sign-ins live here too, and an unguarded NOT IN would empty them.
        $this->seedEvent('donor.portal_login', (int) $donor->id, null);

        $this->assertSame(2, (int) $this->profile((int) $donor->id)['events_total']);

        $this->trash($trashed);
        $profile = $this->profile((int) $donor->id);

        $this->assertSame(1, (int) $profile['events_total']);

        $types = array_column((array) $profile['events'], 'type');
        $this->assertContains('donor.portal_login', $types, 'an event pointing at nothing is left alone');
        $this->assertNotContains('donation.failed', $types);
    }

    private function seedEvent(string $type, int $donorId, ?int $donationId): void
    {
        $e              = Event::make();
        $e->type        = $type;
        $e->donor_id    = $donorId;
        $e->donation_id = $donationId;
        $e->occurred_at = gmdate('Y-m-d H:i:s');
        $e->save();
    }

    public function test_a_donor_whose_only_row_is_trashed_is_not_badged_a_rehearsal(): void
    {
        $donor = $this->donor();
        $this->trash($this->donation((int) $donor->id, ['is_test' => false]));

        // Their one real attempt is in the bin, which makes them a donor with
        // no donations. It does not make them somebody's test data.
        $this->assertSame(
            [],
            DonorRepository::testOnlyIdsAmong([(int) $donor->id]),
            'a spam donor is not relabelled as a rehearsal'
        );
    }

    /**
     * Deliberately trash-blind, so a direct link from a bank statement opens
     * the screen with a banner rather than a 404.
     */
    public function test_the_detail_route_still_resolves_a_trashed_reference(): void
    {
        $donor   = $this->donor();
        $trashed = $this->donation((int) $donor->id);
        $this->trash($trashed);

        $res = rest_do_request(
            new WP_REST_Request('GET', '/gratora/v1/admin/donations/' . $trashed->reference)
        );

        $this->assertSame(200, $res->get_status(), (string) wp_json_encode($res->get_data()));
    }
}
