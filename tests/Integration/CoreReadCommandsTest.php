<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Analytics\EventRecorder;
use Gratora\Campaigns\CampaignService;
use Gratora\Donations\Donation;
use Gratora\Donors\DonorService;
use Gratora\Foundation\Commands\Command;
use Gratora\Foundation\Commands\CommandContext;
use Gratora\Foundation\Commands\CommandRegistry;
use Gratora\Core\Commands\CoreCommandProvider;
use Gratora\Foundation\Helpers\Money;
use Gratora\Foundation\Plugin;
use WP_REST_Request;

/**
 * Read/list commands are the assistant's eyes: paged, cap-gated, non-mutating,
 * and never surfacing raw donor PII in bulk listings (donor identity is its own
 * gratora_view_donors-gated command).
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
        foreach (['gratora_manage_campaigns', 'gratora_manage_forms', 'gratora_view_donations', 'gratora_view_donors', 'gratora_view_reports'] as $cap) {
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

    public function test_report_revenue_labels_the_total_with_the_org_base_currency(): void
    {
        $ctx     = $this->adminCtx();
        $base    = strtoupper(Money::defaultCurrency());
        $foreign = $base === 'EUR' ? 'GBP' : 'EUR';
        $this->seedPaidDonation('GRATORA-REV-FX-1', $foreign, 4000);
        $this->seedPaidDonation('GRATORA-REV-FX-2', $foreign, 4000);
        $this->seedPaidDonation('GRATORA-REV-BASE', $base, 1000);

        $res = $this->registry()->dispatch('report.revenue', [], $ctx);

        $this->assertTrue($res->ok, $res->error ?? '');
        $this->assertSame(9000, (int) $res->data['amount_cents'], 'the total is the base-currency sum');
        $this->assertSame($base, $res->data['currency'], 'a base-currency total carries the base currency, not the commonest donation currency');
    }

    public function test_report_revenue_for_one_campaign_keeps_the_base_currency(): void
    {
        $ctx      = $this->adminCtx();
        $base     = strtoupper(Money::defaultCurrency());
        $foreign  = $base === 'EUR' ? 'GBP' : 'EUR';
        $campaign = Plugin::instance()->container->get(CampaignService::class)->create(['title' => 'Base Currency Appeal']);
        $this->seedPaidDonation('GRATORA-REV-CMP', $base, 2500, (int) $campaign->id);
        $this->seedPaidDonation('GRATORA-REV-OTH-1', $foreign, 4000);
        $this->seedPaidDonation('GRATORA-REV-OTH-2', $foreign, 4000);

        $res = $this->registry()->dispatch('report.revenue', ['campaign_id' => (int) $campaign->id], $ctx);

        $this->assertTrue($res->ok, $res->error ?? '');
        $this->assertSame(2500, (int) $res->data['amount_cents']);
        $this->assertSame($base, $res->data['currency']);
    }

    /** A paid donation whose base value equals its face value, matching the harness 1:1 rates. */
    private function seedPaidDonation(string $reference, string $currency, int $cents, ?int $campaignId = null): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $don = Donation::make();
        $don->reference         = $reference;
        $don->donor_id          = 1;
        $don->campaign_id       = $campaignId;
        $don->amount_cents      = $cents;
        $don->net_cents         = $cents;
        $don->currency          = $currency;
        $don->base_amount_cents = $cents;
        $don->base_currency     = strtoupper(Money::defaultCurrency());
        $don->fx_rate           = '1.00000000';
        $don->gateway           = 'offline';
        $don->status            = 'paid';
        $don->is_test           = false;
        $don->paid_at           = $now;
        $don->created_at        = $now;
        $don->updated_at        = $now;
        $don->save();
    }

    public function test_admin_dispatches_everyday_commands_but_not_sensitive_ones(): void
    {
        // An administrator gets each everyday area cap via grantMetaCaps, so an
        // agent-source dispatch bound to them works just like the admin UI does.
        // Sensitive caps (refunds) stay explicit and are never granted implicitly.
        $admin = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin);

        // The everyday area caps come from grantMetaCaps for any manage_options
        // holder. (That sensitive caps like gratora_refund_donations stay explicit
        // is covered by CommandsRestTest's refund-denied case, which uses a clean
        // non-admin manage_options user - the shared admin role leaks caps here.)
        $this->assertTrue(user_can($admin, 'gratora_manage_campaigns'));
        $this->assertTrue(user_can($admin, 'gratora_view_donations'));

        $ctx = new CommandContext($admin, 'chat', 'req-' . uniqid());
        $res = $this->registry()->dispatch('campaign.list', [], $ctx);
        $this->assertTrue($res->ok, $res->error ?? '');
    }

    public function test_handler_exception_becomes_a_failed_result_not_a_fatal(): void
    {
        $admin = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin);

        $registry = $this->registry();
        $registry->register(new Command(
            'test.boom',
            'Throws a non-CommandError.',
            [],
            [],
            'manage_options',
            false,
            false,
            static function (): array {
                throw new \InvalidArgumentException('kaboom');
            }
        ));

        $res = $registry->dispatch('test.boom', [], new CommandContext($admin, 'chat', 'req-' . uniqid()));

        $this->assertFalse($res->ok);
        $this->assertSame('command.failed', $res->error_code);
        $this->assertStringContainsString('kaboom', (string) $res->error);
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
        $createReq = new WP_REST_Request('POST', '/gratora/v1/donations');
        $createReq->set_header('content-type', 'application/json');
        $createReq->set_body(json_encode([
            'email'        => 'read-cmd@example.com',
            'amount_cents' => 5000,
            'currency'     => 'USD',
            'gateway'      => 'offline',
            'profile'      => ['first_name' => 'Read', 'country' => 'US'],
        ]));
        $reference = rest_do_request($createReq)->get_data()['reference'];

        $confirmReq = new WP_REST_Request('POST', "/gratora/v1/donations/{$reference}/confirm");
        $confirmReq->set_header('content-type', 'application/json');
        $confirmReq->set_body('{}');
        rest_do_request($confirmReq);

        $this->runPendingAsyncJobs();

        return $reference;
    }
}
