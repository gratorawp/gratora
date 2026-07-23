<?php

declare(strict_types=1);

namespace Dono\Core\Commands;

use Dono\Campaigns\CampaignMetricsService;
use Dono\Campaigns\CampaignRepository;
use Dono\Campaigns\CampaignService;
use Dono\Donations\AggregateSyncer;
use Dono\Donations\DonationIntent;
use Dono\Donations\DonationRepository;
use Dono\Donations\DonationService;
use Dono\Donors\ConsentService;
use Dono\Donors\DonorMetricsService;
use Dono\Donors\DonorRepository;
use Dono\Donors\DonorService;
use Dono\Donors\MagicLinkService;
use Dono\Foundation\Commands\Command;
use Dono\Foundation\Commands\CommandContext;
use Dono\Foundation\Commands\CommandError;
use Dono\Foundation\Commands\CommandRegistry;
use Dono\Foundation\Container\Container;
use Dono\Forms\FormRepository;
use Dono\Forms\FormService;
use Dono\Forms\FormTemplates;
use Dono\Foundation\Identity\IdentityHasher;
use Dono\Funds\FundRepository;
use Dono\Funds\FundService;
use Dono\Gateways\GatewayManager;
use Dono\Gateways\SubscriptionAware;
use Dono\Receipts\ReceiptIssuer;
use Dono\Recurring\RecurringCanceller;
use Dono\Recurring\RecurringPlan;

/**
 * Registers core domain operations as Command objects.
 *
 * @version 1.0.0
 */
final class CoreCommandProvider
{
    private const META = ['add_on' => 'core', 'add_on_label' => 'Dono'];

    public function register(CommandRegistry $r, Container $c): void
    {
        $this->donations($r, $c);
        $this->donors($r, $c);
        $this->campaigns($r, $c);
        $this->forms($r, $c);
        $this->funds($r, $c);
        $this->receipts($r, $c);
        $this->recurring($r, $c);
        $this->reads($r, $c);
    }

    private function donations(CommandRegistry $r, Container $c): void
    {
        $r->register(new Command(
            'donation.create',
            'Create a pending donation from an intent; upserts the donor.',
            $this->schema([
                'email'        => ['type' => 'string', 'format' => 'email'],
                'amount_cents' => ['type' => 'integer', 'minimum' => 1],
                'currency'     => ['type' => 'string', 'minLength' => 3, 'maxLength' => 3],
                'gateway'      => ['type' => 'string', 'minLength' => 1],
                'frequency'    => ['type' => 'string', 'enum' => ['one_time', 'recurring']],
                'form_id'      => ['type' => ['integer', 'null'], 'minimum' => 1],
                'campaign_id'  => ['type' => ['integer', 'null'], 'minimum' => 1],
                'fund_id'      => ['type' => ['integer', 'null'], 'minimum' => 1],
                'profile'      => $this->profileSchema(),
                'country'      => ['type' => ['string', 'null']],
                'note_to_org'  => ['type' => ['string', 'null']],
                'is_anonymous' => ['type' => 'boolean'],
            ], ['email', 'amount_cents', 'currency', 'gateway']),
            [],
            'dono_view_donations',
            false,
            true,
            function (array $in) use ($c): array {
                $svc = $c->get(DonationService::class);
                $res = $svc->createPending(new DonationIntent(
                    email: (string) $in['email'],
                    amount_cents: (int) $in['amount_cents'],
                    currency: (string) $in['currency'],
                    gateway: (string) $in['gateway'],
                    frequency: (string) ($in['frequency'] ?? 'one_time'),
                    form_id: isset($in['form_id']) ? (int) $in['form_id'] : null,
                    campaign_id: isset($in['campaign_id']) ? (int) $in['campaign_id'] : null,
                    fund_id: isset($in['fund_id']) ? (int) $in['fund_id'] : null,
                    profile: is_array($in['profile'] ?? null) ? $in['profile'] : [],
                    country: isset($in['country']) ? (string) $in['country'] : null,
                    note_to_org: isset($in['note_to_org']) ? (string) $in['note_to_org'] : null,
                    is_anonymous: (bool) ($in['is_anonymous'] ?? false),
                ));
                return [
                    'donation_id' => (int) $res['donation']->id,
                    'reference'   => (string) $res['donation']->reference,
                    'status'      => (string) $res['donation']->status,
                ];
            },
            self::META,
        ));

        $r->register(new Command(
            'donation.confirm',
            'Mark a pending donation paid; idempotent, syncs aggregates.',
            $this->schema([
                'donation_reference' => ['type' => 'string', 'minLength' => 1],
                'result'             => ['type' => 'object', 'description' => 'Raw gateway confirmation payload (transaction id, etc.). Supplied by the payment gateway, not composed by hand; omit it when confirming manually.'],
            ], ['donation_reference']),
            [],
            'dono_view_donations',
            true,
            true,
            function (array $in) use ($c): array {
                $donation = $c->get(DonationRepository::class)->findByReference((string) $in['donation_reference']);
                if (! $donation) {
                    throw new CommandError('Donation not found.');
                }
                $svc  = $c->get(DonationService::class);
                $done = $svc->confirm($donation, is_array($in['result'] ?? null) ? $in['result'] : []);
                return ['donation_id' => (int) $done->id, 'status' => (string) $done->status];
            },
            self::META,
        ));

        $r->register(new Command(
            'donation.mark_failed',
            'Fail a pending donation.',
            $this->schema([
                'donation_reference' => ['type' => 'string', 'minLength' => 1],
                'reason'             => ['type' => ['string', 'null']],
            ], ['donation_reference']),
            [],
            'dono_view_donations',
            true,
            true,
            function (array $in) use ($c): array {
                $donation = $c->get(DonationRepository::class)->findByReference((string) $in['donation_reference']);
                if (! $donation) {
                    throw new CommandError('Donation not found.');
                }
                $svc  = $c->get(DonationService::class);
                $done = $svc->markFailed($donation, isset($in['reason']) ? (string) $in['reason'] : null);
                return ['donation_id' => (int) $done->id, 'status' => (string) $done->status];
            },
            self::META,
        ));

        $r->register(new Command(
            'donation.refund',
            'Refund all or part of a paid donation; calls the gateway and voids receipts.',
            $this->schema([
                'donation_reference' => ['type' => 'string', 'minLength' => 1],
                'amount_cents'       => ['type' => 'integer', 'minimum' => 1],
                'reason'             => ['type' => ['string', 'null']],
            ], ['donation_reference', 'amount_cents']),
            $this->schema([
                'refund_id'      => ['type' => 'integer'],
                'is_full_refund' => ['type' => 'boolean'],
            ]),
            'dono_refund_donations',
            false,
            true,
            function (array $in, CommandContext $ctx) use ($c): array {
                $donation = $c->get(DonationRepository::class)->findByReference((string) $in['donation_reference']);
                if (! $donation) {
                    throw new CommandError('Donation not found.');
                }
                $refund = $c->get(DonationService::class)->refund(
                    $donation,
                    (int) $in['amount_cents'],
                    isset($in['reason']) ? (string) $in['reason'] : null,
                    $ctx->user_id,
                    'admin',
                );
                return [
                    'refund_id'      => (int) $refund->id,
                    'is_full_refund' => $donation->status === 'refunded',
                ];
            },
            self::META,
        ));

        $r->register(new Command(
            'donation.record_external_refund',
            'Reconcile a dashboard/dispute refund; idempotent on gateway_refund_id.',
            $this->schema([
                'donation_reference' => ['type' => 'string', 'minLength' => 1],
                'amount_cents'       => ['type' => 'integer', 'minimum' => 1],
                'gateway_refund_id'  => ['type' => 'string', 'minLength' => 1],
                'reason'             => ['type' => ['string', 'null']],
                'initiated_by'       => ['type' => 'string'],
            ], ['donation_reference', 'amount_cents', 'gateway_refund_id']),
            [],
            'dono_refund_donations',
            false,
            true,
            function (array $in) use ($c): array {
                $donation = $c->get(DonationRepository::class)->findByReference((string) $in['donation_reference']);
                if (! $donation) {
                    throw new CommandError('Donation not found.');
                }
                $refund = $c->get(DonationService::class)->recordExternalRefund(
                    $donation,
                    (int) $in['amount_cents'],
                    (string) $in['gateway_refund_id'],
                    isset($in['reason']) ? (string) $in['reason'] : null,
                    (string) ($in['initiated_by'] ?? 'gateway'),
                );
                return [
                    'refund_id' => (int) $refund->id,
                    'status'    => (string) $refund->status,
                ];
            },
            self::META,
        ));

        $r->register(new Command(
            'donation.aggregates.sync',
            'Recompute paid aggregates for a donor, campaign, fund, or form.',
            $this->schema([
                'donor_id'    => ['type' => ['integer', 'null'], 'minimum' => 1],
                'campaign_id' => ['type' => ['integer', 'null'], 'minimum' => 1],
                'fund_id'     => ['type' => ['integer', 'null'], 'minimum' => 1],
                'form_id'     => ['type' => ['integer', 'null'], 'minimum' => 1],
            ]),
            [],
            'dono_view_reports',
            true,
            true,
            function (array $in) use ($c): array {
                $agg    = $c->get(AggregateSyncer::class);
                $synced = [];
                if (! empty($in['donor_id'])) {
                    $agg->syncDonor((int) $in['donor_id']);
                    $synced[] = 'donor';
                }
                if (! empty($in['campaign_id'])) {
                    $agg->syncCampaign((int) $in['campaign_id']);
                    $synced[] = 'campaign';
                }
                if (! empty($in['fund_id'])) {
                    $agg->syncFund((int) $in['fund_id']);
                    $synced[] = 'fund';
                }
                if (! empty($in['form_id'])) {
                    $agg->syncForm((int) $in['form_id']);
                    $synced[] = 'form';
                }
                return ['synced' => $synced];
            },
            self::META,
        ));
    }

