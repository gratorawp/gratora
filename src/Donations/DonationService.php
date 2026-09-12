<?php

declare(strict_types=1);

namespace Gratora\Donations;

use Gratora\Analytics\ErrorLog;
use Gratora\Analytics\Event;
use Gratora\Analytics\EventRecorder;
use Gratora\Currency\FxRates;
use Gratora\Donors\Donor;
use Gratora\Donors\DonorService;
use Gratora\Forms\FormTypeRegistry;
use Gratora\Foundation\Crypto\Crypto;
use Gratora\Foundation\Helpers\Money;
use Gratora\Foundation\References\ReferenceGenerator;
use Gratora\Foundation\Time\Clock;
use Gratora\Funds\FundResolver;
use Gratora\Gateways\GatewayManager;
use Gratora\Gateways\TestMode;
use Gratora\Receipts\Receipt;
use Gratora\Recurring\FrequencyMap;
use Gratora\Vendor\Queryable\DB;
use RuntimeException;
use Throwable;

/**
 * Donation state machine: createPending, setGatewayIntent, confirm, markFailed, refund.
 *
 * Gateway interaction lives in the gateway abstraction; this service records
 * the result and fires hooks for observers.
 *
 * @since 1.0.0
 */
final class DonationService
{
    /** @since 1.0.0 */
    public function __construct(
        private DonationRepository $donations,
        private DonorService $donors,
        private ReferenceGenerator $references,
        private EventRecorder $events,
        private GatewayManager $gateways,
        private Clock $clock,
        private AggregateSyncer $aggregates,
        private FundResolver $funds,
        private FxRates $fx,
        private FormTypeRegistry $formTypes,
        private Crypto $crypto,
        private TestMode $testMode,
    ) {
    }

    /**
     * Create a pending donation from an intent. Donor is upserted inside the transaction.
     *
     * Returns `[donation, status_token]`. Only the token's SHA-256 hash is persisted;
     * the raw value lives only in the response.
     *
     * @return array{donation: Donation, status_token: string}
     *
     * @since 1.0.0
     */
    public function createPending(DonationIntent $intent): array
    {
        // Read before anything can hand back a different intent. The tree
        // descriptor is what bounds how many submissions one email can make,
        // and DonationIntent is readonly, so a filter or a type handler
        // returning a rebuilt instance drops it: the retry the controller has
        // already charged then writes itself as a fresh root with a full
        // budget, and every hop after it mints another.
        $retry = $intent->retry;

        // Read here for the same reason as $retry: an add-on must not be able
        // to declare a live donor's submission already collected.
        $alreadyCollected = $intent->already_collected;

        $intent = apply_filters('gratora.donation.intent_creating', $intent);

        $typeHandler = $this->formTypes->handlerFor($intent);
        $intent      = $typeHandler->prepareIntent($intent, $intent->extra);

        // A gateway that creates no schedule cannot take a recurring donation:
        // the card is charged once and the donor is promised a plan nobody will
        // ever collect. The public route checks this against the form's own
        // options, but every other caller reaches this method directly.
        //
        // Asked after prepareIntent, so a type handler that rebuilds the intent
        // cannot carry a frequency past a check that ran before it existed. An
        // import is exempt: it writes down money already taken on a schedule
        // that already existed, and refusing it loses the donor's history while
        // the plan it belongs to imports beside it.
        if (! $alreadyCollected && FrequencyMap::isRecurring($intent->frequency)) {
            $gateway = $this->gateways->get($intent->gateway);
            if ($gateway === null || ! in_array('recurring', $gateway->frequencies(), true)) {
                throw new RuntimeException(esc_html(sprintf(
                    'Refusing a %s donation on gateway "%s": it creates no recurring schedule.',
                    $intent->frequency,
                    $intent->gateway
                )));
            }
        }

        $now = $this->clock->now()->format('Y-m-d H:i:s');
        $donation = null;

        $rawStatusToken = bin2hex(random_bytes(16));
        $statusTokenHash = hash('sha256', $rawStatusToken);

        DB::transaction(function () use ($intent, $retry, $now, $statusTokenHash, &$donation) {
            // Allow donor reactivation only when the intent permits it.
            $donor = $this->donors->findOrCreate(
                $intent->email,
                $intent->profile,
                $intent->reactivate_redacted_donor
            );

            // This row is committed before the gateway is contacted, so the
            // public path declines the reactivation above: otherwise a stranger
            // typing an erased donor's address un-erases them without paying.
            // The address is kept here so confirm() can do it when money has
            // actually moved, which is what the rule was always meant to be.
            // Only for a donor who is erased, and only until it is spent.
            $carriedEmail = null;
            if ($donor->redacted_at !== null && $intent->reactivate_redacted_donor_on_payment) {
                $carriedEmail = $this->crypto->encrypt($intent->email);
            }

            $isTest = $intent->is_test ?? $this->testMode->forFormId($intent->form_id);

            $donation = Donation::make();
            $donation->reference          = $this->references->next($this->numberingScope($isTest));
            $donation->status_token_hash  = $statusTokenHash;
            $donation->donor_id           = $donor->id;
            $donation->form_id            = $intent->form_id;
            $donation->campaign_id        = $intent->campaign_id;
            $donation->fund_id            = $this->funds->resolve(
                $intent->fund_id,
                $intent->form_id,
                $intent->campaign_id
            );
            $donation->amount_cents       = $intent->amount_cents;
            $donation->fee_covered_cents  = $intent->fee_covered_cents;
            $donation->fee_cents          = 0;
            $donation->net_cents          = $intent->amount_cents;
            $donation->currency           = strtoupper($intent->currency);

            $base = strtoupper( Money::defaultCurrency());
            if ($donation->currency === $base) {
                $donation->base_currency     = $base;
                $donation->fx_rate           = sprintf('%.8F', 1);
                $donation->base_amount_cents = $donation->amount_cents;
            } else {
                // No FX rate for this currency: record the donation in its own
                // currency and leave base_amount_cents null. Never reject a
                // donation because cross-currency reporting isn't configured -
                // FX is a reporting concern, not a money gate.
                $rate = $this->fx->rate($donation->currency, $base);
                if ($rate !== null) {
                    $donation->base_currency     = $base;
                    $donation->fx_rate           = sprintf('%.8F', $rate);
                    $donation->base_amount_cents = (int) round($donation->amount_cents * $rate);
                }
            }

            $donation->country            = $intent->country !== null
                ? strtoupper(substr($intent->country, 0, 2))
                : null;
            $donation->frequency          = $intent->frequency;
            $donation->status             = 'pending';
            $donation->kind               = $intent->kind;
            $donation->gateway            = $intent->gateway;
            $donation->payment_method     = $intent->payment_method;
            $donation->source_attribution = $intent->source_attribution;
            $donation->locale             = $intent->locale;
            $donation->note_to_org        = $intent->note_to_org;
            $donation->note_public        = $intent->note_public;
            // Honor the donor's persistent privacy preference: when the
            // existing donor has set `always_anonymous` (via donor portal),
            // force every new donation to anonymous regardless of intent.
            $donorAlwaysAnon = is_array($donor->flags ?? null)
                && is_array($donor->flags['prefs'] ?? null)
                && ! empty($donor->flags['prefs']['always_anonymous']);
            $donation->is_anonymous       = $intent->is_anonymous || $donorAlwaysAnon;
            $donation->is_test            = $isTest;
            $donation->created_at         = $now;
            $donation->updated_at         = $now;

            if (isset($intent->extra['fundraiser_id'])) {
                $donation->fundraiser_id = (int) $intent->extra['fundraiser_id'];
            }
            if (isset($intent->extra['fundraiser_team_id'])) {
                $donation->fundraiser_team_id = (int) $intent->extra['fundraiser_team_id'];
            }

            // A root is its own group and carries its own birth; a retry
            // inherits the descriptor verbatim, so group and born are the
            // root's at every depth and on every branch.
            $donation->flags = ['retry' => $retry ?? [
                'group' => $donation->reference,
                'born'  => time(),
            ]] + (array) ($donation->flags ?? []);

            $donation->custom_data_encrypted = $this->encodeCustom($intent->custom);

            $givenFirst = trim((string) ($intent->profile['first_name'] ?? ''));
            $givenLast  = trim((string) ($intent->profile['last_name']  ?? ''));
            $donation->donor_first_name = $givenFirst !== '' ? $givenFirst : null;
            $donation->donor_last_name  = $givenLast  !== '' ? $givenLast  : null;

            // A donor who stayed erased through the lookup above keeps nothing
            // of themselves on a fresh row either. Erasure cleared these exact
            // fields on every donation they had; writing them back here would
            // restore, one row at a time, what the erasure took. The list to
            // track is CoreDonorDataHandler's, which also names the note as
            // donor-authored and able to carry PII.
            if ($donor->redacted_at !== null) {
                $donation->donor_first_name = null;
                $donation->donor_last_name  = null;
                $donation->note_to_org      = null;
                $donation->note_public      = false;
            }

            // The one exception to the rule above, and the reason it is narrow:
            // encrypted, written only for an erased donor, and cleared the
            // moment it is spent or the attempt is closed. Without it an erased
            // donor who gives again is charged and then hears nothing, because
            // every donor-facing email reads its address from the donor row and
            // erasure emptied it.
            $donation->pending_reactivation_email = $carriedEmail;

            $donation->save();

            // Add-on rows that belong to this donation are written here, not on
            // gratora.donation.intent_created: that one fires after the commit, so
            // a row it wrote could outlive a donation that rolled back.
            do_action('gratora.donation.creating', $donation, $intent, $donor);

            $this->events->record('donation.intent_created', [
                'donor_id'     => $donor->id,
                'donation_id'  => $donation->id,
                'form_id'      => $donation->form_id,
                'campaign_id'  => $donation->campaign_id,
                'country'      => $donation->country,
                'amount_cents' => $donation->amount_cents,
                'currency'     => $donation->currency,
                'payload'      => ['gateway' => $donation->gateway, 'frequency' => $donation->frequency],
            ]);
        });

        do_action('gratora.donation.intent_created', $donation, $intent);
        $typeHandler->onDonationCreated($donation, $intent->extra);

        return ['donation' => $donation, 'status_token' => $rawStatusToken];
    }

