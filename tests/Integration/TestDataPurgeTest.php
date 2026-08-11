<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donations\Donation;
use Dono\Donors\Donor;
use Dono\Donors\DonorService;
use Dono\Foundation\Maintenance\TestDataPurger;
use Dono\Foundation\Plugin;
use Dono\Recurring\RecurringPlan;
use Dono\Vendor\Queryable\DB;
use WP_REST_Request;

/**
 * Clearing test data is the one maintenance action with no undo, so what it
 * must never touch matters more than what it removes.
 */
final class TestDataPurgeTest extends IntegrationTestCase
{
    private function purger(): TestDataPurger
    {
        return new TestDataPurger(Plugin::instance()->container->get(DonorService::class));
    }

    private function donor(string $email): Donor
    {
        $d = Donor::make();
        $d->email_encrypted = 'enc-' . $email;
        $d->email_hash      = hash('sha256', $email);
        $d->first_name      = 'Test';
        $d->last_name       = 'Person';
        $d->created_at      = gmdate('Y-m-d H:i:s');
        $d->updated_at      = $d->created_at;
        $d->save();

        return $d;
    }

    private function donation(Donor $donor, bool $isTest): Donation
    {
        $now = gmdate('Y-m-d H:i:s');
        $d = Donation::make();
        $d->reference         = 'DONO-T-' . bin2hex(random_bytes(4));
        $d->donor_id          = (int) $donor->id;
        $d->amount_cents      = 2500;
        $d->currency          = 'USD';
        $d->base_amount_cents = 2500;
        $d->gateway           = 'stripe';
        $d->status            = 'paid';
        $d->is_test           = $isTest;
        $d->paid_at           = $now;
        $d->created_at        = $now;
        $d->updated_at        = $now;
        $d->save();

        return $d;
    }

    public function test_test_donations_go_and_live_ones_stay(): void
    {
        $live = $this->donation($this->donor('live@example.test'), false);
        $test = $this->donation($this->donor('test@example.test'), true);

        $this->purger()->purge();

        $this->assertNotNull(Donation::query()->where('id', (int) $live->id)->get(), 'a real donation is untouchable');
        $this->assertNull(Donation::query()->where('id', (int) $test->id)->get());
    }

    /**
     * The dangerous case: someone who tried the form in test mode and later
     * gave for real. Their real donation, and their record, must survive.
     */
    public function test_a_donor_with_both_keeps_their_record_and_their_real_gift(): void
    {
        $donor = $this->donor('both@example.test');
        $live  = $this->donation($donor, false);
        $this->donation($donor, true);

        $this->purger()->purge();

        $this->assertNotNull(Donor::query()->where('id', (int) $donor->id)->get(), 'they gave for real');
        $this->assertNotNull(Donation::query()->where('id', (int) $live->id)->get());
        $this->assertSame(
            0,
            (int) Donation::query()->where('donor_id', (int) $donor->id)->where('is_test', 1)->count()
        );
    }

    public function test_a_donor_left_with_nothing_is_removed(): void
    {
        $donor = $this->donor('only-test@example.test');
        $this->donation($donor, true);

        $this->assertSame(1, $this->purger()->preview()['donors']);
        $this->purger()->purge();

        $this->assertNull(Donor::query()->where('id', (int) $donor->id)->get());
    }

    public function test_a_test_plan_goes_and_a_live_one_stays(): void
    {
        $donor = $this->donor('subscriber@example.test');

        $ids = [];
        foreach ([true, false] as $isTest) {
            $p = RecurringPlan::make();
            $p->donor_id                = (int) $donor->id;
            $p->gateway                 = 'stripe';
            $p->gateway_subscription_id = 'sub_' . uniqid();
            $p->amount_cents            = 2500;
            $p->currency                = 'USD';
            $p->interval_unit           = 'week';
            $p->interval_count          = 1;
            $p->status                  = 'active';
            $p->is_test                 = $isTest;
            $p->started_at              = gmdate('Y-m-d H:i:s');
            $p->created_at              = $p->started_at;
            $p->updated_at              = $p->started_at;
            $p->save();

            $ids[$isTest ? 'test' : 'live'] = (int) $p->id;
        }

        $removed = $this->purger()->purge();

        // Clearing the ledger before going live has to reach the schedules a
        // sandbox left behind, or the Subscriptions screen opens on launch day
        // full of plans nobody will ever be charged for.
        $this->assertSame(1, $removed['recurring_plans']);
        $this->assertNull(RecurringPlan::query()->where('id', $ids['test'])->get());
        $this->assertNotNull(RecurringPlan::query()->where('id', $ids['live'])->get());
    }

