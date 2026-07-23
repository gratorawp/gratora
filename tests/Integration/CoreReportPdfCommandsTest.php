<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Analytics\EventRecorder;
use Dono\Campaigns\CampaignService;
use Dono\Core\Commands\CoreCommandProvider;
use Dono\Donations\Donation;
use Dono\Donations\Refund;
use Dono\Donors\DonorService;
use Dono\Foundation\Commands\CommandContext;
use Dono\Foundation\Commands\CommandRegistry;
use Dono\Foundation\Plugin;
use Dono\Reports\CampaignReportBuilder;
use Dono\Reports\TaxStatementBuilder;
use WP_REST_Request;

/**
 * Report-document commands + their secure streaming REST routes: a campaign
 * one-pager (aggregate only, dono_view_reports) and a donor year-end tax
 * statement (PII, dono_view_donors). The commands only mint a nonce-signed
 * download link; the routes regenerate and stream the PDF on demand.
 */
final class CoreReportPdfCommandsTest extends IntegrationTestCase
{
    private const STATEMENT_YEAR = 2025;
    private const OTHER_YEAR     = 2024;

    protected function setUp(): void
    {
        parent::setUp();
        update_option('dono_org_profile', [
            'name'          => 'Hope Foundation',
            'tax_id'        => '12-3456789',
            'address_lines' => ['500 Charity Way', 'Springfield, IL 62704', 'United States'],
        ]);
    }

    public function test_manifest_lists_report_document_commands_as_non_mutating(): void
    {
        $byId = [];
        foreach ($this->registry()->manifest() as $entry) {
            $byId[$entry['id']] = $entry;
        }
        foreach (['report.campaign_pdf', 'donor.tax_statement_pdf'] as $id) {
            $this->assertArrayHasKey($id, $byId, "manifest missing {$id}");
            $this->assertFalse($byId[$id]['mutating'], "{$id} must be non-mutating");
            $this->assertTrue($byId[$id]['idempotent'], "{$id} must be idempotent");
        }
        $this->assertSame('dono_view_reports', $byId['report.campaign_pdf']['capability']);
        $this->assertSame('dono_view_donors', $byId['donor.tax_statement_pdf']['capability']);
    }

    public function test_campaign_pdf_command_returns_nonce_signed_link(): void
    {
        $ctx        = $this->adminCtx();
        $campaignId = $this->seedCampaignWithDonation();

        $res = $this->registry()->dispatch('report.campaign_pdf', [
            'campaign_id' => $campaignId,
            'range'       => 'last-30',
        ], $ctx);

        $this->assertTrue($res->ok, $res->error ?? '');
        foreach (['campaign_id', 'download_url', 'filename', 'expires_hint'] as $key) {
            $this->assertArrayHasKey($key, $res->data);
        }
        $this->assertSame($campaignId, $res->data['campaign_id']);
        // rest_url may percent-encode the route (plain-permalink ?rest_route= form),
        // so decode before matching the path; the nonce and range stay literal.
        $decoded = urldecode($res->data['download_url']);
        $this->assertStringContainsString('_wpnonce=', $res->data['download_url']);
        $this->assertStringContainsString('reports/campaign/' . $campaignId . '/pdf', $decoded);
        $this->assertStringContainsString('range=last-30', $decoded);
        $this->assertSame(CampaignReportBuilder::filename($campaignId, 'last-30'), $res->data['filename']);
    }

    public function test_campaign_pdf_command_rejects_missing_campaign(): void
    {
        $ctx = $this->adminCtx();

        $res = $this->registry()->dispatch('report.campaign_pdf', ['campaign_id' => 999999], $ctx);

        $this->assertFalse($res->ok);
        $this->assertSame('command.failed', $res->error_code);
    }