    /**
     * Point an abandoned attempt at the donation that replaced it.
     *
     * A named-column conditional update rather than save(), which writes the
     * whole pre-call snapshot and would undo a webhook that settled this row
     * mid-request. The pending term is what leaves a row that just went paid
     * alone; a no-op is the right outcome there.
     *
     * flags is encoded here because the query builder writes what it is given
     * and only save() encodes json columns.
     *
     * @since 1.0.0
     */
    public function recordRetriedBy(Donation $parent, string $childReference): void
    {
        $flags = ['retried_by' => $childReference] + (array) ($parent->flags ?? []);

        Donation::query()
            ->where('id', (int) $parent->id)
            ->where('status', 'pending')
            ->update([
                'flags'      => (string) wp_json_encode($flags),
                'updated_at' => $this->clock->now()->format('Y-m-d H:i:s'),
            ]);
    }

    /**
     * Persist gateway-side identifiers + metadata after the gateway returns its intent.
     *
     * @since 1.0.0
     */
    public function setGatewayIntent(Donation $donation, string $intentId, ?array $metadata = null): Donation
    {
        $donation->gateway_intent_id = $intentId;
        if ($metadata !== null) {
            $donation->gateway_metadata = $metadata;
        }
        $donation->updated_at = $this->clock->now()->format('Y-m-d H:i:s');
        $donation->updateColumns([
            'gateway_intent_id' => $donation->gateway_intent_id,
            'gateway_metadata'  => $donation->gateway_metadata,
            'updated_at'        => $donation->updated_at,
        ]);

        return $donation;
    }

    /**
     * Signal that a freshly-created donation is waiting on customer action or
     * settlement (Stripe `requires_action`, SEPA pending, async card methods).
     * Status stays `pending`; this is purely an observability hook for emails
     * and analytics. Distinct from `intent_created` which fires for every new
     * donation including immediately-charged ones.
     *
     * @param array<string,mixed> $metadata
     *
     * @since 1.0.0
     */
    public function markPending(Donation $donation, string $reason, array $metadata = []): void
    {
        if ($donation->status !== 'pending') {
            return;
        }

        $this->events->record('donation.pending', [
            'donor_id'     => $donation->donor_id,
            'donation_id'  => $donation->id,
            'form_id'      => $donation->form_id,
            'campaign_id'  => $donation->campaign_id,
            'country'      => $donation->country,
            'amount_cents' => $donation->amount_cents,
            'currency'     => $donation->currency,
            'payload'      => [
                'gateway'   => $donation->gateway,
                'frequency' => $donation->frequency,
                'reason'    => $reason,
            ] + $metadata,
        ]);

        do_action('gratora.donation.pending', $donation, $reason, $metadata);
    }

    /**
     * Move pending donations to processing while bank settlement is outstanding. Unlike
     * markPending, this changes status; late events cannot move settled or failed donations
     * backwards.
     *
     * @param array<string,mixed> $metadata
     *
     * @since 1.0.0
     */
    public function markProcessing(Donation $donation, string $reason, array $metadata = []): Donation
    {
        if ($donation->status !== 'pending') {
            return $donation;
        }

        $now = $this->clock->now()->format('Y-m-d H:i:s');

        // Conditional transition, for the same reason confirm() uses one: a
        // redelivered webhook and a redirect return can both hold this row as
        // pending, and only one of them may fire the side effects.
        $trashedAt = $donation->trashed_at;
        $trashedBy = $donation->trashed_by;

        $applied = Donation::query()
            ->where('id', $donation->id)
            ->where('status', 'pending')
            ->update([
                'status'     => 'processing',
                'updated_at' => $now,
                // Same rule confirm() applies: a debit on its way is money
                // arriving, and it must not settle into a hidden row.
                'trashed_at' => null,
                'trashed_by' => null,
            ])
            ->affectedRows;

        if ($applied < 1) {
            return $this->donations->findById($donation->id) ?? $donation;
        }

        $donation->status           = 'processing';

        if ($trashedAt !== null) {
            $donation->trashed_at = null;
            $donation->trashed_by = null;
            $this->recordUntrashedBySettlement($donation, $trashedAt, $trashedBy);
        }
        $donation->gateway_metadata = ['processing_reason' => $reason]
            + $metadata
            + (array) ($donation->gateway_metadata ?? []);
        $donation->updated_at       = $now;
        // The capture id the caller recorded on the row it handed over travels
        // with the transition, the way the whole-row write carried it.
        $donation->updateColumns([
            'status'           => $donation->status,
            'gateway_txn_id'   => $donation->gateway_txn_id,
            'gateway_metadata' => $donation->gateway_metadata,
            'updated_at'       => $donation->updated_at,
        ]);

        $this->events->record('donation.processing', [
            'donor_id'     => $donation->donor_id,
            'donation_id'  => $donation->id,
            'form_id'      => $donation->form_id,
            'campaign_id'  => $donation->campaign_id,
            'country'      => $donation->country,
            'amount_cents' => $donation->amount_cents,
            'currency'     => $donation->currency,
            'payload'      => [
                'gateway'   => $donation->gateway,
                'frequency' => $donation->frequency,
                'reason'    => $reason,
            ] + $metadata,
        ]);

        do_action('gratora.donation.processing', $donation, $reason, $metadata);

        return $donation;
    }