    private function donors(CommandRegistry $r, Container $c): void
    {
        $r->register(new Command(
            'donor.find_or_create',
            'Find a donor by email or create one; back-fills empty profile fields.',
            $this->schema([
                'email'   => ['type' => 'string', 'format' => 'email'],
                'profile' => $this->profileSchema(),
            ], ['email']),
            [],
            'dono_edit_donors',
            false,
            true,
            function (array $in) use ($c): array {
                $donor = $c->get(DonorService::class)->findOrCreate(
                    (string) $in['email'],
                    is_array($in['profile'] ?? null) ? $in['profile'] : [],
                );
                return ['donor_id' => (int) $donor->id];
            },
            self::META,
        ));

        $r->register(new Command(
            'donor.refresh_profile',
            'Back-fill only empty donor profile fields from a payload.',
            $this->schema([
                'donor_id' => ['type' => 'integer', 'minimum' => 1],
                'profile'  => $this->profileSchema(),
            ], ['donor_id', 'profile']),
            [],
            'dono_edit_donors',
            true,
            true,
            function (array $in) use ($c): array {
                $donor = $c->get(DonorRepository::class)->findById((int) $in['donor_id']);
                if (! $donor) {
                    throw new CommandError('Donor not found.');
                }
                $updated = $c->get(DonorService::class)->refreshProfile(
                    $donor,
                    is_array($in['profile'] ?? null) ? $in['profile'] : [],
                );
                return ['donor_id' => (int) $updated->id];
            },
            self::META,
        ));

        $r->register(new Command(
            'donor.change_email',
            'Admin email change; recomputes hash and encrypted email.',
            $this->schema([
                'donor_id'  => ['type' => 'integer', 'minimum' => 1],
                'new_email' => ['type' => 'string', 'format' => 'email'],
            ], ['donor_id', 'new_email']),
            [],
            'dono_edit_donors',
            true,
            true,
            function (array $in) use ($c): array {
                $donor = $c->get(DonorRepository::class)->findById((int) $in['donor_id']);
                if (! $donor) {
                    throw new CommandError('Donor not found.');
                }
                $updated = $c->get(DonorService::class)->changeEmail($donor, (string) $in['new_email']);
                return ['donor_id' => (int) $updated->id];
            },
            self::META,
        ));

        $r->register(new Command(
            'donor.redact',
            'GDPR soft-redact: zero PII, preserve the row and donation totals.',
            $this->schema([
                'donor_id' => ['type' => 'integer', 'minimum' => 1],
            ], ['donor_id']),
            [],
            'dono_redact_donors',
            true,
            true,
            function (array $in) use ($c): array {
                $donor = $c->get(DonorRepository::class)->findById((int) $in['donor_id']);
                if (! $donor) {
                    throw new CommandError('Donor not found.');
                }
                $redacted = $c->get(DonorService::class)->redact($donor);
                return [
                    'donor_id'    => (int) $redacted->id,
                    'redacted_at' => $redacted->redacted_at,
                ];
            },
            self::META,
        ));

        $r->register(new Command(
            'donor.consent.record',
            'Record a consent grant or withdrawal for a purpose.',
            $this->schema([
                'donor_id'    => ['type' => 'integer', 'minimum' => 1],
                'purpose_key' => ['type' => 'string', 'minLength' => 1],
                'granted'     => ['type' => 'boolean'],
                'context'     => [
                    'type'                 => ['object', 'null'],
                    'additionalProperties' => false,
                    'description'          => 'Optional audit metadata for where the consent came from. Only these keys are recorded.',
                    'properties'           => [
                        'source' => ['type' => 'string', 'description' => 'Origin of the consent record, e.g. "admin" or "form". Defaults to "admin".'],
                        'ip'     => ['type' => 'string', 'description' => 'Donor IP address; stored only as a salted hash.'],
                    ],
                ],
            ], ['donor_id', 'purpose_key', 'granted']),
            [],
            'dono_edit_donors',
            false,
            true,
            function (array $in) use ($c): array {
                $consent = $c->get(ConsentService::class)->record(
                    (int) $in['donor_id'],
                    (string) $in['purpose_key'],
                    (bool) $in['granted'],
                    is_array($in['context'] ?? null) ? $in['context'] : [],
                );
                return [
                    'consent_id' => (int) $consent->id,
                    'granted'    => (bool) $consent->granted,
                ];
            },
            self::META,
        ));

        $r->register(new Command(
            'donor.magic_link.issue',
            'Issue a single-use magic-link token for a donor and purpose.',
            $this->schema([
                'donor_id'    => ['type' => 'integer', 'minimum' => 1],
                'purpose'     => ['type' => 'string', 'minLength' => 1],
                'target_id'   => ['type' => ['integer', 'null'], 'minimum' => 1],
                'ttl_seconds' => ['type' => 'integer', 'minimum' => 1],
            ], ['donor_id', 'purpose']),
            [],
            'dono_edit_donors',
            false,
            true,
            function (array $in) use ($c): array {
                $token = $c->get(MagicLinkService::class)->issue(
                    (int) $in['donor_id'],
                    (string) $in['purpose'],
                    isset($in['target_id']) ? (int) $in['target_id'] : null,
                    isset($in['ttl_seconds']) ? (int) $in['ttl_seconds'] : 2_592_000,
                );
                return ['token' => $token];
            },
            self::META,
        ));
    }

