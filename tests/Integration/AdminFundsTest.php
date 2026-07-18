<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use WP_REST_Request;

final class AdminFundsTest extends IntegrationTestCase
{
    public function test_index_is_empty_initially(): void
    {
        $res = $this->get('/dono/v1/admin/funds');
        $this->assertSame(200, $res->get_status());
        $this->assertSame([], $res->get_data());
        $this->assertSame('0', $res->get_headers()['X-WP-Total'] ?? '0');
    }

    public function test_create_requires_code_and_name(): void
    {
        $res = $this->post('/dono/v1/admin/funds', ['code' => 'building']);
        $this->assertSame(400, $res->get_status());
        $this->assertSame('rest_missing_callback_param', $res->get_data()['code']);
    }

    public function test_create_fund_returns_shaped_payload(): void
    {
        $res = $this->post('/dono/v1/admin/funds', [
            'code'          => 'Building',
            'name'          => 'Building Fund',
            'description'   => 'Capital works',
            'is_restricted' => true,
            'goal_cents'    => 150000,
        ]);
        $this->assertSame(201, $res->get_status());
        $data = $res->get_data();
        $this->assertSame('building', $data['code'], 'Code is normalized to lowercase');
        $this->assertSame('Building Fund', $data['name']);
        $this->assertTrue($data['is_restricted']);
        $this->assertSame(150000, $data['goal_cents']);
        $this->assertSame(0, $data['raised_cents']);
        $this->assertSame(0, $data['donations_count']);
        $this->assertFalse($data['is_default']);
        $this->assertTrue($data['is_active']);
    }

    public function test_create_rejects_duplicate_code(): void
    {
        $this->post('/dono/v1/admin/funds', ['code' => 'general', 'name' => 'General']);
        $res = $this->post('/dono/v1/admin/funds', ['code' => 'general', 'name' => 'Other']);
        $this->assertSame(422, $res->get_status());
        $this->assertSame('dono_invalid_input', $res->get_data()['code']);
    }

    public function test_show_404s_unknown_fund(): void
    {
        $res = $this->get('/dono/v1/admin/funds/99999');
        $this->assertSame(404, $res->get_status());
        $this->assertSame('dono_not_found', $res->get_data()['code']);
    }

    public function test_update_is_partial(): void
    {
        $created = $this->post('/dono/v1/admin/funds', [
            'code' => 'scholar', 'name' => 'Scholarship',
        ])->get_data();

        $res = $this->put("/dono/v1/admin/funds/{$created['id']}", ['name' => 'Scholarship Fund']);
        $this->assertSame(200, $res->get_status());
        $this->assertSame('Scholarship Fund', $res->get_data()['name']);
        $this->assertSame('scholar', $res->get_data()['code'], 'Untouched fields keep their value');
    }

    public function test_setting_default_demotes_the_previous_default(): void
    {
        $a = $this->post('/dono/v1/admin/funds', [
            'code' => 'general', 'name' => 'General', 'is_default' => true,
        ])->get_data();
        $this->assertTrue($a['is_default']);

        $b = $this->post('/dono/v1/admin/funds', [
            'code' => 'capital', 'name' => 'Capital', 'is_default' => true,
        ])->get_data();
        $this->assertTrue($b['is_default']);

        $reloadedA = $this->get("/dono/v1/admin/funds/{$a['id']}")->get_data();
        $this->assertFalse($reloadedA['is_default'], 'Only one fund stays default');
    }

    public function test_default_fund_cannot_be_deleted(): void
    {
        $fund = $this->post('/dono/v1/admin/funds', [
            'code' => 'general', 'name' => 'General', 'is_default' => true,
        ])->get_data();

        $res = $this->deleteReq("/dono/v1/admin/funds/{$fund['id']}");
        $this->assertSame(422, $res->get_status());
        $this->assertSame('dono_fund_delete_blocked', $res->get_data()['code']);
    }