    /**
     * Use the clock and log invalid or implausible gateway timestamps; bad metadata must not
     * prevent a paid donation from confirming.
     *
     * @since 1.0.0
     */
    private function paidAtFrom(mixed $raw, string $now): string
    {
        if (! is_string($raw) || trim($raw) === '') {
            return $now;
        }

        $raw = trim($raw);
        $ts  = strtotime($raw);
        if ($ts === false) {
            ErrorLog::toDebugLog(sprintf('unreadable paid_at %s; using the clock instead.', $raw));
            return $now;
        }

        $stamp = gmdate('Y-m-d H:i:s', $ts);

        // Two days of slack, not one: the record-a-donation endpoint already
        // allows a calendar day ahead of the site for an admin east of it, and
        // this must not undo that. Wide enough for any offset in use, narrow
        // enough to catch a mistyped year.
        $latest   = gmdate('Y-m-d H:i:s', (int) strtotime($now) + (2 * 86400));
        $earliest = '2000-01-01 00:00:00';

        if ($stamp > $latest || $stamp < $earliest) {
            ErrorLog::toDebugLog(sprintf('paid_at %s is outside the plausible range; using the clock instead.', $raw));
            return $now;
        }

        return $stamp;
    }

    /**
     * The record of a trash that settlement undid.
     *
     * Written directly rather than through EventRecorder, which swallows write
     * failures and runs a rewritable filter. A row reappearing in the list with
     * nothing saying why is the one outcome this has to rule out.
     *
     * donor_id stays null on purpose: this is something the gateway did, and on
     * the donor's own timeline it would read as their activity.
     *
     * @since 1.0.0
     */
    private function recordUntrashedBySettlement(Donation $donation, string $trashedAt, ?int $trashedBy): void
    {
        $e               = Event::make();
        $e->type         = 'donation.untrashed_by_settlement';
        $e->donation_id  = (int) $donation->id;
        $e->campaign_id  = $donation->campaign_id !== null ? (int) $donation->campaign_id : null;
        $e->form_id      = $donation->form_id !== null ? (int) $donation->form_id : null;
        $e->amount_cents = (int) $donation->amount_cents;
        $e->currency     = (string) $donation->currency;
        $e->occurred_at  = $this->clock->now()->format('Y-m-d H:i:s');
        $e->payload      = [
            'reference'  => (string) $donation->reference,
            'status'     => (string) $donation->status,
            'kind'       => (string) $donation->kind,
            'gateway'    => (string) $donation->gateway,
            'fund_id'    => $donation->fund_id !== null ? (int) $donation->fund_id : null,
            'created_at' => (string) $donation->created_at,
            'trashed_at' => $trashedAt,
            'trashed_by' => $trashedBy,
        ];
        $e->save();
    }

    /** @since 1.0.0 */
    public function confirm(Donation $donation, array $result): Donation
    {
        // Only pending/processing/failed may move to paid. Guards every caller
        // at once: a replayed gateway webhook or a re-confirm against a
        // refunded donation must not resurrect it (paid stays idempotent).
        // `processing` is here because bank debit settles days after it is
        // authorised, and that settlement is what makes it paid.
        if (! in_array($donation->status, ['pending', 'processing', 'failed'], true)) {
            return $donation;
        }

        $now = $this->clock->now()->format('Y-m-d H:i:s');
        // An admin recording a check banked last month is stating when the
        // money arrived, which is not when they got round to typing it in.
        // Everything else leaves this unset and gets the clock.
        $paidAt = $this->paidAtFrom($result['paid_at'] ?? null, $now);

        // Read before the write clears them. Money that lands has to land
        // somewhere visible, and what it cleared is the only thing that
        // explains the row coming back.
        $trashedAt = $donation->trashed_at;
        $trashedBy = $donation->trashed_by;

        $affected = 0;
        DB::transaction(function () use ($donation, $result, $now, $paidAt, $trashedAt, $trashedBy, &$affected) {
            // Single-winner transition. The sync redirect-return auto_confirm
            // and the payment_intent.succeeded webhook can both load the same
            // pending row; the conditional UPDATE lets exactly one flip it to
            // paid, so completion side effects (receipt, thank-you email) fire
            // once. An in-memory status check alone races between processes.
            $affected = Donation::query()
                ->where('id', $donation->id)
                ->whereIn('status', ['pending', 'processing', 'failed'])
                ->update([
                    'status'     => 'paid',
                    'updated_at' => $now,
                    // Cleared in the same statement that settles it: a
                    // donation that took money must not be in the bin.
                    'trashed_at' => null,
                    'trashed_by' => null,
                ])
                ->affectedRows;

            if ($affected < 1) {
                return;
            }

            $donation->status               = 'paid';

            if ($trashedAt !== null) {
                $donation->trashed_at = null;
                $donation->trashed_by = null;
                $this->recordUntrashedBySettlement($donation, $trashedAt, $trashedBy);
            }
            $donation->gateway_txn_id       = $result['gateway_txn_id'] ?? $donation->gateway_txn_id;
            $donation->payment_method       = $result['payment_method'] ?? $donation->payment_method;
            $donation->payment_method_brand = $result['payment_method_brand'] ?? null;
            $donation->payment_method_last4 = $result['payment_method_last4'] ?? null;
            $donation->fee_cents            = isset($result['fee_cents']) ? (int) $result['fee_cents'] : 0;
            $donation->net_cents            = max(0, $donation->amount_cents - $donation->fee_cents);
            // Merged, not replaced: a donation reaches this point carrying what
            // earlier steps learned about it, and the settling event only knows
            // about itself. Replacing drops the hold reason, the order id and
            // the payer email at the one moment somebody wants to read them.
            $donation->gateway_metadata     = isset($result['metadata'])
                ? ((array) $result['metadata']) + (array) ($donation->gateway_metadata ?? [])
                : $donation->gateway_metadata;
            $donation->paid_at              = $paidAt;
            $donation->updated_at           = $now;

            // Money has moved, which is the proof the public path could not
            // have. Spent before donation.completed, because that event is what
            // sends the receipt, and a receipt reads its address from the donor
            // row: reuniting them afterwards would post it to an empty one.
            //
            // Cleared either way. It has done its work on success, and on a
            // donor who is no longer erased, or whose record this address no
            // longer answers to, it has none to do.
            $carried = (string) ($donation->pending_reactivation_email ?? '');
            if ($carried !== '') {
                $donor = $this->donors->findById($donation->donor_id);
                $plain = $this->crypto->decrypt($carried);
                if ($donor !== null && is_string($plain) && $plain !== '') {
                    $this->donors->reactivateRedacted($donor, $plain);
                }
                $donation->pending_reactivation_email = null;
            }

            $donation->updateColumns([
                'status'                     => $donation->status,
                'gateway_txn_id'             => $donation->gateway_txn_id,
                'payment_method'             => $donation->payment_method,
                'payment_method_brand'       => $donation->payment_method_brand,
                'payment_method_last4'       => $donation->payment_method_last4,
                'fee_cents'                  => $donation->fee_cents,
                'net_cents'                  => $donation->net_cents,
                'gateway_metadata'           => $donation->gateway_metadata,
                'paid_at'                    => $donation->paid_at,
                'pending_reactivation_email' => $donation->pending_reactivation_email,
                'updated_at'                 => $donation->updated_at,
            ]);

            $this->events->record('donation.completed', [
                'donor_id'     => $donation->donor_id,
                'donation_id'  => $donation->id,
                'form_id'      => $donation->form_id,
                'campaign_id'  => $donation->campaign_id,
                'country'      => $donation->country,
                'amount_cents' => $donation->amount_cents,
                'currency'     => $donation->currency,
                'payload'      => ['gateway' => $donation->gateway, 'frequency' => $donation->frequency],
            ]);

        });

        if ($affected < 1) {
            // Lost the race: another caller already completed this donation and
            // fired its side effects. Return the persisted row, fire nothing.
            return Donation::query()->find('id', (int) $donation->id) ?? $donation;
        }

        // Outside the transaction that took the money: each of these is a whole
        // aggregate over the campaign, form and fund, and a lock wait or a
        // deadlock in one of them must not put a donation the gateway has
        // already charged back to pending. Before the hook, so a listener still
        // reads the figures this donation is part of. Donor counters are
        // deferred to the post-commit listener.
        $this->resyncAggregatesFor($donation);

        do_action('gratora.donation.completed', $donation);

        return $donation;
    }

