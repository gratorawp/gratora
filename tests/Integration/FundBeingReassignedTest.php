<?php

declare(strict_types=1);

namespace FundKit\Tests\Integration;

use FundKit\Donations\Donation;
use FundKit\Foundation\Plugin;
use FundKit\Funds\Fund;
use FundKit\Funds\FundReassignmentJob;
use FundKit\Funds\FundResolver;
use FundKit\Vendor\Queryable\DB;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Deleting a fund with a reassignment target queues a job that hard-deletes the
 * row once the donations have moved. The delete refuses three things at that
 * moment: the default fund, a fund with sub-funds under it, and an inactive
 * target. Every one of the three is a column another request can change while
 * the job waits its turn, and the row is gone by the time anyone notices.
 */
final class FundBeingReassignedTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    public function test_a_fund_being_reassigned_cannot_be_promoted_to_default(): void
    {
        $general = $this->fund(['code' => 'general', 'name' => 'General', 'is_default' => true]);
        $source  = $this->queued($target = $this->fund(['code' => 'roof', 'name' => 'Roof']));

        $res = $this->updateFund((int) $source->id, ['is_default' => true]);

        $this->assertSame(422, $res->get_status());
        $this->assertStringContainsString('reassigned', $res->as_error()->get_error_message());

        $row = Fund::query()->find('id', (int) $source->id);
        $this->assertFalse((bool) $row->is_default, 'nothing was written on the refused path');
        $this->assertFalse((bool) $row->is_active);

        $this->runPendingAsyncJobs();

        $this->assertSame(1, (int) Fund::query()->where('is_default', 1)->count());
        $this->assertSame((int) $general->id, $this->resolved());
        $this->assertNull(Fund::query()->find('id', (int) $source->id), 'the reassignment still finished');
        $this->assertNotNull(Fund::query()->find('id', (int) $target->id));
    }

    public function test_a_fund_being_reassigned_cannot_be_reactivated(): void
    {
        $source = $this->queued($this->fund(['code' => 'roof', 'name' => 'Roof']));

        $res = $this->updateFund((int) $source->id, ['is_active' => true]);

        $this->assertSame(422, $res->get_status());
        $this->assertFalse((bool) Fund::query()->find('id', (int) $source->id)->is_active);
    }

    public function test_a_fund_being_reassigned_cannot_be_given_sub_funds(): void
    {
        $source = $this->queued($this->fund(['code' => 'roof', 'name' => 'Roof']));
        $child  = $this->fund(['code' => 'water', 'name' => 'Water']);

        $res = $this->updateFund((int) $child->id, ['parent_fund_id' => (int) $source->id]);

        $this->assertSame(422, $res->get_status());
        $this->assertNull(Fund::query()->find('id', (int) $child->id)->parent_fund_id);

        $this->runPendingAsyncJobs();

        $this->assertNull(Fund::query()->find('id', (int) $source->id));
        $this->assertNull(
            Fund::query()->find('id', (int) $child->id)->parent_fund_id,
            'no sub-fund is left pointing at a row that was removed'
        );
    }

    /**
     * The refusals close the API. The job has to hold the same line on its own,
     * because a write that beat the guard, or an older release, leaves the row
     * in exactly this state.
     */
    public function test_the_job_will_not_delete_a_fund_that_became_the_default(): void
    {
        $general = $this->fund(['code' => 'general', 'name' => 'General', 'is_default' => true]);
        $source  = $this->queued($this->fund(['code' => 'roof', 'name' => 'Roof']));

        DB::table('fundkit_funds')->where('id', (int) $source->id)->update(['is_default' => 1, 'is_active' => 1]);
        DB::table('fundkit_funds')->where('id', (int) $general->id)->update(['is_default' => 0]);

        $this->runPendingAsyncJobs();

        $this->assertNotNull(Fund::query()->find('id', (int) $source->id), 'the site would have no default at all');
        $this->assertSame((int) $source->id, $this->resolved());
        $this->assertArrayNotHasKey(
            (int) $source->id,
            FundReassignmentJob::pending(),
            'and it does not sit on Reassigning forever'
        );
    }

    public function test_the_job_will_not_orphan_a_sub_fund_added_after_it_was_queued(): void
    {
        $this->fund(['code' => 'general', 'name' => 'General', 'is_default' => true]);
        $source = $this->queued($this->fund(['code' => 'roof', 'name' => 'Roof']));
        $child  = $this->fund(['code' => 'water', 'name' => 'Water']);

        DB::table('fundkit_funds')->where('id', (int) $child->id)->update(['parent_fund_id' => (int) $source->id]);

        $this->runPendingAsyncJobs();

        $this->assertNotNull(Fund::query()->find('id', (int) $source->id));
        $this->assertNotNull(
            Fund::query()->find('id', (int) Fund::query()->find('id', (int) $child->id)->parent_fund_id),
            'the sub-fund points at a fund that still exists'
        );
        $this->assertArrayNotHasKey((int) $source->id, FundReassignmentJob::pending());
    }

    public function test_the_job_will_not_file_donations_onto_a_fund_that_was_switched_off(): void
    {
        $this->fund(['code' => 'general', 'name' => 'General', 'is_default' => true]);
        $target = $this->fund(['code' => 'roof', 'name' => 'Roof']);
        $source = $this->queued($target);

        $this->donation((int) $source->id);
        DB::table('fundkit_funds')->where('id', (int) $target->id)->update(['is_active' => 0]);

        $this->runPendingAsyncJobs();

        $this->assertSame(
            0,
            (int) Donation::query()->where('fund_id', (int) $target->id)->count(),
            'donations were filed against a fund the org has switched off'
        );
        $this->assertNotNull(Fund::query()->find('id', (int) $source->id));
        $this->assertArrayNotHasKey((int) $source->id, FundReassignmentJob::pending());
    }


    /** Queues a childless, non-default fund for reassignment onto $target. */
    private function queued(Fund $target): Fund
    {
        $source = $this->fund(['code' => 'building', 'name' => 'Building']);

        $req = new WP_REST_Request('DELETE', '/fundkit/v1/admin/funds/' . (int) $source->id);
        $req->set_param('reassign_to', (int) $target->id);
        $res = rest_do_request($req);

        $this->assertSame(202, $res->get_status(), (string) wp_json_encode($res->get_data()));
        $this->assertSame('reassign_queued', $res->get_data()['action']);

        return Fund::query()->find('id', (int) $source->id);
    }

    private function donation(int $fundId): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $d   = Donation::make();

        $d->reference    = 'FUNDKIT-REASSIGN-' . $fundId;
        $d->donor_id     = 1;
        $d->amount_cents = 5000;
        $d->net_cents    = 5000;
        $d->currency     = 'USD';
        $d->base_amount_cents = 5000;
        $d->base_currency     = 'USD';
        $d->fx_rate      = '1.00000000';
        $d->gateway      = 'offline';
        $d->status       = 'paid';
        $d->fund_id      = $fundId;
        $d->paid_at      = $now;
        $d->created_at   = $now;
        $d->updated_at   = $now;
        $d->save();
    }

    private function resolved(): ?int
    {
        return Plugin::instance()->container->get(FundResolver::class)->resolve(null, null, null);
    }

    /** @param array<string,mixed> $body */
    private function fund(array $body): Fund
    {
        $req = new WP_REST_Request('POST', '/fundkit/v1/admin/funds');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode($body));

        $res = rest_do_request($req);
        $this->assertSame(201, $res->get_status(), (string) wp_json_encode($res->get_data()));

        return Fund::query()->find('id', (int) $res->get_data()['id']);
    }

    /** @param array<string,mixed> $body */
    private function updateFund(int $id, array $body): WP_REST_Response
    {
        $req = new WP_REST_Request('POST', '/fundkit/v1/admin/funds/' . $id);
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode($body));

        return rest_do_request($req);
    }
}