    public function test_tax_statement_command_reports_year_scoped_net_figures(): void
    {
        $ctx     = $this->adminCtx();
        $donorId = $this->seedDonorWithDonations();

        $res = $this->registry()->dispatch('donor.tax_statement_pdf', [
            'donor_id' => $donorId,
            'year'     => self::STATEMENT_YEAR,
        ], $ctx);

        $this->assertTrue($res->ok, $res->error ?? '');
        foreach (['donor_id', 'year', 'download_url', 'filename', 'donation_count', 'total_cents'] as $key) {
            $this->assertArrayHasKey($key, $res->data);
        }
        $this->assertStringContainsString('_wpnonce=', $res->data['download_url']);
        $this->assertStringContainsString(
            'reports/donor/' . $donorId . '/tax-statement/' . self::STATEMENT_YEAR,
            urldecode($res->data['download_url'])
        );
        $this->assertSame(TaxStatementBuilder::filename($donorId, self::STATEMENT_YEAR), $res->data['filename']);

        // Only the two target-year donations count, and the partial refund on one
        // of them is netted out: 10000 + (20000 - 5000) = 25000. The 2024
        // donation and the refunded amount are excluded.
        $this->assertSame(2, $res->data['donation_count']);
        $this->assertSame(25000, $res->data['total_cents']);
    }

    public function test_tax_statement_command_rejects_out_of_range_year(): void
    {
        $ctx     = $this->adminCtx();
        $donorId = $this->seedDonorWithDonations();

        $res = $this->registry()->dispatch('donor.tax_statement_pdf', [
            'donor_id' => $donorId,
            'year'     => 1999,
        ], $ctx);

        // 1999 is below the schema minimum, so validation rejects it before dispatch.
        $this->assertFalse($res->ok);
        $this->assertSame('command.invalid_input', $res->error_code);
    }

    public function test_campaign_pdf_route_streams_pdf_for_authed_admin(): void
    {
        $this->actAsAdminWithCaps();
        $campaignId = $this->seedCampaignWithDonation();

        $request  = new WP_REST_Request('GET', "/dono/v1/reports/campaign/{$campaignId}/pdf");
        $response = rest_do_request($request);
        $this->assertSame(200, $response->get_status());

        $this->assertStringStartsWith('%PDF', $this->capturePdf($request, $response));
    }

    public function test_tax_statement_route_streams_pdf_for_authed_admin(): void
    {
        $this->actAsAdminWithCaps();
        $donorId = $this->seedDonorWithDonations();

        $year     = self::STATEMENT_YEAR;
        $request  = new WP_REST_Request('GET', "/dono/v1/reports/donor/{$donorId}/tax-statement/{$year}");
        $response = rest_do_request($request);
        $this->assertSame(200, $response->get_status());

        $this->assertStringStartsWith('%PDF', $this->capturePdf($request, $response));
    }