    public function test_unused_fund_is_hard_deleted(): void
    {
        $fund = $this->post('/dono/v1/admin/funds', [
            'code' => 'temp', 'name' => 'Temporary',
        ])->get_data();

        $res = $this->deleteReq("/dono/v1/admin/funds/{$fund['id']}");
        $this->assertSame(200, $res->get_status());
        $this->assertSame('deleted', $res->get_data()['action']);
        $this->assertSame(404, $this->get("/dono/v1/admin/funds/{$fund['id']}")->get_status());
    }

    public function test_referenced_fund_is_deactivated_not_deleted(): void
    {
        $fund     = $this->post('/dono/v1/admin/funds', ['code' => 'restricted', 'name' => 'Restricted'])->get_data();
        $campaign = $this->post('/dono/v1/admin/campaigns', ['title' => 'Appeal'])->get_data();
        $this->put("/dono/v1/admin/campaigns/{$campaign['id']}", ['default_fund_id' => $fund['id']]);

        $res = $this->deleteReq("/dono/v1/admin/funds/{$fund['id']}");
        $this->assertSame(200, $res->get_status());
        $this->assertSame('deactivated', $res->get_data()['action']);
        $this->assertGreaterThanOrEqual(1, $res->get_data()['campaigns']);

        $reloaded = $this->get("/dono/v1/admin/funds/{$fund['id']}");
        $this->assertSame(200, $reloaded->get_status(), 'Fund is kept, not deleted');
        $this->assertFalse($reloaded->get_data()['is_active'], 'Fund is deactivated');
    }

    public function test_fund_designated_by_a_form_is_deactivated_not_deleted(): void
    {
        $fund     = $this->post('/dono/v1/admin/funds', ['code' => 'formfund', 'name' => 'Form Fund'])->get_data();
        $campaign = $this->post('/dono/v1/admin/campaigns', ['title' => 'Has Form'])->get_data();

        // A form designates this fund as its default. Hard-deleting it would
        // dangle Form.default_fund_id, so it must deactivate-and-keep instead.
        $now  = gmdate('Y-m-d H:i:s');
        $form = \Dono\Forms\Form::make();
        $form->title           = 'Designating form';
        $form->slug            = 'designating-form-' . substr(md5(uniqid('', true)), 0, 6);
        $form->status          = 'published';
        $form->campaign_id     = (int) $campaign['id'];
        $form->default_fund_id = (int) $fund['id'];
        $form->created_at      = $now;
        $form->updated_at      = $now;
        $form->save();

        $res = $this->deleteReq("/dono/v1/admin/funds/{$fund['id']}");
        $this->assertSame(200, $res->get_status());
        $this->assertSame('deactivated', $res->get_data()['action']);
        $this->assertGreaterThanOrEqual(1, (int) $res->get_data()['forms']);

        $reloaded = $this->get("/dono/v1/admin/funds/{$fund['id']}");
        $this->assertSame(200, $reloaded->get_status(), 'fund kept, not deleted');
        $this->assertFalse($reloaded->get_data()['is_active']);
    }

