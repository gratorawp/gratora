<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Campaigns\Campaign;
use Dono\Donations\Donation;
use WP_REST_Request;

/**
 * Campaigns and Funds say what their figures leave out.
 *
 * Both read stored rollups, and AggregateSyncer writes those through
 * donationsOnly(), so no test money can ever reach raised_cents. That makes a
 * toggle meaningless here: there is no other version of the number to show.
 * What the screens owe the operator is the reason the figure is zero, which is
 * the same answer donor insights arrived at.
 */
final class CampaignFundTestHiddenTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    private function seedTestDonation(): void
    {
        $c = Campaign::make();
        $c->title      = 'Hidden count ' . uniqid();
        $c->slug       = 'hidden-count-' . uniqid();
        $c->status     = 'published';
        $c->goal_type  = 'amount';
        $c->goal_cents = 100000;
        $c->created_at = gmdate('Y-m-d H:i:s');
        $c->updated_at = gmdate('Y-m-d H:i:s');
        $c->save();

        $now = gmdate('Y-m-d H:i:s');
        $d = Donation::make();
        $d->reference         = 'HID-' . strtoupper(uniqid());
        $d->donor_id          = 1;
        $d->campaign_id       = (int) $c->id;
        $d->amount_cents      = 5000;
        $d->base_amount_cents = 5000;
        $d->currency          = 'EUR';
        $d->status            = 'paid';
        $d->kind              = 'donation';
        $d->gateway           = 'offline';
        $d->is_test           = true;
        $d->paid_at           = $now;
        $d->created_at        = $now;
        $d->updated_at        = $now;
        $d->save();
    }

    private function hiddenHeader(string $route): int
    {
        $res = rest_do_request(new WP_REST_Request('GET', $route));

        return (int) ($res->get_headers()['X-Dono-Test-Hidden'] ?? 0);
    }

    public function test_the_campaigns_list_reports_what_it_is_not_counting(): void
    {
        $this->assertSame(0, $this->hiddenHeader('/dono/v1/admin/campaigns'));

        $this->seedTestDonation();

        $this->assertSame(
            1,
            $this->hiddenHeader('/dono/v1/admin/campaigns'),
            'a zero on this screen has to be explainable'
        );
    }

    public function test_the_funds_list_reports_it_too(): void
    {
        $this->seedTestDonation();

        $this->assertSame(1, $this->hiddenHeader('/dono/v1/admin/funds'));
    }

    public function test_the_rollup_itself_never_moves(): void
    {
        $this->seedTestDonation();

        // The counterpart to the notice: the figure stays clean, which is why
        // the screen explains rather than offering to include it.
        $campaign = Campaign::query()->orderBy('id', 'DESC')->limit(1)->getAll()[0];

        $this->assertSame(0, (int) $campaign->raised_cents);
    }
}
