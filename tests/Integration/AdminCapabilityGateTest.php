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

    private function body(string $method, string $route): string
    {
        $res = rest_do_request(new WP_REST_Request($method, $route));

        return (string) $res->get_data();
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

        // A fresh install grants nobody the granular caps, so a strict
        // current_user_can() locked the site owner out of their own exports.
        $this->assertAllowed($this->status('GET', '/dono/v1/admin/exports/donors.csv'), 'admin exports donors');
        $this->assertAllowed($this->status('GET', '/dono/v1/admin/exports/revenue.csv'), 'admin exports revenue');
        $this->assertAllowed($this->status('GET', '/dono/v1/admin/exports/options'), 'admin reads export options');
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

    public function test_managing_roles_requires_full_admin_not_just_settings_cap(): void
    {
        $this->actAs(['dono_manage_settings']);

        // A settings-scoped role edits ordinary settings...
        $this->assertAllowed(
            $this->status('PUT', '/dono/v1/admin/settings/general', ['organization_name' => 'X']),
            'settings manager edits general settings'
        );
        // ...but must not rewrite the role->capability mapping (privilege escalation),
        $this->assertForbidden(
            $this->status('PUT', '/dono/v1/admin/settings/roles', ['mapping' => ['subscriber' => ['dono_refund_donations']]]),
            'settings manager cannot grant capabilities via the roles mapping'
        );
        // ...nor restore/export a settings bundle (applies the mapping + leaks secrets).
        $this->assertForbidden(
            $this->status('POST', '/dono/v1/admin/tools/import', ['settings' => []]),
            'settings manager cannot import a settings bundle'
        );
        $this->assertForbidden(
            $this->status('GET', '/dono/v1/admin/tools/export'),
            'settings manager cannot export secrets'
        );
    }

    public function test_bulk_exports_carry_the_capability_of_the_data_not_the_screen(): void
    {
        // Reading a donor on screen is one record at a time; the CSV is the
        // whole list with decrypted emails, phones and addresses in a file that
        // leaves the site.
        $this->actAs(['dono_view_donors']);
        $this->assertForbidden(
            $this->status('GET', '/dono/v1/admin/exports/donors.csv'),
            'viewing donors does not carry bulk export'
        );

        $this->actAs(['dono_export_donors']);
        $this->assertAllowed(
            $this->status('GET', '/dono/v1/admin/exports/donors.csv'),
            'the export capability does'
        );

        // Revenue figures are aggregates, so they sit behind reports and must
        // not be reachable with the donor export cap alone.
        $this->assertForbidden(
            $this->status('GET', '/dono/v1/admin/exports/revenue.csv'),
            'donor export does not carry revenue reporting'
        );

        // The donations CSV is a second route to the same donor list. Gating
        // the donors export while this one shipped names and emails under the
        // weaker cap made dono_export_donors decorative.
        $this->actAs(['dono_view_donations']);
        $csv = $this->body('GET', '/dono/v1/admin/donations/export.csv');
        $this->assertStringNotContainsString('@', $csv, 'a donations viewer gets no donor emails');
        $this->assertStringNotContainsString('Donor email', $csv, 'and no column promising them');

        $this->actAs(['dono_view_donations', 'dono_export_donors']);
        $this->assertStringContainsString(
            'Donor email',
            $this->body('GET', '/dono/v1/admin/donations/export.csv'),
            'holding the export capability restores the columns'
        );

        $this->actAs(['dono_view_reports']);
        $this->assertAllowed(
            $this->status('GET', '/dono/v1/admin/exports/revenue.csv'),
            'reports viewer reads revenue'
        );
        $this->assertForbidden(
            $this->status('GET', '/dono/v1/admin/exports/donors.csv'),
            'reports viewer is not handed the donor list'
        );
    }

    public function test_full_admin_can_manage_roles(): void
    {
        // The IntegrationTestCase default user is an administrator (manage_options).
        $this->assertAllowed(
            $this->status('PUT', '/dono/v1/admin/settings/roles', ['mapping' => []]),
            'an admin can manage the roles mapping'
        );
        $this->assertAllowed(
            $this->status('GET', '/dono/v1/admin/tools/export'),
            'an admin can export'
        );
    }
}