    public function test_reassign_then_delete_moves_references_and_removes_fund(): void
    {
        $fund     = $this->post('/dono/v1/admin/funds', ['code' => 'old', 'name' => 'Old'])->get_data();
        $target   = $this->post('/dono/v1/admin/funds', ['code' => 'new', 'name' => 'New'])->get_data();
        $campaign = $this->post('/dono/v1/admin/campaigns', ['title' => 'Appeal'])->get_data();
        $this->put("/dono/v1/admin/campaigns/{$campaign['id']}", ['default_fund_id' => $fund['id']]);

        // A form defaulting to the fund + a recurring plan crediting it: both
        // must repoint, else new donations / renewals would hit the deleted fund.
        $now  = gmdate('Y-m-d H:i:s');
        $form = \Dono\Forms\Form::make();
        $form->title = 'Plan form'; $form->slug = 'plan-form'; $form->status = 'published';
        $form->blocks = ''; $form->spec_version = 1; $form->default_fund_id = (int) $fund['id'];
        $form->created_at = $now; $form->updated_at = $now;
        $form->save();

        $plan = \Dono\Recurring\RecurringPlan::make();
        $plan->donor_id = 1; $plan->gateway = 'stripe';
        $plan->gateway_subscription_id = 'sub_reassign_' . bin2hex(random_bytes(3));
        $plan->amount_cents = 2000; $plan->currency = 'USD'; $plan->fund_id = (int) $fund['id'];
        $plan->started_at = $now; $plan->created_at = $now; $plan->updated_at = $now;
        $plan->save();

        // A paid donation on the source fund: its money must follow to the
        // target fund's aggregate, not vanish with the deleted source.
        $donation = \Dono\Donations\Donation::make();
        $donation->reference = 'DONO-FUND-MOVE'; $donation->donor_id = 1;
        $donation->amount_cents = 7000; $donation->net_cents = 7000;
        $donation->currency = 'USD'; $donation->base_amount_cents = 7000;
        $donation->base_currency = 'USD'; $donation->fx_rate = '1.00000000';
        $donation->gateway = 'offline';
        $donation->status = 'paid'; $donation->fund_id = (int) $fund['id'];
        $donation->paid_at = $now; $donation->created_at = $now; $donation->updated_at = $now;
        $donation->save();

        $res = $this->deleteReq("/dono/v1/admin/funds/{$fund['id']}", ['reassign_to' => $target['id']]);
        $this->assertSame(202, $res->get_status());
        $this->assertSame('reassign_queued', $res->get_data()['action']);

        $pending = $this->get("/dono/v1/admin/funds/{$fund['id']}");
        $this->assertSame(200, $pending->get_status(), 'Source kept until the job finishes');
        $this->assertTrue($pending->get_data()['reassign_pending']);

        $this->runPendingAsyncJobs();

        $this->assertSame(404, $this->get("/dono/v1/admin/funds/{$fund['id']}")->get_status(),
            'Source fund removed once references moved');
        $reloadedCampaign = $this->get("/dono/v1/admin/campaigns/{$campaign['id']}")->get_data();
        $this->assertSame($target['id'], (int) $reloadedCampaign['default_fund_id'],
            'Campaign default fund repointed to the target');

        $this->assertSame((int) $target['id'], (int) \Dono\Forms\Form::query()->where('id', $form->id)->get()->default_fund_id,
            'Form default fund repointed to the target');
        $this->assertSame((int) $target['id'], (int) \Dono\Recurring\RecurringPlan::query()->where('id', $plan->id)->get()->fund_id,
            'Recurring plan fund repointed to the target');

        $targetFund = \Dono\Funds\Fund::query()->where('id', (int) $target['id'])->get();
        $this->assertSame(7000, (int) $targetFund->raised_cents,
            'Target fund raised_cents resynced to include the moved donation');
        $this->assertSame(1, (int) $targetFund->donations_count,
            'Target fund donations_count resynced after reassignment');
    }

    public function test_reassign_to_invalid_target_is_422(): void
    {
        $fund     = $this->post('/dono/v1/admin/funds', ['code' => 'src', 'name' => 'Src'])->get_data();
        $campaign = $this->post('/dono/v1/admin/campaigns', ['title' => 'Appeal'])->get_data();
        $this->put("/dono/v1/admin/campaigns/{$campaign['id']}", ['default_fund_id' => $fund['id']]);

        $missing = $this->deleteReq("/dono/v1/admin/funds/{$fund['id']}", ['reassign_to' => 999999]);
        $this->assertSame(422, $missing->get_status());
        $this->assertSame('dono_invalid_input', $missing->get_data()['code']);

        $self = $this->deleteReq("/dono/v1/admin/funds/{$fund['id']}", ['reassign_to' => $fund['id']]);
        $this->assertSame(422, $self->get_status());
        $this->assertSame('dono_invalid_input', $self->get_data()['code']);
    }

