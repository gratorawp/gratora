<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Rest\Admin\UserPrefsController;
use WP_REST_Request;

/**
 * The saved-view endpoint bounds itself because "a client bug cannot grow one
 * user's meta without bound". The layout endpoint beside it took order and
 * hidden straight off the body with no size and no scope cap, and WordPress
 * fetches and decodes a user's whole meta set on essentially every
 * authenticated request.
 */
final class WidgetLayoutPrefsTest extends IntegrationTestCase
{
    private const META = 'gratora_widget_layout';

    private function admin(): int
    {
        $id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($id);

        return $id;
    }

    /** @param array<string, mixed> $body */
    private function save(string $scope, array $body): array
    {
        $request = new WP_REST_Request('PUT', '/gratora/v1/admin/me/layout');
        $request->set_param('scope', $scope);
        $request->set_body(wp_json_encode($body));
        $request->set_header('content-type', 'application/json');

        return (array) (new UserPrefsController())->update($request)->get_data();
    }

    private function read(string $scope): array
    {
        $request = new WP_REST_Request('GET', '/gratora/v1/admin/me/layout');
        $request->set_param('scope', $scope);

        return (array) (new UserPrefsController())->show($request)->get_data();
    }

    private function stored(): array
    {
        return (array) json_decode((string) get_user_meta(get_current_user_id(), self::META, true), true);
    }

    public function test_scopes_cannot_be_invented_without_bound(): void
    {
        $this->admin();

        for ($i = 0; $i < 60; $i++) {
            $this->save('scope' . $i, ['order' => ['a', 'b']]);
        }

        $this->assertLessThanOrEqual(40, count($this->stored()));
        $this->assertSame(['a', 'b'], $this->read('scope0')['order'], 'the ones that landed are still readable');
    }

    public function test_an_oversized_layout_is_refused_rather_than_stored(): void
    {
        $this->admin();

        $this->save('dashboard', ['order' => array_fill(0, 4000, 'widget_with_a_long_name')]);

        $this->assertSame([], $this->read('dashboard')['order']);
    }

    public function test_a_scope_name_cannot_be_arbitrarily_long(): void
    {
        $this->admin();

        $this->save(str_repeat('x', 5000), ['order' => ['a']]);

        $this->assertLessThanOrEqual(64, max(array_map('strlen', array_keys($this->stored()))));
    }
}
