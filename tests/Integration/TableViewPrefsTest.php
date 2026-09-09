<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Rest\Admin\UserPrefsController;
use WP_REST_Request;

/**
 * A saved list view belongs to the person looking at the screen, so it has to
 * follow them between machines and never reach anybody else.
 */
final class TableViewPrefsTest extends IntegrationTestCase
{
    private function admin(): int
    {
        $id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($id);

        return $id;
    }

    /** @param array<string, mixed> $body */
    private function save(string $scope, array $body): array
    {
        $request = new WP_REST_Request('PUT', '/gratora/v1/admin/me/table-view');
        $request->set_param('scope', $scope);
        $request->set_body(wp_json_encode($body));
        $request->set_header('content-type', 'application/json');

        return (array) (new UserPrefsController())->updateView($request)->get_data();
    }

    private function read(string $scope): array
    {
        $request = new WP_REST_Request('GET', '/gratora/v1/admin/me/table-view');
        $request->set_param('scope', $scope);

        return (array) (new UserPrefsController())->showView($request)->get_data();
    }

    public function test_a_view_comes_back_the_way_it_was_left(): void
    {
        $this->admin();

        $this->save('donations', [
            'fields'  => ['reference', 'status', 'amount'],
            'order'   => ['reference', 'status', 'amount', 'gateway'],
            'sort'    => ['field' => 'created_at', 'direction' => 'asc'],
            'perPage' => 50,
            'filters' => [['field' => 'status', 'operator' => 'is', 'value' => 'failed']],
        ]);

        $view = $this->read('donations');

        $this->assertSame(['reference', 'status', 'amount'], $view['fields']);
        $this->assertSame(['field' => 'created_at', 'direction' => 'asc'], $view['sort']);
        $this->assertSame(50, $view['perPage']);
        $this->assertSame('failed', $view['filters'][0]['value']);
    }

    public function test_one_persons_view_is_not_another_persons(): void
    {
        $this->admin();
        $this->save('donations', ['fields' => ['reference'], 'perPage' => 100]);

        $this->admin();

        $this->assertSame([], $this->read('donations'), 'a second admin inherited someone else\'s view');
    }

    public function test_each_screen_keeps_its_own_view(): void
    {
        $this->admin();

        $this->save('donations', ['fields' => ['reference']]);
        $this->save('donors', ['fields' => ['name', 'email']]);

        $this->assertSame(['reference'], $this->read('donations')['fields']);
        $this->assertSame(['name', 'email'], $this->read('donors')['fields']);
    }

    public function test_the_arrangement_keeps_columns_that_are_not_showing(): void
    {
        $this->admin();

        $this->save('donations', [
            'fields' => ['amount', 'reference'],
            'order'  => ['amount', 'status', 'reference'],
        ]);

        $view = $this->read('donations');

        $this->assertSame(['amount', 'status', 'reference'], $view['order']);
        $this->assertSame(['amount', 'reference'], $view['fields']);
    }

    public function test_a_page_size_is_held_to_a_sane_range(): void
    {
        $this->admin();

        $this->assertSame(100, $this->save('big', ['perPage' => 100000])['perPage']);
        $this->assertSame(1, $this->save('small', ['perPage' => 0])['perPage']);
    }

    public function test_the_search_and_the_page_number_are_not_remembered(): void
    {
        $this->admin();

        $view = $this->save('donations', ['search' => 'ada@example.org', 'page' => 7, 'perPage' => 25]);

        $this->assertArrayNotHasKey('search', $view);
        $this->assertArrayNotHasKey('page', $view);
    }

    public function test_a_filter_that_is_not_shaped_like_one_is_dropped(): void
    {
        $this->admin();

        $view = $this->save('donations', [
            'filters' => [
                'not-an-array',
                ['operator' => 'is', 'value' => 'x'],
                ['field' => 'status', 'operator' => 'is', 'value' => 'paid'],
            ],
        ]);

        $this->assertCount(1, $view['filters']);
        $this->assertSame('status', $view['filters'][0]['field']);
    }

    public function test_a_list_filter_keeps_its_members(): void
    {
        $this->admin();

        $view = $this->save('donations', [
            'filters' => [['field' => 'status', 'operator' => 'isAny', 'value' => ['paid', 'pending']]],
        ]);

        $this->assertSame(['paid', 'pending'], $view['filters'][0]['value']);
    }

    public function test_an_oversized_view_is_refused_rather_than_stored(): void
    {
        $this->admin();

        $this->save('donations', ['fields' => array_fill(0, 4000, 'column_with_a_long_name')]);

        $this->assertSame([], $this->read('donations'));
    }

    public function test_a_filter_whose_field_is_not_a_string_is_dropped(): void
    {
        $this->admin();

        $view = $this->save('donations', [
            'filters' => [
                ['field' => ['nested'], 'operator' => 'is', 'value' => 'x'],
                ['field' => 'status', 'operator' => ['is'], 'value' => 'x'],
                ['field' => 'status', 'operator' => 'is', 'value' => 'paid'],
            ],
        ]);

        $this->assertCount(1, $view['filters']);
        $this->assertSame('status', $view['filters'][0]['field']);
    }

    public function test_a_sort_field_that_is_not_a_string_is_ignored(): void
    {
        $this->admin();

        $view = $this->save('donations', ['sort' => ['field' => ['x'], 'direction' => 'asc']]);

        $this->assertArrayNotHasKey('sort', $view);
    }

    public function test_scopes_cannot_be_invented_without_bound(): void
    {
        $this->admin();

        for ($i = 0; $i < 60; $i++) {
            $this->save('scope' . $i, ['perPage' => 25]);
        }

        $stored = json_decode((string) get_user_meta(get_current_user_id(), 'gratora_table_views', true), true);

        $this->assertLessThanOrEqual(40, count($stored));
        // The ones that did land are still readable, not corrupted by the cap.
        $this->assertSame(25, $this->read('scope0')['perPage']);
    }

    public function test_a_screen_with_no_saved_view_says_so(): void
    {
        $this->admin();

        $this->assertSame([], $this->read('never-opened'));
    }
}
