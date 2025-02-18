<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use WP_REST_Request;

/**
 * B7: GET /admin/commands (manifest) and POST /admin/commands/{id} (generic
 * invocation). The route gate is manage_options; the fine-grained per-command
 * capability is enforced inside dispatch(), so a manage_options user without
 * dono_refund_donations is still denied donation.refund.
 */
final class CommandsRestTest extends IntegrationTestCase
{
    public function test_get_manifest_as_admin_returns_id_list(): void
    {
        $res = rest_do_request(new WP_REST_Request('GET', '/dono/v1/admin/commands'));
        $this->assertSame(200, $res->get_status());

        $ids = array_column($res->get_data(), 'id');
        foreach (['donation.refund', 'donation.get', 'campaign.metrics', 'recurring.cancel'] as $id) {
            $this->assertContains($id, $ids);
        }
    }

    public function test_get_manifest_denied_for_low_cap_user(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));

        $res = rest_do_request(new WP_REST_Request('GET', '/dono/v1/admin/commands'));
        $this->assertSame(403, $res->get_status());
    }

    public function test_post_read_command_as_admin_returns_200(): void
    {
        // The programmatic path enforces the fine-grained cap (section 9);
        // administrator carries manage_dono only until the roles UI grants it.
        $reference = $this->driveDonationToPaid();
        $this->actAsAdminWithCap('dono_view_donations');

        $res = $this->post('/dono/v1/admin/commands/donation.get', [
            'input' => ['donation_reference' => $reference],
        ]);

        $this->assertSame(200, $res->get_status());
        $this->assertSame($reference, $res->get_data()['reference']);
        $this->assertSame('paid', $res->get_data()['status']);
    }

    public function test_refund_denied_for_manage_options_user_without_fine_grained_cap(): void
    {
        // Administrator has manage_options (route gate passes) but not the
        // dono_refund_donations cap (granted only via the roles mapping UI).
        $reference = $this->driveDonationToPaid();
        $this->actAsAdminWithoutCap('dono_refund_donations');

        $res = $this->post('/dono/v1/admin/commands/donation.refund', [
            'input' => ['donation_reference' => $reference, 'amount_cents' => 5000],
        ]);

        $this->assertSame(403, $res->get_status());
        $this->assertSame('command.denied', $res->get_data()['code']);

        $denied = self::$wpdb->get_results(
            "SELECT type FROM " . self::$prefix . "dono_events WHERE type = 'command.denied'"
        );
        $this->assertNotEmpty($denied, 'A command.denied event row must be written');
    }

    public function test_dry_run_on_mutating_command_returns_confirm_digest(): void
    {
        $reference = $this->driveDonationToPaid();
        $this->actAsAdminWithCap('dono_refund_donations');

        $res = $this->post('/dono/v1/admin/commands/donation.refund', [
            'dry_run' => true,
            'input'   => ['donation_reference' => $reference, 'amount_cents' => 5000],
        ]);

        $this->assertSame(200, $res->get_status());
        $this->assertArrayHasKey('confirm_digest', $res->get_data());
        $this->assertNotEmpty($res->get_data()['confirm_digest']);
        $this->assertArrayHasKey('canonical_input', $res->get_data());

        // Dry-run writes nothing: no Refund row, no command.invoked event.
        $refunds = self::$wpdb->get_var("SELECT COUNT(*) FROM " . self::$prefix . "dono_refunds");
        $this->assertSame('0', (string) $refunds);
    }

    public function test_unknown_command_returns_404(): void
    {
        $res = $this->post('/dono/v1/admin/commands/nope.missing', ['input' => []]);
        $this->assertSame(404, $res->get_status());
        $this->assertSame('command.not_found', $res->get_data()['code']);
    }

    private function driveDonationToPaid(): string
    {
        $createReq = new WP_REST_Request('POST', '/dono/v1/donations');
        $createReq->set_header('content-type', 'application/json');
        $createReq->set_body(json_encode([
            'email'        => 'rest-cmd@example.com',
            'amount_cents' => 5000,
            'currency'     => 'USD',
            'gateway'      => 'offline',
            'profile'      => ['first_name' => 'Rest', 'country' => 'US'],
        ]));
        $reference = rest_do_request($createReq)->get_data()['reference'];

        $confirmReq = new WP_REST_Request('POST', "/dono/v1/donations/{$reference}/confirm");
        $confirmReq->set_header('content-type', 'application/json');
        $confirmReq->set_body('{}');
        rest_do_request($confirmReq);

        $this->runPendingAsyncJobs();

        return $reference;
    }

    private function post(string $path, array $body): \WP_REST_Response
    {
        $req = new WP_REST_Request('POST', $path);
        $req->set_header('content-type', 'application/json');
        $req->set_body(json_encode($body));
        return rest_do_request($req);
    }

    /**
     * Switch to a fresh administrator that holds $cap. A fresh user is
     * required: re-setting an already-loaded user does not recompute its
     * capability cache after a role mutation.
     */
    private function actAsAdminWithCap(string $cap): void
    {
        get_role('administrator')->add_cap($cap);
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    /**
     * Switch to a fresh administrator that holds manage_options but not $cap,
     * order-independent of any test that granted $cap to the role.
     */
    private function actAsAdminWithoutCap(string $cap): void
    {
        get_role('administrator')->remove_cap($cap);
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }
}
