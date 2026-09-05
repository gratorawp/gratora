<?php

declare(strict_types=1);

namespace FundKit\Tests\Integration;

use FundKit\Campaigns\CampaignMetricsService;
use FundKit\Donations\Donation;
use FundKit\Donations\DonationRepository;
use FundKit\Foundation\Plugin;
use WP_REST_Request;

/**
 * A ticket order rides the donations table with kind='order' and carries the
 * campaign_id so it can be reported against, but it is a purchase rather than
 * a donation. Every rollup already excludes it. The narrative widgets on the same
 * screen did not, so one response listed a ticket buyer in Recent donations and
 * counted them as a donor while the totals above them left the purchase out.
 */
final class ReportingExcludesOrdersTest extends IntegrationTestCase
{
    private const DONATION  = 500000;
    private const ORDER = 4000;

    private int $campaignId;

    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        $req = new WP_REST_Request('POST', '/fundkit/v1/admin/campaigns');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode(['title' => 'Gala', 'status' => 'published']));
        $this->campaignId = (int) rest_do_request($req)->get_data()['id'];
    }

    private function row(string $kind, int $cents, string $note = ''): Donation
    {
        $now = gmdate('Y-m-d H:i:s');

        $d = Donation::make();
        $d->reference         = strtoupper($kind) . '-' . uniqid();
        $d->donor_id          = $kind === 'order' ? 9001 : 9002;
        $d->campaign_id       = $this->campaignId;
        $d->amount_cents      = $cents;
        $d->base_amount_cents = $cents;
        $d->currency          = 'USD';
        $d->base_currency     = 'USD';
        $d->status            = 'paid';
        $d->gateway           = 'offline';
        $d->frequency         = 'one_time';
        $d->kind              = $kind;
        $d->is_test           = false;
        $d->note_to_org       = $note;
        $d->paid_at           = $now;
        $d->created_at        = $now;
        $d->updated_at        = $now;
        $d->save();

        return $d;
    }

    private function metrics(): CampaignMetricsService
    {
        return Plugin::instance()->container->get(CampaignMetricsService::class);
    }

    private function repo(): DonationRepository
    {
        return Plugin::instance()->container->get(DonationRepository::class);
    }

    public function test_recent_donations_lists_donations_only(): void
    {
        $this->row('donation', self::DONATION);
        $this->row('order', self::ORDER);

        $amounts = array_map(
            static fn (array $r): int => (int) $r['amount_cents'],
            $this->metrics()->recentDonations($this->campaignId)
        );

        $this->assertContains(self::DONATION, $amounts, 'the donation is listed');
        $this->assertNotContains(self::ORDER, $amounts, 'the ticket purchase is not a donation');
    }

    public function test_the_stories_widget_does_not_quote_a_checkout_note(): void
    {
        $this->row('donation', self::DONATION, 'For the roof fund');
        $this->row('order', self::ORDER, 'Table of eight please');

        $notes = array_map(
            static fn (array $r): string => (string) ($r['note'] ?? $r['note_to_org'] ?? ''),
            $this->metrics()->notes($this->campaignId)
        );

        $this->assertContains('For the roof fund', $notes);
        $this->assertNotContains('Table of eight please', $notes);
    }

    public function test_the_cohort_split_does_not_count_a_ticket_buyer_as_a_donor(): void
    {
        $this->row('donation', self::DONATION);
        $this->row('order', self::ORDER);

        $rows = $this->repo()->donorCohortRowsForCampaign($this->campaignId, null, null);
        $donorIds = array_map(static fn ($r): int => (int) ($r['donor_id'] ?? $r->donor_id), $rows);

        $this->assertContains(9002, $donorIds, 'the giver is a donor');
        $this->assertNotContains(9001, $donorIds, 'the ticket buyer is not');
    }

    public function test_the_median_is_the_median_of_the_buckets_beside_it(): void
    {
        // Ten donations well above the ticket price, and ten cheaper tickets. With
        // orders in the ordering, the offset taken from a donation-only count
        // lands inside the block of ticket rows.
        for ($i = 0; $i < 10; $i++) {
            $this->row('donation', 100000);
            $this->row('order', 1000);
        }

        $distribution = $this->metrics()->distributionBuckets($this->campaignId);
        $median = (int) $distribution['median_cents'];

        $this->assertSame(10, (int) $distribution['total_count'], 'the buckets count donations only');
        $this->assertSame(100000, $median, 'so the median has to be a donation, not a ticket');
    }

    public function test_the_public_recent_donations_block_shows_no_ticket_orders(): void
    {
        $this->row('donation', self::DONATION);
        $this->row('order', self::ORDER);

        $amounts = array_map(
            static fn ($d): int => (int) $d->amount_cents,
            $this->repo()->recentForCampaign($this->campaignId)
        );

        $this->assertContains(self::DONATION, $amounts);
        $this->assertNotContains(self::ORDER, $amounts, 'a campaign page must not name a ticket buyer as a donor');
    }
}