    public function test_campaign_pdf_route_denied_without_reports_cap(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));
        $campaignId = $this->seedCampaignWithDonation();

        $status = rest_do_request(new WP_REST_Request('GET', "/dono/v1/reports/campaign/{$campaignId}/pdf"))->get_status();
        $this->assertContains($status, [401, 403]);
    }

    public function test_tax_statement_route_denied_without_donors_cap(): void
    {
        // A subscriber holds neither manage_options nor dono_view_donors. Admins
        // hold dono_view_donors implicitly (Capabilities::grantMetaCaps grants the
        // everyday area caps to manage_options users), so the denial case is a
        // genuine non-cap user. The donor-PII route must reject them.
        $donorId = $this->seedDonorWithDonations();
        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));

        $year   = self::STATEMENT_YEAR;
        $status = rest_do_request(new WP_REST_Request('GET', "/dono/v1/reports/donor/{$donorId}/tax-statement/{$year}"))->get_status();
        $this->assertContains($status, [401, 403]);
    }

    // --- helpers ---------------------------------------------------------

    private function registry(): CommandRegistry
    {
        $c = Plugin::instance()->container;
        $r = new CommandRegistry($c->get(EventRecorder::class));
        (new CoreCommandProvider())->register($r, $c);
        return $r;
    }

    /** A fresh administrator holding both report capabilities, set as current user. */
    private function adminCtx(): CommandContext
    {
        $userId = $this->actAsAdminWithCaps();
        return new CommandContext($userId, 'rest', 'req-' . uniqid());
    }

    private function actAsAdminWithCaps(): int
    {
        $role = get_role('administrator');
        foreach (['dono_view_reports', 'dono_view_donors'] as $cap) {
            $role->add_cap($cap);
        }
        $userId = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($userId);
        return (int) $userId;
    }

    private function seedCampaignWithDonation(): int
    {
        $campaign = Plugin::instance()->container->get(CampaignService::class)->create([
            'title'      => 'Winter Relief',
            'goal_type'  => 'amount',
            'goal_cents' => 1_000_000,
        ]);
        $this->seedPaidDonation((int) $campaign->id, 200_000, gmdate('Y-m-d H:i:s'), 'DONO-CMP-' . uniqid());
        return (int) $campaign->id;
    }

    /**
     * A donor with two paid donations in the target year (one partially
     * refunded) and one in another year, plus an address to exercise decryption.
     */
    private function seedDonorWithDonations(): int
    {
        $donor = Plugin::instance()->container->get(DonorService::class)->findOrCreate('taxdonor@example.com', [
            'first_name' => 'Jane',
            'last_name'  => 'Donor',
            'address'    => ['line1' => '9 Elm St', 'city' => 'Boston', 'region' => 'MA', 'postal' => '02108', 'country' => 'US'],
        ]);
        $donorId = (int) $donor->id;

        // Target-year donation, no refund: fully deductible (10000).
        $this->seedPaidDonation(null, 10_000, self::STATEMENT_YEAR . '-03-04 09:00:00', 'DN-2025-A', 'paid', 0, $donorId);
        // Target-year donation with a 5000 partial refund: net 15000 deductible.
        $refundedDonationId = $this->seedPaidDonation(null, 20_000, self::STATEMENT_YEAR . '-06-15 10:00:00', 'DN-2025-B', 'partial_refund', 5_000, $donorId);
        $this->seedRefund($refundedDonationId, 5_000, self::STATEMENT_YEAR . '-07-01 10:00:00');
        // Prior-year donation: excluded from the target-year statement.
        $this->seedPaidDonation(null, 50_000, self::OTHER_YEAR . '-11-20 12:00:00', 'DN-2024-C', 'paid', 0, $donorId);

        return $donorId;
    }

    private function seedPaidDonation(
        ?int $campaignId,
        int $amountCents,
        string $paidAt,
        string $reference,
        string $status = 'paid',
        int $refundedCents = 0,
        int $donorId = 1
    ): int {
        $don = Donation::make();
        $don->reference         = $reference;
        $don->donor_id          = $donorId;
        $don->campaign_id       = $campaignId;
        $don->amount_cents      = $amountCents;
        $don->net_cents         = $amountCents - $refundedCents;
        $don->currency          = 'USD';
        $don->base_amount_cents = $amountCents;
        $don->base_currency     = 'USD';
        $don->fx_rate           = '1.00000000';
        $don->gateway           = 'offline';
        $don->status            = $status;
        $don->is_test           = false;
        $don->refunded_cents    = $refundedCents;
        $don->paid_at           = $paidAt;
        $don->created_at        = $paidAt;
        $don->updated_at        = $paidAt;
        $don->save();
        return (int) $don->id;
    }

    private function seedRefund(int $donationId, int $amountCents, string $occurredAt): void
    {
        $refund               = Refund::make();
        $refund->donation_id  = $donationId;
        $refund->amount_cents = $amountCents;
        $refund->currency     = 'USD';
        $refund->initiated_by = 'admin';
        $refund->status       = 'succeeded';
        $refund->occurred_at  = $occurredAt;
        $refund->save();
    }

    /**
     * The streaming routes echo PDF bytes from a rest_pre_serve_request closure
     * bound to the request's route and return a null-body response. rest_do_request
     * registers that closure; fire it with the same request to capture the bytes.
     */
    private function capturePdf(WP_REST_Request $request, \WP_REST_Response $response): string
    {
        ob_start();
        apply_filters('rest_pre_serve_request', false, $response, $request, rest_get_server());
        return (string) ob_get_clean();
    }
}
