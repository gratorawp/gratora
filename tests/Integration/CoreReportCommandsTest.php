<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Analytics\EventRecorder;
use Dono\Campaigns\CampaignService;
use Dono\Core\Commands\CoreCommandProvider;
use Dono\Donations\Donation;
use Dono\Donors\Donor;
use Dono\Donors\DonorService;
use Dono\Foundation\Commands\CommandContext;
use Dono\Foundation\Commands\CommandRegistry;
use Dono\Foundation\Plugin;
use WP_REST_Request;

/**
 * "Ask your data" analytics reads: thin, cap-gated projections over the
 * dashboard and donor metrics services so the assistant can answer "how are we
 * doing", "what's our recurring revenue", and "who's at risk of lapsing". All
 * non-mutating + idempotent.
 */
final class CoreReportCommandsTest extends IntegrationTestCase
{
    private const REPORT_IDS = [
        'report.dashboard', 'report.recurring', 'report.top_campaigns',
        'report.attention', 'donor.at_risk',
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
        foreach (['dono_view_reports', 'dono_view_donors'] as $cap) {
            $role->add_cap($cap);
        }
        wp_set_current_user($admin);
        return new CommandContext($admin, 'rest', 'req-' . uniqid());
    }

    public function test_diagnostics_recent_groups_recent_failures(): void
    {
        $events = Plugin::instance()->container->get(EventRecorder::class);
        $events->record('command.failed', ['user_id' => 1, 'payload' => ['command_id' => 'refund.create', 'error' => 'Stripe: No such charge']]);
        $events->record('command.failed', ['user_id' => 1, 'payload' => ['command_id' => 'refund.create', 'error' => 'Stripe: No such charge']]);
        $events->record('donation.failed', ['payload' => ['gateway' => 'stripe', 'reason' => 'card_declined']]);
        $events->record('donation.completed', ['payload' => []]);

        $res = $this->registry()->dispatch('diagnostics.recent', ['hours' => 24], $this->adminCtx());

        $this->assertTrue($res->ok);
        $this->assertSame(3, $res->data['total_issues'], '2 command failures + 1 failed donation');

        $byType = [];
        foreach ($res->data['issues'] as $issue) {
            $byType[$issue['type']] = $issue;
        }
        $this->assertSame(2, $byType['command.failed']['count']);
        $this->assertSame('Stripe: No such charge', $byType['command.failed']['sample_error']);

        $cmd = $byType['command.failed']['commands'][0];
        $this->assertSame('refund.create', $cmd['command_id']);
        $this->assertSame(2, $cmd['count']);
        $this->assertSame(1, $cmd['by_user'][0]['user_id'], 'the operator who hit the failure is named');
        $this->assertSame(2, $cmd['by_user'][0]['count']);
        $this->assertSame('card_declined', $byType['donation.failed']['sample_error']);
        $this->assertSame(1, $res->data['healthy']['donation.completed'] ?? 0, 'healthy activity is counted for context');
    }

    public function test_diagnostics_recent_needs_the_reports_capability(): void
    {
        $user = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($user);
        $ctx = new CommandContext($user, 'rest', 'req-' . uniqid());

        $res = $this->registry()->dispatch('diagnostics.recent', [], $ctx);

        $this->assertFalse($res->ok, 'error diagnostics are gated behind dono_view_reports');
    }

    public function test_manifest_lists_the_report_commands_as_non_mutating(): void
    {
        $byId = [];
        foreach ($this->registry()->manifest() as $entry) {
            $byId[$entry['id']] = $entry;
        }
        foreach (self::REPORT_IDS as $id) {
            $this->assertArrayHasKey($id, $byId, "manifest missing {$id}");
            $this->assertFalse($byId[$id]['mutating'], "{$id} must be non-mutating");
            $this->assertTrue($byId[$id]['idempotent'], "{$id} must be idempotent");
        }
    }

