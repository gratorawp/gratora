<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Analytics\EventRecorder;
use Dono\Campaigns\CampaignService;
use Dono\Donors\DonorService;
use Dono\Foundation\Commands\CommandContext;
use Dono\Foundation\Commands\CommandRegistry;
use Dono\Core\Commands\CoreCommandProvider;
use Dono\Foundation\Plugin;
use WP_REST_Request;

/**
 * Read/list commands are the assistant's eyes: paged, cap-gated, non-mutating,
 * and never surfacing raw donor PII in bulk listings (donor identity is its own
 * dono_view_donors-gated command).
 */
final class CoreReadCommandsTest extends IntegrationTestCase
{
    private const READ_IDS = [
        'campaign.list', 'fund.list', 'form.list', 'donation.list',
        'donor.list', 'donor.find_by_email', 'report.revenue',
    ];

    private function registry(): CommandRegistry
    {
        $c = Plugin::instance()->container;
        $r = new CommandRegistry($c->get(EventRecorder::class));
        (new CoreCommandProvider())->register($r, $c);
        return $r;
    }

    private function adminCtx(): CommandContext
    {
        $admin = self::factory()->user->create(['role' => 'administrator']);
        $role  = get_role('administrator');
        foreach (['dono_manage_campaigns', 'dono_manage_forms', 'dono_view_donations', 'dono_view_donors', 'dono_view_reports'] as $cap) {
            $role->add_cap($cap);
        }
        wp_set_current_user($admin);
        return new CommandContext($admin, 'rest', 'req-' . uniqid());
    }

    public function test_manifest_lists_read_commands_as_non_mutating(): void
    {
        $byId = [];
        foreach ($this->registry()->manifest() as $entry) {
            $byId[$entry['id']] = $entry;
        }
        foreach (self::READ_IDS as $id) {
            $this->assertArrayHasKey($id, $byId, "manifest missing {$id}");
            $this->assertFalse($byId[$id]['mutating'], "{$id} must be non-mutating");
            $this->assertTrue($byId[$id]['idempotent'], "{$id} must be idempotent");
        }
    }

    public function test_campaign_list_returns_projected_campaigns(): void
    {
        $ctx = $this->adminCtx();
        Plugin::instance()->container->get(CampaignService::class)->create(['title' => 'Shelter Drive']);
        Plugin::instance()->container->get(CampaignService::class)->create(['title' => 'Winter Appeal']);

        $res = $this->registry()->dispatch('campaign.list', ['per_page' => 50], $ctx);

        $this->assertTrue($res->ok, $res->error ?? '');
        $this->assertGreaterThanOrEqual(2, $res->data['total']);
        $titles = array_column($res->data['items'], 'title');
        $this->assertContains('Shelter Drive', $titles);
        $first = $res->data['items'][0];
        foreach (['id', 'title', 'slug', 'status', 'campaign_type', 'raised_cents'] as $key) {
            $this->assertArrayHasKey($key, $first);
        }
    }

    public function test_donation_list_is_paged_and_hides_donor_pii(): void
    {
        $ctx = $this->adminCtx();
        $this->driveDonationToPaid();

        $res = $this->registry()->dispatch('donation.list', ['per_page' => 1], $ctx);

        $this->assertTrue($res->ok, $res->error ?? '');
        $this->assertGreaterThanOrEqual(1, $res->data['total']);
        $this->assertLessThanOrEqual(1, count($res->data['items']));
        $item = $res->data['items'][0];
        $this->assertArrayHasKey('donor_id', $item);
        $this->assertArrayHasKey('amount_cents', $item);
        $this->assertArrayNotHasKey('email', $item, 'bulk donation list must not carry donor email');
        $this->assertArrayNotHasKey('name', $item, 'bulk donation list must not carry donor name');
    }

    public function test_donor_find_by_email_locates_a_seeded_donor(): void
    {
        $ctx = $this->adminCtx();
        Plugin::instance()->container->get(DonorService::class)
            ->findOrCreate('lookup@example.com', ['first_name' => 'Lena', 'last_name' => 'Ortiz']);

        $res = $this->registry()->dispatch('donor.find_by_email', ['email' => 'lookup@example.com'], $ctx);

        $this->assertTrue($res->ok, $res->error ?? '');
        $this->assertTrue($res->data['found']);
        $this->assertSame('lookup@example.com', $res->data['donor']['email']);
        $this->assertSame('Lena Ortiz', $res->data['donor']['name']);
    }

    public function test_donor_find_by_email_reports_not_found(): void
    {
        $ctx = $this->adminCtx();
        $res = $this->registry()->dispatch('donor.find_by_email', ['email' => 'nobody@example.com'], $ctx);

        $this->assertTrue($res->ok, $res->error ?? '');
        $this->assertFalse($res->data['found']);
    }

    public function test_report_revenue_sums_paid_donations(): void
    {
        $ctx = $this->adminCtx();
        $this->driveDonationToPaid();

        $res = $this->registry()->dispatch('report.revenue', [], $ctx);

        $this->assertTrue($res->ok, $res->error ?? '');
        $this->assertGreaterThan(0, $res->data['amount_cents']);
        $this->assertGreaterThanOrEqual(1, $res->data['donations_count']);
    }

    public function test_read_command_denied_without_capability(): void
    {
        $subscriber = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($subscriber);
        $ctx = new CommandContext($subscriber, 'rest', 'req-' . uniqid());

        $res = $this->registry()->dispatch('donor.list', [], $ctx);

        $this->assertFalse($res->ok);
        $this->assertSame('command.denied', $res->error_code);
    }

    private function driveDonationToPaid(): string
    {
        $createReq = new WP_REST_Request('POST', '/dono/v1/donations');
        $createReq->set_header('content-type', 'application/json');
        $createReq->set_body(json_encode([
            'email'        => 'read-cmd@example.com',
            'amount_cents' => 5000,
            'currency'     => 'USD',
            'gateway'      => 'offline',
            'profile'      => ['first_name' => 'Read', 'country' => 'US'],
        ]));
        $reference = rest_do_request($createReq)->get_data()['reference'];

        $confirmReq = new WP_REST_Request('POST', "/dono/v1/donations/{$reference}/confirm");
        $confirmReq->set_header('content-type', 'application/json');
        $confirmReq->set_body('{}');
        rest_do_request($confirmReq);

        $this->runPendingAsyncJobs();

        return $reference;
    }
}