    /**
     * Create + confirm a renewal donation under an existing recurring plan; fires
     * gratora.donation.completed plus gratora.recurring.renewed. Idempotent per (gateway,
     * intent): an existing donation is returned without a second renewal event.
     *
     * @param array<string,mixed> $confirmResult Same shape DonationService::confirm() consumes.
     *
     * @return array{donation: Donation, created: bool} `created` is false for a
     *   redelivered webhook (the renewal row already existed), so the caller can
     *   skip non-idempotent side effects like bumping plan payment counters.
     *
     * @since 1.0.0
     */
    public function createRenewal(
        \Gratora\Recurring\RecurringPlan $plan,
        int $amountCents,
        string $currency,
        string $gateway,
        string $gatewayIntentId,
        array $confirmResult = [],
    ): array {
        // Idempotency: webhook re-delivery must not create a duplicate donation.
        $existing = Donation::query()
            ->where('gateway', $gateway)
            ->where('gateway_intent_id', $gatewayIntentId)
            ->get();
        if ($existing) {
            // A prior delivery created the row but may have failed before
            // confirm(); re-confirm a still-pending renewal so redelivery heals
            // it instead of stranding it out of all reporting.
            if ($existing->status === 'pending') {
                $this->confirm($existing, $confirmResult);
            }
            return ['donation' => $existing, 'created' => false];
        }

        $now = $this->clock->now()->format('Y-m-d H:i:s');

        $donation = Donation::make();
        $donation->reference         = $this->references->next($this->numberingScope((bool) $plan->is_test));
        $donation->status_token_hash = '';
        $donation->donor_id          = $plan->donor_id;
        $donation->form_id           = $plan->form_id;
        $donation->campaign_id       = $plan->campaign_id;
        $donation->fund_id           = $plan->fund_id;
        $donation->fundraiser_id     = $plan->fundraiser_id;
        $donation->fundraiser_team_id= $plan->fundraiser_team_id;
        $donation->recurring_plan_id = $plan->id;
        $donation->amount_cents      = $amountCents;
        $donation->fee_cents         = 0;
        $donation->net_cents         = $amountCents;
        $donation->currency          = strtoupper($currency);

        $initial = Donation::query()
            ->where('recurring_plan_id', $plan->id)
            ->orderBy('id', 'asc')
            ->get();

        $base = strtoupper(Money::defaultCurrency());
        if ($donation->currency === $base) {
            $donation->base_currency     = $base;
            $donation->fx_rate           = sprintf('%.8F', 1);
            $donation->base_amount_cents = $amountCents;
        } else {
            $rate = $this->fx->rate($donation->currency, $base);
            // Already charged at the gateway, so never reject; fall back to the
            // rate the first donation locked in rather than leave base NULL.
            if ($rate === null && $initial && $initial->fx_rate !== null) {
                $rate = (float) $initial->fx_rate;
            }
            if ($rate !== null) {
                $donation->base_currency     = $base;
                $donation->fx_rate           = sprintf('%.8F', $rate);
                $donation->base_amount_cents = (int) round($amountCents * $rate);
            }
        }

        // Carry demographic fields from the initial donation so receipts,
        // locale matching, and the anonymous flag persist across renewals.
        if ($initial) {
            $donation->country          = $initial->country;
            $donation->locale           = $initial->locale;
            $donation->donor_first_name = $initial->donor_first_name;
            $donation->donor_last_name  = $initial->donor_last_name;
            $donation->is_anonymous     = (bool) $initial->is_anonymous;
        }

        $donation->frequency      = $this->frequencyFromPlan($plan);
        $donation->status         = 'pending';
        $donation->gateway        = $gateway;
        $donation->gateway_intent_id = $gatewayIntentId;
        // Renew in the plan’s stored mode, independent of current settings.
        $donation->is_test        = (bool) $plan->is_test;
        $donation->created_at     = $now;
        $donation->updated_at     = $now;
        try {
            $donation->save();
        } catch (\Gratora\Vendor\Queryable\QueryException $e) {
            // Lost the race to a concurrent redelivery of the same invoice: the
            // other call already inserted the row (UNIQUE gateway_intent_id).
            // Return the winner idempotently instead of throwing a 500.
            $winner = Donation::query()
                ->where('gateway', $gateway)
                ->where('gateway_intent_id', $gatewayIntentId)
                ->get();
            if (! $winner) throw $e;
            if ($winner->status === 'pending') {
                $this->confirm($winner, $confirmResult);
            }
            return ['donation' => $winner, 'created' => false];
        }

        // The renewal's own creation seam, fired before confirm() so an add-on
        // row exists by the time the donation counts.
        //
        // Separate from gratora.donation.creating: a renewal has no
        // DonationIntent and no form submission behind it, so the listeners
        // that read those would be handed a lie. What carries over is the plan
        // and the donor's standing record.
        do_action(
            'gratora.donation.renewal_creating',
            $donation,
            $plan,
            Donor::query()->where('id', (int) $plan->donor_id)->get()
        );

        // Confirm immediately (caller has already established that the gateway
        // charge succeeded; we just mirror that locally).
        $this->confirm($donation, $confirmResult);

        $this->events->record('recurring.renewed', [
            'donor_id'     => $donation->donor_id,
            'donation_id'  => $donation->id,
            'form_id'      => $donation->form_id,
            'campaign_id'  => $donation->campaign_id,
            'country'      => $donation->country,
            'amount_cents' => $donation->amount_cents,
            'currency'     => $donation->currency,
            'payload'      => [
                'gateway'           => $donation->gateway,
                'recurring_plan_id' => $plan->id,
                'payments_count'    => $plan->payments_count,
            ],
        ]);

        do_action('gratora.recurring.renewed', $donation, $plan);

        return ['donation' => $donation, 'created' => true];
    }

    /**
     * Record a failed PaymentIntent -> Subscription conversion. The first charge is
     * already collected, so no refund; subscription_creation_failed* flags give the
     * admin a retry affordance instead of silently losing all future renewals.
     *
     * @since 1.0.0
     */
    public function recordSubscriptionCreationFailure(Donation $donation, \Throwable $e): void
    {
        $now = $this->clock->now()->format('Y-m-d H:i:s');

        $donation->flags = array_merge(
            (array) ($donation->flags ?? []),
            [
                'subscription_creation_failed'        => true,
                'subscription_creation_failed_reason' => $e->getMessage(),
                'subscription_creation_failed_at'     => $now,
            ]
        );
        $donation->updated_at = $now;
        $donation->updateColumns([
            'flags'      => $donation->flags,
            'updated_at' => $donation->updated_at,
        ]);

        // Also to the log, because that is the screen someone opens when a
        // recurring donation did not behave, and a donor left on a schedule
        // nobody is collecting is exactly what it exists to report.
        ErrorLog::record(
            'recurring.' . $donation->gateway,
            sprintf(
                /* translators: 1: donation reference, 2: the gateway's own message */
                __('No recurring plan was created for %1$s, so nothing will renew: %2$s', 'gratora-donation-platform'),
                (string) $donation->reference,
                $e->getMessage()
            ),
            ['donation_id' => (int) $donation->id, 'gateway' => (string) $donation->gateway]
        );

        $this->events->record('recurring.subscription_creation_failed', [
            'donor_id'     => $donation->donor_id,
            'donation_id'  => $donation->id,
            'form_id'      => $donation->form_id,
            'campaign_id'  => $donation->campaign_id,
            'amount_cents' => $donation->amount_cents,
            'currency'     => $donation->currency,
            'payload'      => [
                'gateway' => $donation->gateway,
                'reason'  => $e->getMessage(),
            ],
        ]);

        do_action('gratora.recurring.subscription_creation_failed', $donation, $e);
    }

