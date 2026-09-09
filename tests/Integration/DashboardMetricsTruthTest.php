<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Campaigns\Campaign;
use Gratora\Dashboard\DashboardMetricsService;
use Gratora\Donations\Donation;
use Gratora\Donations\Refund;
use Gratora\Foundation\Plugin;

/**
 * The dashboard is read as a statement about the organisation, so a figure it
 * cannot support is worse than a figure it does not show.
 */
final class DashboardMetricsTruthTest extends IntegrationTestCase
{
    private function service(): DashboardMetricsService
    {
        $c = Plugin::instance()->container;

        return new DashboardMetricsService(
            $c->get(\Gratora\Foundation\Time\Clock::class),
            $c->get(\Gratora\Donations\DonationRepository::class),
            $c->get(\Gratora\Recurring\RecurringPlanRepository::class),
        );
    }

    private function paidDonation(int $cents, array $overrides = []): Donation
    {
        $now = gmdate('Y-m-d H:i:s');
        $d   = Donation::make();
        $d->reference         = 'GRATORA-DASH-' . uniqid();
        $d->donor_id          = 1;
        $d->amount_cents      = $cents;
        $d->net_cents         = $cents;
        $d->currency          = 'USD';
        $d->base_amount_cents = $cents;
        $d->base_currency     = 'USD';
        $d->fx_rate           = '1.00000000';
        $d->gateway           = 'offline';
        $d->status            = 'paid';
        $d->is_test           = false;
        $d->paid_at           = $now;
        $d->created_at        = $now;
        $d->updated_at        = $now;
        foreach ($overrides as $k => $v) $d->{$k} = $v;
        $d->save();

        return $d;
    }


    public function test_todays_total_is_net_of_a_refund(): void
    {
        $d = $this->paidDonation(10_000, ['status' => 'partial_refund', 'refunded_cents' => 2_500]);

        $r = Refund::make();
        $r->donation_id  = (int) $d->id;
        $r->amount_cents = 2_500;
        $r->currency     = 'USD';
        $r->initiated_by = 'admin';
        $r->status       = 'succeeded';
        $r->occurred_at  = gmdate('Y-m-d H:i:s');
        $r->save();

        $today = $this->service()->today();

        $this->assertSame(1, $today['donations_count']);
        $this->assertSame(7_500, $today['amount_raised_cents']);
    }

    public function test_todays_note_count_is_the_donations_carrying_a_message(): void
    {
        $this->paidDonation(1_000, ['note_to_org' => 'Keep up the work']);
        $this->paidDonation(1_000);
        $this->paidDonation(1_000, ['note_to_org' => '   ']);

        $this->assertSame(1, $this->service()->today()['notes_count']);
    }

    public function test_a_donation_with_no_exchange_rate_adds_nothing_rather_than_its_foreign_cents(): void
    {
        $this->paidDonation(1_000);
        $this->paidDonation(500_000, ['currency' => 'JPY', 'base_amount_cents' => null, 'fx_rate' => null]);

        $this->assertSame(1_000, $this->service()->today()['amount_raised_cents']);
    }


    private function campaign(array $overrides): Campaign
    {
        $now = gmdate('Y-m-d H:i:s');
        $c   = Campaign::make();
        $c->title      = 'Attention ' . uniqid();
        $c->slug       = 'attention-' . uniqid();
        $c->status     = 'published';
        $c->created_at = $now;
        $c->updated_at = $now;
        foreach ($overrides as $k => $v) $c->{$k} = $v;
        $c->save();

        return $c;
    }

    /** @return list<string> */
    private function attentionKeys(): array
    {
        return array_map(
            static fn (array $i): string => (string) $i['key'],
            (array) $this->service()->attention()
        );
    }

    public function test_a_campaign_ending_today_is_listed(): void
    {
        // A bare date means the end of that day, which is what every other
        // reader resolves it to. Read as midnight it had already passed.
        $c = $this->campaign(['ends_at' => gmdate('Y-m-d'), 'default_form_id' => 1]);

        $this->assertContains('ending-' . (int) $c->id, $this->attentionKeys());
    }

    public function test_a_campaign_that_ended_yesterday_is_not(): void
    {
        $c = $this->campaign(['ends_at' => gmdate('Y-m-d', strtotime('-1 day')), 'default_form_id' => 1]);

        $this->assertNotContains('ending-' . (int) $c->id, $this->attentionKeys());
    }

    public function test_a_campaign_ending_beyond_the_window_is_not(): void
    {
        $c = $this->campaign(['ends_at' => gmdate('Y-m-d', strtotime('+30 days')), 'default_form_id' => 1]);

        $this->assertNotContains('ending-' . (int) $c->id, $this->attentionKeys());
    }

    public function test_the_queue_does_not_grow_without_limit(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->campaign(['default_form_id' => null]);
        }

        $noForm = array_filter($this->attentionKeys(), static fn (string $k): bool => str_starts_with($k, 'no-form-'));

        $this->assertLessThanOrEqual(20, count($noForm));
    }


    public function test_an_ended_campaign_is_not_badged_active(): void
    {
        // raised_cents orders the widget and it shows the top six, so a fixture
        // with none of its own may not appear at all.
        $c = $this->campaign(['ends_at' => gmdate('Y-m-d', strtotime('-2 days')), 'raised_cents' => 900_000]);

        $row = null;
        foreach ((array) $this->service()->activeCampaigns() as $r) {
            if ((int) $r['id'] === (int) $c->id) $row = $r;
        }

        $this->assertNotNull($row, 'fixture: the campaign is in the widget');
        $this->assertSame('ended', (string) ($row['not_accepting'] ?? ''));
    }

    public function test_a_running_campaign_carries_no_reason(): void
    {
        $c = $this->campaign(['ends_at' => gmdate('Y-m-d', strtotime('+30 days')), 'raised_cents' => 900_000]);

        $row = null;
        foreach ((array) $this->service()->activeCampaigns() as $r) {
            if ((int) $r['id'] === (int) $c->id) $row = $r;
        }

        $this->assertNotNull($row, 'fixture: the campaign is in the widget');
        $this->assertNull($row['not_accepting']);
    }
}