    private function campaigns(CommandRegistry $r, Container $c): void
    {
        $r->register(new Command(
            'campaign.create',
            'Create a campaign with its default form and page.',
            $this->schema([
                'title'       => ['type' => 'string'],
                'slug'        => ['type' => 'string'],
                'status'      => ['type' => 'string', 'enum' => ['draft', 'published', 'archived']],
                'description' => ['type' => ['string', 'null']],
                'currency'    => ['type' => 'string'],
                'goal_type'   => ['type' => 'string', 'enum' => ['amount', 'donations', 'donors']],
                'goal_cents'  => ['type' => ['integer', 'null'], 'minimum' => 0],
                'goal_count'  => ['type' => ['integer', 'null'], 'minimum' => 0],
                'campaign_type' => ['type' => 'string', 'description' => 'Campaign type slug from the campaign-type registry (e.g. standard, peer_to_peer). Unknown values fall back to standard.'],
                'image_attachment_id' => ['type' => ['integer', 'null'], 'minimum' => 1, 'description' => 'Media-library attachment ID to use as the campaign photo.'],
            ]),
            [],
            'dono_manage_campaigns',
            false,
            true,
            function (array $in) use ($c): array {
                $campaign = $c->get(CampaignService::class)->create($in);
                return ['campaign_id' => (int) $campaign->id, 'slug' => (string) $campaign->slug];
            },
            self::META,
        ));

        $r->register(new Command(
            'campaign.update',
            'Update a campaign; partial update of supplied fields.',
            $this->schema([
                'campaign_id' => ['type' => 'integer', 'minimum' => 1],
                'title'       => ['type' => 'string'],
                'slug'        => ['type' => 'string'],
                'status'      => ['type' => 'string', 'enum' => ['draft', 'published', 'archived']],
                'description' => ['type' => ['string', 'null']],
                'goal_cents'  => ['type' => ['integer', 'null'], 'minimum' => 0],
                'goal_count'  => ['type' => ['integer', 'null'], 'minimum' => 0],
                'campaign_type' => ['type' => 'string', 'description' => 'Campaign type slug from the campaign-type registry. Only a standard campaign can be promoted to another type; ignored otherwise.'],
                'image_attachment_id' => ['type' => ['integer', 'null'], 'minimum' => 1, 'description' => 'Media-library attachment ID to use as the campaign photo.'],
            ], ['campaign_id']),
            [],
            'dono_manage_campaigns',
            true,
            true,
            function (array $in) use ($c): array {
                $campaign = $c->get(CampaignRepository::class)->findById((int) $in['campaign_id']);
                if (! $campaign) {
                    throw new CommandError('Campaign not found.');
                }
                unset($in['campaign_id']);
                $updated = $c->get(CampaignService::class)->update($campaign, $in);
                return ['campaign_id' => (int) $updated->id];
            },
            self::META,
        ));

        $r->register(new Command(
            'campaign.delete',
            'Delete a campaign and its linked page.',
            $this->schema([
                'campaign_id' => ['type' => 'integer', 'minimum' => 1],
            ], ['campaign_id']),
            [],
            'dono_manage_campaigns',
            true,
            true,
            function (array $in) use ($c): array {
                $campaign = $c->get(CampaignRepository::class)->findById((int) $in['campaign_id']);
                if (! $campaign) {
                    throw new CommandError('Campaign not found.');
                }
                $c->get(CampaignService::class)->delete($campaign);
                return ['campaign_id' => (int) $in['campaign_id'], 'deleted' => true];
            },
            self::META,
        ));

        $r->register(new Command(
            'campaign.duplicate',
            'Duplicate a campaign and its default form.',
            $this->schema([
                'campaign_id' => ['type' => 'integer', 'minimum' => 1],
            ], ['campaign_id']),
            [],
            'dono_manage_campaigns',
            false,
            true,
            function (array $in) use ($c): array {
                $source = $c->get(CampaignRepository::class)->findById((int) $in['campaign_id']);
                if (! $source) {
                    throw new CommandError('Campaign not found.');
                }
                $copy = $c->get(CampaignService::class)->duplicate($source);
                return ['campaign_id' => (int) $copy->id, 'slug' => (string) $copy->slug];
            },
            self::META,
        ));
    }