    /** @since 1.0.0 */
    public function clearSubscriptionCreationFailure(Donation $donation): void
    {
        $flags = (array) ($donation->flags ?? []);
        unset(
            $flags['subscription_creation_failed'],
            $flags['subscription_creation_failed_reason'],
            $flags['subscription_creation_failed_at']
        );
        $donation->flags      = $flags === [] ? null : $flags;
        $donation->updated_at = $this->clock->now()->format('Y-m-d H:i:s');
        $donation->updateColumns([
            'flags'      => $donation->flags,
            'updated_at' => $donation->updated_at,
        ]);
    }

    /**
     * A renewal the gateway could not collect.
     *
     * Counted by the repository, announced here, so every surface that cares
     * hears the same thing: the donor's dunning email, reporting, and anything
     * an add-on wires up.
     *
     * `attempt` is the running failure count for this plan, so a listener can
     * distinguish a first decline from a card that has been dead for a month.
     *
     * @since 1.0.0
     */
    public function recordRecurringFailure(\Gratora\Recurring\RecurringPlan $plan, ?string $reason = null): void
    {
        $attempt = (int) $plan->failed_renewals_count;

        $this->events->record('recurring.failed', [
            'donor_id'     => $plan->donor_id,
            'donation_id'  => null,
            'form_id'      => $plan->form_id,
            'campaign_id'  => $plan->campaign_id,
            'amount_cents' => $plan->amount_cents,
            'currency'     => $plan->currency,
            'payload'      => [
                'gateway'           => $plan->gateway,
                'recurring_plan_id' => $plan->id,
                'reason'            => $reason,
                'attempt'           => $attempt,
            ],
        ]);

        do_action('gratora.recurring.renewal_failed', $plan, [
            'gateway' => (string) $plan->gateway,
            'reason'  => $reason,
            'attempt' => $attempt,
        ]);
    }

    /** @since 1.0.0 */
    public function recordRecurringCancellation(\Gratora\Recurring\RecurringPlan $plan, ?string $reason = null): void
    {
        $this->events->record('recurring.cancelled', [
            'donor_id'     => $plan->donor_id,
            'donation_id'  => null,
            'form_id'      => $plan->form_id,
            'campaign_id'  => $plan->campaign_id,
            'amount_cents' => $plan->amount_cents,
            'currency'     => $plan->currency,
            'payload'      => [
                'gateway'           => $plan->gateway,
                'recurring_plan_id' => $plan->id,
                'reason'            => $reason,
            ],
        ]);

        do_action('gratora.recurring.cancelled', $plan, $reason);
    }

    /** @since 1.0.0 */
    private function frequencyFromPlan(\Gratora\Recurring\RecurringPlan $plan): string
    {
        // Plan stores Stripe-shaped interval; donations carry the Gratora label.
        return match ([$plan->interval_unit, $plan->interval_count]) {
            ['week',  1] => 'weekly',
            ['week',  2] => 'biweekly',
            ['month', 1] => 'monthly',
            ['month', 3] => 'quarterly',
            ['year',  1] => 'yearly',
            default       => 'monthly',
        };
    }

    /**
     * Money that landed and was taken back by the bank.
     *
     * Only bank debit can do this. A Direct Debit confirms, gets counted, and
     * can still fail days later when the bank bounces it, or be charged back
     * months later under the Direct Debit Guarantee. Neither is a refund: the
     * charity did not choose it and gave nothing back, so no Refund row is
     * written and the donation walks to `disputed` instead.
     *
     * What it shares with a refund is that the money must leave every total,
     * which the aggregate syncers do for free once the status is off `paid`.
     *
     * @param string $kind chargeback or late_failure
     *
     * @since 1.0.0
     */
    public function markReversed(Donation $donation, string $kind, ?string $reason = null): Donation
    {
        $now = $this->clock->now()->format('Y-m-d H:i:s');

        // Conditional, because GoCardless redelivers webhooks and one delivery
        // carries many events, so the same reversal arrives more than once as a
        // matter of course. Only money still counted can be taken back:
        // `refunded` is settled and `disputed` is already done.
        $applied = DB::table('gratora_donations')
            ->where('id', $donation->id)
            ->whereIn('status', ['paid', 'partial_refund'])
            ->update([
                'status'         => 'disputed',
                'reversal_kind'  => $kind,
                'failure_reason' => $reason,
                'updated_at'     => $now,
            ])->affectedRows;

        if ($applied === 0) {
            return $this->donations->findById($donation->id) ?? $donation;
        }

        $donation->status         = 'disputed';
        $donation->reversal_kind  = $kind;
        $donation->failure_reason = $reason;
        $donation->updated_at     = $now;

        $this->events->record('donation.disputed', [
            'donor_id'     => $donation->donor_id,
            'donation_id'  => $donation->id,
            'form_id'      => $donation->form_id,
            'campaign_id'  => $donation->campaign_id,
            'amount_cents' => $donation->amount_cents,
            'currency'     => $donation->currency,
            'payload'      => [
                'gateway' => $donation->gateway,
                'kind'    => $kind,
                'reason'  => $reason,
            ],
        ]);

        $this->resyncAggregatesFor($donation);

        do_action('gratora.donation.disputed', $donation, $kind);

        return $donation;
    }

    /**
     * The charity contested the reversal and won, so the money is theirs again.
     * Clears the kind: the row should not keep claiming it was charged back
     * once the claim has been thrown out.
     *
     * @since 1.0.0
     */
    public function reinstateReversed(Donation $donation): Donation
    {
        $now = $this->clock->now()->format('Y-m-d H:i:s');

        $applied = DB::table('gratora_donations')
            ->where('id', $donation->id)
            ->where('status', 'disputed')
            ->update([
                // A donation that was partly refunded before the reversal comes
                // back as partial_refund, not paid, or the refund would vanish
                // from the books along with the dispute.
                'status'         => (int) $donation->refunded_cents > 0 ? 'partial_refund' : 'paid',
                'reversal_kind'  => null,
                'failure_reason' => null,
                'updated_at'     => $now,
            ])->affectedRows;

        if ($applied === 0) {
            return $this->donations->findById($donation->id) ?? $donation;
        }

        $donation->status         = (int) $donation->refunded_cents > 0 ? 'partial_refund' : 'paid';
        $donation->reversal_kind  = null;
        $donation->failure_reason = null;
        $donation->updated_at     = $now;

        $this->events->record('donation.reversal_reinstated', [
            'donor_id'     => $donation->donor_id,
            'donation_id'  => $donation->id,
            'form_id'      => $donation->form_id,
            'campaign_id'  => $donation->campaign_id,
            'amount_cents' => $donation->amount_cents,
            'currency'     => $donation->currency,
            'payload'      => ['gateway' => $donation->gateway],
        ]);

        $this->resyncAggregatesFor($donation);

        do_action('gratora.donation.reversal_reinstated', $donation);

        return $donation;
    }

    /**
     * Counters, not money. Every caller runs this after its transaction has
     * committed, so a recompute that cannot finish leaves a figure stale and
     * nothing else: the donation stands, the receipt and the thank-you still
     * go, and Tools, Recalculate is what puts the figure right.
     *
     * @since 1.0.0
     */
    private function resyncAggregatesFor(Donation $donation): void
    {
        try {
            if ($donation->campaign_id) {
                $this->aggregates->syncCampaign((int) $donation->campaign_id);
            }
            if ($donation->form_id) {
                $this->aggregates->syncForm((int) $donation->form_id);
            }
            if ($donation->fund_id) {
                $this->aggregates->syncFund((int) $donation->fund_id);
            }
        } catch (Throwable $e) {
            ErrorLog::record('donations.aggregates', $e->getMessage(), [
                'donation_id' => (int) $donation->id,
                'campaign_id' => (int) ($donation->campaign_id ?? 0),
            ]);
        }
    }