    public function test_report_dashboard_returns_org_kpis(): void
    {
        $ctx = $this->adminCtx();
        $this->driveDonationToPaid();

        $res = $this->registry()->dispatch('report.dashboard', ['range' => 'all-time'], $ctx);

        $this->assertTrue($res->ok, $res->error ?? '');
        foreach (['amount_raised_cents', 'donations_count', 'donors_count', 'avg_donation_cents', 'currency', 'comparison'] as $key) {
            $this->assertArrayHasKey($key, $res->data);
        }
        $this->assertGreaterThan(0, $res->data['amount_raised_cents']);
        $this->assertGreaterThanOrEqual(1, $res->data['donations_count']);
    }

    public function test_report_dashboard_compare_flag_adds_a_comparison_block(): void
    {
        $ctx = $this->adminCtx();

        $res = $this->registry()->dispatch('report.dashboard', ['range' => 'last-30', 'compare' => true], $ctx);

        $this->assertTrue($res->ok, $res->error ?? '');
        $this->assertIsArray($res->data['comparison'], 'compare=true must yield a comparison block');
        $this->assertSame('period', $res->data['comparison']['mode']);
    }

    public function test_report_recurring_returns_an_mrr_snapshot(): void
    {
        $ctx = $this->adminCtx();

        $res = $this->registry()->dispatch('report.recurring', [], $ctx);

        $this->assertTrue($res->ok, $res->error ?? '');
        foreach (['active_plans', 'mrr_cents', 'projected_30d_cents', 'new_this_month', 'currency'] as $key) {
            $this->assertArrayHasKey($key, $res->data);
        }
    }

    public function test_report_top_campaigns_ranks_a_seeded_campaign(): void
    {
        $ctx      = $this->adminCtx();
        $campaign = Plugin::instance()->container->get(CampaignService::class)->create(['title' => 'Flagship Appeal']);
        $this->seedPaidDonationForCampaign((int) $campaign->id);

        $res = $this->registry()->dispatch('report.top_campaigns', ['range' => 'all-time', 'limit' => 5], $ctx);

        $this->assertTrue($res->ok, $res->error ?? '');
        $this->assertArrayHasKey('campaigns', $res->data);
        $this->assertGreaterThanOrEqual(1, count($res->data['campaigns']));
        $first = $res->data['campaigns'][0];
        foreach (['id', 'title', 'currency', 'amount_cents', 'donations_count'] as $key) {
            $this->assertArrayHasKey($key, $first);
        }
        $this->assertArrayNotHasKey('sparkline', $first, 'top_campaigns must not carry the raw sparkline series');
    }

    public function test_report_attention_returns_an_items_queue(): void
    {
        $ctx = $this->adminCtx();

        $res = $this->registry()->dispatch('report.attention', [], $ctx);

        $this->assertTrue($res->ok, $res->error ?? '');
        $this->assertArrayHasKey('items', $res->data);
        $this->assertIsArray($res->data['items']);
        foreach ($res->data['items'] as $item) {
            foreach (['key', 'tone', 'title'] as $key) {
                $this->assertArrayHasKey($key, $item);
            }
        }
    }

    public function test_a_single_donor_note_links_to_that_donors_profile(): void
    {
        $ctx = $this->adminCtx();
        $this->seedNotedDonation(1, 'Please keep this anonymous.');

        $items = $this->registry()->dispatch('report.attention', [], $ctx)->data['items'];
        $note  = $this->itemByKey($items, 'donor-notes');

        $this->assertNotNull($note, 'expected a donor-notes attention item');
        $this->assertStringContainsString('page=dono-donors', $note['action_href']);
        $this->assertStringEndsWith('#donor/1', $note['action_href']);
    }

    public function test_notes_from_several_donors_fall_back_to_the_donor_list(): void
    {
        $ctx = $this->adminCtx();
        $this->seedNotedDonation(1, 'First note.');
        $this->seedNotedDonation(2, 'Second note.');

        $items = $this->registry()->dispatch('report.attention', [], $ctx)->data['items'];
        $note  = $this->itemByKey($items, 'donor-notes');

        $this->assertNotNull($note);
        $this->assertStringEndsWith('page=dono-donors', $note['action_href']);
        $this->assertStringNotContainsString('#donor/', $note['action_href']);
    }