    private function forms(CommandRegistry $r, Container $c): void
    {
        $templates    = FormTemplates::all();
        $templateIds  = array_values(array_filter(array_map(static fn (array $t): string => (string) ($t['id'] ?? ''), $templates)));
        $templateList = implode('; ', array_map(
            static fn (array $t): string => sprintf('%s (%s)', (string) ($t['id'] ?? ''), wp_strip_all_tags((string) ($t['name'] ?? ''))),
            $templates
        ));

        $r->register(new Command(
            'form.create',
            'Create a donation form from a built-in template.',
            $this->schema([
                'title'       => ['type' => 'string'],
                'slug'        => ['type' => 'string'],
                'status'      => ['type' => 'string', 'enum' => ['draft', 'published', 'archived']],
                'template'    => [
                    'type'        => 'string',
                    'enum'        => $templateIds ?: ['blank'],
                    'description' => 'Which built-in template to start from (its field layout and default settings). Options: ' . $templateList . '. Defaults to "blank".',
                ],
                'settings'    => $this->formSettingsSchema(),
                'campaign_id' => ['type' => ['integer', 'null'], 'minimum' => 1],
            ]),
            [],
            'dono_manage_forms',
            false,
            true,
            function (array $in) use ($c): array {
                $templateId = (string) ($in['template'] ?? 'blank');
                $template   = FormTemplates::find($templateId) ?? FormTemplates::find('blank');
                unset($in['template']);
                // Layout comes from the trusted template; the agent may only
                // override typed settings on top of the template defaults.
                $in['blocks']   = (string) ($template['blocks'] ?? '');
                $base           = is_array($template['settings'] ?? null) ? $template['settings'] : [];
                $override       = is_array($in['settings'] ?? null) ? $in['settings'] : [];
                $in['settings'] = array_replace($base, $override);
                $form = $c->get(FormService::class)->create($in);
                return ['form_id' => (int) $form->id, 'slug' => (string) $form->slug, 'template' => $templateId];
            },
            $this->meta(['agent_hint' => 'The field layout comes from the template, not raw block markup you author. Pick the closest template; it can be refined in the form builder afterwards.']),
        ));

        $r->register(new Command(
            'form.update',
            'Update a donation form\'s title, slug, status, or settings.',
            $this->schema([
                'form_id'  => ['type' => 'integer', 'minimum' => 1],
                'title'    => ['type' => 'string'],
                'slug'     => ['type' => 'string'],
                'status'   => ['type' => 'string', 'enum' => ['draft', 'published', 'archived']],
                'settings' => $this->formSettingsSchema(),
            ], ['form_id']),
            [],
            'dono_manage_forms',
            true,
            true,
            function (array $in) use ($c): array {
                $form = $c->get(FormRepository::class)->findById((int) $in['form_id']);
                if (! $form) {
                    throw new CommandError('Form not found.');
                }
                unset($in['form_id']);
                // Saving replaces settings wholesale; merge the patch over what is
                // already stored so a partial update never drops other keys.
                if (array_key_exists('settings', $in) && is_array($in['settings'])) {
                    $existing = is_array($form->settings) ? $form->settings : [];
                    $in['settings'] = array_replace($existing, $in['settings']);
                }
                $updated = $c->get(FormService::class)->update($form, $in);
                return [
                    'form_id'  => (int) $updated->id,
                    'status'   => (string) $updated->status,
                    'settings' => $updated->settings ?: (object) [],
                ];
            },
            $this->meta(['agent_hint' => 'The field layout (blocks) is designed in the form builder, not here. Read the form with form.get first so you act on its real structure. Settings are replaced wholesale, so send the full settings object.']),
        ));

        $r->register(new Command(
            'form.get',
            'Read a donation form: its status, settings, and field-block structure.',
            $this->schema(['form_id' => ['type' => 'integer', 'minimum' => 1]], ['form_id']),
            [],
            'dono_manage_forms',
            true,
            false,
            function (array $in) use ($c): array {
                $form = $c->get(FormRepository::class)->findById((int) $in['form_id']);
                if (! $form) {
                    throw new CommandError('Form not found.');
                }
                $blocks = [];
                foreach (parse_blocks((string) $form->blocks) as $block) {
                    if (! empty($block['blockName'])) {
                        $blocks[] = (string) $block['blockName'];
                    }
                }
                return [
                    'form_id'     => (int) $form->id,
                    'title'       => (string) $form->title,
                    'status'      => (string) $form->status,
                    'campaign_id' => (int) $form->campaign_id,
                    'settings'    => $form->settings ?: (object) [],
                    'blocks'      => $blocks,
                ];
            },
            $this->meta(['agent_hint' => 'Use before editing a form so you work from its real structure, not assumptions.']),
        ));

        $r->register(new Command(
            'form.delete',
            'Delete a donation form.',
            $this->schema([
                'form_id' => ['type' => 'integer', 'minimum' => 1],
            ], ['form_id']),
            [],
            'dono_manage_forms',
            true,
            true,
            function (array $in) use ($c): array {
                $form = $c->get(FormRepository::class)->findById((int) $in['form_id']);
                if (! $form) {
                    throw new CommandError('Form not found.');
                }
                $c->get(FormService::class)->delete($form);
                return ['form_id' => (int) $in['form_id'], 'deleted' => true];
            },
            self::META,
        ));

        $r->register(new Command(
            'form.duplicate',
            'Duplicate a donation form.',
            $this->schema([
                'form_id' => ['type' => 'integer', 'minimum' => 1],
            ], ['form_id']),
            [],
            'dono_manage_forms',
            false,
            true,
            function (array $in) use ($c): array {
                $source = $c->get(FormRepository::class)->findById((int) $in['form_id']);
                if (! $source) {
                    throw new CommandError('Form not found.');
                }
                $copy = $c->get(FormService::class)->duplicate($source);
                return ['form_id' => (int) $copy->id, 'slug' => (string) $copy->slug];
            },
            self::META,
        ));
    }