    /** @since 1.0.0 */
    public function markFailed(Donation $donation, ?string $reason = null): Donation
    {
        // Paid money is refunded, not failed; refund states are terminal.
        if (in_array($donation->status, ['paid', 'refunded', 'partial_refund'], true)) {
            return $donation;
        }

        $now = $this->clock->now()->format('Y-m-d H:i:s');

        // Conditional transition: a webhook may have moved the row to a terminal
        // (paid/refunded) state since this object loaded. Only fail it while it
        // is still non-terminal so we never clobber real money back to failed.
        $applied = DB::table('gratora_donations')
            ->where('id', $donation->id)
            // Exclude 'failed' too: a redelivered payment_intent.payment_failed
            // must not re-run the transition (fresh updated_at would count as a
            // changed row) and re-fire the donation.failed event.
            ->whereNotIn('status', ['paid', 'refunded', 'partial_refund', 'failed'])
            ->update([
                'status'         => 'failed',
                'failure_reason' => $reason,
                'updated_at'     => $now,
            ])->affectedRows;

        if ($applied === 0) {
            // Lost the race to a terminal transition; reflect the winning row.
            return $this->donations->findById($donation->id) ?? $donation;
        }

        $donation->status         = 'failed';
        $donation->failure_reason = $reason;
        $donation->updated_at     = $now;

        $this->events->record('donation.failed', [
            'donor_id'    => $donation->donor_id,
            'donation_id' => $donation->id,
            'form_id'     => $donation->form_id,
            'campaign_id' => $donation->campaign_id,
            'country'     => $donation->country,
            'payload'     => ['gateway' => $donation->gateway, 'reason' => $reason],
        ]);

        do_action('gratora.donation.failed', $donation);

        return $donation;
    }

    /**
     * Record a refund the gateway already executed externally.
     *
     * @since 1.0.0
     */
    public function recordExternalRefund(
        Donation $donation,
        int $amountCents,
        string $gatewayRefundId,
        ?string $reason = null,
        string $initiatedBy = 'gateway',
        ?array $metadata = null,
        bool $settled = true,
        ?int $initiatedUserId = null
    ): Refund {
        if ($amountCents <= 0) {
            throw new RuntimeException(esc_html("Invalid external refund amount: {$amountCents}."));
        }
        if ($gatewayRefundId === '') {
            throw new RuntimeException(esc_html('External refund missing gateway_refund_id; cannot dedup.'));
        }

        // Redelivered webhook: idempotent return. A row still awaiting the
        // gateway is the exception, because the call that says the money has
        // actually gone is the one this was waiting for: that row is cleared
        // out of the way so the settled path below runs whole, rather than
        // being reported as already handled and never taking the money off the
        // books at all.
        $existing = Refund::query()
            ->where('gateway_refund_id', $gatewayRefundId)
            ->get();
        $replacing = null;
        if ($existing) {
            // A reversed row is spent in the same way an awaited one is: the
            // money it described came back, so the id names nothing standing,
            // and reporting it as already handled means a gateway that takes
            // the money a second time can never take it off the books again.
            //
            // A failed row is spent too. It was let go because the gateway
            // seemed to have abandoned it, so a settlement arriving afterwards
            // is the money genuinely leaving and has to be booked, not dropped
            // as a duplicate of a refund that never happened.
            $spent = in_array((string) $existing->status, ['pending', 'reversed', 'failed'], true);

            if (! $spent || ! $settled) {
                return $existing;
            }

            $replacing = (int) $existing->id;
        }

        if (! $settled) {
            return $this->recordAwaitedRefund($donation, $amountCents, $gatewayRefundId, $reason, $initiatedBy, $metadata);
        }

        if ($donation->status !== 'paid' && $donation->status !== 'partial_refund') {
            throw new RuntimeException(
                esc_html("Donation {$donation->reference} not refundable locally (status: {$donation->status}).")
            );
        }

        $alreadyRefunded = (int) Refund::query()
            ->where('donation_id', $donation->id)
            ->where('status', 'succeeded')
            ->sum('amount_cents');

        // Clamp against the remaining balance, mirroring refund(). Out-of-order
        // or duplicated gateway events (a lost dispute, charge.refunded with an
        // array of refunds) can report more than is left; without this the
        // cumulative refunded can exceed the principal and drive net base
        // aggregates negative.
        $maxRefundable = max(0, $donation->amount_cents - $alreadyRefunded);
        if ($amountCents > $maxRefundable) {
            ErrorLog::toDebugLog(sprintf(
                'external refund for %s reports %d cents but only %d is refundable; clamping.',
                $donation->reference,
                $amountCents,
                $maxRefundable
            ));
            $amountCents = $maxRefundable;
        }
        if ($amountCents <= 0) {
            throw new RuntimeException(
                esc_html("External refund for {$donation->reference} exceeds the refundable balance.")
            );
        }

        $now    = $this->clock->now()->format('Y-m-d H:i:s');
        $refund = Refund::make();

        try {
        DB::transaction(function () use (
            $donation, $amountCents, $reason, $initiatedBy, $metadata,
            $gatewayRefundId, $now, $refund, $replacing, $initiatedUserId
        ) {
            // Atomic over-refund guard: bump the cumulative counter only while
            // the new total still fits the principal. A concurrent refund that
            // consumed the balance since the SUM was read makes this match zero
            // rows; the increment's row lock then serialises the rest of this
            // transaction so the check-then-act clamp above cannot be outrun.
            $reserved = DB::table('gratora_donations')
                ->whereRaw('id = ' . (int) $donation->id . ' AND refunded_cents + ' . (int) $amountCents . ' <= amount_cents')
                ->increment('refunded_cents', (int) $amountCents);
            if ($reserved->affectedRows < 1) {
                throw new RuntimeException(
                    esc_html("External refund for {$donation->reference} exceeds the refundable balance.")
                );
            }
            $newTotal = (int) (DB::table('gratora_donations')
                ->where('id', $donation->id)
                ->selectRaw('refunded_cents AS total')
                ->get()['total'] ?? 0);
            $isFullRefund = $newTotal >= $donation->amount_cents;
            $donation->refunded_cents = $newTotal;

            // Delete the replaced refund only after all refusal checks pass.
            if ($replacing !== null) {
                Refund::query()->where('id', $replacing)->delete();
            }

            $refund->donation_id       = $donation->id;
            $refund->amount_cents      = $amountCents;
            $refund->currency          = $donation->currency;
            $refund->reason            = $reason;
            $refund->initiated_by      = $initiatedBy;
            $refund->initiated_user_id = $initiatedUserId;
            $refund->gateway_refund_id = $gatewayRefundId;
            $refund->status            = 'succeeded';
            $refund->metadata          = $metadata;
            $refund->occurred_at       = $now;
            $refund->save();

            $donation->status      = $isFullRefund ? 'refunded' : 'partial_refund';
            $donation->refunded_at = $now;
            $donation->updated_at  = $now;
            $donation->updateColumns([
                'status'      => $donation->status,
                'refunded_at' => $donation->refunded_at,
                'updated_at'  => $donation->updated_at,
            ]);

            if ($isFullRefund) {
                $this->voidReceiptsFor($donation, $now);
            }

            $this->events->record('donation.refunded', [
                'donor_id'     => $donation->donor_id,
                'donation_id'  => $donation->id,
                'form_id'      => $donation->form_id,
                'campaign_id'  => $donation->campaign_id,
                'amount_cents' => $amountCents,
                'currency'     => $donation->currency,
                'payload'      => [
                    'gateway'           => $donation->gateway,
                    'refund_id'         => $refund->id,
                    'gateway_refund_id' => $gatewayRefundId,
                    'is_full_refund'    => $isFullRefund,
                    'initiated_by'      => $initiatedBy,
                ],
            ]);

        });
        } catch (\Gratora\Vendor\Queryable\QueryException $e) {
            // Reuse only the recorded winner of the refund-ID race. Awaited or reversal-spent
            // rows still need settlement.
            $dup = Refund::query()->where('gateway_refund_id', $gatewayRefundId)->get();
            if ($dup && ! in_array((string) $dup->status, ['pending', 'reversed'], true)) {
                return $dup;
            }
            throw $e;
        }

        $this->resyncAggregatesFor($donation);

        do_action('gratora.donation.refunded', $donation, $refund);

        return $refund;
    }