    public function test_orphaned_reassignment_is_self_healed_on_list_load(): void
    {
        $fund     = $this->post('/dono/v1/admin/funds', ['code' => 'orphan', 'name' => 'Orphan'])->get_data();
        $target   = $this->post('/dono/v1/admin/funds', ['code' => 'keep', 'name' => 'Keep'])->get_data();
        $campaign = $this->post('/dono/v1/admin/campaigns', ['title' => 'Appeal'])->get_data();
        $this->put("/dono/v1/admin/campaigns/{$campaign['id']}", ['default_fund_id' => $fund['id']]);

        // Simulate the pre-fix state: pending marker set, but the background
        // job was lost (it completed as a no-op under the args bug, nothing
        // re-queued it).
        \Dono\Funds\FundReassignmentJob::markPending((int) $fund['id'], (int) $target['id']);

        $pendingActions = static fn (): int => (int) self::$wpdb->get_var(
            "SELECT COUNT(*) FROM " . self::$prefix . "actionscheduler_actions"
            . " WHERE hook = '" . \Dono\Funds\FundReassignmentJob::HOOK . "' AND status = 'pending'"
        );
        $this->assertSame(0, $pendingActions(), 'Precondition: no live reassignment job');

        // Loading the funds list reconciles and re-queues the lost job.
        $this->get('/dono/v1/admin/funds');
        $this->assertGreaterThanOrEqual(1, $pendingActions(), 'Reconcile re-queued the lost job');

        $this->runPendingAsyncJobs();

        $this->assertSame(404, $this->get("/dono/v1/admin/funds/{$fund['id']}")->get_status(),
            'Source fund removed once the re-queued job ran');
        $this->assertArrayNotHasKey((int) $fund['id'], \Dono\Funds\FundReassignmentJob::pending(),
            'Pending marker cleared, so the badge resolves');
        $reCampaign = $this->get("/dono/v1/admin/campaigns/{$campaign['id']}")->get_data();
        $this->assertSame((int) $target['id'], (int) $reCampaign['default_fund_id']);
    }

    public function test_index_paginates_and_sets_headers(): void
    {
        for ($i = 1; $i <= 6; $i++) {
            $this->post('/dono/v1/admin/funds', ['code' => "fund-$i", 'name' => "Fund $i"]);
        }

        $res = $this->get('/dono/v1/admin/funds', ['per_page' => 5, 'page' => 1]);
        $this->assertSame(200, $res->get_status());
        $this->assertCount(5, $res->get_data());
        $this->assertSame('6', $res->get_headers()['X-WP-Total'] ?? '0');
        $this->assertSame('2', $res->get_headers()['X-WP-TotalPages'] ?? '0');
    }

    public function test_search_matches_name_or_code(): void
    {
        $this->post('/dono/v1/admin/funds', ['code' => 'roof', 'name' => 'Roof Repair']);
        $this->post('/dono/v1/admin/funds', ['code' => 'general', 'name' => 'General']);

        $res = $this->get('/dono/v1/admin/funds', ['search' => 'roof']);
        $names = array_column($res->get_data(), 'code');
        $this->assertContains('roof', $names);
        $this->assertNotContains('general', $names);
    }

    public function test_fund_cannot_be_its_own_parent(): void
    {
        $a = $this->post('/dono/v1/admin/funds', ['code' => 'a', 'name' => 'A'])->get_data();

        $res = $this->put("/dono/v1/admin/funds/{$a['id']}", ['parent_fund_id' => $a['id']]);
        $this->assertSame(422, $res->get_status());
        $this->assertSame('dono_invalid_input', $res->get_data()['code']);
    }

    public function test_nesting_is_limited_to_one_level(): void
    {
        $parent = $this->post('/dono/v1/admin/funds', ['code' => 'parent', 'name' => 'Parent'])->get_data();
        $child  = $this->post('/dono/v1/admin/funds', [
            'code' => 'child', 'name' => 'Child', 'parent_fund_id' => $parent['id'],
        ])->get_data();
        $this->assertSame($parent['id'], $child['parent_fund_id']);

        // A sub-fund cannot itself be a parent (would be three levels deep).
        $res = $this->post('/dono/v1/admin/funds', [
            'code' => 'grandchild', 'name' => 'Grandchild', 'parent_fund_id' => $child['id'],
        ]);
        $this->assertSame(422, $res->get_status());
        $this->assertSame('dono_invalid_input', $res->get_data()['code']);
    }

