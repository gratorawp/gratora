<?php

declare(strict_types=1);

namespace Dono\Core\Commands;

use Dono\Analytics\Event;
use Dono\Campaigns\CampaignMetricsService;
use Dono\Campaigns\CampaignRepository;
use Dono\Campaigns\CampaignService;
use Dono\Currency\BaseCurrencyLocked;
use Dono\Currency\Currency;
use Dono\Currency\SupportedCurrencies;
use Dono\Dashboard\DashboardMetricsService;
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
use Dono\Foundation\Helpers\Money;
use Dono\Foundation\Time\Clock;
use Dono\Forms\FormRepository;
use Dono\Forms\FormService;
use Dono\Forms\FormTemplates;
use Dono\Foundation\Identity\IdentityHasher;
use Dono\Funds\FundRepository;
use Dono\Funds\FundService;
use Dono\Gateways\GatewayManager;
use Dono\Mail\Mailer;
use Dono\Receipts\ReceiptIssuer;
use Dono\Reports\CampaignReportBuilder;
use Dono\Reports\TaxStatementBuilder;
use Dono\Recurring\CampaignCancelRecurringJob;
use Dono\Recurring\RecurringPlan;
use Dono\Recurring\RecurringPlanActions;
use Dono\Recurring\RecurringPlanChange;
use Dono\Recurring\RecurringPlanRepository;
use Dono\Settings\SettingsService;
use Dono\Settings\SecretRedactor;

/**
 * Registers core domain operations as Command objects.
 *
 * @since 1.0.0
 */
final class CoreCommandProvider
{
    private const META = ['add_on' => 'core', 'add_on_label' => 'Dono'];

    /** Date-range windows the dashboard metrics service accepts. */
    private const REPORT_RANGES = ['today', 'last-7', 'last-30', 'last-90', 'all-time'];

    /**
     * Benign settings groups the assistant may read and write. Deliberately
     * omits roles (privilege-escalation surface), gateways (Stripe secrets and
     * bank details) and privacy - those stay human-only.
     * An out-of-list group is rejected by the schema enum as command.invalid_input.
     */
    private const SETTINGS_GROUPS = ['org-profile', 'currency-locale', 'org-brand', 'receipts', 'email', 'numbering', 'consents'];