    public function test_the_preview_counts_test_plans_before_anything_is_deleted(): void
    {
        $donor = $this->donor('previewer@example.test');

        $p = RecurringPlan::make();
        $p->donor_id                = (int) $donor->id;
        $p->gateway                 = 'stripe';
        $p->gateway_subscription_id = 'sub_' . uniqid();
        $p->amount_cents            = 2500;
        $p->currency                = 'USD';
        $p->interval_unit           = 'week';
        $p->interval_count          = 1;
        $p->status                  = 'active';
        $p->is_test                 = true;
        $p->started_at              = gmdate('Y-m-d H:i:s');
        $p->created_at              = $p->started_at;
        $p->updated_at              = $p->started_at;
        $p->save();

        // The card lists what will go before anyone types DELETE, and a
        // schedule missing from that list is one nobody agreed to remove.
        $this->assertSame(1, $this->purger()->preview()['recurring_plans']);
        $this->assertNotNull(RecurringPlan::query()->where('id', (int) $p->id)->get());
    }

    public function test_a_donor_with_a_live_plan_is_kept_even_with_no_donations_left(): void
    {
        $donor = $this->donor('planner@example.test');
        $this->donation($donor, true);

        $p = RecurringPlan::make();
        $p->donor_id                = (int) $donor->id;
        $p->gateway                 = 'stripe';
        $p->gateway_subscription_id = 'sub_' . uniqid();
        $p->amount_cents            = 1000;
        $p->currency                = 'USD';
        $p->interval_unit           = 'month';
        $p->interval_count          = 1;
        $p->status                  = 'active';
        $p->is_test                 = false;
        $p->started_at              = gmdate('Y-m-d H:i:s');
        $p->created_at              = $p->started_at;
        $p->updated_at              = $p->started_at;
        $p->save();

        $this->purger()->purge();

        $this->assertNotNull(Donor::query()->where('id', (int) $donor->id)->get(), 'they are still giving');
        $this->assertNotNull(RecurringPlan::query()->where('id', (int) $p->id)->get());
    }

    public function test_the_rows_describing_a_test_donation_go_with_it(): void
    {
        $donation = $this->donation($this->donor('withnote@example.test'), true);
        $id = (int) $donation->id;

        DB::table('dono_donation_notes')->insert([
            'donation_id'    => $id,
            'author_user_id' => 1,
            'body_encrypted' => 'enc-internal-note',
            'created_at'     => gmdate('Y-m-d H:i:s'),
            'updated_at'     => gmdate('Y-m-d H:i:s'),
        ]);
        $this->assertSame(1, (int) DB::table('dono_donation_notes')->where('donation_id', $id)->count());

        $this->purger()->purge();

        $this->assertSame(0, (int) DB::table('dono_donation_notes')->where('donation_id', $id)->count());
    }

    public function test_add_ons_are_told_before_the_rows_disappear(): void
    {
        $donation = $this->donation($this->donor('addon@example.test'), true);

        $seen = [];
        add_action('dono.test_data.purge_donations', function (array $ids) use (&$seen): void {
            $seen = array_merge($seen, $ids);
        });

        $this->purger()->purge();

        $this->assertContains((int) $donation->id, $seen);
    }

    public function test_the_route_refuses_without_the_typed_confirmation(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $donation = $this->donation($this->donor('guard@example.test'), true);

        $req = new WP_REST_Request('POST', '/dono/v1/admin/tools/purge-test-data');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode(['confirmation' => 'yes please']));
        $res = rest_do_request($req);

        $this->assertSame(400, $res->get_status());
        $this->assertNotNull(Donation::query()->where('id', (int) $donation->id)->get(), 'nothing was removed');

        $req = new WP_REST_Request('POST', '/dono/v1/admin/tools/purge-test-data');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode(['confirmation' => 'DELETE']));
        $this->assertSame(200, rest_do_request($req)->get_status());
        $this->assertNull(Donation::query()->where('id', (int) $donation->id)->get());
    }
}