    private function itemByKey(array $items, string $key): ?array
    {
        foreach ($items as $item) {
            if (($item['key'] ?? '') === $key) return $item;
        }
        return null;
    }

    private function seedNotedDonation(int $donorId, string $note): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $don = Donation::make();
        $don->reference         = 'DONO-NOTE-' . $donorId;
        $don->donor_id          = $donorId;
        $don->amount_cents      = 5000;
        $don->net_cents         = 5000;
        $don->currency          = 'USD';
        $don->base_amount_cents = 5000;
        $don->base_currency     = 'USD';
        $don->fx_rate           = '1.00000000';
        $don->gateway           = 'stripe';
        $don->status            = 'paid';
        $don->is_test           = false;
        $don->note_to_org       = $note;
        $don->paid_at           = $now;
        $don->created_at        = $now;
        $don->updated_at        = $now;
        $don->save();
    }

    public function test_donor_at_risk_lists_a_lapsing_donor(): void
    {
        $ctx = $this->adminCtx();
        $this->seedAtRiskDonor('lapsing@example.com');

        $res = $this->registry()->dispatch('donor.at_risk', ['per_page' => 25], $ctx);

        $this->assertTrue($res->ok, $res->error ?? '');
        foreach (['items', 'total', 'page', 'per_page'] as $key) {
            $this->assertArrayHasKey($key, $res->data);
        }
        $this->assertGreaterThanOrEqual(1, $res->data['total']);
        $this->assertContains('lapsing@example.com', array_column($res->data['items'], 'email'));
    }

    public function test_report_command_denied_without_capability(): void
    {
        $subscriber = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($subscriber);
        $ctx = new CommandContext($subscriber, 'rest', 'req-' . uniqid());

        $res = $this->registry()->dispatch('report.dashboard', [], $ctx);

        $this->assertFalse($res->ok);
        $this->assertSame('command.denied', $res->error_code);
    }

    /** A donor whose last donation lands in the 90-180 day at-risk band. */
    private function seedAtRiskDonor(string $email): int
    {
        $donor = Plugin::instance()->container->get(DonorService::class)
            ->findOrCreate($email, ['first_name' => 'Lapsing', 'last_name' => 'Donor']);
        $last = (new \DateTimeImmutable())->modify('-120 days')->format('Y-m-d 12:00:00');
        Donor::query()->where('id', (int) $donor->id)->update([
            'donations_count'     => 2,
            'total_donated_cents' => 12000,
            'first_donation_at'   => $last,
            'last_donation_at'    => $last,
        ]);
        return (int) $donor->id;
    }

    /** A paid donation crediting a campaign, with a base amount so it ranks. */
    private function seedPaidDonationForCampaign(int $campaignId): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $don = Donation::make();
        $don->reference         = 'DONO-RPT-' . substr(md5((string) $campaignId), 0, 8);
        $don->donor_id          = 1;
        $don->campaign_id       = $campaignId;
        $don->amount_cents      = 8000;
        $don->net_cents         = 8000;
        $don->currency          = 'USD';
        $don->base_amount_cents = 8000;
        $don->base_currency     = 'USD';
        $don->fx_rate           = '1.00000000';
        $don->gateway           = 'stripe';
        $don->status            = 'paid';
        $don->is_test           = false;
        $don->paid_at           = $now;
        $don->created_at        = $now;
        $don->updated_at        = $now;
        $don->save();
    }

    private function driveDonationToPaid(): string
    {
        $createReq = new WP_REST_Request('POST', '/dono/v1/donations');
        $createReq->set_header('content-type', 'application/json');
        $createReq->set_body(json_encode([
            'email'        => 'report-cmd@example.com',
            'amount_cents' => 5000,
            'currency'     => 'USD',
            'gateway'      => 'offline',
            'profile'      => ['first_name' => 'Report', 'country' => 'US'],
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
