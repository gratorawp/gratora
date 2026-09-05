<?php

declare(strict_types=1);

namespace FundKit\Tests\Integration;

use FundKit\Donations\Donation;
use FundKit\Funds\Fund;
use WP_REST_Request;

/**
 * A bookkeeper recording a cheque picks the fund it was earmarked for. The
 * picker offered every active fund, but FundResolver refuses two kinds it
 * offered, a fund outside its schedule window and a parent that heads a group,
 * and falls through to the org default. Restricted money went into the general
 * pot and the drawer reported success.
 */
final class RecordDonationFundTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    private function fund(array $body): int
    {
        $req = new WP_REST_Request('POST', '/fundkit/v1/admin/funds');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode($body));
        $res = rest_do_request($req);

        $this->assertSame(201, $res->get_status(), 'the fund fixture has to exist');

        return (int) $res->get_data()['id'];
    }

    /** @return list<array<string,mixed>> */
    private function offered(): array
    {
        $res = rest_do_request(new WP_REST_Request('GET', '/fundkit/v1/admin/donations/fund-options'));
        $this->assertSame(200, $res->get_status());

        return (array) $res->get_data();
    }

    private function record(?int $fundId): \WP_REST_Response
    {
        $req = new WP_REST_Request('POST', '/fundkit/v1/admin/donations');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode(array_filter([
            'email'          => 'cheque-' . uniqid() . '@example.test',
            'first_name'     => 'Pat',
            'amount_cents'   => 500000,
            'currency'       => 'USD',
            'payment_method' => 'cheque',
            'received_at'    => gmdate('Y-m-d'),
            'fund_id'        => $fundId,
        ], static fn ($v) => $v !== null)));

        return rest_do_request($req);
    }

    private function yesterday(): string
    {
        return gmdate('Y-m-d', time() - 2 * DAY_IN_SECONDS);
    }

    public function test_a_fund_that_has_stopped_taking_money_is_not_offered(): void
    {
        $closed = $this->fund(['code' => 'roof', 'name' => 'Roof Appeal', 'ends_at' => $this->yesterday()]);
        $open   = $this->fund(['code' => 'general-giving', 'name' => 'General giving']);

        $ids = array_map(static fn (array $o): int => (int) $o['id'], $this->offered());

        $this->assertContains($open, $ids, 'the picker still offers what is running');
        $this->assertNotContains($closed, $ids, 'and stops offering what has ended');
    }

    public function test_a_parent_that_only_heads_a_group_is_not_offered(): void
    {
        $parent = $this->fund(['code' => 'memorial', 'name' => 'Memorial Funds']);
        $child  = $this->fund(['code' => 'memorial-a', 'name' => 'In memory of A', 'parent_fund_id' => $parent]);

        $ids = array_map(static fn (array $o): int => (int) $o['id'], $this->offered());

        $this->assertContains($child, $ids, 'a donor can choose the specific fund');
        $this->assertNotContains($parent, $ids, 'but not the header above it');
    }

    public function test_a_fund_the_resolver_would_refuse_is_refused_here_instead_of_redirected(): void
    {
        $closed = $this->fund(['code' => 'roof', 'name' => 'Roof Appeal', 'ends_at' => $this->yesterday()]);
        $this->fund(['code' => 'general-giving', 'name' => 'General giving', 'is_default' => true]);

        $res = $this->record($closed);

        $this->assertSame(422, $res->get_status(), 'silently filing it elsewhere is the defect');
        $this->assertSame('fundkit_invalid_fund', (string) ($res->get_data()['code'] ?? ''));
        $this->assertSame(0, (int) Donation::query()->where('amount_cents', 500000)->count(), 'and no money was recorded');
    }

    public function test_a_fund_that_is_offered_is_the_fund_the_money_lands_in(): void
    {
        $open = $this->fund(['code' => 'water', 'name' => 'Water and Sanitation']);
        $this->fund(['code' => 'general-giving', 'name' => 'General giving', 'is_default' => true]);

        $res = $this->record($open);
        $this->assertSame(201, $res->get_status(), (string) wp_json_encode($res->get_data()));

        $donation = Donation::query()->find('reference', (string) $res->get_data()['reference']);
        $this->assertNotNull($donation);
        $this->assertSame($open, (int) $donation->fund_id, 'the money went where the bookkeeper put it');
    }

    public function test_recording_without_a_fund_still_falls_back_to_the_default(): void
    {
        $default = $this->fund(['code' => 'general-giving', 'name' => 'General giving', 'is_default' => true]);

        $res = $this->record(null);
        $this->assertSame(201, $res->get_status(), (string) wp_json_encode($res->get_data()));

        $donation = Donation::query()->find('reference', (string) $res->get_data()['reference']);
        $this->assertSame($default, (int) $donation->fund_id, 'choosing nothing is not the same as choosing wrongly');
    }
}
