<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Analytics\EventRecorder;
use Dono\Campaigns\Campaign;
use Dono\Core\Commands\CoreCommandProvider;
use Dono\Donations\DonationRepository;
use Dono\Donors\DonorService;
use Dono\Foundation\Commands\CommandContext;
use Dono\Foundation\Commands\CommandRegistry;
use Dono\Foundation\Plugin;
use Dono\Receipts\Receipt;
use Dono\Recurring\RecurringPlan;
use WP_REST_Request;

/**
 * Three operator "chore" commands: the missing-receipt sweep (read), the
 * bulk cancel-recurring-for-a-campaign action (write), and the one-off donor
 * email (write). Each is a thin adapter over an existing service, dispatched
 * from a trusted 'rest' source so the writes run straight through.
 */
final class CoreChoresCommandsTest extends IntegrationTestCase
{
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
        foreach (['dono_view_donations', 'dono_edit_donors'] as $cap) {
            $role->add_cap($cap);
        }
        wp_set_current_user($admin);
        return new CommandContext($admin, 'rest', 'req-' . uniqid());
    }

    public function test_manifest_lists_the_three_chore_commands_with_correct_flags(): void
    {
        $byId = [];
        foreach ($this->registry()->manifest() as $entry) {
            $byId[$entry['id']] = $entry;
        }

        $this->assertArrayHasKey('donation.missing_receipts', $byId);
        $this->assertFalse($byId['donation.missing_receipts']['mutating'], 'missing_receipts is a read');
        $this->assertTrue($byId['donation.missing_receipts']['idempotent']);
        $this->assertSame('dono_view_donations', $byId['donation.missing_receipts']['capability']);

        $this->assertArrayHasKey('recurring.cancel_for_campaign', $byId);
        $this->assertTrue($byId['recurring.cancel_for_campaign']['mutating'], 'cancel_for_campaign is a write');
        $this->assertFalse($byId['recurring.cancel_for_campaign']['idempotent']);
        $this->assertSame('dono_view_donations', $byId['recurring.cancel_for_campaign']['capability']);

        $this->assertArrayHasKey('donor.send_email', $byId);
        $this->assertTrue($byId['donor.send_email']['mutating'], 'send_email is a write');
        $this->assertFalse($byId['donor.send_email']['idempotent']);
        $this->assertSame('dono_edit_donors', $byId['donor.send_email']['capability']);
    }

    public function test_missing_receipts_lists_paid_donations_without_a_valid_receipt(): void
    {
        $ctx  = $this->adminCtx();
        $repo = Plugin::instance()->container->get(DonationRepository::class);

        // Donation 1: paid, but its only receipt is voided -> must appear.
        $ref1 = $this->driveDonationToPaid('missing-1@example.com');
        $d1   = $repo->findByReference($ref1);
        Receipt::query()->where('donation_id', $d1->id)->delete();
        $this->seedReceipt((int) $d1->id, (int) $d1->donor_id, true);

        // Donation 2: paid with a valid (non-voided) receipt -> must not appear.
        $ref2 = $this->driveDonationToPaid('missing-2@example.com');
        $d2   = $repo->findByReference($ref2);
        Receipt::query()->where('donation_id', $d2->id)->delete();
        $this->seedReceipt((int) $d2->id, (int) $d2->donor_id, false);

        $res = $this->registry()->dispatch('donation.missing_receipts', ['per_page' => 100], $ctx);

        $this->assertTrue($res->ok, $res->error ?? '');
        $refs = array_column($res->data['items'], 'reference');
        $this->assertContains($ref1, $refs, 'a paid donation whose only receipt is voided must appear');
        $this->assertNotContains($ref2, $refs, 'a paid donation with a valid receipt must not appear');

        // The projection carries the money fields but no donor PII.
        $row = null;
        foreach ($res->data['items'] as $item) {
            if ($item['reference'] === $ref1) {
                $row = $item;
                break;
            }
        }
        $this->assertNotNull($row);
        $this->assertArrayHasKey('amount_cents', $row);
        $this->assertArrayHasKey('currency', $row);
        $this->assertArrayNotHasKey('email', $row, 'missing_receipts must not carry donor email');
        $this->assertArrayNotHasKey('donor_id', $row, 'missing_receipts must not carry donor id');
    }

    public function test_cancel_for_campaign_cancels_active_plans(): void
    {
        $ctx      = $this->adminCtx();
        $campaign = $this->makeCampaign();
        $plan     = $this->seedActivePlan((int) $campaign->id);

        $res = $this->registry()->dispatch('recurring.cancel_for_campaign', [
            'campaign_id' => (int) $campaign->id,
            'reason'      => 'campaign wound down',
        ], $ctx);

        $this->assertTrue($res->ok, $res->error ?? '');
        $this->assertGreaterThanOrEqual(1, $res->data['queued'], 'it reports what it queued');

        // Queued rather than cancelled inline: each plan is a blocking gateway
        // round trip and a campaign can have thousands.
        $this->assertSame(
            'active',
            RecurringPlan::query()->where('id', $plan->id)->get()->status,
            'the command returns without waiting on the gateway'
        );

        $this->runPendingAsyncJobs();

        $fresh = RecurringPlan::query()->where('id', $plan->id)->get();
        $this->assertSame('cancelled', $fresh->status, 'the active plan must no longer be active');
    }

    public function test_cancel_for_campaign_rejects_an_unknown_campaign(): void
    {
        $ctx = $this->adminCtx();
        $res = $this->registry()->dispatch('recurring.cancel_for_campaign', ['campaign_id' => 987654], $ctx);

        $this->assertFalse($res->ok);
        $this->assertSame('command.failed', $res->error_code);
    }

    public function test_send_email_sends_a_one_off_email_to_the_donor(): void
    {
        $ctx   = $this->adminCtx();
        $donor = Plugin::instance()->container->get(DonorService::class)
            ->findOrCreate('one-off@example.com', ['first_name' => 'Ona']);

        $mails = $this->captureMails();
        $res   = $this->registry()->dispatch('donor.send_email', [
            'donor_id' => (int) $donor->id,
            'subject'  => 'Thank you for your support',
            'body'     => 'We are grateful for your generosity.',
        ], $ctx);

        $this->assertTrue($res->ok, $res->error ?? '');
        $this->assertTrue($res->data['sent']);
        $this->assertCount(1, $mails, 'exactly one email must be dispatched');
        $this->assertSame('one-off@example.com', $mails[0]['to']);
        $this->assertSame('Thank you for your support', $mails[0]['subject']);
        $this->assertStringContainsString('grateful', (string) $mails[0]['message']);
    }

    public function test_send_email_refuses_a_redacted_donor(): void
    {
        $ctx     = $this->adminCtx();
        $service = Plugin::instance()->container->get(DonorService::class);
        $donor   = $service->findOrCreate('to-erase@example.com', ['first_name' => 'Ephemera']);
        $service->redact($donor);

        $mails = $this->captureMails();
        $res   = $this->registry()->dispatch('donor.send_email', [
            'donor_id' => (int) $donor->id,
            'subject'  => 'Hello',
            'body'     => 'Test message.',
        ], $ctx);

        $this->assertFalse($res->ok, 'a redacted donor must never be emailed');
        $this->assertSame('command.failed', $res->error_code);
        $this->assertCount(0, $mails, 'no email may be dispatched for a redacted donor');
    }

    private function makeCampaign(): Campaign
    {
        $now             = gmdate('Y-m-d H:i:s');
        $c               = Campaign::make();
        $c->title        = 'Chore test';
        $c->slug         = 'chore-test-' . uniqid();
        $c->status       = 'published';
        $c->currency     = 'USD';
        $c->created_at   = $now;
        $c->updated_at   = $now;
        $c->save();
        return $c;
    }

    private function seedActivePlan(int $campaignId): RecurringPlan
    {
        $donor = Plugin::instance()->container->get(DonorService::class)
            ->findOrCreate('plan-' . uniqid() . '@example.com', ['first_name' => 'Plan']);

        $now = gmdate('Y-m-d H:i:s');
        $plan = RecurringPlan::make();
        $plan->donor_id                = (int) $donor->id;
        $plan->campaign_id             = $campaignId;
        // Offline isn't SubscriptionAware, so the cancel is local-only (no
        // gateway API call) - keeps the test off the network.
        $plan->gateway                 = 'offline';
        $plan->gateway_subscription_id = 'sub_chore_' . bin2hex(random_bytes(4));
        $plan->amount_cents            = 2000;
        $plan->currency                = 'USD';
        $plan->interval_unit           = 'month';
        $plan->interval_count          = 1;
        $plan->status                  = 'active';
        $plan->payments_count          = 1;
        $plan->total_paid_cents        = 2000;
        $plan->started_at              = $now;
        $plan->created_at              = $now;
        $plan->updated_at              = $now;
        $plan->save();
        return $plan;
    }

    public function test_a_receipt_that_was_never_sent_still_counts_as_missing(): void
    {
        $ctx  = $this->adminCtx();
        $repo = Plugin::instance()->container->get(DonationRepository::class);

        // The receipt row is committed before the PDF renders and before the
        // send is attempted, and the send is skipped outright when the donor
        // has no address. A numbered receipt nobody received is exactly what
        // this report exists to surface.
        $ref = $this->driveDonationToPaid('never-sent@example.com');
        $d   = $repo->findByReference($ref);
        Receipt::query()->where('donation_id', $d->id)->delete();
        $this->seedReceipt((int) $d->id, (int) $d->donor_id, false, false);

        $res = $this->registry()->dispatch('donation.missing_receipts', ['per_page' => 100], $ctx);

        $this->assertTrue($res->ok, $res->error ?? '');
        $this->assertContains(
            $ref,
            array_column($res->data['items'], 'reference'),
            'issued is not delivered'
        );
    }

    private function seedReceipt(int $donationId, int $donorId, bool $voided, bool $sent = true): Receipt
    {
        $now                = gmdate('Y-m-d H:i:s');
        $receipt            = Receipt::make();
        $receipt->donation_id    = $donationId;
        $receipt->donor_id       = $donorId;
        $receipt->renderer_id    = 'annual';
        $receipt->locale         = 'en';
        $receipt->receipt_number = 'RC-' . uniqid();
        $receipt->voided         = $voided;
        $receipt->voided_at      = $voided ? $now : null;
        $receipt->issued_at      = $now;
        // A receipt the donor actually got. The row is written before the send
        // is attempted, so the gap report keys on this rather than the row.
        $receipt->sent_to_email_at = $sent ? $now : null;
        $receipt->save();
        return $receipt;
    }

    private function driveDonationToPaid(string $email): string
    {
        $createReq = new WP_REST_Request('POST', '/dono/v1/donations');
        $createReq->set_header('content-type', 'application/json');
        $createReq->set_body(json_encode([
            'email'        => $email,
            'amount_cents' => 5000,
            'currency'     => 'USD',
            'gateway'      => 'offline',
            'profile'      => ['first_name' => 'Chore', 'country' => 'US'],
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
