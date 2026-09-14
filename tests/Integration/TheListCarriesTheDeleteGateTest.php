<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Campaigns\Campaign;
use Gratora\Campaigns\CampaignService;
use Gratora\Donations\Donation;
use Gratora\Foundation\Plugin;
use Gratora\Recurring\RecurringPlan;
use WP_REST_Request;

/**
 * The campaigns list re-derived the delete gate from donations_count, because
 * asking the gate itself cost two counts per row and the list would have paid
 * them once per campaign on the page.
 *
 * The copy was narrower than the gate: the server also refuses a campaign a
 * recurring plan points at. And withholding the action took the gate's own
 * sentence with it, which is the one that names archiving as the way to keep
 * the records.
 *
 * So the gate answers a whole page at once, and the row carries its verdict.
 */
final class TheListCarriesTheDeleteGateTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    private function service(): CampaignService
    {
        return Plugin::instance()->container->get(CampaignService::class);
    }

    private function campaign(string $title): Campaign
    {
        $c = Campaign::make();
        $c->title      = $title;
        $c->slug       = sanitize_title($title) . '-' . bin2hex(random_bytes(3));
        $c->status     = 'published';
        $c->created_at = gmdate('Y-m-d H:i:s');
        $c->updated_at = gmdate('Y-m-d H:i:s');
        $c->save();

        return $c;
    }

    private function donationFor(Campaign $c): void
    {
        $d = Donation::make();
        $d->campaign_id  = (int) $c->id;
        $d->reference    = 'DON-' . bin2hex(random_bytes(4));
        $d->amount_cents = 1000;
        $d->currency     = 'USD';
        $d->status       = 'paid';
        $d->created_at   = gmdate('Y-m-d H:i:s');
        $d->save();
    }

    private function planFor(Campaign $c): void
    {
        $p = RecurringPlan::make();
        $p->campaign_id             = (int) $c->id;
        $p->donor_id                = 1;
        $p->gateway                 = 'stripe';
        $p->gateway_subscription_id = 'sub_' . bin2hex(random_bytes(4));
        $p->amount_cents            = 1000;
        $p->currency                = 'USD';
        $p->interval_unit           = 'month';
        $p->interval_count          = 1;
        $p->status                  = 'active';
        $p->created_at              = gmdate('Y-m-d H:i:s');
        $p->updated_at              = gmdate('Y-m-d H:i:s');
        $p->save();
    }

    /** @return array<int,array<string,mixed>> the list rows, keyed by campaign id */
    private function listRows(): array
    {
        $req = new WP_REST_Request('GET', '/gratora/v1/admin/campaigns');
        $req->set_param('per_page', 100);

        $res = rest_do_request($req);
        $this->assertSame(200, $res->get_status(), (string) wp_json_encode($res->get_data()));

        $out  = [];
        $data = (array) $res->get_data();

        foreach (($data['items'] ?? $data) as $row) {
            $out[(int) $row['id']] = (array) $row;
        }

        return $out;
    }

    public function test_a_row_says_whether_it_can_be_deleted(): void
    {
        $free = $this->campaign('Nothing recorded');

        $row = $this->listRows()[(int) $free->id] ?? null;

        $this->assertNotNull($row);
        $this->assertTrue($row['deletable']);
        $this->assertNull($row['delete_blocked']);
    }

    public function test_a_campaign_with_donations_carries_the_reason(): void
    {
        $held = $this->campaign('Has donations');
        $this->donationFor($held);

        $row = $this->listRows()[(int) $held->id];

        $this->assertFalse($row['deletable']);
        $this->assertStringContainsString(
            'Archive',
            (string) $row['delete_blocked'],
            'the refusal has to name what to do instead, or it is a dead end'
        );
    }

    /**
     * The half the client's own copy of the rule did not know about. A campaign
     * with no donation at all is still held by a plan that points at it.
     */
    public function test_a_campaign_held_only_by_a_recurring_plan_is_refused(): void
    {
        $held = $this->campaign('Only a plan');
        $this->planFor($held);

        $row = $this->listRows()[(int) $held->id];

        $this->assertSame(0, (int) $row['donations_count'], 'nothing a donation count could catch');
        $this->assertFalse($row['deletable']);
        $this->assertNotNull($row['delete_blocked']);
    }

    /** The batched gate and the single one are the same gate. */
    public function test_the_page_gate_agrees_with_the_one_the_delete_route_uses(): void
    {
        $free = $this->campaign('Free');
        $held = $this->campaign('Held');
        $this->donationFor($held);

        $batch = $this->service()->deleteBlockedReasons([(int) $free->id, (int) $held->id]);

        $this->assertSame($this->service()->deleteBlockedReason($free), $batch[(int) $free->id]);
        $this->assertSame($this->service()->deleteBlockedReason($held), $batch[(int) $held->id]);
    }

    /** And the route still refuses, whatever a row was told. */
    public function test_the_route_is_still_the_authority(): void
    {
        $held = $this->campaign('Has donations');
        $this->donationFor($held);

        $res = rest_do_request(new WP_REST_Request('DELETE', '/gratora/v1/admin/campaigns/' . (int) $held->id));

        $this->assertSame(422, $res->get_status());
        $this->assertNotNull(Campaign::query()->find('id', (int) $held->id));
    }

    public function test_a_campaign_nothing_holds_really_is_deleted(): void
    {
        $free = $this->campaign('Free to go');

        $res = rest_do_request(new WP_REST_Request('DELETE', '/gratora/v1/admin/campaigns/' . (int) $free->id));

        $this->assertSame(200, $res->get_status(), (string) wp_json_encode($res->get_data()));
        $this->assertNull(Campaign::query()->find('id', (int) $free->id));
    }

    /** An empty page asks nothing rather than building a query with no ids. */
    public function test_no_campaigns_is_no_query(): void
    {
        $this->assertSame([], $this->service()->deleteBlockedReasons([]));
    }
}
