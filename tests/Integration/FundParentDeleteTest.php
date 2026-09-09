<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Funds\Fund;
use WP_REST_Request;

/**
 * A fund with sub-funds under it is a shape the funds screen produces on its
 * own, so what the delete dialog offers on one has to be what the server will
 * do: deactivating a parent is supported and leaves the sub-funds standing,
 * while reassigning removes the row and would orphan them.
 */
final class FundParentDeleteTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    /** @param array<string, mixed> $extra */
    private function createFund(string $code, string $name, array $extra = []): int
    {
        $req = new WP_REST_Request('POST', '/gratora/v1/admin/funds');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode(['code' => $code, 'name' => $name] + $extra));

        $res = rest_do_request($req);
        $this->assertSame(201, $res->get_status(), (string) wp_json_encode($res->get_data()));

        return (int) $res->get_data()['id'];
    }

    private function deleteFund(int $id, ?int $reassignTo = null): \WP_REST_Response
    {
        $req = new WP_REST_Request('DELETE', '/gratora/v1/admin/funds/' . $id);
        if ($reassignTo !== null) {
            $req->set_param('reassign_to', $reassignTo);
        }

        return rest_do_request($req);
    }

    public function test_deleting_a_parent_fund_deactivates_it_and_leaves_the_sub_funds(): void
    {
        $parent = $this->createFund('programs', 'Programs');
        $child  = $this->createFund('water', 'Water', ['parent_fund_id' => $parent]);

        $res = $this->deleteFund($parent);

        $this->assertSame(200, $res->get_status(), (string) wp_json_encode($res->get_data()));
        $this->assertSame('deactivated', $res->get_data()['action']);

        $parentRow = Fund::query()->find('id', $parent);
        $this->assertNotNull($parentRow, 'the parent row must survive a deactivation');
        $this->assertFalse((bool) $parentRow->is_active);

        $childRow = Fund::query()->find('id', $child);
        $this->assertNotNull($childRow, 'the sub-fund must not be removed with its parent');
        $this->assertSame($parent, (int) $childRow->parent_fund_id);
        $this->assertTrue((bool) $childRow->is_active);
    }

    public function test_reassigning_a_parent_fund_away_is_refused(): void
    {
        $parent = $this->createFund('programs', 'Programs');
        $this->createFund('water', 'Water', ['parent_fund_id' => $parent]);
        $target = $this->createFund('general', 'General');

        $res = $this->deleteFund($parent, $target);

        $this->assertSame(422, $res->get_status());
        $this->assertNotNull(Fund::query()->find('id', $parent));
    }

    public function test_the_list_says_which_funds_have_sub_funds(): void
    {
        $parent = $this->createFund('programs', 'Programs');
        $this->createFund('water', 'Water', ['parent_fund_id' => $parent]);
        $lone = $this->createFund('general', 'General');

        $rows = rest_do_request(new WP_REST_Request('GET', '/gratora/v1/admin/funds'))->get_data();
        $byId = [];
        foreach ($rows as $row) {
            $byId[(int) $row['id']] = $row;
        }

        $this->assertTrue($byId[$parent]['has_children']);
        $this->assertFalse($byId[$lone]['has_children']);
    }

    public function test_a_fund_window_that_ends_before_it_starts_is_refused_on_create(): void
    {
        $req = new WP_REST_Request('POST', '/gratora/v1/admin/funds');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode([
            'code'      => 'transposed',
            'name'      => 'Transposed',
            'starts_at' => '2026-12-01',
            'ends_at'   => '2026-01-01',
        ]));

        $res = rest_do_request($req);

        $this->assertSame(422, $res->get_status());
        $this->assertNull(Fund::query()->find('code', 'transposed'));
    }

    public function test_the_funds_list_can_be_ordered_by_type(): void
    {
        $this->createFund('unrestricted-one', 'Unrestricted one', ['is_restricted' => false]);
        $this->createFund('restricted-one', 'Restricted one', ['is_restricted' => true]);

        $req = new WP_REST_Request('GET', '/gratora/v1/admin/funds');
        $req->set_param('orderby', 'is_restricted');
        $req->set_param('order', 'desc');

        $rows  = rest_do_request($req)->get_data();
        $types = array_map(static fn (array $r): bool => (bool) $r['is_restricted'], $rows);

        $sorted = $types;
        rsort($sorted);
        $this->assertSame($sorted, $types, 'restricted funds group ahead of unrestricted ones');
        $this->assertTrue($types[0]);
        $this->assertFalse($types[count($types) - 1]);
    }
}
