<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Donors\Donor;
use Gratora\Donors\DonorService;
use Gratora\Foundation\Plugin;
use Gratora\Recurring\RecurringPlan;
use WP_REST_Request;

/**
 * The subscriptions list is where an admin goes with a cadence in mind or a
 * gateway handle off an email, so its filter and its search have to answer the
 * question that was asked.
 */
final class RecurringListFiltersTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    private function donor(string $email): Donor
    {
        return Plugin::instance()->container
            ->get(DonorService::class)
            ->findOrCreate($email, ['first_name' => 'Nadia', 'last_name' => 'Petrova']);
    }

    private function plan(string $unit, int $count, string $handle): RecurringPlan
    {
        $now = gmdate('Y-m-d H:i:s');

        $p = RecurringPlan::make();
        $p->donor_id                = (int) $this->donor($handle . '@example.com')->id;
        $p->gateway                 = 'stripe';
        $p->gateway_subscription_id = $handle;
        $p->amount_cents            = 2500;
        $p->currency                = 'EUR';
        $p->interval_unit           = $unit;
        $p->interval_count          = $count;
        $p->status                  = 'active';
        $p->is_test                 = false;
        $p->started_at              = $now;
        $p->created_at              = $now;
        $p->updated_at              = $now;
        $p->save();

        return $p;
    }

    /** @return array{0: list<int>, 1: int} the ids on the page and the reported total */
    private function list(array $params): array
    {
        $req = new WP_REST_Request('GET', '/gratora/v1/admin/recurring');
        foreach ($params as $k => $v) {
            $req->set_param($k, $v);
        }

        $res = rest_do_request($req);
        $this->assertSame(200, $res->get_status(), (string) wp_json_encode($res->get_data()));

        return [
            array_map(static fn (array $r): int => (int) $r['id'], $res->get_data()),
            (int) $res->get_headers()['X-WP-Total'],
        ];
    }

    public function test_the_monthly_filter_leaves_quarterly_plans_out(): void
    {
        $monthly   = $this->plan('month', 1, 'sub_monthly');
        $quarterly = $this->plan('month', 3, 'sub_quarterly');

        [$ids, $total] = $this->list(['frequency' => 'monthly']);

        $this->assertContains((int) $monthly->id, $ids);
        $this->assertNotContains((int) $quarterly->id, $ids);
        $this->assertSame(count($ids), $total, 'the count header must agree with the page');
    }

    public function test_the_weekly_filter_leaves_fortnightly_plans_out(): void
    {
        $weekly   = $this->plan('week', 1, 'sub_weekly');
        $biweekly = $this->plan('week', 2, 'sub_biweekly');

        [$ids] = $this->list(['frequency' => 'weekly']);

        $this->assertContains((int) $weekly->id, $ids);
        $this->assertNotContains((int) $biweekly->id, $ids);
    }

    public function test_the_quarterly_filter_finds_the_quarterly_plans(): void
    {
        $quarterly = $this->plan('month', 3, 'sub_q');
        $monthly   = $this->plan('month', 1, 'sub_m');

        [$ids] = $this->list(['frequency' => 'quarterly']);

        $this->assertContains((int) $quarterly->id, $ids);
        $this->assertNotContains((int) $monthly->id, $ids);
    }

    public function test_a_gateway_subscription_id_finds_its_plan(): void
    {
        $wanted = $this->plan('month', 1, 'sub_1PabcXYZ');
        $other  = $this->plan('month', 1, 'sub_other');

        [$ids, $total] = $this->list(['search' => 'sub_1PabcXYZ']);

        $this->assertSame([(int) $wanted->id], $ids);
        $this->assertNotContains((int) $other->id, $ids);
        $this->assertSame(1, $total);
    }

    public function test_a_search_that_matches_nothing_still_returns_nothing(): void
    {
        $this->plan('month', 1, 'sub_present');

        [$ids, $total] = $this->list(['search' => 'sub_absent']);

        $this->assertSame([], $ids);
        $this->assertSame(0, $total);
    }
}
