<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Campaigns\Campaign;
use Gratora\Campaigns\CampaignStatMetrics;
use Gratora\Donations\Donation;
use Gratora\Foundation\Plugin;
use WP_REST_Request;

/**
 * One figure at a time, and silence when the campaign cannot answer.
 *
 * The silence is the part worth testing. A stat block is placed by hand, so it
 * lands on campaigns it does not suit: a goal figure on a campaign with no
 * goal, days left on one that never ends. Answering zero there would be a
 * confident wrong number on a public page, so these all return null and the
 * block renders nothing.
 */
final class CampaignStatMetricsTest extends IntegrationTestCase
{
    private function metrics(): CampaignStatMetrics
    {
        return Plugin::instance()->container->get(CampaignStatMetrics::class);
    }

    /** @param array<string,mixed> $attrs */
    private function campaign(array $attrs = []): Campaign
    {
        $req = new WP_REST_Request('POST', '/gratora/v1/admin/campaigns');
        $req->set_header('content-type', 'application/json');
        $req->set_body(json_encode($attrs + ['title' => 'Metric probe', 'status' => 'published']));
        $created = rest_do_request($req)->get_data();

        return Campaign::query()->find('id', (int) $created['id']);
    }

    private function paidGift(Campaign $c, int $cents): void
    {
        $d = Donation::make();
        $d->donor_id     = 1;
        $d->campaign_id  = (int) $c->id;
        $d->reference    = 'MET-' . bin2hex(random_bytes(4));
        $d->amount_cents = $cents;
        $d->base_amount_cents = $cents;
        $d->fx_rate      = '1';
        $d->currency     = (string) $c->currency;
        $d->status       = 'paid';
        $d->gateway      = 'offline';
        $d->is_test      = false;
        $d->paid_at      = gmdate('Y-m-d H:i:s');
        $d->created_at   = gmdate('Y-m-d H:i:s');
        $d->updated_at   = gmdate('Y-m-d H:i:s');
        $d->save();
    }

    public function test_every_advertised_metric_is_resolvable(): void
    {
        $c = $this->campaign(['goal_cents' => 100000]);

        foreach (CampaignStatMetrics::keys() as $key) {
            // Null is a legitimate answer; throwing or a stray "0" is not.
            $value = $this->metrics()->value($c, $key);
            $this->assertTrue(
                $value === null || is_string($value),
                "metric {$key} answered something that is neither a string nor null"
            );
        }
    }

    public function test_a_campaign_with_no_goal_has_no_goal_figures(): void
    {
        $c = $this->campaign(['goal_cents' => 0]);

        foreach (['goal', 'remaining', 'percent'] as $key) {
            $this->assertNull($this->metrics()->value($c, $key), "{$key} should stay silent");
        }
    }

    /**
     * The shape an installed site already has: the campaign was switched to a
     * donor goal while its money target stayed on the row, and no screen shows
     * that target any more. Written straight to the row, never through the
     * service, so the read side has to answer for it on its own.
     */
    public function test_a_donor_goal_never_states_the_money_target_it_still_carries(): void
    {
        $c = $this->campaign(['goal_cents' => 1000000]);

        $c->goal_type    = 'donors';
        $c->goal_count   = 250;
        $c->donors_count = 100;
        $c->save();

        $c = Campaign::query()->find('id', (int) $c->id);
        $this->assertSame(1000000, (int) $c->goal_cents, 'the stale money target is the premise of this test');

        $this->assertSame('250', $this->metrics()->value($c, 'goal'));
        $this->assertSame('150', $this->metrics()->value($c, 'remaining'));
        $this->assertSame('40%', $this->metrics()->value($c, 'percent'));
    }

    public function test_a_donations_goal_with_no_count_stays_silent(): void
    {
        $c = $this->campaign(['goal_cents' => 1000000]);

        $c->goal_type  = 'donations';
        $c->goal_count = null;
        $c->save();

        $c = Campaign::query()->find('id', (int) $c->id);

        foreach (['goal', 'remaining', 'percent'] as $key) {
            $this->assertNull($this->metrics()->value($c, $key), "{$key} should stay silent");
        }
    }

    /** The panel only renders the active type's input, so the other one cannot be left behind. */
    public function test_switching_the_goal_type_clears_the_target_it_no_longer_uses(): void
    {
        $c = $this->campaign(['goal_cents' => 1000000]);

        $req = new WP_REST_Request('PATCH', '/gratora/v1/admin/campaigns/' . (int) $c->id);
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode(['goal_type' => 'donors', 'goal_count' => 250]));
        $this->assertSame(200, rest_do_request($req)->get_status());

        $c = Campaign::query()->find('id', (int) $c->id);

        $this->assertNull($c->goal_cents);
        $this->assertSame(250, (int) $c->goal_count);
    }

    public function test_a_campaign_with_no_end_date_has_no_days_left(): void
    {
        $this->assertNull($this->metrics()->value($this->campaign(), 'days_left'));
    }

    public function test_average_and_top_stay_silent_before_the_first_donation(): void
    {
        $c = $this->campaign();

        $this->assertNull($this->metrics()->value($c, 'average'));
        $this->assertNull($this->metrics()->value($c, 'top'));
    }

    public function test_top_donation_is_the_largest_single_gift(): void
    {
        $c = $this->campaign();
        $this->paidGift($c, 1000);
        $this->paidGift($c, 25000);
        $this->paidGift($c, 5000);

        // Reload: the aggregate syncer moves donations_count on the row, and
        // top is gated on it.
        $c = Campaign::query()->find('id', (int) $c->id);

        $this->assertStringContainsString('250', (string) $this->metrics()->value($c, 'top'));
    }

    public function test_an_unknown_metric_resolves_to_nothing(): void
    {
        $this->assertNull($this->metrics()->value($this->campaign(), 'not_a_metric'));
        $this->assertFalse(CampaignStatMetrics::isKey('not_a_metric'));
    }

    public function test_a_custom_label_replaces_the_default(): void
    {
        $this->assertSame('So far', $this->metrics()->label('raised', 'So far'));
        $this->assertSame('Amount raised', $this->metrics()->label('raised', '   '));
    }
}