    /**
     * A refund the gateway has taken on but not completed.
     *
     * Recorded so the admin can see it was asked for, and deliberately nothing
     * else: the money is still with the org, so the donation stays paid, its
     * receipt stays valid, the totals stay whole and the donor is not told they
     * have been repaid. What settles it is the gateway saying so, which comes
     * back through recordExternalRefund and replaces this row.
     *
     * @param array<string,mixed>|null $metadata
     *
     * @since 1.0.0
     */
    private function recordAwaitedRefund(
        Donation $donation,
        int $amountCents,
        string $gatewayRefundId,
        ?string $reason,
        string $initiatedBy,
        ?array $metadata
    ): Refund {
        $refund = Refund::make();

        $refund->donation_id       = $donation->id;
        $refund->amount_cents      = $amountCents;
        $refund->currency          = $donation->currency;
        $refund->reason            = $reason;
        $refund->initiated_by      = $initiatedBy;
        $refund->initiated_user_id = null;
        $refund->gateway_refund_id = $gatewayRefundId;
        $refund->status            = 'pending';
        $refund->metadata          = $metadata;
        $refund->occurred_at       = $this->clock->now()->format('Y-m-d H:i:s');
        $refund->save();

        return $refund;
    }

    /**
     * Refunds the gateway has accepted and not settled, in cents.
     *
     * The money is out of the org's hands and not yet in the donor's, so it
     * belongs to neither balance: it is off the refundable amount without being
     * counted as refunded.
     *
     * @since 1.0.0
     */
    public static function inFlightRefundCents(Donation $donation): int
    {
        return (int) Refund::query()
            ->where('donation_id', $donation->id)
            ->where('status', 'pending')
            ->sum('amount_cents');
    }

    /**
     * The gateway gave up on a refund it had taken on, so the awaited row stops
     * holding the balance it was reserving. Nothing else moves: an awaited
     * refund never came off any total in the first place.
     *
     * @since 1.0.0
     */
    public function failAwaitedRefund(Donation $donation, string $gatewayRefundId): ?Refund
    {
        if ($gatewayRefundId === '') {
            return null;
        }

        $refund = Refund::query()
            ->where('gateway_refund_id', $gatewayRefundId)
            ->where('status', 'pending')
            ->get();

        if (! $refund || (int) $refund->donation_id !== (int) $donation->id) {
            return null;
        }

        $refund->status = 'failed';
        $refund->save();

        return $refund;
    }

    /**
     * Undo an external refund the gateway has reversed.
     *
     * A dispute Gratora lost is recorded as a refund, which drops the money out of
     * every total and voids the receipt. Winning it later puts the money back
     * on the balance, so leaving the refund standing keeps the donation missing
     * from the org's own reporting for good.
     *
     * Idempotent on the gateway refund id: a redelivered reinstatement finds
     * nothing still succeeded and returns null.
     *
     * @since 1.0.0
     */
    public function reverseExternalRefund(Donation $donation, string $gatewayRefundId): ?Refund
    {
        if ($gatewayRefundId === '') {
            return null;
        }

        $refund = Refund::query()
            ->where('gateway_refund_id', $gatewayRefundId)
            ->where('status', 'succeeded')
            ->get();

        if (! $refund || (int) $refund->donation_id !== (int) $donation->id) {
            return null;
        }

        $now    = $this->clock->now()->format('Y-m-d H:i:s');
        $amount = (int) $refund->amount_cents;

        DB::transaction(function () use ($donation, $refund, $amount, $now) {
            // Guarded decrement, mirroring the reservation on the way in, so
            // two reinstatements for one dispute cannot drive the counter
            // below zero.
            $released = DB::table('gratora_donations')
                ->whereRaw('id = ' . (int) $donation->id . ' AND refunded_cents >= ' . $amount)
                ->increment('refunded_cents', -$amount);
            if ($released->affectedRows < 1) {
                throw new RuntimeException(
                    esc_html("Cannot reverse refund on {$donation->reference}: the refunded total is already below it.")
                );
            }

            $refund->status     = 'reversed';
            $refund->save();

            $newTotal = (int) (DB::table('gratora_donations')
                ->where('id', $donation->id)
                ->selectRaw('refunded_cents AS total')
                ->get()['total'] ?? 0);

            $donation->refunded_cents = $newTotal;
            $donation->status         = $newTotal > 0 ? 'partial_refund' : 'paid';
            $donation->refunded_at    = $newTotal > 0 ? $donation->refunded_at : null;
            $donation->updated_at     = $now;
            $donation->updateColumns([
                'refunded_cents' => $donation->refunded_cents,
                'status'         => $donation->status,
                'refunded_at'    => $donation->refunded_at,
                'updated_at'     => $donation->updated_at,
            ]);

            // A receipt is void only while the donation retains nothing, so a
            // reversal that leaves any money with the org puts it back. The
            // donor's tax document is the thing this reversal is for, and
            // nothing else can restore it: a voided receipt is filtered out of
            // the portal, the receipts route and the admin donation, and the
            // re-issue path skips voided rows while still reporting that it
            // queued one.
            if ($newTotal < (int) $donation->amount_cents) {
                $this->unvoidReceiptsFor($donation);
            }

            $this->events->record('donation.refund_reversed', [
                'donor_id'     => $donation->donor_id,
                'donation_id'  => $donation->id,
                'form_id'      => $donation->form_id,
                'campaign_id'  => $donation->campaign_id,
                'amount_cents' => $amount,
                'currency'     => $donation->currency,
                'payload'      => [
                    'gateway'           => $donation->gateway,
                    'refund_id'         => $refund->id,
                    'gateway_refund_id' => (string) $refund->gateway_refund_id,
                ],
            ]);

        });

        $this->resyncAggregatesFor($donation);

        do_action('gratora.donation.refund_reversed', $donation, $refund);

        return $refund;
    }