    private function funds(CommandRegistry $r, Container $c): void
    {
        $r->register(new Command(
            'fund.create',
            'Create a fund.',
            $this->schema([
                'code'          => ['type' => 'string', 'minLength' => 1, 'maxLength' => 64],
                'name'          => ['type' => 'string', 'minLength' => 1, 'maxLength' => 150],
                'description'   => ['type' => ['string', 'null']],
                'is_restricted' => ['type' => ['boolean', 'null']],
                'is_default'    => ['type' => ['boolean', 'null']],
                'is_active'     => ['type' => ['boolean', 'null']],
                'goal_cents'    => ['type' => ['integer', 'null'], 'minimum' => 0],
            ], ['code', 'name']),
            [],
            'dono_manage_settings',
            false,
            true,
            function (array $in) use ($c): array {
                $fund = $c->get(FundService::class)->create($in);
                return ['fund_id' => (int) $fund->id, 'code' => (string) $fund->code];
            },
            self::META,
        ));

        $r->register(new Command(
            'fund.update',
            'Update a fund; partial update of supplied fields.',
            $this->schema([
                'fund_id'       => ['type' => 'integer', 'minimum' => 1],
                'name'          => ['type' => 'string', 'minLength' => 1, 'maxLength' => 150],
                'description'   => ['type' => ['string', 'null']],
                'is_restricted' => ['type' => ['boolean', 'null']],
                'is_default'    => ['type' => ['boolean', 'null']],
                'is_active'     => ['type' => ['boolean', 'null']],
                'goal_cents'    => ['type' => ['integer', 'null'], 'minimum' => 0],
            ], ['fund_id']),
            [],
            'dono_manage_settings',
            true,
            true,
            function (array $in) use ($c): array {
                $fund = $c->get(FundRepository::class)->findById((int) $in['fund_id']);
                if (! $fund) {
                    throw new CommandError('Fund not found.');
                }
                unset($in['fund_id']);
                $updated = $c->get(FundService::class)->update($fund, $in);
                return ['fund_id' => (int) $updated->id];
            },
            self::META,
        ));

        $r->register(new Command(
            'fund.delete',
            'Delete a fund, optionally reassigning its donations.',
            $this->schema([
                'fund_id'     => ['type' => 'integer', 'minimum' => 1],
                'reassign_to' => ['type' => ['integer', 'null'], 'minimum' => 1],
            ], ['fund_id']),
            [],
            'dono_manage_settings',
            true,
            true,
            function (array $in) use ($c): array {
                $fund = $c->get(FundRepository::class)->findById((int) $in['fund_id']);
                if (! $fund) {
                    throw new CommandError('Fund not found.');
                }
                $result = $c->get(FundService::class)->delete(
                    $fund,
                    isset($in['reassign_to']) ? (int) $in['reassign_to'] : null,
                );
                return ['fund_id' => (int) $in['fund_id'], 'deleted' => true, 'detail' => $result];
            },
            self::META,
        ));
    }

    private function receipts(CommandRegistry $r, Container $c): void
    {
        $r->register(new Command(
            'receipt.requeue',
            'Requeue receipt issuance for a paid donation (admin resend).',
            $this->schema([
                'donation_id' => ['type' => 'integer', 'minimum' => 1],
            ], ['donation_id']),
            [],
            'dono_resend_receipt',
            true,
            true,
            function (array $in) use ($c): array {
                $queued = $c->get(ReceiptIssuer::class)->requeueForDonation((int) $in['donation_id']);
                return ['queued' => $queued];
            },
            self::META,
        ));

        $r->register(new Command(
            'receipt.render_pdf',
            'Regenerate the PDF for an issued receipt and return its path.',
            $this->schema([
                'receipt_id' => ['type' => 'integer', 'minimum' => 1],
            ], ['receipt_id']),
            [],
            'dono_resend_receipt',
            true,
            true,
            function (array $in) use ($c): array {
                $path = $c->get(ReceiptIssuer::class)->renderReceiptPdf((int) $in['receipt_id']);
                if ($path === null) {
                    throw new CommandError('Receipt or renderer not found.');
                }
                return ['path' => $path];
            },
            self::META,
        ));
    }

