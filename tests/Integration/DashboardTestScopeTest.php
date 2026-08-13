<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Campaigns\Campaign;
use Dono\Donations\Donation;
use Dono\Foundation\Plugin;
use WP_REST_Request;

/**
 * What the dashboard counts, and what it admits to hiding.
 *
 * Money on this screen is the number an operator quotes, so test rows stay out
 * of it unless they explicitly ask. The failure that mattered was the other
 * half: a screen showing zero without saying why is indistinguishable from a
 * broken one, which is why the payload always carries the hidden count.
 */
final class DashboardTestScopeTest extends IntegrationTestCase
{
    private int $campaignId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        $c = Campaign::make();
        $c->title      = 'Dashboard scope';
        $c->slug       = 'dashboard-scope-' . uniqid();
        $c->status     = 'published';
        $c->goal_type  = 'amount';
        $c->goal_cents = 100000;
        $c->created_at = gmdate('Y-m-d H:i:s');
        $c->updated_at = gmdate('Y-m-d H:i:s');
        $c->save();
        $this->campaignId = (int) $c->id;
    }

    private function donation(bool $isTest, int $cents = 5000, string $kind = 'donation'): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $d = Donation::make();
        $d->reference         = 'DASH-' . strtoupper(uniqid());
        $d->donor_id          = 1;
        $d->campaign_id       = $this->campaignId;
        $d->amount_cents      = $cents;
        $d->base_amount_cents = $cents;
        $d->currency          = 'EUR';
        $d->status            = 'paid';
        $d->kind              = $kind;
        $d->gateway           = 'offline';
        $d->is_test           = $isTest;
        $d->paid_at           = $now;
        $d->created_at        = $now;
        $d->updated_at        = $now;
        $d->save();
    }

    /** @return array<string,mixed> */
    private function dashboard(?bool $includeTest = null): array
    {
        $req = new WP_REST_Request('GET', '/dono/v1/admin/dashboard');
        if ($includeTest !== null) {
            $req->set_param('include_test', $includeTest);
        }

        return (array) rest_do_request($req)->get_data();
    }

    public function test_money_excludes_test_donations_by_default(): void
    {
        $this->donation(false, 5000);
        $this->donation(true, 9900);

        $kpi = $this->dashboard()['kpi'];

        $this->assertSame(5000, (int) $kpi['amount_raised_cents'], 'rehearsal money is not income');
        $this->assertSame(1, (int) $kpi['donations_count']);
    }

    public function test_the_payload_always_says_how_much_is_hidden(): void
    {
        $this->donation(true, 9900);
        $this->donation(true, 100);

        $payload = $this->dashboard();

        // Without this the screen shows zero and offers no explanation, which
        // is the complaint this whole change answers.
        $this->assertFalse($payload['test']['includes_test']);
        $this->assertSame(2, (int) $payload['test']['hidden']['donations']);
    }

    public function test_the_envelope_survives_widget_filtering(): void
    {
        $this->donation(true);

        $req = new WP_REST_Request('GET', '/dono/v1/admin/dashboard');
        $req->set_param('include', 'kpis');
        $payload = (array) rest_do_request($req)->get_data();

        $this->assertArrayHasKey('test', $payload, 'the explanation is not a widget');
    }

    public function test_opting_in_moves_every_money_figure_together(): void
    {
        $this->donation(false, 5000);
        $this->donation(true, 9900);

        $payload = $this->dashboard(true);

        $this->assertTrue($payload['test']['includes_test']);
        $this->assertSame(14900, (int) $payload['kpi']['amount_raised_cents']);
        $this->assertSame(2, (int) $payload['kpi']['donations_count']);

        // The card is derived from the two above it, so a split would make the
        // row a broken calculator rather than a caveat.
        $this->assertSame(7450, (int) $payload['kpi']['avg_donation_cents']);
    }

    public function test_opting_in_never_admits_ticket_orders(): void
    {
        $this->donation(false, 5000);
        $this->donation(false, 7000, 'order');

        // A ticket sale is a purchase, not a gift. "Show me test data" must not
        // quietly also mean "show me orders".
        $this->assertSame(5000, (int) $this->dashboard(true)['kpi']['amount_raised_cents']);
        $this->assertSame(5000, (int) $this->dashboard(false)['kpi']['amount_raised_cents']);
    }

    public function test_a_live_ticket_order_is_not_listed_as_a_donation(): void
    {
        $this->donation(false, 7000, 'order');

        // These reads scoped on is_test alone, which filters rehearsals but not
        // kind, so a live ticket sale appeared in the donation feed and the
        // last-24h ribbon as though someone had given.
        $this->assertSame([], $this->dashboard()['recent_activity']);
        $this->assertSame(0, (int) ($this->dashboard()['today']['donations_count'] ?? 0));
    }

    public function test_recent_activity_follows_the_toggle(): void
    {
        $this->donation(true, 9900);

        $this->assertSame([], $this->dashboard()['recent_activity']);
        $this->assertCount(1, $this->dashboard(true)['recent_activity']);
    }

    public function test_the_campaign_rollup_column_is_never_rewritten(): void
    {
        $this->donation(true, 9900);

        $before = (int) Campaign::query()->find('id', $this->campaignId)->raised_cents;
        $this->dashboard(true);
        $after = (int) Campaign::query()->find('id', $this->campaignId)->raised_cents;

        // The public progress bar reads this column. Widening it would put
        // rehearsal money on the campaign page for every visitor.
        $this->assertSame($before, $after);
    }

    public function test_a_failed_test_donation_is_always_reported(): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $d = Donation::make();
        $d->reference    = 'DASH-FAIL-' . strtoupper(uniqid());
        $d->donor_id     = 1;
        $d->campaign_id  = $this->campaignId;
        $d->amount_cents = 2500;
        $d->currency     = 'EUR';
        $d->status       = 'failed';
        $d->kind         = 'donation';
        $d->gateway      = 'offline';
        $d->is_test      = true;
        $d->created_at   = $now;
        $d->updated_at   = $now;
        $d->save();

        // A gateway misconfigured during a rehearsal is what a rehearsal is
        // for finding, so this one alert ignores the toggle.
        $keys = array_column($this->dashboard(false)['attention'], 'key');

        $this->assertContains('failed-test-donations', $keys);
        $this->assertNotContains('failed-donations', $keys, 'and it stays a separate, dismissable item');
    }
}
