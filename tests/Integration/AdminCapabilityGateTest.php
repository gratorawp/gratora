<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use WP_REST_Request;

/**
 * Granular-capability enforcement on the admin REST controllers. A non-admin
 * role granted one area's cap reaches that area only; manage_options bypasses
 * every gate; sensitive per-route ops require their own specific cap.
 */
final class AdminCapabilityGateTest extends IntegrationTestCase
{
    /** Become a fresh subscriber (no manage_options) holding exactly $caps. */
    private function actAs(array $caps): void
    {
        $uid  = self::factory()->user->create(['role' => 'subscriber']);
        $user = get_user_by('id', $uid);
        foreach ($caps as $cap) {
            $user->add_cap($cap);
        }
        wp_set_current_user($uid);
    }

    private function status(string $method, string $route, array $body = []): int
    {
        $req = new WP_REST_Request($method, $route);
        if ($body) {
            $req->set_header('content-type', 'application/json');
            $req->set_body((string) wp_json_encode($body));
        }
        return rest_do_request($req)->get_status();
    }

    private function assertForbidden(int $status, string $msg): void
    {
        $this->assertContains($status, [401, 403], $msg . " (got {$status})");
    }

    private function assertAllowed(int $status, string $msg): void
    {
        $this->assertNotContains($status, [401, 403], $msg . " (got {$status})");
    }

    public function test_view_donations_reaches_donations_not_donors(): void
    {
        $this->actAs(['dono_view_donations']);
        $this->assertAllowed($this->status('GET', '/dono/v1/admin/donations'), 'donations viewer sees donations');
        $this->assertForbidden($this->status('GET', '/dono/v1/admin/donors'), 'donations viewer blocked from donors');
    }

    public function test_view_donors_reaches_donors_not_donations(): void
    {
        $this->actAs(['dono_view_donors']);
        $this->assertAllowed($this->status('GET', '/dono/v1/admin/donors'), 'donor viewer sees donors');
        $this->assertForbidden($this->status('GET', '/dono/v1/admin/donations'), 'donor viewer blocked from donations');
    }

    public function test_manage_options_bypasses_all_gates(): void
    {
        // The IntegrationTestCase default user is administrator (manage_options).
        $this->assertAllowed($this->status('GET', '/dono/v1/admin/donors'), 'admin sees donors');
        $this->assertAllowed($this->status('GET', '/dono/v1/admin/donations'), 'admin sees donations');
        $this->assertAllowed($this->status('GET', '/dono/v1/admin/campaigns'), 'admin sees campaigns');
        $this->assertAllowed($this->status('GET', '/dono/v1/admin/dashboard'), 'admin sees dashboard');
        $this->assertAllowed($this->status('GET', '/dono/v1/admin/settings/general'), 'admin sees settings');
    }

    public function test_settings_requires_settings_cap(): void
    {
        $this->actAs(['dono_view_donations']);
        $this->assertForbidden($this->status('GET', '/dono/v1/admin/settings/general'), 'donations viewer blocked from settings');

        $this->actAs(['dono_manage_settings']);
        $this->assertAllowed($this->status('GET', '/dono/v1/admin/settings/general'), 'settings manager reaches settings');
    }

    public function test_redact_requires_redact_cap_not_just_view(): void
    {
        // view_donors alone cannot redact; the per-route gate needs dono_redact_donors.
        $this->actAs(['dono_view_donors']);
        $this->assertForbidden(
            $this->status('POST', '/dono/v1/admin/donors/999999/redact', ['confirmation' => 'x']),
            'donor viewer cannot redact'
        );

        // With the cap the gate lets it through (404/422 for the missing donor, but not forbidden).
        $this->actAs(['dono_view_donors', 'dono_redact_donors']);
        $this->assertAllowed(
            $this->status('POST', '/dono/v1/admin/donors/999999/redact', ['confirmation' => 'x']),
            'redact cap passes the gate'
        );
    }
}
