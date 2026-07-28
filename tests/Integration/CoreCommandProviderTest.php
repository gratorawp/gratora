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
            'campaign.duplicate', 'form.create', 'form.update', 'form.get', 'form.delete',
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

    public function test_manifest_flags_destructive_commands_and_previewable_ones(): void
    {
        $byId = [];
        foreach ($this->registry()->manifest() as $entry) {
            $byId[$entry['id']] = $entry;
        }

        $destructive = [
            'donation.refund', 'donation.record_external_refund', 'donor.redact',
            'campaign.delete', 'form.delete', 'fund.delete',
            'recurring.cancel', 'recurring.cancel_for_campaign',
        ];
        foreach ($destructive as $id) {
            $this->assertNotEmpty($byId[$id]['meta']['destructive'] ?? null, "{$id} must be flagged destructive in meta");
        }

        $previewable = [
            'campaign.update', 'form.update', 'donation.refund',
            'recurring.cancel_for_campaign', 'campaign.delete', 'form.delete', 'fund.delete',
        ];
        foreach ($previewable as $id) {
            $this->assertTrue($byId[$id]['has_preview'], "{$id} must expose has_preview");
        }

        // A command that got neither stays unflagged.
        $this->assertArrayNotHasKey('destructive', $byId['campaign.update']['meta']);
        $this->assertFalse($byId['campaign.create']['has_preview']);
    }

    public function test_preview_for_campaign_update_shows_the_status_change(): void
    {
        $admin = self::factory()->user->create(['role' => 'administrator']);
        get_role('administrator')->add_cap('dono_manage_campaigns');
        wp_set_current_user($admin);

        $r          = $this->registry();
        $ctx        = new CommandContext($admin, 'rest', 'req-' . uniqid());
        $campaignId = (int) $r->dispatch('campaign.create', ['title' => 'Preview Me', 'status' => 'draft'], $ctx)->data['campaign_id'];

        $rows = $r->previewFor('campaign.update', ['campaign_id' => $campaignId, 'status' => 'published'], $ctx);

        $this->assertNotEmpty($rows, 'a real status change should yield a preview row');
        $statusRow = null;
        foreach ($rows as $row) {
            if (($row['label'] ?? '') === 'Status') {
                $statusRow = $row;
            }
        }
        $this->assertNotNull($statusRow, 'a Status row must be present');
        $this->assertSame('draft', $statusRow['from']);
        $this->assertSame('published', $statusRow['to']);
    }

    /**
     * The inverse has to put back everything the approval card previewed. It
     * used to carry only `status`, so approving a rename plus a publish and
     * clicking Undo restored the status, left the rename applied, and reported
     * "Reverted."
     */
    public function test_reverse_for_campaign_update_covers_every_changed_field(): void
    {
        $admin = self::factory()->user->create(['role' => 'administrator']);
        get_role('administrator')->add_cap('dono_manage_campaigns');
        wp_set_current_user($admin);

        $r          = $this->registry();
        $ctx        = new CommandContext($admin, 'rest', 'req-' . uniqid());
        $campaignId = (int) $r->dispatch('campaign.create', ['title' => 'Reverse Me', 'status' => 'draft'], $ctx)->data['campaign_id'];

        $inverse = $r->reverseFor('campaign.update', [
            'campaign_id' => $campaignId,
            'status'      => 'published',
            'title'       => 'Renamed',
        ], $ctx);

        $this->assertSame(
            ['campaign_id' => $campaignId, 'title' => 'Reverse Me', 'status' => 'draft'],
            $inverse,
            'both changed fields come back, not just the status'
        );
    }

    public function test_a_single_field_change_is_still_reversible(): void
    {
        $admin = self::factory()->user->create(['role' => 'administrator']);
        get_role('administrator')->add_cap('dono_manage_campaigns');
        wp_set_current_user($admin);

        $r          = $this->registry();
        $ctx        = new CommandContext($admin, 'rest', 'req-' . uniqid());
        $campaignId = (int) $r->dispatch('campaign.create', ['title' => 'Reverse Me', 'status' => 'draft'], $ctx)->data['campaign_id'];

        $this->assertSame(
            ['campaign_id' => $campaignId, 'status' => 'draft'],
            $r->reverseFor('campaign.update', ['campaign_id' => $campaignId, 'status' => 'published'], $ctx)
        );
        $this->assertSame(
            ['campaign_id' => $campaignId, 'title' => 'Reverse Me'],
            $r->reverseFor('campaign.update', ['campaign_id' => $campaignId, 'title' => 'Renamed'], $ctx),
            'a rename is restorable, so it is reversible'
        );
    }

    /**
     * Promotion off 'standard' is one way, so a change carrying it is offered
     * no Undo at all. Half an undo reported as "Reverted" is the bug.
     */
    public function test_a_change_with_an_irreversible_field_offers_no_undo(): void
    {
        $admin = self::factory()->user->create(['role' => 'administrator']);
        get_role('administrator')->add_cap('dono_manage_campaigns');
        wp_set_current_user($admin);
        add_filter('dono.campaign.types', static fn (array $t): array => $t + ['squad' => 'Squad']);

        $r          = $this->registry();
        $ctx        = new CommandContext($admin, 'rest', 'req-' . uniqid());
        $campaignId = (int) $r->dispatch('campaign.create', ['title' => 'One Way', 'status' => 'draft'], $ctx)->data['campaign_id'];

        $this->assertNull($r->reverseFor('campaign.update', [
            'campaign_id'   => $campaignId,
            'status'        => 'published',
            'campaign_type' => 'squad',
        ], $ctx));
    }

    public function test_a_no_op_or_unknown_command_yields_no_inverse(): void
    {
        $admin = self::factory()->user->create(['role' => 'administrator']);
        get_role('administrator')->add_cap('dono_manage_campaigns');
        wp_set_current_user($admin);

        $r          = $this->registry();
        $ctx        = new CommandContext($admin, 'rest', 'req-' . uniqid());
        $campaignId = (int) $r->dispatch('campaign.create', ['title' => 'Reverse Me', 'status' => 'draft'], $ctx)->data['campaign_id'];

        $this->assertNull($r->reverseFor('campaign.update', ['campaign_id' => $campaignId, 'status' => 'draft'], $ctx), 'nothing changed');
        $this->assertNull($r->reverseFor('campaign.teleport', ['campaign_id' => 1], $ctx));
        $this->assertNull($r->reverseFor('campaign.duplicate', ['campaign_id' => $campaignId], $ctx));
    }

    public function test_preview_for_unknown_invalid_or_previewless_command_returns_empty(): void
    {
        $admin = self::factory()->user->create(['role' => 'administrator']);
        get_role('administrator')->add_cap('dono_manage_campaigns');
        wp_set_current_user($admin);

        $r   = $this->registry();
        $ctx = new CommandContext($admin, 'rest', 'req-' . uniqid());

        // Unknown command id.
        $this->assertSame([], $r->previewFor('campaign.teleport', ['campaign_id' => 1], $ctx));

        // Invalid input: campaign.update requires campaign_id, so this fails validation.
        $this->assertSame([], $r->previewFor('campaign.update', ['status' => 'published'], $ctx));

        // Valid input but the command declares no preview closure.
        $campaignId = (int) $r->dispatch('campaign.create', ['title' => 'No Preview'], $ctx)->data['campaign_id'];
        $this->assertSame([], $r->previewFor('campaign.duplicate', ['campaign_id' => $campaignId], $ctx));
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

    public function test_campaign_create_rejects_a_type_whose_add_on_is_inactive(): void
    {
        $admin = self::factory()->user->create(['role' => 'administrator']);
        get_role('administrator')->add_cap('dono_manage_campaigns');
        wp_set_current_user($admin);

        // No add-on registered peer_to_peer, so it is not an available type and
        // must be refused - not silently downgraded to standard with a success.
        $ctx = new CommandContext($admin, 'rest', 'req-' . uniqid());
        $res = $this->registry()->dispatch('campaign.create', [
            'title'         => 'Ghost P2P',
            'campaign_type' => 'peer_to_peer',
        ], $ctx);
        $this->assertFalse($res->ok, 'an unavailable campaign type must be rejected');
        $this->assertSame('command.invalid_input', $res->error_code);

        // A standard campaign still works.
        $ok = $this->registry()->dispatch('campaign.create', ['title' => 'Plain', 'campaign_type' => 'standard'], $ctx);
        $this->assertTrue($ok->ok, $ok->error ?? '');
    }

    public function test_campaign_update_sets_the_image_attachment(): void
    {
        $admin = self::factory()->user->create(['role' => 'administrator']);
        get_role('administrator')->add_cap('dono_manage_campaigns');
        wp_set_current_user($admin);

        $ctx        = new CommandContext($admin, 'rest', 'req-' . uniqid());
        $campaignId = (int) $this->registry()->dispatch('campaign.create', ['title' => 'Photo Campaign'], $ctx)->data['campaign_id'];
        $attachment = $this->makeImageAttachment();

        $res = $this->registry()->dispatch('campaign.update', [
            'campaign_id'         => $campaignId,
            'image_attachment_id' => $attachment,
        ], $ctx);

        $this->assertTrue($res->ok, $res->error ?? '');
        $campaign = Plugin::instance()->container->get(CampaignRepository::class)->findById($campaignId);
        $this->assertSame($attachment, (int) $campaign->image_attachment_id);
    }

    public function test_form_get_reads_structure_and_form_update_rejects_fantasy_settings(): void
    {
        $admin = self::factory()->user->create(['role' => 'administrator']);
        get_role('administrator')->add_cap('dono_manage_campaigns');
        get_role('administrator')->add_cap('dono_manage_forms');
        wp_set_current_user($admin);

        $ctx        = new CommandContext($admin, 'rest', 'req-' . uniqid());
        $campaignId = (int) $this->registry()->dispatch('campaign.create', ['title' => 'Form Host'], $ctx)->data['campaign_id'];
        $formId     = (int) Plugin::instance()->container->get(CampaignRepository::class)->findById($campaignId)->default_form_id;
        $this->assertGreaterThan(0, $formId, 'campaign.create should seed a default form');

        // form.get reports the real structure.
        $get = $this->registry()->dispatch('form.get', ['form_id' => $formId], $ctx);
        $this->assertTrue($get->ok, $get->error ?? '');
        $this->assertSame($formId, $get->data['form_id']);
        $this->assertIsArray($get->data['blocks']);

        // Fantasy settings (currency/recurring) are rejected, not silently stored.
        $bad = $this->registry()->dispatch('form.update', [
            'form_id'  => $formId,
            'settings' => ['supported_currencies' => ['USD', 'EUR'], 'recurring_enabled' => true],
        ], $ctx);
        $this->assertFalse($bad->ok);
        $this->assertSame('command.invalid_input', $bad->error_code);

        // A real setting (goal) applies and the result reflects it.
        $ok = $this->registry()->dispatch('form.update', [
            'form_id'  => $formId,
            'settings' => ['goal' => ['type' => 'amount', 'amount_cents' => 500000]],
        ], $ctx);
        $this->assertTrue($ok->ok, $ok->error ?? '');
        $this->assertSame('amount', $ok->data['settings']['goal']['type']);
    }

    public function test_form_update_merges_settings_without_dropping_keys(): void
    {
        $admin = self::factory()->user->create(['role' => 'administrator']);
        get_role('administrator')->add_cap('dono_manage_campaigns');
        get_role('administrator')->add_cap('dono_manage_forms');
        wp_set_current_user($admin);

        $ctx        = new CommandContext($admin, 'rest', 'req-' . uniqid());
        $campaignId = (int) $this->registry()->dispatch('campaign.create', ['title' => 'Merge Host'], $ctx)->data['campaign_id'];
        $formId     = (int) Plugin::instance()->container->get(CampaignRepository::class)->findById($campaignId)->default_form_id;

        // Set a thank-you message, then patch only the goal.
        $this->registry()->dispatch('form.update', ['form_id' => $formId, 'settings' => ['thank_you_message' => 'Cheers']], $ctx);
        $this->registry()->dispatch('form.update', ['form_id' => $formId, 'settings' => ['goal' => ['type' => 'amount', 'amount_cents' => 1000]]], $ctx);

        $get = $this->registry()->dispatch('form.get', ['form_id' => $formId], $ctx);
        $this->assertSame('Cheers', $get->data['settings']['thank_you_message'], 'a partial settings patch must not drop other keys');
        $this->assertSame('amount', $get->data['settings']['goal']['type']);
    }

    public function test_form_create_seeds_a_template_and_rejects_fantasy_input(): void
    {
        $admin = self::factory()->user->create(['role' => 'administrator']);
        get_role('administrator')->add_cap('dono_manage_campaigns');
        get_role('administrator')->add_cap('dono_manage_forms');
        wp_set_current_user($admin);

        $ctx        = new CommandContext($admin, 'rest', 'req-' . uniqid());
        $campaignId = (int) $this->registry()->dispatch('campaign.create', ['title' => 'Tmpl Host'], $ctx)->data['campaign_id'];

        // Creating from a named template seeds that template's field blocks.
        $created = $this->registry()->dispatch('form.create', [
            'title'       => 'Quick Give copy',
            'template'    => 'quick-give',
            'campaign_id' => $campaignId,
        ], $ctx);
        $this->assertTrue($created->ok, $created->error ?? '');
        $get = $this->registry()->dispatch('form.get', ['form_id' => (int) $created->data['form_id']], $ctx);
        $this->assertContains('dono/donation-amount', $get->data['blocks'], 'template field blocks should be seeded');

        // An unknown template id is rejected by the enum.
        $badTpl = $this->registry()->dispatch('form.create', ['template' => 'no-such-template', 'campaign_id' => $campaignId], $ctx);
        $this->assertFalse($badTpl->ok);
        $this->assertSame('command.invalid_input', $badTpl->error_code);

        // A fantasy settings key is rejected, not silently stored.
        $badSettings = $this->registry()->dispatch('form.create', [
            'template'    => 'blank',
            'campaign_id' => $campaignId,
            'settings'    => ['currency' => 'EUR'],
        ], $ctx);
        $this->assertFalse($badSettings->ok);
        $this->assertSame('command.invalid_input', $badSettings->error_code);
    }

    public function test_donor_profile_is_typed_and_rejects_fantasy_keys(): void
    {
        $admin = self::factory()->user->create(['role' => 'administrator']);
        get_role('administrator')->add_cap('dono_edit_donors');
        wp_set_current_user($admin);

        $ctx = new CommandContext($admin, 'rest', 'req-' . uniqid());

        // The real, documented profile keys are accepted (including nested address).
        $ok = $this->registry()->dispatch('donor.find_or_create', [
            'email'   => 'typed-profile@example.com',
            'profile' => ['first_name' => 'Ada', 'country' => 'US', 'address' => ['city' => 'NYC', 'postal' => '10001']],
        ], $ctx);
        $this->assertTrue($ok->ok, $ok->error ?? '');

        // A made-up profile key is rejected, not silently ignored.
        $bad = $this->registry()->dispatch('donor.find_or_create', [
            'email'   => 'typed-profile2@example.com',
            'profile' => ['loyalty_points' => 999],
        ], $ctx);
        $this->assertFalse($bad->ok);
        $this->assertSame('command.invalid_input', $bad->error_code);
    }

    private function makeImageAttachment(): int
    {
        $png    = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
        $upload = wp_upload_bits('dono-cmd-test.png', null, $png);
        return (int) wp_insert_attachment([
            'post_mime_type' => 'image/png',
            'post_title'     => 'test',
            'post_status'    => 'inherit',
        ], $upload['file']);
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
