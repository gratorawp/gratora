<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Donations\Donation;
use WP_REST_Request;

/**
 * campaign_id is nullable and the record-a-donation route accepts null, so an
 * org that enters cheques before it creates a campaign has paid money that
 * belongs to none. The top-campaigns widget grouped that into a row it could
 * not render, then passed an empty id list to whereIn, which compiles to
 * "id IN ()" and is a syntax error, so the whole dashboard failed to load
 * rather than one card coming back empty.
 */
final class DashboardNoCampaignTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    private function paidWithNoCampaign(): void
    {
        $now = gmdate('Y-m-d H:i:s');

        $d = Donation::make();
        $d->reference         = 'NOCAMP-' . uniqid();
        $d->donor_id          = 4242;
        $d->campaign_id       = null;
        $d->amount_cents      = 25000;
        $d->base_amount_cents = 25000;
        $d->currency          = 'USD';
        $d->base_currency     = 'USD';
        $d->status            = 'paid';
        $d->gateway           = 'offline';
        $d->frequency         = 'one_time';
        $d->kind              = 'donation';
        $d->is_test           = false;
        $d->paid_at           = $now;
        $d->created_at        = $now;
        $d->updated_at        = $now;
        $d->save();
    }

    private function dashboard(): \WP_REST_Response
    {
        $req = new WP_REST_Request('GET', '/gratora/v1/admin/dashboard');
        $req->set_param('include', 'top-campaigns');

        return rest_do_request($req);
    }

    public function test_the_dashboard_loads_when_every_donation_belongs_to_no_campaign(): void
    {
        $this->paidWithNoCampaign();
        $this->paidWithNoCampaign();

        $res = $this->dashboard();

        $this->assertSame(200, $res->get_status(), (string) wp_json_encode($res->get_data()));
        $this->assertSame([], (array) ($res->get_data()['top_campaigns'] ?? null), 'an empty card, not a broken screen');
    }

    public function test_money_with_no_campaign_is_not_listed_as_a_campaign(): void
    {
        $req = new WP_REST_Request('POST', '/gratora/v1/admin/campaigns');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode(['title' => 'Winter', 'status' => 'published']));
        $campaignId = (int) rest_do_request($req)->get_data()['id'];

        $now = gmdate('Y-m-d H:i:s');
        $d = Donation::make();
        $d->reference         = 'CAMP-' . uniqid();
        $d->donor_id          = 4243;
        $d->campaign_id       = $campaignId;
        $d->amount_cents      = 1000;
        $d->base_amount_cents = 1000;
        $d->currency          = 'USD';
        $d->base_currency     = 'USD';
        $d->status            = 'paid';
        $d->gateway           = 'offline';
        $d->frequency         = 'one_time';
        $d->kind              = 'donation';
        $d->is_test           = false;
        $d->paid_at           = $now;
        $d->created_at        = $now;
        $d->updated_at        = $now;
        $d->save();

        // Far larger, so without the filter it would top the list.
        $this->paidWithNoCampaign();

        $res = $this->dashboard();
        $this->assertSame(200, $res->get_status());

        $ids = array_map(
            static fn (array $r): int => (int) ($r['id'] ?? $r['campaign_id'] ?? 0),
            (array) ($res->get_data()['top_campaigns'] ?? [])
        );

        $this->assertSame([$campaignId], $ids, 'only real campaigns are ranked');
    }
}