    private function recurring(CommandRegistry $r, Container $c): void
    {
        $r->register(new Command(
            'recurring.cancel',
            'Cancel a recurring plan at the gateway and locally.',
            $this->schema([
                'plan_id' => ['type' => 'integer', 'minimum' => 1],
                'reason'  => ['type' => ['string', 'null']],
            ], ['plan_id']),
            [],
            'dono_view_donations',
            false,
            true,
            function (array $in) use ($c): array {
                [$plan] = $this->resolvePlan($c, (int) $in['plan_id']);
                $reason = isset($in['reason']) ? (string) $in['reason'] : null;
                // Gateway cancel + winner-gated local side effects (one email
                // even if the gateway's subscription.deleted webhook races).
                $c->get(RecurringCanceller::class)->cancel($plan, $reason);
                return ['plan_id' => (int) $plan->id, 'status' => (string) $plan->status];
            },
            self::META,
        ));

        $r->register(new Command(
            'recurring.pause',
            'Pause a recurring plan, optionally until a resume date.',
            $this->schema([
                'plan_id'    => ['type' => 'integer', 'minimum' => 1],
                'resumes_at' => ['type' => ['string', 'null']],
            ], ['plan_id']),
            [],
            'dono_view_donations',
            true,
            true,
            function (array $in) use ($c): array {
                [$plan, $sub] = $this->resolvePlan($c, (int) $in['plan_id']);
                $resumesAt = isset($in['resumes_at']) ? (string) $in['resumes_at'] : null;
                if ($sub) {
                    $sub->pauseSubscription($plan, $resumesAt);
                }
                $plan->status = 'paused';
                if ($resumesAt !== null) {
                    $plan->next_payment_at = $resumesAt;
                }
                $plan->save();
                do_action('dono.recurring.plan_paused', $plan);
                return ['plan_id' => (int) $plan->id, 'status' => (string) $plan->status];
            },
            self::META,
        ));

        $r->register(new Command(
            'recurring.resume',
            'Resume a paused recurring plan.',
            $this->schema([
                'plan_id' => ['type' => 'integer', 'minimum' => 1],
            ], ['plan_id']),
            [],
            'dono_view_donations',
            true,
            true,
            function (array $in) use ($c): array {
                [$plan, $sub] = $this->resolvePlan($c, (int) $in['plan_id']);
                if ($sub) {
                    $sub->resumeSubscription($plan);
                }
                $plan->status = 'active';
                $plan->save();
                do_action('dono.recurring.plan_resumed', $plan);
                return ['plan_id' => (int) $plan->id, 'status' => (string) $plan->status];
            },
            self::META,
        ));

        $r->register(new Command(
            'recurring.update_amount',
            'Change the charge amount of a recurring plan.',
            $this->schema([
                'plan_id'      => ['type' => 'integer', 'minimum' => 1],
                'amount_cents' => ['type' => 'integer', 'minimum' => 1],
            ], ['plan_id', 'amount_cents']),
            [],
            'dono_view_donations',
            true,
            true,
            function (array $in) use ($c): array {
                [$plan, $sub] = $this->resolvePlan($c, (int) $in['plan_id']);
                $amount = (int) $in['amount_cents'];
                if ($sub) {
                    $sub->updateSubscriptionAmount($plan, $amount);
                }
                $plan->amount_cents = $amount;
                $plan->save();
                do_action('dono.recurring.plan_amount_changed', $plan);
                return ['plan_id' => (int) $plan->id, 'amount_cents' => (int) $plan->amount_cents];
            },
            self::META,
        ));
    }

