<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Foundation\Uninstall\DataEraser;
use WP_REST_Request;

/**
 * "Delete all Gratora data" takes what the plugin kept against each person as
 * well as against the site, and only that. The rows are written through the
 * screens that write them, so a key one of them starts using is a key this
 * notices nobody removes.
 */
final class UninstallUserDataTest extends IntegrationTestCase
{
    public function test_what_the_plugin_kept_for_each_person_goes(): void
    {
        $people = [
            self::factory()->user->create(['role' => 'administrator']),
            self::factory()->user->create(['role' => 'administrator']),
        ];
        foreach ($people as $person) {
            $this->useThePluginAs($person);
            $this->assertCount(5, $this->kept($person), 'fixture: ' . implode(', ', $this->kept($person)));
        }

        (new DataEraser())->removeUserData();

        foreach ($people as $person) {
            $this->assertSame([], $this->kept($person));
        }
    }

    public function test_what_an_add_on_or_wordpress_kept_stays(): void
    {
        $person = self::factory()->user->create(['role' => 'administrator']);
        $this->useThePluginAs($person);
        update_user_meta($person, 'gratora_p2p_onboarding_seen', '1');
        update_user_meta($person, 'nickname', 'Ada');

        (new DataEraser())->removeUserData();

        $this->assertSame(['gratora_p2p_onboarding_seen'], $this->kept($person));
        $this->assertSame('Ada', get_user_meta($person, 'nickname', true));
    }

    public function test_the_plan_names_them(): void
    {
        $person = self::factory()->user->create(['role' => 'administrator']);
        $this->useThePluginAs($person);

        $planned = (new DataEraser())->plan()['user_meta'];
        sort($planned);

        $this->assertSame($this->kept($person), $planned);
    }

    private function useThePluginAs(int $person): void
    {
        wp_set_current_user($person);

        $this->send('PUT', '/gratora/v1/admin/me/layout', ['order' => ['revenue', 'activity'], 'hidden' => ['channels']]);
        $this->send('PUT', '/gratora/v1/admin/me/table-view', ['fields' => ['reference', 'status'], 'perPage' => 50], ['scope' => 'donations']);
        $this->send('POST', '/gratora/v1/admin/me/attention/dismiss', ['key' => 'webhook_secret', 'signature' => 'a']);
        $this->send('POST', '/gratora/v1/admin/me/review-prompt', ['answer' => 'never']);
        $this->send('POST', '/gratora/v1/admin/me/first-run', []);
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $query
     */
    private function send(string $method, string $route, array $body, array $query = []): void
    {
        $request = new WP_REST_Request($method, $route);
        $request->set_query_params($query);
        $request->set_header('content-type', 'application/json');
        $request->set_body((string) wp_json_encode($body));
        $response = rest_do_request($request);

        $this->assertSame(200, $response->get_status(), $route . ' ' . wp_json_encode($response->get_data()));
    }

    /** @return list<string> every gratora row held against the person, sorted */
    private function kept(int $person): array
    {
        global $wpdb;

        return $wpdb->get_col($wpdb->prepare(
            "SELECT meta_key FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key LIKE %s ORDER BY meta_key",
            $person,
            $wpdb->esc_like('gratora_') . '%'
        ));
    }
}