    /** @since 1.0.0 */
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
        $this->reports($r, $c);
        $this->reportDocuments($r, $c);
        $this->settings($r, $c);
    }

    /** @since 1.0.0 */
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
                // Same rules the two REST routes apply. This one reaches
                // DonationService directly, so without them the assistant could
                // record a currency the org does not accept, or a fractional
                // amount in a currency that has no minor unit.
                $currency = strtoupper((string) $in['currency']);
                if (! SupportedCurrencies::accepts($currency)) {
                    throw new CommandError(esc_html(sprintf(
                        /* translators: 1: currency code, 2: the accepted codes. */
                        __('%1$s is not one of your accepted currencies (%2$s).', 'dono-fundraising-platform'),
                        $currency,
                        implode(', ', SupportedCurrencies::all())
                    )));
                }
                if (Currency::minorUnits($currency) === 0 && ((int) $in['amount_cents']) % 100 !== 0) {
                    throw new CommandError(
                        esc_html__('This currency does not support fractional amounts.', 'dono-fundraising-platform')
                    );
                }

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
                    throw new CommandError(esc_html('Donation not found.'));
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
                    throw new CommandError(esc_html('Donation not found.'));
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
                    throw new CommandError(esc_html('Donation not found.'));
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
            $this->meta(['destructive' => true]),
            function (array $in) use ($c): array {
                $donation = $c->get(DonationRepository::class)->findByReference((string) ($in['donation_reference'] ?? ''));
                if (! $donation) {
                    return [];
                }
                $amount = (int) ($in['amount_cents'] ?? 0);
                $isFull = $amount >= (int) $donation->amount_cents;
                return [[
                    'label' => sprintf('Refund donation %s', (string) $donation->reference),
                    'to'    => Money::format($amount, (string) $donation->currency) . ($isFull ? ' (full refund)' : ' (partial refund)'),
                ]];
            },
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
                    throw new CommandError(esc_html('Donation not found.'));
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
            $this->meta(['destructive' => true]),
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

        $r->register(new Command(
            'donation.missing_receipts',
            'List paid donations that have no issued, non-voided receipt - the donors who never got their receipt. Paged, newest first; optional campaign filter. No donor PII.',
            $this->listSchema([
                'campaign_id' => ['type' => 'integer', 'minimum' => 1],
            ]),
            [],
            'dono_view_donations',
            true,
            false,
            function (array $in) use ($c): array {
                $res = $c->get(DonationRepository::class)->paidWithoutReceipt($in);
                return $this->page($in, array_map(static fn ($m): array => [
                    'reference'    => (string) $m->reference,
                    'amount_cents' => (int) $m->amount_cents,
                    'currency'     => (string) $m->currency,
                    'campaign_id'  => $m->campaign_id !== null ? (int) $m->campaign_id : null,
                    'paid_at'      => $m->paid_at,
                    'created_at'   => (string) $m->created_at,
                ], $res['items']), $res['total']);
            },
            $this->meta(['agent_hint' => 'Finds paid donations still missing a receipt so you can requeue each with receipt.requeue. Read-only; carries no donor email or name.']),
        ));
    }

    /** @since 1.0.0 */
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
                    throw new CommandError(esc_html('Donor not found.'));
                }
                if ($donor->redacted_at !== null) {
                    throw new CommandError(esc_html('This donor has been erased and can no longer be edited.'));
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
                    throw new CommandError(esc_html('Donor not found.'));
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
                    throw new CommandError(esc_html('Donor not found.'));
                }
                $redacted = $c->get(DonorService::class)->redact($donor);
                return [
                    'donor_id'    => (int) $redacted->id,
                    'redacted_at' => $redacted->redacted_at,
                ];
            },
            $this->meta(['destructive' => true]),
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
                    // The token opens a full donor session, so no caller may ask
                    // for a lifetime beyond the staff-issued link.
                    min(isset($in['ttl_seconds']) ? (int) $in['ttl_seconds'] : 2_592_000, DonorMetricsService::STAFF_LINK_TTL),
                );
                return ['token' => $token];
            },
            self::META,
        ));

        $r->register(new Command(
            'donor.send_email',
            'Send a single one-off email to one donor. The operator approves the exact recipient, subject, and body before it sends.',
            $this->schema([
                'donor_id' => ['type' => 'integer', 'minimum' => 1],
                'subject'  => ['type' => 'string', 'minLength' => 1],
                'body'     => ['type' => 'string', 'minLength' => 1],
            ], ['donor_id', 'subject', 'body']),
            [],
            'dono_edit_donors',
            false,
            true,
            function (array $in) use ($c): array {
                $donor = $c->get(DonorRepository::class)->findById((int) $in['donor_id']);
                // A redacted donor has no address on file and must never be emailed.
                if (! $donor || $donor->redacted_at !== null) {
                    throw new CommandError(esc_html('Donor not found.'));
                }
                $email = $c->get(DonorService::class)->decryptEmail($donor);
                if ($email === null || $email === '') {
                    throw new CommandError(esc_html('Donor has no email address on file.'));
                }
                $sent = $c->get(Mailer::class)->sendRaw(
                    $email,
                    (string) $in['subject'],
                    (string) $in['body'],
                );
                return ['sent' => (bool) $sent, 'donor_id' => (int) $donor->id];
            },
            $this->meta(['agent_hint' => 'A one-off email the operator approves before it sends; to resend a receipt use receipt.requeue and for a login link use donor.magic_link.issue instead.']),
        ));
    }

    /** @since 1.0.0 */
    private function campaigns(CommandRegistry $r, Container $c): void
    {
        // Built from the LIVE registry so a type a Pro add-on contributes (e.g.
        // peer_to_peer from dono-p2p) is offered only when that add-on is active.
        // An unavailable type is then rejected at the boundary, not silently
        // downgraded to standard with a misleading success.
        $campaignTypes = array_keys((array) apply_filters('dono.campaign.types', ['standard' => '']));

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
                'campaign_type' => ['type' => 'string', 'enum' => $campaignTypes, 'description' => 'Campaign type. Only these registered types exist; a type contributed by a Pro add-on (e.g. peer_to_peer) is listed only when that add-on is active.'],
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
                'campaign_type' => ['type' => 'string', 'enum' => $campaignTypes, 'description' => 'Campaign type. Only these registered types exist; only a standard campaign can be promoted to another type.'],
                'image_attachment_id' => ['type' => ['integer', 'null'], 'minimum' => 1, 'description' => 'Media-library attachment ID to use as the campaign photo.'],
            ], ['campaign_id']),
            [],
            'dono_manage_campaigns',
            true,
            true,
            function (array $in) use ($c): array {
                $campaign = $c->get(CampaignRepository::class)->findById((int) $in['campaign_id']);
                if (! $campaign) {
                    throw new CommandError(esc_html('Campaign not found.'));
                }
                unset($in['campaign_id']);
                $updated = $c->get(CampaignService::class)->update($campaign, $in);
                return ['campaign_id' => (int) $updated->id];
            },
            self::META,
            function (array $in) use ($c): array {
                $campaign = $c->get(CampaignRepository::class)->findById((int) ($in['campaign_id'] ?? 0));

                return $campaign ? $this->campaignChangeSet($in, $campaign)['rows'] : [];
            },
            function (array $in) use ($c): ?array {
                $campaign = $c->get(CampaignRepository::class)->findById((int) ($in['campaign_id'] ?? 0));
                if (! $campaign) {
                    return null;
                }
                $inverse = $this->campaignChangeSet($in, $campaign)['inverse'];

                return $inverse === null ? null : ['campaign_id' => (int) $campaign->id] + $inverse;
            },
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
                    throw new CommandError(esc_html('Campaign not found.'));
                }
                $c->get(CampaignService::class)->delete($campaign);
                return ['campaign_id' => (int) $in['campaign_id'], 'deleted' => true];
            },
            $this->meta(['destructive' => true]),
            function (array $in) use ($c): array {
                $campaign = $c->get(CampaignRepository::class)->findById((int) ($in['campaign_id'] ?? 0));
                if (! $campaign) {
                    return [];
                }
                return [[
                    'label' => 'Delete campaign',
                    'to'    => sprintf('%s (#%d)', (string) $campaign->title, (int) $campaign->id),
                ]];
            },
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
                    throw new CommandError(esc_html('Campaign not found.'));
                }
                $copy = $c->get(CampaignService::class)->duplicate($source);
                return ['campaign_id' => (int) $copy->id, 'slug' => (string) $copy->slug];
            },
            self::META,
        ));
    }

    /** @since 1.0.0 */
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
                    throw new CommandError(esc_html('Form not found.'));
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
            function (array $in) use ($c): array {
                $form = $c->get(FormRepository::class)->findById((int) ($in['form_id'] ?? 0));

                return $form ? $this->formChangeSet($in, $form)['rows'] : [];
            },
            function (array $in) use ($c): ?array {
                $form = $c->get(FormRepository::class)->findById((int) ($in['form_id'] ?? 0));
                if (! $form) {
                    return null;
                }
                $inverse = $this->formChangeSet($in, $form)['inverse'];

                return $inverse === null ? null : ['form_id' => (int) $form->id] + $inverse;
            },
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
                    throw new CommandError(esc_html('Form not found.'));
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
                    throw new CommandError(esc_html('Form not found.'));
                }
                $c->get(FormService::class)->delete($form);
                return ['form_id' => (int) $in['form_id'], 'deleted' => true];
            },
            $this->meta(['destructive' => true]),
            function (array $in) use ($c): array {
                $form = $c->get(FormRepository::class)->findById((int) ($in['form_id'] ?? 0));
                if (! $form) {
                    return [];
                }
                return [[
                    'label' => 'Delete form',
                    'to'    => sprintf('%s (#%d)', (string) $form->title, (int) $form->id),
                ]];
            },
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
                    throw new CommandError(esc_html('Form not found.'));
                }
                $copy = $c->get(FormService::class)->duplicate($source);
                return ['form_id' => (int) $copy->id, 'slug' => (string) $copy->slug];
            },
            self::META,
        ));
    }

    /** @since 1.0.0 */
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
                    throw new CommandError(esc_html('Fund not found.'));
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
                    throw new CommandError(esc_html('Fund not found.'));
                }
                $result = $c->get(FundService::class)->delete(
                    $fund,
                    isset($in['reassign_to']) ? (int) $in['reassign_to'] : null,
                );
                return ['fund_id' => (int) $in['fund_id'], 'deleted' => true, 'detail' => $result];
            },
            $this->meta(['destructive' => true]),
            function (array $in) use ($c): array {
                $repo = $c->get(FundRepository::class);
                $fund = $repo->findById((int) ($in['fund_id'] ?? 0));
                if (! $fund) {
                    return [];
                }
                $rows = [[
                    'label' => 'Delete fund',
                    'to'    => sprintf('%s (%s)', (string) $fund->name, (string) $fund->code),
                ]];
                if (! empty($in['reassign_to'])) {
                    $target = $repo->findById((int) $in['reassign_to']);
                    if ($target) {
                        $rows[] = ['label' => 'Reassign donations to', 'to' => sprintf('%s (%s)', (string) $target->name, (string) $target->code)];
                    }
                }
                return $rows;
            },
        ));
    }

    /** @since 1.0.0 */
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
            function (array $in, CommandContext $ctx) use ($c): array {
                // The PDF carries the donor's address, and their email wherever
                // the org put the merge tag, so rendering one reads the donor
                // record. A command declares a single capability and this one
                // has to hold two, resolved the way dispatch resolves its own.
                $mayReadDonors = in_array($ctx->source, ['rest', 'cli'], true)
                    ? current_user_can('dono_view_donors')
                    : ($ctx->user_id !== null && user_can($ctx->user_id, 'dono_view_donors'));
                if (! $mayReadDonors) {
                    throw new CommandError(esc_html('Not permitted: dono_view_donors.'));
                }

                $path = $c->get(ReceiptIssuer::class)->renderReceiptPdf((int) $in['receipt_id']);
                if ($path === null) {
                    throw new CommandError(esc_html('Receipt or renderer not found.'));
                }
                return ['path' => $path];
            },
            self::META,
        ));
    }

    /** @since 1.0.0 */
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
                $plan   = $this->resolvePlan($c, (int) $in['plan_id']);
                $reason = isset($in['reason']) ? (string) $in['reason'] : null;
                // Gateway cancel + winner-gated local side effects (one email
                // even if the gateway's subscription.deleted webhook races).
                $c->get(RecurringPlanActions::class)->cancel(
                    $plan,
                    $reason,
                    RecurringPlanChange::byAdmin('cancel', false)
                );
                return ['plan_id' => (int) $plan->id, 'status' => (string) $plan->status];
            },
            $this->meta(['destructive' => true]),
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
                $plan = $this->resolvePlan($c, (int) $in['plan_id']);
                // The caller's own date wins. A pause always needs a restart
                // date: PayPal's suspend is indefinite and only
                // RecurringResumer lifts it, keying on resume_at.
                $resumesAt = isset($in['resumes_at']) && (string) $in['resumes_at'] !== ''
                    ? (string) $in['resumes_at']
                    : RecurringPlanActions::monthsFromNow(1);
                $c->get(RecurringPlanActions::class)->pause(
                    $plan,
                    $resumesAt,
                    RecurringPlanChange::byAdmin('pause', false)
                );
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
                $plan = $this->resolvePlan($c, (int) $in['plan_id']);
                $c->get(RecurringPlanActions::class)->resume(
                    $plan,
                    RecurringPlanChange::byAdmin('resume', false)
                );
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
                $plan = $this->resolvePlan($c, (int) $in['plan_id']);
                try {
                    $c->get(RecurringPlanActions::class)->changeAmount(
                        $plan,
                        (int) $in['amount_cents'],
                        RecurringPlanChange::byAdmin('change_amount', false)
                    );
                } catch (\InvalidArgumentException $e) {
                    throw new CommandError(esc_html($e->getMessage()));
                }
                return ['plan_id' => (int) $plan->id, 'amount_cents' => (int) $plan->amount_cents];
            },
            self::META,
        ));

        $r->register(new Command(
            'recurring.cancel_for_campaign',
            'Cancel every live recurring plan attributed to a campaign.',
            $this->schema([
                'campaign_id' => ['type' => 'integer', 'minimum' => 1],
                'reason'      => ['type' => ['string', 'null']],
            ], ['campaign_id']),
            [],
            'dono_view_donations',
            false,
            true,
            function (array $in) use ($c): array {
                $campaignId = (int) $in['campaign_id'];
                if (! $c->get(CampaignRepository::class)->findById($campaignId)) {
                    throw new CommandError(esc_html('Campaign not found.'));
                }
                // Queued, for the reason on CampaignCancelRecurringJob: one
                // blocking gateway call per plan does not fit in a request.
                // The same count the confirmation preview showed, and the same
                // set the sweep takes: paused and past_due plans still bill.
                $queued = (int) RecurringPlan::query()
                    ->where('campaign_id', $campaignId)
                    ->whereIn('status', RecurringPlanRepository::LIVE_STATUSES)
                    ->where('is_test', false)
                    ->count();

                $c->get(CampaignCancelRecurringJob::class)->start(
                    $campaignId,
                    isset($in['reason']) ? (string) $in['reason'] : null,
                );

                return [
                    'campaign_id' => $campaignId,
                    'queued'      => $queued,
                ];
            },
            $this->meta(['destructive' => true, 'agent_hint' => 'Cancels ALL live recurring donations on the campaign at once, including paused and past-due ones, and cannot be undone. To cancel a single plan use recurring.cancel instead.']),
            function (array $in) use ($c): array {
                $campaign = $c->get(CampaignRepository::class)->findById((int) ($in['campaign_id'] ?? 0));
                if (! $campaign) {
                    return [];
                }
                $live = (int) ($c->get(RecurringPlanRepository::class)->liveForCampaign((int) $campaign->id)['count'] ?? 0);
                return [[
                    'label' => sprintf('Cancel all live recurring plans on %s', (string) $campaign->title),
                    'to'    => $live === 1 ? '1 live plan' : sprintf('%d live plans', $live),
                ]];
            },
        ));
    }

    /** @since 1.0.0 */
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
                    throw new CommandError(esc_html('Donation not found.'));
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
                    throw new CommandError(esc_html('Donor not found.'));
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
     * "Ask your data" analytics reads: thin projections over the dashboard and
     * donor metrics services so the assistant can answer "how are we doing",
     * "what's our recurring revenue", and "who's at risk of lapsing". All
     * non-mutating + idempotent, so they skip the confirmation gate.
     *
     * @since 1.0.0
     */
    private function reports(CommandRegistry $r, Container $c): void
    {
        $rangeArg = [
            'type'        => 'string',
            'enum'        => self::REPORT_RANGES,
            'description' => 'Reporting window. One of today, last-7, last-30, last-90, all-time. Defaults to last-30.',
        ];

        $r->register(new Command(
            'report.dashboard',
            'Org-wide KPI snapshot for a date range: revenue raised, donation count, unique donors, and average donation.',
            $this->schema([
                'range'   => $rangeArg,
                'compare' => ['type' => 'boolean', 'description' => 'When true, also compare against the immediately preceding period of the same length. Ignored for the all-time range.'],
            ]),
            [],
            'dono_view_reports',
            true,
            false,
            function (array $in) use ($c): array {
                $range   = (string) ($in['range'] ?? 'last-30');
                // The service takes a string mode (none|period|year); expose a
                // simple on/off and map true to a same-length previous period.
                $compare = ! empty($in['compare']) ? 'period' : 'none';
                return $this->dashboardMetrics($c)->kpi($range, $compare);
            },
            self::META,
        ));

        $r->register(new Command(
            'report.recurring',
            'Recurring-revenue snapshot: active plans, monthly recurring revenue (MRR), 30-day projection, and new plans this month.',
            [],
            [],
            'dono_view_reports',
            true,
            false,
            fn (): array => $this->dashboardMetrics($c)->recurring(),
            self::META,
        ));

        $r->register(new Command(
            'report.top_campaigns',
            'Top campaigns ranked by revenue raised in a date range.',
            $this->schema([
                'range' => $rangeArg,
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'description' => 'How many campaigns to return (1 to 50). Defaults to 5.'],
            ]),
            [],
            'dono_view_reports',
            true,
            false,
            function (array $in) use ($c): array {
                $range = (string) ($in['range'] ?? 'last-30');
                $limit = isset($in['limit']) ? (int) $in['limit'] : 5;
                $rows  = $this->dashboardMetrics($c)->topCampaigns($range, $limit);
                // Drop the per-row sparkline: this command answers "which
                // campaigns raised the most", not the daily shape of each.
                return [
                    'range'     => $range,
                    'campaigns' => array_map(static fn (array $row): array => [
                        'id'              => (int) $row['id'],
                        'title'           => (string) $row['title'],
                        'currency'        => (string) $row['currency'],
                        'amount_cents'    => (int) $row['amount_cents'],
                        'donations_count' => (int) $row['donations_count'],
                    ], $rows),
                ];
            },
            self::META,
        ));

        $r->register(new Command(
            'diagnostics.recent',
            'Recent operational problems from the event log: failed donations and subscriptions, plus commands that errored, were denied, or were rate-limited. Grouped by type with a count, the most recent occurrence, a sample error, and which operator ran each failed or denied command, alongside a healthy-activity tally for context. Read-only; carries no donor names or emails.',
            $this->schema([
                'hours' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 168, 'description' => 'How far back to look, in hours (1 to 168). Defaults to 24.'],
            ]),
            [],
            'dono_view_reports',
            true,
            false,
            function (array $in): array {
                $hours = max(1, min(168, (int) ($in['hours'] ?? 24)));
                $since = gmdate('Y-m-d H:i:s', time() - $hours * 3600);

                $issueTypes = [
                    'command.failed',
                    'command.denied',
                    'command.rate_limited',
                    'donation.failed',
                    'recurring.subscription_creation_failed',
                ];
                $healthyTypes = ['donation.completed', 'recurring.renewed', 'receipt.issued'];

                $rows = Event::query()
                    ->whereIn('type', array_merge($issueTypes, $healthyTypes))
                    ->where('occurred_at', $since, '>=')
                    ->orderBy('id', 'DESC')
                    ->limit(2000)
                    ->getAll();

                $issues  = [];
                $healthy = [];
                foreach ($rows as $ev) {
                    $type = (string) $ev->type;
                    if (in_array($type, $healthyTypes, true)) {
                        $healthy[$type] = ($healthy[$type] ?? 0) + 1;
                        continue;
                    }
                    $payload = is_array($ev->payload) ? $ev->payload : [];
                    if (! isset($issues[$type])) {
                        $issues[$type] = ['type' => $type, 'count' => 0, 'last_at' => (string) $ev->occurred_at, 'sample_error' => null, 'commands' => []];
                    }
                    $issues[$type]['count']++;
                    // Rows are newest-first, so the first error text we meet is the most recent.
                    if ($issues[$type]['sample_error'] === null) {
                        $err = $payload['error'] ?? $payload['reason'] ?? $payload['message'] ?? null;
                        if (is_string($err) && $err !== '') {
                            $issues[$type]['sample_error'] = $err;
                        }
                    }
                    if (isset($payload['command_id']) && $payload['command_id'] !== '') {
                        $cid = (string) $payload['command_id'];
                        if (! isset($issues[$type]['commands'][$cid])) {
                            $issues[$type]['commands'][$cid] = ['count' => 0, 'users' => []];
                        }
                        $issues[$type]['commands'][$cid]['count']++;
                        // The operator who ran it: a WP user (staff), never a donor.
                        $uid = $ev->user_id !== null ? (int) $ev->user_id : (int) ($payload['user_id'] ?? 0);
                        if ($uid > 0) {
                            $issues[$type]['commands'][$cid]['users'][$uid] = ($issues[$type]['commands'][$cid]['users'][$uid] ?? 0) + 1;
                        }
                    }
                }

                $names     = [];
                $issueList = [];
                foreach ($issues as $issue) {
                    $commands = [];
                    foreach ($issue['commands'] as $cid => $data) {
                        arsort($data['users']);
                        $byUser = [];
                        foreach ($data['users'] as $uid => $hits) {
                            if (! isset($names[$uid])) {
                                $user        = get_userdata((int) $uid);
                                $names[$uid] = $user ? $user->display_name : 'user #' . $uid;
                            }
                            $byUser[] = ['user_id' => (int) $uid, 'name' => $names[$uid], 'count' => $hits];
                        }
                        $commands[] = ['command_id' => $cid, 'count' => $data['count'], 'by_user' => $byUser];
                    }
                    $issueList[] = [
                        'type'         => $issue['type'],
                        'count'        => $issue['count'],
                        'last_at'      => $issue['last_at'],
                        'sample_error' => $issue['sample_error'],
                        'commands'     => $commands,
                    ];
                }

                return [
                    'window_hours' => $hours,
                    'since'        => $since,
                    'total_issues' => array_sum(array_column($issueList, 'count')),
                    'issues'       => $issueList,
                    'healthy'      => $healthy,
                ];
            },
            $this->meta(['agent_hint' => 'Use when the operator asks what is failing, why donations or subscriptions are not going through, why a command was denied, who hit a denial or error, or to troubleshoot recent problems. Read sample_error to explain the likely cause and suggest a fix, and read each command breakdown\'s by_user to name which operator was affected. It reflects only what the plugin recorded, not PHP fatals or the server error log.']),
        ));

        $r->register(new Command(
            'report.attention',
            'Operations queue needing a decision: failed donations, campaigns ending soon, published campaigns with no form, and recent donor notes. Each item carries a tone and an admin link.',
            [],
            [],
            'dono_view_reports',
            true,
            false,
            function () use ($c): array {
                $items = array_map(static fn (array $i): array => [
                    'key'          => (string) $i['key'],
                    'tone'         => (string) $i['tone'],
                    'title'        => (string) $i['title'],
                    'count'        => isset($i['count']) ? (int) $i['count'] : null,
                    'action_label' => $i['action_label'] ?? null,
                    'action_href'  => $i['action_href'] ?? null,
                ], $this->dashboardMetrics($c)->attention());
                return ['items' => $items];
            },
            self::META,
        ));

        $r->register(new Command(
            'donor.at_risk',
            'List at-risk donors (paged): donors who gave before but are now lapsing, highest lifetime value first. Returns PII (name, email); gated on dono_view_donors.',
            $this->schema([
                'page'     => ['type' => 'integer', 'minimum' => 1],
                'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
            ]),
            [],
            'dono_view_donors',
            true,
            false,
            function (array $in) use ($c): array {
                $page    = max(1, (int) ($in['page'] ?? 1));
                $perPage = max(1, min(100, (int) ($in['per_page'] ?? 25)));
                $result  = $c->get(DonorMetricsService::class)->atRisk($page, $perPage);
                return $this->page($in, $result['rows'], $result['total']);
            },
            self::META,
        ));
    }

    /**
     * Report-document commands. Each only generates a capability-gated, time-
     * limited download link (it does not stream or store a PDF), so both are
     * non-mutating + idempotent and skip the confirmation gate. The link points
     * at a core REST route that regenerates and streams the PDF on demand; the
     * donor tax statement carries PII and is gated on dono_view_donors.
     *
     * @since 1.0.0
     */
    private function reportDocuments(CommandRegistry $r, Container $c): void
    {
        $currentYear = (int) wp_date('Y');

        $r->register(new Command(
            'report.campaign_pdf',
            'Generate a secure download link for a campaign performance one-pager PDF (raised vs goal, donations, donors, average). Aggregate figures only, no donor PII.',
            $this->schema([
                'campaign_id' => ['type' => 'integer', 'minimum' => 1],
                'range'       => [
                    'type'        => 'string',
                    'enum'        => self::REPORT_RANGES,
                    'description' => 'Reporting window. One of today, last-7, last-30, last-90, all-time. Defaults to last-30.',
                ],
            ], ['campaign_id']),
            [],
            'dono_view_reports',
            true,
            false,
            function (array $in) use ($c): array {
                $campaignId = (int) $in['campaign_id'];
                $campaign   = $c->get(CampaignRepository::class)->findById($campaignId);
                if (! $campaign) {
                    throw new CommandError(esc_html('Campaign not found.'));
                }
                $range = in_array($in['range'] ?? null, self::REPORT_RANGES, true)
                    ? (string) $in['range']
                    : 'last-30';

                return [
                    'campaign_id'  => $campaignId,
                    'download_url' => $this->reportUrl(
                        'dono/v1/reports/campaign/' . $campaignId . '/pdf',
                        ['range' => $range],
                    ),
                    'filename'     => CampaignReportBuilder::filename($campaignId, $range),
                    'expires_hint' => $this->linkExpiryHint(),
                ];
            },
            self::META,
        ));

        $r->register(new Command(
            'donor.tax_statement_pdf',
            'Generate a secure download link for a donor year-end tax statement PDF (US 501(c)(3) style, net of refunds), and report the donation count and net total for the year. Returns a PII document link; gated on dono_view_donors.',
            $this->schema([
                'donor_id' => ['type' => 'integer', 'minimum' => 1],
                'year'     => [
                    'type'        => 'integer',
                    'minimum'     => 2000,
                    'maximum'     => $currentYear,
                    'description' => 'Calendar year of the statement, e.g. 2025. Between 2000 and the current year.',
                ],
            ], ['donor_id', 'year']),
            [],
            'dono_view_donors',
            true,
            false,
            function (array $in) use ($c, $currentYear): array {
                $donorId = (int) $in['donor_id'];
                $year    = (int) $in['year'];
                if ($year < 2000 || $year > $currentYear) {
                    throw new CommandError(esc_html('Statement year must be between 2000 and the current year.'));
                }
                $donor = $c->get(DonorRepository::class)->findById($donorId);
                if (! $donor || $donor->redacted_at !== null) {
                    throw new CommandError(esc_html('Donor not found.'));
                }

                // Count + net total from the year's paid donations so the
                // assistant can state the figures without opening the PDF.
                $summary = $c->get(TaxStatementBuilder::class)->summary($donorId, $year);

                return [
                    'donor_id'       => $donorId,
                    'year'           => $year,
                    'download_url'   => $this->reportUrl(
                        'dono/v1/reports/donor/' . $donorId . '/tax-statement/' . $year,
                        [],
                    ),
                    'filename'       => TaxStatementBuilder::filename($donorId, $year),
                    'donation_count' => (int) $summary['donation_count'],
                    // Null when the year spans currencies, because there is no
                    // one number to state. Casting it would answer a question
                    // about tax with a confident zero.
                    'total_cents'    => $summary['total_cents'] === null ? null : (int) $summary['total_cents'],
                    'currency'       => $summary['currency'],
                    'totals_by_currency' => $summary['totals_by_currency'],
                ];
            },
            self::META,
        ));
    }

    /**
     * Build a report download URL. A clicked link carries the operator's auth
     * cookie but not a REST nonce, so a fresh wp_rest nonce is appended for
     * cookie auth to validate; the link is therefore time-limited to the nonce
     * lifetime (see linkExpiryHint).
     *
     * @param array<string,scalar> $args
     *
     * @since 1.0.0
     */
    private function reportUrl(string $path, array $args): string
    {
        $args['_wpnonce'] = wp_create_nonce('wp_rest');
        return esc_url_raw(add_query_arg($args, rest_url($path)));
    }

    /** @since 1.0.0 */
    private function linkExpiryHint(): string
    {
        $life = (int) apply_filters('nonce_life', DAY_IN_SECONDS);
        return sprintf(
            /* translators: %s: human-readable duration, e.g. "1 day". */
            __('Link is time-limited to your login session (about %s); regenerate it if it stops working.', 'dono-fundraising-platform'),
            human_time_diff(0, $life),
        );
    }

    /**
     * Read and write benign org settings. Security-critical: only SETTINGS_GROUPS
     * is reachable (the enum rejects anything else as command.invalid_input), so
     * roles, gateways and privacy can never be touched
     * here. Secret-shaped values are redacted on read and refused on write as
     * defense in depth, even though no allowlisted group holds one today.
     *
     * @since 1.0.0
     */
    private function settings(CommandRegistry $r, Container $c): void
    {
        $groupArg = [
            'type'        => 'string',
            'enum'        => self::SETTINGS_GROUPS,
            'description' => 'Which settings group to act on. Only these benign groups are reachable; roles and gateways (and other sensitive groups) are intentionally excluded and stay human-only.',
        ];

        $r->register(new Command(
            'settings.get',
            'Read one benign org settings group (org profile, currency and locale, brand, receipts, email, numbering, or consents). Any secret-shaped value is redacted.',
            $this->schema(['group' => $groupArg], ['group']),
            [],
            'dono_manage_settings',
            true,
            false,
            function (array $in) use ($c): array {
                $group  = (string) $in['group'];
                $values = $c->get(SettingsService::class)->get($group);
                return ['group' => $group, 'values' => $this->redactSecrets($values)];
            },
            self::META,
        ));

        $r->register(new Command(
            'settings.update',
            'Update one benign org settings group. Partial update; only existing, non-secret keys can be set.',
            $this->schema([
                'group'  => $groupArg,
                'values' => [
                    'type'        => 'object',
                    'description' => 'The settings keys to change for this group. Call settings.get first to see the exact keys; only existing, non-secret keys can be set. Unknown or secret-shaped keys are rejected.',
                ],
            ], ['group', 'values']),
            [],
            'dono_manage_settings',
            false,
            true,
            function (array $in) use ($c): array {
                $group    = (string) $in['group'];
                $values   = is_array($in['values'] ?? null) ? $in['values'] : [];
                $settings = $c->get(SettingsService::class);

                // Settable keys = the group's current top-level keys, minus any
                // secret-shaped key. Derived from the live settings so no
                // per-group schema is hardcoded and the agent cannot invent keys.
                $current  = $settings->get($group);
                $settable = [];
                foreach ($current as $key => $_v) {
                    if (is_string($key) && ! SecretRedactor::isSecretKey($key)) {
                        $settable[$key] = true;
                    }
                }

                $validated = [];
                foreach ($values as $key => $value) {
                    if (is_string($key) && SecretRedactor::isSecretKey($key)) {
                        throw new CommandError(esc_html(sprintf('The "%s" setting holds a secret and cannot be set here.', $key)));
                    }
                    if (! isset($settable[$key])) {
                        throw new CommandError(esc_html(sprintf('Unknown setting "%s" for group "%s". Call settings.get first to see the settable keys.', (string) $key, $group)));
                    }
                    $validated[$key] = $value;
                }

                try {
                    $updated = $settings->update($group, $validated);
                } catch (BaseCurrencyLocked $e) {
                    // A refusal the caller can act on, not a fault to log.
                    throw new CommandError(esc_html($e->getMessage()));
                }

                return ['group' => $group, 'values' => $this->redactSecrets($updated)];
            },
            $this->meta(['agent_hint' => 'Call settings.get first to learn the exact key names for the group; only existing, non-secret keys can be set. This command never reaches roles or gateways.']),
        ));
    }

    /**
     * SecretRedactor is the single owner of this rule, so every caller masks
     * identically.
     *
     * @param array<string,mixed> $values
     * @return array<string,mixed>
     *
     * @since 1.0.0
     */
    private function redactSecrets(array $values): array
    {
        return SecretRedactor::redact($values);
    }

    /**
     * @param array<string,array<string,mixed>> $properties
     * @param list<string>                       $required
     * @return array<string,mixed>
     *
     * @since 1.0.0
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
     * which shows the plain `summary`.
     *
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     *
     * @since 1.0.0
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
     *
     * @since 1.0.0
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
     *
     * @since 1.0.0
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

    /**
     * Display name from the plaintext first/last columns; email stays encrypted.
     *
     * @since 1.0.0
     */
    private static function donorName(object $donor): string
    {
        $full = trim(((string) ($donor->first_name ?? '')) . ' ' . ((string) ($donor->last_name ?? '')));
        return $full !== '' ? $full : '-';
    }

    /**
     * @param array<string,mixed> $in
     * @return array{rows:list<array<string,string>>, inverse:array<string,mixed>|null}
     *
     * @since 1.0.0
     */
    private function campaignChangeSet(array $in, object $campaign): array
    {
        return $this->changeSet($in, [
            'title'      => ['label' => 'Title',       'current' => fn (): string => (string) $campaign->title],
            'status'     => ['label' => 'Status',      'current' => fn (): string => (string) $campaign->status],
            'goal_cents' => [
                'label'   => 'Goal amount',
                'current' => fn (): int => (int) $campaign->goal_cents,
                'format'  => fn (mixed $v): string => Money::format((int) $v, (string) $campaign->currency),
            ],
            'goal_count' => ['label' => 'Goal count',  'current' => fn (): int => (int) $campaign->goal_count],
            // Promotion off 'standard' is one way (CampaignService refuses the
            // way back), so a change that includes it cannot be undone at all.
            'campaign_type' => [
                'label'      => 'Campaign type',
                'current'    => fn (): string => (string) $campaign->campaign_type,
                'reversible' => false,
            ],
        ]);
    }

    /**
     * @param array<string,mixed> $in
     * @return array{rows:list<array<string,string>>, inverse:array<string,mixed>|null}
     *
     * @since 1.0.0
     */
    private function formChangeSet(array $in, object $form): array
    {
        $fields   = ['status' => ['label' => 'Status', 'current' => fn (): string => (string) $form->status]];
        $existing = is_array($form->settings) ? $form->settings : [];

        // Settings keys are open-ended, so the map is built from whatever the
        // patch touches. update() merges the patch over what is stored, so
        // restoring the previous values of exactly those keys is a real undo.
        $flat = [];
        if (is_array($in['settings'] ?? null)) {
            foreach ($in['settings'] as $key => $value) {
                $flat['setting:' . $key] = $value;
                $fields['setting:' . $key] = [
                    'label'   => sprintf('Setting: %s', (string) $key),
                    'current' => fn (): mixed => $existing[$key] ?? null,
                ];
            }
        }

        $result = $this->changeSet($flat + $in, $fields);

        if (is_array($result['inverse'])) {
            $settings = [];
            foreach ($result['inverse'] as $key => $value) {
                if (str_starts_with((string) $key, 'setting:')) {
                    $settings[substr((string) $key, 8)] = $value;
                    unset($result['inverse'][$key]);
                }
            }
            if ($settings !== []) {
                $result['inverse']['settings'] = $settings;
            }
        }

        return $result;
    }

    /**
     * The preview an operator approves and the inverse that undoes it, derived
     * from one field map so they cannot disagree.
     *
     * A field that cannot be put back (campaign_type promotion is one-way)
     * makes the whole change irreversible rather than partly so: offering Undo
     * for a change it can only half-undo is worse than offering none.
     *
     * @param array<string,mixed> $in
     * @param array<string,array{label:string,current:callable,format?:callable,reversible?:bool}> $fields
     * @return array{rows:list<array<string,string>>, inverse:array<string,mixed>|null}
     *
     * @since 1.0.0
     */
    private function changeSet(array $in, array $fields): array
    {
        $rows       = [];
        $inverse    = [];
        $reversible = true;

        foreach ($fields as $key => $spec) {
            if (! array_key_exists($key, $in)) continue;

            $before = ($spec['current'])();
            $after  = $in[$key];

            // Loose compare so an int goal arriving as a string is not a change.
            if ((string) $this->previewValue($before) === (string) $this->previewValue($after)) continue;

            $format = $spec['format'] ?? fn (mixed $v): string => $this->previewValue($v);
            $rows[] = [
                'label' => $spec['label'],
                'from'  => (string) $format($before),
                'to'    => (string) $format($after),
            ];

            if ($spec['reversible'] ?? true) {
                $inverse[$key] = $before;
            } else {
                $reversible = false;
            }
        }

        return [
            'rows'    => $rows,
            'inverse' => ($reversible && $inverse !== []) ? $inverse : null,
        ];
    }

    /** @since 1.0.0 */
    private function previewValue(mixed $value): string
    {
        if ($value === null) {
            return 'not set';
        }
        if (is_bool($value)) {
            return $value ? 'yes' : 'no';
        }
        if (is_scalar($value)) {
            return (string) $value;
        }
        return (string) wp_json_encode($value);
    }

    /**
     * The only donor-profile keys the platform accepts. Typed strictly so the
     * agent sees exactly what is settable and cannot pass fields that would be
     * silently ignored.
     *
     * @return array<string,mixed>
     *
     * @since 1.0.0
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
     *
     * @since 1.0.0
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
     * DashboardMetricsService is not container-bound; build it exactly as the
     * dashboard REST controller does (Clock plus the donation + recurring repos).
     *
     * @since 1.0.0
     */
    private function dashboardMetrics(Container $c): DashboardMetricsService
    {
        return new DashboardMetricsService(
            $c->get(Clock::class),
            $c->get(DonationRepository::class),
            $c->get(RecurringPlanRepository::class),
        );
    }

    /**
     * The plan a command names. Gateway resolution belongs to
     * RecurringPlanActions, which every one of these commands calls.
     *
     * @since 1.0.0
     */
    private function resolvePlan(Container $c, int $planId): RecurringPlan
    {
        $plan = RecurringPlan::query()->find('id', $planId);
        if (! $plan) {
            throw new CommandError(esc_html('Recurring plan not found.'));
        }
        return $plan;
    }
}