    private function reads(CommandRegistry $r, Container $c): void
    {
        $r->register(new Command(
            'donation.get',
            'Read a donation by reference (no PII).',
            $this->schema([
                'donation_reference' => ['type' => 'string', 'minLength' => 1],
            ], ['donation_reference']),
            [],
            'dono_view_donations',
            true,
            false,
            function (array $in) use ($c): array {
                $donation = $c->get(DonationRepository::class)->findByReference((string) $in['donation_reference']);
                if (! $donation) {
                    throw new CommandError('Donation not found.');
                }
                return [
                    'donation_id'  => (int) $donation->id,
                    'reference'    => (string) $donation->reference,
                    'status'       => (string) $donation->status,
                    'amount_cents' => (int) $donation->amount_cents,
                    'currency'     => (string) $donation->currency,
                    'donor_id'     => (int) $donation->donor_id,
                    'campaign_id'  => $donation->campaign_id !== null ? (int) $donation->campaign_id : null,
                    'form_id'      => $donation->form_id !== null ? (int) $donation->form_id : null,
                    'created_at'   => (string) $donation->created_at,
                ];
            },
            self::META,
        ));

        $r->register(new Command(
            'donor.get',
            'Read a donor by id (no PII; aggregates only).',
            $this->schema([
                'donor_id' => ['type' => 'integer', 'minimum' => 1],
            ], ['donor_id']),
            [],
            'dono_view_donors',
            true,
            false,
            function (array $in) use ($c): array {
                $donor = $c->get(DonorRepository::class)->findById((int) $in['donor_id']);
                if (! $donor) {
                    throw new CommandError('Donor not found.');
                }
                return [
                    'donor_id'            => (int) $donor->id,
                    'donations_count'     => (int) $donor->donations_count,
                    'total_donated_cents' => (int) $donor->total_donated_cents,
                    'first_donation_at'   => $donor->first_donation_at,
                    'last_donation_at'    => $donor->last_donation_at,
                    'redacted'            => $donor->redacted_at !== null,
                ];
            },
            self::META,
        ));

        $r->register(new Command(
            'campaign.metrics',
            'Campaign reporting summary for a date range.',
            $this->schema([
                'campaign_id' => ['type' => 'integer', 'minimum' => 1],
                'range'       => ['type' => 'string', 'minLength' => 1],
            ], ['campaign_id']),
            [],
            'dono_view_reports',
            true,
            false,
            function (array $in) use ($c): array {
                $summary = $c->get(CampaignMetricsService::class)->summary(
                    (int) $in['campaign_id'],
                    (string) ($in['range'] ?? 'all-time'),
                );
                return ['summary' => $summary];
            },
            self::META,
        ));

        $r->register(new Command(
            'donor.insights',
            'Donor-base lifecycle, RFM, LTV, and retention insights.',
            [],
            [],
            'dono_view_reports',
            true,
            false,
            fn (): array => ['insights' => $c->get(DonorMetricsService::class)->insights()],
            self::META,
        ));

        // Read/list commands: the assistant's eyes. Paged, cap-gated, and never
        // surfacing raw donor PII in a bulk listing (donor identity is its own
        // dono_view_donors command). All are non-mutating + idempotent, so they
        // skip the confirmation gate entirely.
        $r->register(new Command(
            'campaign.list',
            'List campaigns (paged, newest first); filter by status or search text.',
            $this->listSchema(['status' => ['type' => 'string', 'enum' => ['draft', 'published', 'archived']]]),
            [],
            'dono_manage_campaigns',
            true,
            false,
            function (array $in) use ($c): array {
                $res = $c->get(CampaignRepository::class)->listAdmin($in);
                return $this->page($in, array_map(static fn ($m): array => [
                    'id'              => (int) $m->id,
                    'title'           => (string) $m->title,
                    'slug'            => (string) $m->slug,
                    'status'          => (string) $m->status,
                    'campaign_type'   => (string) $m->campaign_type,
                    'goal_type'       => (string) $m->goal_type,
                    'goal_cents'      => $m->goal_cents,
                    'goal_count'      => $m->goal_count,
                    'currency'        => (string) $m->currency,
                    'raised_cents'    => (int) $m->raised_cents,
                    'donations_count' => (int) $m->donations_count,
                    'donors_count'    => (int) $m->donors_count,
                ], $res['items']), $res['total']);
            },
            self::META,
        ));

        $r->register(new Command(
            'fund.list',
            'List funds (paged); filter by search text.',
            $this->listSchema(),
            [],
            'dono_manage_campaigns',
            true,
            false,
            function (array $in) use ($c): array {
                $res = $c->get(FundRepository::class)->listAdmin($in);
                return $this->page($in, array_map(static fn ($m): array => [
                    'id'        => (int) $m->id,
                    'code'      => (string) $m->code,
                    'name'      => (string) $m->name,
                    'is_active' => (bool) $m->is_active,
                ], $res['items']), $res['total']);
            },
            self::META,
        ));

        $r->register(new Command(
            'form.list',
            'List donation forms (paged); filter by status, campaign, or search text.',
            $this->listSchema([
                'status'      => ['type' => 'string'],
                'campaign_id' => ['type' => 'integer', 'minimum' => 1],
            ]),
            [],
            'dono_manage_forms',
            true,
            false,
            function (array $in) use ($c): array {
                $res = $c->get(FormRepository::class)->listAdmin($in);
                return $this->page($in, array_map(static fn ($m): array => [
                    'id'          => (int) $m->id,
                    'title'       => (string) $m->title,
                    'slug'        => (string) $m->slug,
                    'status'      => (string) $m->status,
                    'form_type'   => (string) $m->form_type,
                    'campaign_id' => (int) $m->campaign_id,
                ], $res['items']), $res['total']);
            },
            self::META,
        ));

        $r->register(new Command(
            'donation.list',
            'List donations (paged, newest first); filter by status, campaign, or search. Carries donor_id but never donor name or email.',
            $this->listSchema([
                'status'      => ['type' => 'string'],
                'campaign_id' => ['type' => 'integer', 'minimum' => 1],
            ]),
            [],
            'dono_view_donations',
            true,
            false,
            function (array $in) use ($c): array {
                $res = $c->get(DonationRepository::class)->listAdmin($in);
                return $this->page($in, array_map(static fn ($m): array => [
                    'id'           => (int) $m->id,
                    'reference'    => (string) $m->reference,
                    'amount_cents' => (int) $m->amount_cents,
                    'currency'     => (string) $m->currency,
                    'status'       => (string) $m->status,
                    'gateway'      => (string) $m->gateway,
                    'frequency'    => (string) $m->frequency,
                    'campaign_id'  => $m->campaign_id,
                    'fund_id'      => $m->fund_id,
                    'donor_id'     => (int) $m->donor_id,
                    'is_anonymous' => (bool) $m->is_anonymous,
                    'is_test'      => (bool) $m->is_test,
                    'created_at'   => (string) $m->created_at,
                ], $res['items']), $res['total']);
            },
            self::META,
        ));

        $r->register(new Command(
            'donor.list',
            'List donors (paged) with name, email, and lifetime totals. Returns PII; gated on dono_view_donors.',
            $this->listSchema(),
            [],
            'dono_view_donors',
            true,
            false,
            function (array $in) use ($c): array {
                $res     = $c->get(DonorRepository::class)->listAdmin($in);
                $service = $c->get(DonorService::class);
                return $this->page($in, array_map(static fn ($m): array => [
                    'id'                  => (int) $m->id,
                    'name'                => self::donorName($m),
                    'email'               => $service->decryptEmail($m) ?: null,
                    'donations_count'     => (int) $m->donations_count,
                    'total_donated_cents' => (int) $m->total_donated_cents,
                    'last_donation_at'    => $m->last_donation_at,
                ], $res['items']), $res['total']);
            },
            self::META,
        ));

        $r->register(new Command(
            'donor.find_by_email',
            'Look up a single donor by email address. Returns PII; gated on dono_view_donors.',
            $this->schema([
                'email' => ['type' => 'string', 'format' => 'email', 'minLength' => 3],
            ], ['email']),
            [],
            'dono_view_donors',
            true,
            false,
            function (array $in) use ($c): array {
                $hash  = $c->get(IdentityHasher::class)->emailHash((string) $in['email']);
                $donor = $c->get(DonorRepository::class)->findByEmailHash($hash);
                if (! $donor) {
                    return ['found' => false, 'donor' => null];
                }
                $service = $c->get(DonorService::class);
                return [
                    'found' => true,
                    'donor' => [
                        'id'                  => (int) $donor->id,
                        'name'                => self::donorName($donor),
                        'email'               => $service->decryptEmail($donor) ?: null,
                        'donations_count'     => (int) $donor->donations_count,
                        'total_donated_cents' => (int) $donor->total_donated_cents,
                        'last_donation_at'    => $donor->last_donation_at,
                    ],
                ];
            },
            self::META,
        ));

        $r->register(new Command(
            'report.revenue',
            'Paid-donation revenue totals for an optional date range and campaign.',
            $this->schema([
                'from'        => ['type' => ['string', 'null'], 'format' => 'date'],
                'to'          => ['type' => ['string', 'null'], 'format' => 'date'],
                'campaign_id' => ['type' => ['integer', 'null'], 'minimum' => 1],
            ]),
            [],
            'dono_view_reports',
            true,
            false,
            function (array $in) use ($c): array {
                $repo       = $c->get(DonationRepository::class);
                $from       = $in['from'] ?? null;
                $to         = $in['to'] ?? null;
                $campaignId = isset($in['campaign_id']) ? (int) $in['campaign_id'] : null;
                $agg        = $repo->aggregatePaidBetween($from, $to, $campaignId);
                return [
                    'amount_cents'    => (int) ($agg['amount_cents'] ?? 0),
                    'donations_count' => (int) ($agg['donations_count'] ?? 0),
                    'donors_count'    => (int) ($agg['donors_count'] ?? 0),
                    'currency'        => $repo->topCurrencyForPaid($from, $to),
                    'from'            => $from,
                    'to'              => $to,
                    'campaign_id'     => $campaignId,
                ];
            },
            self::META,
        ));
    }

