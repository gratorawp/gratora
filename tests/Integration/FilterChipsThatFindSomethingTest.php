<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Campaigns\Campaign;
use Gratora\Foundation\Plugin;
use Gratora\Recurring\RecurringPlan;
use Gratora\Recurring\RecurringPlanRepository;
use WP_REST_Request;

/**
 * The campaign filter on the subscriptions list offered every campaign that
 * had ever taken a donation, read from the donations screen's own route. Most
 * of its chips answered with an empty table: a campaign can run for years on
 * one-off giving and never see a subscription.
 *
 * The gateway filter beside it has always been drawn from the plans, which is
 * what this one now does too.
 */
final class FilterChipsThatFindSomethingTest extends IntegrationTestCase
{
    private function campaign(string $title): int
    {
        $now = gmdate('Y-m-d H:i:s');

        $c = Campaign::make();
        $c->title      = $title;
        $c->slug       = sanitize_title($title) . '-' . bin2hex(random_bytes(3));
        $c->status     = 'published';
        $c->created_at = $now;
        $c->updated_at = $now;
        $c->save();

        return (int) $c->id;
    }

    private function planOn(?int $campaignId): void
    {
        $now = gmdate('Y-m-d H:i:s');

        $p = RecurringPlan::make();
        $p->donor_id                = 1;
        $p->campaign_id             = $campaignId;
        $p->gateway                 = 'stripe';
        $p->gateway_subscription_id = 'sub_' . bin2hex(random_bytes(4));
        $p->amount_cents            = 2000;
        $p->currency                = 'USD';
        $p->interval_unit           = 'month';
        $p->interval_count          = 1;
        $p->status                  = 'active';
        $p->started_at              = $now;
        $p->created_at              = $now;
        $p->updated_at              = $now;
        $p->save();
    }

    /** @return list<string> */
    private function offered(): array
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        $res = rest_do_request(new WP_REST_Request('GET', '/gratora/v1/admin/recurring/campaign-options'));

        return array_column((array) $res->get_data(), 'label');
    }

    public function test_a_campaign_with_a_subscription_is_offered(): void
    {
        $this->planOn($this->campaign('Monthly Giving Circle'));

        $this->assertContains('Monthly Giving Circle', $this->offered());
    }

    public function test_a_campaign_with_no_subscription_is_not(): void
    {
        $this->campaign('One Off Gala');
        $this->planOn($this->campaign('Monthly Giving Circle'));

        $this->assertNotContains('One Off Gala', $this->offered());
    }

    /** One chip per campaign, however many plans it carries. */
    public function test_a_campaign_is_offered_once(): void
    {
        $id = $this->campaign('Clean Water');
        $this->planOn($id);
        $this->planOn($id);
        $this->planOn($id);

        $this->assertSame(['Clean Water'], array_values(array_filter(
            $this->offered(),
            static fn (string $label): bool => $label === 'Clean Water'
        )));
    }

    /** A plan on no campaign puts nothing in the list. */
    public function test_a_plan_with_no_campaign_offers_nothing(): void
    {
        $this->planOn(null);

        $this->assertSame([], $this->offered());
    }

    /** Every chip the filter offers finds at least one row. */
    public function test_every_chip_finds_a_subscription(): void
    {
        $this->campaign('Never Subscribed');
        $this->planOn($this->campaign('Monthly Giving Circle'));
        $this->planOn($this->campaign('Clean Water'));

        $plans = Plugin::instance()->container->get(RecurringPlanRepository::class);

        foreach ($plans->campaignsInUse() as $chip) {
            $found = $plans->listAdmin(['campaign_id' => (int) $chip['value'], 'per_page' => 1]);

            $this->assertNotSame(
                0,
                (int) ($found['total'] ?? count((array) ($found['rows'] ?? $found))),
                $chip['label'] . ' is offered and answers with nothing'
            );
        }
    }

    /** A link to a plan has to be able to fetch the plan it names. */
    public function test_a_plan_can_be_fetched_by_id(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        $this->planOn($this->campaign('Monthly Giving Circle'));
        $plan = RecurringPlan::query()->orderBy('id', 'DESC')->get();

        $res = rest_do_request(new WP_REST_Request('GET', '/gratora/v1/admin/recurring/' . (int) $plan->id));

        $this->assertSame(200, $res->get_status());
        $this->assertSame((int) $plan->id, (int) $res->get_data()['id']);
        $this->assertArrayHasKey('donor', $res->get_data(), 'the same shape the list sends');
    }

    public function test_a_link_to_a_plan_that_is_gone_answers_not_found(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        $res = rest_do_request(new WP_REST_Request('GET', '/gratora/v1/admin/recurring/99999'));

        $this->assertSame(404, $res->get_status());
    }
}