    /**
     * Refund all or part of a paid donation.
     *
     * @throws RuntimeException when the donation isn't refundable or the gateway rejects.
     *
     * @since 1.0.0
     */
    public function refund(
        Donation $donation,
        int $amountCents,
        ?string $reason = null,
        ?int $initiatedUserId = null,
        string $initiatedBy = 'admin'
    ): Refund {
        if ($donation->status !== 'paid' && $donation->status !== 'partial_refund') {
            throw new RuntimeException(esc_html("Donation {$donation->reference} is not refundable (status: {$donation->status})."));
        }

        // A donation can belong to a charge it does not own, and refunding it
        // has to happen where the money actually moved. Whoever holds that link
        // refuses here and says where to go instead. Absence of a transaction
        // id cannot stand in for this: an offline donation has none and is
        // refundable all the same.
        $refusal = apply_filters('gratora.donation.refund_refusal', null, $donation, $amountCents);
        if (is_string($refusal) && $refusal !== '') {
            throw new RuntimeException(esc_html($refusal));
        }

        $alreadyRefunded = (int) Refund::query()
            ->where('donation_id', $donation->id)
            ->where('status', 'succeeded')
            ->sum('amount_cents');

        // A refund the gateway took on but has not settled is money already on
        // its way to the donor. Left out of the balance, the same money is
        // offered again and the second refund pays it twice.
        $inFlight = self::inFlightRefundCents($donation);

        $maxRefundable = max(0, $donation->amount_cents - $alreadyRefunded - $inFlight);
        if ($amountCents <= 0 || $amountCents > $maxRefundable) {
            if ($inFlight > 0) {
                $currency = (string) $donation->currency;
                throw new RuntimeException(esc_html(sprintf(
                    'Invalid refund amount: %s. %s has already been sent to the gateway and has not settled yet, which leaves %s refundable.',
                    Money::format($amountCents, $currency),
                    Money::format($inFlight, $currency),
                    Money::format($maxRefundable, $currency)
                )));
            }

            throw new RuntimeException(
                esc_html("Invalid refund amount: {$amountCents}. Available to refund: {$maxRefundable} cents.")
            );
        }

        $gateway = $this->gateways->get($donation->gateway);
        if (! $gateway) {
            throw new RuntimeException(esc_html("Gateway '{$donation->gateway}' is no longer registered; cannot refund."));
        }

        // Gateway call runs before the transaction: money moves before the local
        // write. If the local write fails, state is stale until a webhook re-syncs it.
        $result = $gateway->refund($donation, $amountCents, $reason);
        if (! $result->success) {
            throw new RuntimeException(esc_html($result->error ?? 'Gateway refund failed.'));
        }

        $recordedCents = (int) ($result->amount_cents ?? $amountCents);

        // The webhook may record this unique refund first. Reuse an unsettled row or reconcile
        // a settled result through the webhook path to avoid duplicate refunds.
        if ($result->gateway_refund_id) {
            $existing = Refund::query()
                ->where('gateway_refund_id', $result->gateway_refund_id)
                ->get();

            if ($existing && ! $result->settled) {
                return $existing;
            }

            if ($existing) {
                return $this->recordExternalRefund(
                    $donation,
                    $recordedCents,
                    (string) $result->gateway_refund_id,
                    $reason,
                    $initiatedBy,
                    $result->metadata,
                    true,
                    $initiatedUserId
                );
            }
        }

        // The gateway took the instruction but has not returned the money, so
        // nothing comes off the books yet: a bank refund created pending can
        // still fail, and until it does the org holds the funds. What settles
        // it is the gateway saying so on its own event, which arrives at
        // recordExternalRefund and replaces this row.
        if (! $result->settled) {
            return $this->recordAwaitedRefund(
                $donation,
                $recordedCents,
                (string) $result->gateway_refund_id,
                $reason,
                $initiatedBy,
                $result->metadata
            );
        }

        $now = $this->clock->now()->format('Y-m-d H:i:s');
        $refund = Refund::make();

        DB::transaction(function () use (
            $donation, $result, $reason, $initiatedBy, $initiatedUserId,
            $amountCents, $recordedCents, $now, $refund
        ) {
            // Atomic over-refund guard (see recordExternalRefund): the counter
            // bump applies only while the new total fits the principal, and its
            // row lock serialises concurrent refunds on this donation.
            $reserved = DB::table('gratora_donations')
                ->whereRaw('id = ' . (int) $donation->id . ' AND refunded_cents + ' . $recordedCents . ' <= amount_cents')
                ->increment('refunded_cents', $recordedCents);
            if ($reserved->affectedRows < 1) {
                throw new RuntimeException(
                    esc_html("Refund for {$donation->reference} exceeds the refundable balance.")
                );
            }
            $newTotal = (int) (DB::table('gratora_donations')
                ->where('id', $donation->id)
                ->selectRaw('refunded_cents AS total')
                ->get()['total'] ?? 0);
            $isFullRefund = $newTotal >= $donation->amount_cents;
            $donation->refunded_cents = $newTotal;

            $refund->donation_id       = $donation->id;
            $refund->amount_cents      = $result->amount_cents ?? $amountCents;
            $refund->currency          = $donation->currency;
            $refund->reason            = $reason;
            $refund->initiated_by      = $initiatedBy;
            $refund->initiated_user_id = $initiatedUserId;
            $refund->gateway_refund_id = $result->gateway_refund_id;
            $refund->status            = 'succeeded';
            $refund->metadata          = $result->metadata;
            $refund->occurred_at       = $now;
            $refund->save();

            $donation->status      = $isFullRefund ? 'refunded' : 'partial_refund';
            $donation->refunded_at = $now;
            $donation->updated_at  = $now;
            $donation->updateColumns([
                'status'      => $donation->status,
                'refunded_at' => $donation->refunded_at,
                'updated_at'  => $donation->updated_at,
            ]);

            if ($isFullRefund) {
                $this->voidReceiptsFor($donation, $now);
            }

            $this->events->record('donation.refunded', [
                'donor_id'     => $donation->donor_id,
                'donation_id'  => $donation->id,
                'form_id'      => $donation->form_id,
                'campaign_id'  => $donation->campaign_id,
                'amount_cents' => $refund->amount_cents,
                'currency'     => $donation->currency,
                'payload'      => [
                    'gateway'           => $donation->gateway,
                    'refund_id'         => $refund->id,
                    'gateway_refund_id' => $refund->gateway_refund_id,
                    'is_full_refund'    => $isFullRefund,
                ],
            ]);

        });

        // Outside the money transaction: a counter that cannot be recomputed
        // must not undo a refund the gateway has already made. Donor counters
        // run from the post-commit gratora.donation.refunded listener.
        $this->resyncAggregatesFor($donation);

        do_action('gratora.donation.refunded', $donation, $refund);

        return $refund;
    }

    /**
     * Put back the receipts a refund voided, when the refund itself is undone.
     *
     * @since 1.0.0
     */
    private function unvoidReceiptsFor(Donation $donation): void
    {
        $receipts = Receipt::query()
            ->where('donation_id', $donation->id)
            ->where('voided', 1)
            ->getAll();

        foreach ($receipts as $receipt) {
            $receipt->voided    = false;
            $receipt->voided_at = null;
            $receipt->save();
        }
    }

    /**
     * Which counter a donation's reference comes from.
     *
     * A rehearsal must not spend numbers from the sequence the org's real
     * donations are numbered in: the test-data purge deletes the rows holding
     * whatever it spent, and what is left is a ledger with holes nobody can
     * account for. Receipts already number this way, for the same reason.
     *
     * @since 1.0.0
     */
    private function numberingScope(bool $isTest): string
    {
        return $isTest ? 'test_donation' : 'donation';
    }

    /**
     * Void a fully refunded donation's receipts (never delete them: legal
     * retention). Only when nothing is retained. The document is rendered from
     * live data on every download, so a partial refund still produces a true
     * receipt - it prints the refunded line and the net total - and voiding it
     * would take the donor's only working copy of a figure they really gave.
     *
     * @since 1.0.0
     */
    private function voidReceiptsFor(Donation $donation, string $now): void
    {
        $receipts = Receipt::query()
            ->where('donation_id', $donation->id)
            ->where('voided', 0)
            ->getAll();
        foreach ($receipts as $receipt) {
            $receipt->voided    = true;
            $receipt->voided_at = $now;
            $receipt->save();
        }
    }

    /**
     * @param array<string,mixed> $custom
     *
     * @since 1.0.0
     */
    private function encodeCustom(array $custom): ?string
    {
        if ($custom === []) {
            return null;
        }
        return $this->crypto->encrypt((string) wp_json_encode($custom));
    }

    /**
     * Decrypt a donation's stored custom field values. Authorized contexts
     * only (admin donation detail). Empty array when absent or unreadable.
     *
     * @return array<string,mixed>
     *
     * @since 1.0.0
     */
    public function decryptCustomData(Donation $donation): array
    {
        if ($donation->custom_data_encrypted === null || $donation->custom_data_encrypted === '') {
            return [];
        }
        $plain = $this->crypto->decrypt($donation->custom_data_encrypted);
        if ($plain === null) {
            return [];
        }
        $data = json_decode($plain, true);
        return is_array($data) ? $data : [];
    }
}