    /**
     * @param array<string,array<string,mixed>> $properties
     * @param list<string>                       $required
     * @return array<string,mixed>
     */
    private function schema(array $properties, array $required = []): array
    {
        $schema = [
            'type'                 => 'object',
            'properties'           => $properties,
            'additionalProperties' => false,
        ];
        if ($required !== []) {
            $schema['required'] = $required;
        }
        return $schema;
    }

    /**
     * Command meta. `agent_hint` is guidance sent only to the model (appended to
     * the tool description); it never appears in the human-facing Tools tab,
     * which shows the plain `summary`. Keeps operator-facing copy clean while
     * still steering the assistant.
     *
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    private function meta(array $extra = []): array
    {
        return array_merge(self::META, $extra);
    }

    /**
     * Common paged-list input: page + per_page + search, plus any $extra filters.
     *
     * @param array<string,array<string,mixed>> $extra
     * @return array<string,mixed>
     */
    private function listSchema(array $extra = []): array
    {
        return $this->schema(array_merge([
            'page'     => ['type' => 'integer', 'minimum' => 1],
            'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
            'search'   => ['type' => 'string'],
        ], $extra));
    }

    /**
     * Wrap projected list rows with echoed pagination, mirroring the per_page
     * clamp the repositories apply (1..100, default 25).
     *
     * @param array<string,mixed> $in
     * @param list<array<string,mixed>> $items
     * @return array{items: list<array<string,mixed>>, total: int, page: int, per_page: int}
     */
    private function page(array $in, array $items, int $total): array
    {
        return [
            'items'    => $items,
            'total'    => $total,
            'page'     => max(1, (int) ($in['page'] ?? 1)),
            'per_page' => max(1, min(100, (int) ($in['per_page'] ?? 25))),
        ];
    }

    /** Display name from the plaintext first/last columns; email stays encrypted. */
    private static function donorName(object $donor): string
    {
        $full = trim(((string) ($donor->first_name ?? '')) . ' ' . ((string) ($donor->last_name ?? '')));
        return $full !== '' ? $full : '-';
    }

    /**
     * The only donor-profile keys the platform accepts. Typed strictly so the
     * agent sees exactly what is settable and cannot pass fields that would be
     * silently ignored.
     *
     * @return array<string,mixed>
     */
    private function profileSchema(): array
    {
        return [
            'type'                 => ['object', 'null'],
            'additionalProperties' => false,
            'description'          => 'Donor profile. Only these keys are accepted.',
            'properties'           => [
                'first_name' => ['type' => ['string', 'null']],
                'last_name'  => ['type' => ['string', 'null']],
                'company'    => ['type' => ['string', 'null']],
                'locale'     => ['type' => ['string', 'null']],
                'country'    => ['type' => ['string', 'null'], 'description' => 'ISO 3166-1 alpha-2 country code.'],
                'donor_type' => ['type' => 'string', 'enum' => ['individual', 'organization']],
                'phone'      => ['type' => ['string', 'null']],
                'address'    => [
                    'type'                 => ['object', 'null'],
                    'additionalProperties' => false,
                    'properties'           => [
                        'line1'   => ['type' => ['string', 'null']],
                        'line2'   => ['type' => ['string', 'null']],
                        'city'    => ['type' => ['string', 'null']],
                        'region'  => ['type' => ['string', 'null']],
                        'postal'  => ['type' => ['string', 'null']],
                        'country' => ['type' => ['string', 'null']],
                    ],
                ],
            ],
        ];
    }

    /**
     * The complete set of form-settings keys the platform reads. Typed strictly
     * so the agent cannot invent settings (there is no currency setting: Dono
     * uses one org currency) and knows the real goal/recurring shapes. The
     * gateways list is written by the payment-gateways block, so it is not
     * settable here.
     *
     * @return array<string,mixed>
     */
    private function formSettingsSchema(): array
    {
        return [
            'type'                 => ['object', 'null'],
            'additionalProperties' => false,
            'description'          => 'Form settings. Only these keys exist. There is no currency setting; Dono uses a single org currency. Send the full object (read it first with form.get): saving replaces settings wholesale.',
            'properties'           => [
                'layout'            => ['type' => 'string', 'description' => 'How the form renders, e.g. "inline" or "modal".'],
                'style'            => [
                    'type'                 => 'object',
                    'additionalProperties' => false,
                    'properties'           => ['preset_id' => ['type' => 'string', 'description' => 'Style-preset id, or empty for the campaign default.']],
                ],
                'recurring'         => [
                    'type'                 => 'object',
                    'additionalProperties' => false,
                    'description'          => 'Recurring-giving options offered on the form.',
                    'properties'           => [
                        'enabled'     => ['type' => 'boolean'],
                        'frequencies' => ['type' => 'array', 'items' => ['type' => 'string']],
                    ],
                ],
                'anonymous_allowed' => ['type' => 'boolean'],
                'thank_you_message' => ['type' => 'string'],
                'redirect_url'      => ['type' => 'string'],
                'goal'              => [
                    'type'                 => 'object',
                    'additionalProperties' => false,
                    'description'          => 'Fundraising goal shown on the form.',
                    'properties'           => [
                        'type'         => ['type' => 'string', 'enum' => ['amount', 'donations', 'donors', 'none']],
                        'amount_cents' => ['type' => 'integer', 'minimum' => 0, 'description' => 'Target in minor units, when type is "amount".'],
                        'count'        => ['type' => 'integer', 'minimum' => 0, 'description' => 'Target donation/donor count, when type is "donations" or "donors".'],
                    ],
                ],
            ],
        ];
    }

    /**
     * Resolves a plan and its gateway (null for Offline, which has no remote subscription).
     *
     * @return array{0: RecurringPlan, 1: ?SubscriptionAware}
     */
    private function resolvePlan(Container $c, int $planId): array
    {
        $plan = RecurringPlan::query()->find('id', $planId);
        if (! $plan) {
            throw new CommandError('Recurring plan not found.');
        }
        $gateway = $c->get(GatewayManager::class)->get((string) $plan->gateway);
        $sub     = $gateway instanceof SubscriptionAware ? $gateway : null;
        return [$plan, $sub];
    }
}