    public function test_fund_with_children_cannot_become_a_child(): void
    {
        $parent = $this->post('/dono/v1/admin/funds', ['code' => 'parent', 'name' => 'Parent'])->get_data();
        $this->post('/dono/v1/admin/funds', [
            'code' => 'child', 'name' => 'Child', 'parent_fund_id' => $parent['id'],
        ]);
        $other = $this->post('/dono/v1/admin/funds', ['code' => 'other', 'name' => 'Other'])->get_data();

        // Parent has a sub-fund, so it cannot be nested under Other (and this
        // is exactly the A->B, B->A cycle case).
        $res = $this->put("/dono/v1/admin/funds/{$parent['id']}", ['parent_fund_id' => $other['id']]);
        $this->assertSame(422, $res->get_status());
        $this->assertSame('dono_invalid_input', $res->get_data()['code']);
    }

    public function test_stats_returns_org_wide_aggregates(): void
    {
        $this->post('/dono/v1/admin/funds', [
            'code' => 'general', 'name' => 'General', 'is_default' => true, 'goal_cents' => 0,
        ]);
        $this->post('/dono/v1/admin/funds', [
            'code' => 'building', 'name' => 'Building', 'is_restricted' => true,
        ]);
        $inactive = $this->post('/dono/v1/admin/funds', [
            'code' => 'old', 'name' => 'Old',
        ])->get_data();
        $this->put("/dono/v1/admin/funds/{$inactive['id']}", ['is_active' => false]);

        $stats = $this->get('/dono/v1/admin/funds/stats')->get_data();

        $this->assertSame(3, $stats['total']);
        $this->assertSame(2, $stats['active'], 'general + building active; old deactivated');
        $this->assertSame(1, $stats['restricted']);
        $this->assertSame(0, $stats['raised_cents']);
        $this->assertSame('General', $stats['default']['name']);
    }

    public function test_parent_fund_rolls_up_child_totals(): void
    {
        $parent = $this->post('/dono/v1/admin/funds', [
            'code' => 'parent', 'name' => 'Parent', 'goal_cents' => 100000,
        ])->get_data();
        $child = $this->post('/dono/v1/admin/funds', [
            'code' => 'child', 'name' => 'Child', 'parent_fund_id' => $parent['id'],
        ])->get_data();

        // A synced child that has collected donations; the parent's own row
        // stays 0 because donations name the exact fund.
        \Dono\Funds\Fund::query()->where('id', (int) $child['id'])
            ->update(['raised_cents' => 7000, 'donations_count' => 2]);

        $byId = [];
        foreach ($this->get('/dono/v1/admin/funds')->get_data() as $row) {
            $byId[(int) $row['id']] = $row;
        }

        $this->assertSame(7000, $byId[(int) $parent['id']]['raised_cents'],
            'Parent shows its children raised total, not its own empty row');
        $this->assertSame(2, $byId[(int) $parent['id']]['donations_count'],
            'Parent donation count rolls up its children');
        $this->assertSame(7000, $byId[(int) $child['id']]['raised_cents'],
            'Child keeps its own total');

        $this->assertSame(7000, $this->get('/dono/v1/admin/funds/stats')->get_data()['raised_cents'],
            'Org-wide raised counts the money once, not once per level');
    }

    private function get(string $path, array $params = []): \WP_REST_Response
    {
        $req = new WP_REST_Request('GET', $path);
        if (! empty($params)) $req->set_query_params($params);
        return rest_do_request($req);
    }

    private function post(string $path, array $body): \WP_REST_Response
    {
        $req = new WP_REST_Request('POST', $path);
        $req->set_header('content-type', 'application/json');
        $req->set_body(json_encode($body));
        return rest_do_request($req);
    }

    private function put(string $path, array $body): \WP_REST_Response
    {
        $req = new WP_REST_Request('PUT', $path);
        $req->set_header('content-type', 'application/json');
        $req->set_body(json_encode($body));
        return rest_do_request($req);
    }

    private function deleteReq(string $path, array $params = []): \WP_REST_Response
    {
        $req = new WP_REST_Request('DELETE', $path);
        if (! empty($params)) $req->set_query_params($params);
        return rest_do_request($req);
    }
}
