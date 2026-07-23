<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Analytics\EventRecorder;
use Dono\Campaigns\CampaignRepository;
use Dono\Core\Commands\CoreCommandProvider;
use Dono\Donations\Donation;
use Dono\Donations\DonationRepository;
use Dono\Donations\Refund;
use Dono\Foundation\Commands\CommandContext;
use Dono\Foundation\Commands\CommandRegistry;
use Dono\Foundation\Plugin;
use WP_REST_Request;

/**
 * B6 modifies zero files under src/Donations, src/Donors, src/Campaigns,
 * src/Forms, src/Funds, src/Receipts, src/Recurring. Every command here is a
 * thin adapter that resolves the unmodified service from the container and
 * calls it, which the end-to-end refund test below proves.
 */
final class CoreCommandProviderTest extends IntegrationTestCase
{
    private function registry(): CommandRegistry
    {
        $c = Plugin::instance()->container;
        $r = new CommandRegistry($c->get(EventRecorder::class));
        (new CoreCommandProvider())->register($r, $c);
        return $r;
    }

    public function test_manifest_lists_every_core_command(): void
    {
        $manifest = $this->registry()->manifest();
        $ids      = array_column($manifest, 'id');

        $expected = [
            'donation.create', 'donation.confirm', 'donation.mark_failed',
            'donation.refund', 'donation.record_external_refund',
            'donation.aggregates.sync', 'donor.find_or_create',
            'donor.refresh_profile', 'donor.change_email', 'donor.redact',
            'donor.consent.record', 'donor.magic_link.issue',
            'campaign.create', 'campaign.update', 'campaign.delete',
            'campaign.duplicate', 'form.create', 'form.update', 'form.delete',
            'form.duplicate', 'fund.create', 'fund.update', 'fund.delete',
            'receipt.requeue', 'receipt.render_pdf', 'recurring.cancel',
            'recurring.pause', 'recurring.resume', 'recurring.update_amount',
            'donation.get', 'donor.get', 'campaign.metrics', 'donor.insights',
            'campaign.list', 'fund.list', 'form.list', 'donation.list',
            'donor.list', 'donor.find_by_email', 'report.revenue',
        ];
        foreach ($expected as $id) {
            $this->assertContains($id, $ids, "manifest missing {$id}");
        }
    }

    public function test_money_movers_are_mutating_and_not_idempotent(): void
    {
        $byId = [];
        foreach ($this->registry()->manifest() as $entry) {
            $byId[$entry['id']] = $entry;
        }

        foreach (['donation.refund', 'donation.record_external_refund', 'recurring.cancel'] as $id) {
            $this->assertTrue($byId[$id]['mutating'], "{$id} must be mutating");
            $this->assertFalse($byId[$id]['idempotent'], "{$id} must not be idempotent");
        }

        foreach (['donation.get', 'donor.get', 'campaign.metrics', 'donor.insights'] as $id) {
            $this->assertFalse($byId[$id]['mutating'], "{$id} must be read-only");
        }

        $this->assertSame('dono_refund_donations', $byId['donation.refund']['capability']);
        $this->assertSame('core', $byId['donation.refund']['meta']['add_on']);
    }

    public function test_donation_refund_dispatches_through_the_real_service(): void
    {
        $admin = self::factory()->user->create(['role' => 'administrator']);
        get_role('administrator')->add_cap('dono_refund_donations');
        wp_set_current_user($admin);

        $reference = $this->driveDonationToPaid();
        $donation  = Plugin::instance()->container->get(DonationRepository::class)
            ->findByReference($reference);

        $ctx = new CommandContext($admin, 'rest', 'req-' . uniqid());
        $res = $this->registry()->dispatch('donation.refund', [
            'donation_reference' => $reference,
            'amount_cents'       => $donation->amount_cents,
            'reason'             => 'donor requested',
        ], $ctx);

        $this->assertTrue($res->ok, $res->error ?? '');
        $this->assertTrue($res->data['is_full_refund']);

        $refund = Refund::query()->where('donation_id', $donation->id)->get();
        $this->assertNotNull($refund, 'Real DonationService::refund must have written a Refund row');
        $this->assertSame($donation->amount_cents, $refund->amount_cents);

        $reloaded = Plugin::instance()->container->get(DonationRepository::class)
            ->findByReference($reference);
        $this->assertSame('refunded', $reloaded->status);

        $eventTypes = array_column(
            self::$wpdb->get_results('SELECT type FROM ' . self::$prefix . 'dono_events ORDER BY id'),
            'type'
        );
        $this->assertContains('donation.refunded', $eventTypes);
        $this->assertContains('command.invoked', $eventTypes);
    }

    public function test_campaign_create_honors_campaign_type_from_the_registry(): void
    {
        $admin = self::factory()->user->create(['role' => 'administrator']);
        get_role('administrator')->add_cap('dono_manage_campaigns');
        wp_set_current_user($admin);

        // An add-on contributes its type to the live filter, as dono-p2p does.
        add_filter('dono.campaign.types', static function (array $types): array {
            $types['peer_to_peer'] = 'Peer-to-peer';
            return $types;
        });

        $ctx = new CommandContext($admin, 'rest', 'req-' . uniqid());
        $res = $this->registry()->dispatch('campaign.create', [
            'title'         => 'Dog Shelter Drive',
            'campaign_type' => 'peer_to_peer',
        ], $ctx);

        $this->assertTrue($res->ok, $res->error ?? '');

        $campaign = Plugin::instance()->container->get(CampaignRepository::class)
            ->findById((int) $res->data['campaign_id']);
        $this->assertSame(
            'peer_to_peer',
            $campaign->campaign_type,
            'campaign_type must survive dispatch; additionalProperties:false was stripping it'
        );

        remove_all_filters('dono.campaign.types');
    }

    private function driveDonationToPaid(): string
    {
        $createReq = new WP_REST_Request('POST', '/dono/v1/donations');
        $createReq->set_header('content-type', 'application/json');
        $createReq->set_body(json_encode([
            'email'        => 'refund-cmd@example.com',
            'amount_cents' => 5000,
            'currency'     => 'USD',
            'gateway'      => 'offline',
            'profile'      => ['first_name' => 'Cmd', 'country' => 'US'],
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
