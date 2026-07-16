<?php

declare(strict_types=1);

namespace Dono\Donations;

use Dono\Analytics\EventRecorder;
use Dono\Donors\DonorService;
use Dono\Foundation\Crypto\Crypto;
use Dono\Foundation\Helpers\Money;
use Dono\Foundation\References\ReferenceGenerator;
use Dono\Foundation\Time\Clock;
use Dono\Gateways\GatewayManager;
use Dono\Gateways\RefundResult;
use Dono\Gateways\TestMode;
use Dono\Receipts\Receipt;
use Dono\Vendor\Queryable\DB;
use RuntimeException;

/**
 * Donation state machine: createPending, setGatewayIntent, confirm, markFailed, refund.
 *
 * Gateway interaction lives in the gateway abstraction; this service records
 * the result and fires hooks for observers.
 *
 * @version 1.0.0
 */
final class DonationService
{
    public function __construct(
        private DonationRepository $donations,
        private DonorService $donors,
        private ReferenceGenerator $references,
        private EventRecorder $events,
        private GatewayManager $gateways,
        private Clock $clock,
        private AggregateSyncer $aggregates,
        private \Dono\Funds\FundResolver $funds,
        private \Dono\Currency\FxRates $fx,
        private \Dono\Forms\FormTypeRegistry $formTypes,
        private Crypto $crypto,
        private TestMode $testMode,
        private ?DonationTributeRepository $tributes = null,
    ) {
    }

    /**
     * Create a pending donation from an intent. Donor is upserted inside the transaction.
     *
     * Returns `[donation, status_token]`. Only the token's SHA-256 hash is persisted;
     * the raw value lives only in the response.
     *
     * @return array{donation: Donation, status_token: string}
     */
    public function createPending(DonationIntent $intent): array
    {
        $intent = apply_filters('dono.donation.intent_creating', $intent);

        $typeHandler = $this->formTypes->handlerFor($intent);
        $intent      = $typeHandler->prepareIntent($intent, $intent->extra);

        $now = $this->clock->now()->format('Y-m-d H:i:s');
        $donation = null;

        $rawStatusToken = bin2hex(random_bytes(16));
        $statusTokenHash = hash('sha256', $rawStatusToken);

        DB::transaction(function () use ($intent, $now, $statusTokenHash, &$donation) {
            $donor = $this->donors->findOrCreate($intent->email, $intent->profile);

            $donation = Donation::make();
            $donation->reference          = $this->references->next('donation');
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
            $donation->is_test            = $this->testMode->forFormId($intent->form_id);
            $donation->created_at         = $now;
            $donation->updated_at         = $now;

            if (isset($intent->extra['fundraiser_id'])) {
                $donation->fundraiser_id = (int) $intent->extra['fundraiser_id'];
            }
            if (isset($intent->extra['fundraiser_team_id'])) {
                $donation->fundraiser_team_id = (int) $intent->extra['fundraiser_team_id'];
            }

            $donation->custom_data_encrypted = $this->encodeCustom($intent->custom);

            $givenFirst = trim((string) ($intent->profile['first_name'] ?? ''));
            $givenLast  = trim((string) ($intent->profile['last_name']  ?? ''));
            $donation->donor_first_name = $givenFirst !== '' ? $givenFirst : null;
            $donation->donor_last_name  = $givenLast  !== '' ? $givenLast  : null;

            $donation->save();

            if ($intent->tribute && $this->tributes !== null) {
                $this->tributes->persist($donation, $intent->tribute);
            }

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

        do_action('dono.donation.intent_created', $donation, $intent);
        $typeHandler->onDonationCreated($donation, $intent->extra);

        return ['donation' => $donation, 'status_token' => $rawStatusToken];
    }

    /** Persist gateway-side identifiers + metadata after the gateway returns its intent. */
    public function setGatewayIntent(Donation $donation, string $intentId, ?array $metadata = null): Donation
    {
        $donation->gateway_intent_id = $intentId;
        if ($metadata !== null) {
            $donation->gateway_metadata = $metadata;
        }
        $donation->updated_at = $this->clock->now()->format('Y-m-d H:i:s');
        $donation->save();

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

        do_action('dono.donation.pending', $donation, $reason, $metadata);
    }

    /**
     * Transition donation to paid
     */
    public function confirm(Donation $donation, array $result): Donation
    {
        // Only pending/failed may move to paid. Guards every caller at once:
        // a replayed gateway webhook or a re-confirm against a refunded
        // donation must not resurrect it (paid stays idempotent).
        if (! in_array($donation->status, ['pending', 'failed'], true)) {
            return $donation;
        }

        $now = $this->clock->now()->format('Y-m-d H:i:s');

        $affected = 0;
        DB::transaction(function () use ($donation, $result, $now, &$affected) {
            // Single-winner transition. The sync redirect-return auto_confirm
            // and the payment_intent.succeeded webhook can both load the same
            // pending row; the conditional UPDATE lets exactly one flip it to
            // paid, so completion side effects (receipt, thank-you email) fire
            // once. An in-memory status check alone races between processes.
            $affected = Donation::query()
                ->where('id', $donation->id)
                ->whereIn('status', ['pending', 'failed'])
                ->update(['status' => 'paid', 'updated_at' => $now])
                ->affectedRows;

            if ($affected < 1) {
                return;
            }

            $donation->status               = 'paid';
            $donation->gateway_txn_id       = $result['gateway_txn_id'] ?? $donation->gateway_txn_id;
            $donation->payment_method       = $result['payment_method'] ?? $donation->payment_method;
            $donation->payment_method_brand = $result['payment_method_brand'] ?? null;
            $donation->payment_method_last4 = $result['payment_method_last4'] ?? null;
            $donation->fee_cents            = isset($result['fee_cents']) ? (int) $result['fee_cents'] : 0;
            $donation->net_cents            = max(0, $donation->amount_cents - $donation->fee_cents);
            $donation->gateway_metadata     = $result['metadata'] ?? $donation->gateway_metadata;
            $donation->paid_at              = $now;
            $donation->updated_at           = $now;
            $donation->save();

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

            // Donor counters are deferred to the post-commit listener.
            if ($donation->campaign_id) {
                $this->aggregates->syncCampaign((int) $donation->campaign_id);
            }
            if ($donation->form_id) {
                $this->aggregates->syncForm((int) $donation->form_id);
            }
            if ($donation->fund_id) {
                $this->aggregates->syncFund((int) $donation->fund_id);
            }
        });

        if ($affected < 1) {
            // Lost the race: another caller already completed this donation and
            // fired its side effects. Return the persisted row, fire nothing.
            return Donation::query()->find('id', (int) $donation->id) ?? $donation;
        }

        do_action('dono.donation.completed', $donation);

        return $donation;
    }

    /**
     * Create + confirm a renewal donation under an existing recurring plan; fires
     * dono.donation.completed plus dono.recurring.renewed. Idempotent per (gateway,
     * intent): an existing donation is returned without a second renewal event.
     *
     * @param array<string,mixed> $confirmResult Same shape DonationService::confirm() consumes.
     */
    /**
     * @return array{donation: Donation, created: bool} `created` is false for a
     *   redelivered webhook (the renewal row already existed), so the caller can
     *   skip non-idempotent side effects like bumping plan payment counters.
     */
    public function createRenewal(
        \Dono\Recurring\RecurringPlan $plan,
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
        $donation->reference         = $this->references->next('donation');
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

        // Load the plan's first donation once: an FX-rate fallback below plus
        // the demographic fields carried into every renewal.
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
        // Inherit the plan's fixed mode, not the current setting: a live plan
        // renewing while test mode is on must stay live (and vice versa).
        $donation->is_test        = (bool) $plan->is_test;
        $donation->created_at     = $now;
        $donation->updated_at     = $now;
        $donation->save();

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

        do_action('dono.recurring.renewed', $donation, $plan);

        return ['donation' => $donation, 'created' => true];
    }

    /**
     * Record a failed PaymentIntent -> Subscription conversion. The first charge is
     * already collected, so no refund; subscription_creation_failed* flags give the
     * admin a retry affordance instead of silently losing all future renewals.
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
        $donation->save();

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

        do_action('dono.recurring.subscription_creation_failed', $donation, $e);
    }

    /**
     * Clear the subscription-creation failure flags after a successful retry.
     */
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
        $donation->save();
    }

    public function recordRecurringCancellation(\Dono\Recurring\RecurringPlan $plan, ?string $reason = null): void
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

        do_action('dono.recurring.cancelled', $plan, $reason);
    }

    private function frequencyFromPlan(\Dono\Recurring\RecurringPlan $plan): string
    {
        // Plan stores Stripe-shaped interval; donations carry the Dono label.
        return match ([$plan->interval_unit, $plan->interval_count]) {
            ['week',  1] => 'weekly',
            ['week',  2] => 'biweekly',
            ['month', 1] => 'monthly',
            ['month', 3] => 'quarterly',
            ['year',  1] => 'yearly',
            default       => 'monthly',
        };
    }

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
        $applied = DB::table('dono_donations')
            ->where('id', $donation->id)
            ->whereNotIn('status', ['paid', 'refunded', 'partial_refund'])
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

        do_action('dono.donation.failed', $donation);

        return $donation;
    }

    /**
     * Record a refund the gateway already executed externally.
     */
    public function recordExternalRefund(
        Donation $donation,
        int $amountCents,
        string $gatewayRefundId,
        ?string $reason = null,
        string $initiatedBy = 'gateway',
        ?array $metadata = null
    ): Refund {
        if ($amountCents <= 0) {
            throw new RuntimeException("Invalid external refund amount: {$amountCents}.");
        }
        if ($gatewayRefundId === '') {
            throw new RuntimeException('External refund missing gateway_refund_id; cannot dedup.');
        }

        // Redelivered webhook: idempotent return.
        $existing = Refund::query()
            ->where('gateway_refund_id', $gatewayRefundId)
            ->get();
        if ($existing) {
            return $existing;
        }

        if ($donation->status !== 'paid' && $donation->status !== 'partial_refund') {
            throw new RuntimeException(
                "Donation {$donation->reference} not refundable locally (status: {$donation->status})."
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
            error_log(sprintf(
                '[dono] external refund for %s reports %d cents but only %d is refundable; clamping.',
                $donation->reference,
                $amountCents,
                $maxRefundable
            ));
            $amountCents = $maxRefundable;
        }
        if ($amountCents <= 0) {
            throw new RuntimeException(
                "External refund for {$donation->reference} exceeds the refundable balance."
            );
        }

        $now    = $this->clock->now()->format('Y-m-d H:i:s');
        $refund = Refund::make();

        try {
        DB::transaction(function () use (
            $donation, $amountCents, $reason, $initiatedBy, $metadata,
            $gatewayRefundId, $now, $refund
        ) {
            // Atomic over-refund guard: bump the cumulative counter only while
            // the new total still fits the principal. A concurrent refund that
            // consumed the balance since the SUM was read makes this match zero
            // rows; the increment's row lock then serialises the rest of this
            // transaction so the check-then-act clamp above cannot be outrun.
            $reserved = DB::table('dono_donations')
                ->whereRaw('id = ' . (int) $donation->id . ' AND refunded_cents + ' . (int) $amountCents . ' <= amount_cents')
                ->increment('refunded_cents', (int) $amountCents);
            if ($reserved->affectedRows < 1) {
                throw new RuntimeException(
                    "External refund for {$donation->reference} exceeds the refundable balance."
                );
            }
            $newTotal = (int) (DB::table('dono_donations')
                ->where('id', $donation->id)
                ->selectRaw('refunded_cents AS total')
                ->get()['total'] ?? 0);
            $isFullRefund = $newTotal >= $donation->amount_cents;
            $donation->refunded_cents = $newTotal;

            $refund->donation_id       = $donation->id;
            $refund->amount_cents      = $amountCents;
            $refund->currency          = $donation->currency;
            $refund->reason            = $reason;
            $refund->initiated_by      = $initiatedBy;
            $refund->initiated_user_id = null;
            $refund->gateway_refund_id = $gatewayRefundId;
            $refund->status            = 'succeeded';
            $refund->metadata          = $metadata;
            $refund->occurred_at       = $now;
            $refund->save();

            $donation->status      = $isFullRefund ? 'refunded' : 'partial_refund';
            $donation->refunded_at = $now;
            $donation->updated_at  = $now;
            $donation->save();

            $this->voidReceiptsFor($donation, $now);

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

            if ($donation->campaign_id) {
                $this->aggregates->syncCampaign((int) $donation->campaign_id);
            }
            if ($donation->form_id) {
                $this->aggregates->syncForm((int) $donation->form_id);
            }
            if ($donation->fund_id) {
                $this->aggregates->syncFund((int) $donation->fund_id);
            }
        });
        } catch (\Dono\Vendor\Queryable\QueryException $e) {
            // Lost the UNIQUE(gateway_refund_id) race: a concurrent or
            // redelivered webhook already recorded this exact refund. Return it
            // idempotently - the winner fired the side effects (status flip,
            // receipt void, refund email, aggregate resync).
            $dup = Refund::query()->where('gateway_refund_id', $gatewayRefundId)->get();
            if ($dup) {
                return $dup;
            }
            throw $e;
        }

        do_action('dono.donation.refunded', $donation, $refund);

        return $refund;
    }

    /**
     * Refund all or part of a paid donation.
     *
     * @throws RuntimeException when the donation isn't refundable or the gateway rejects.
     */
    public function refund(
        Donation $donation,
        int $amountCents,
        ?string $reason = null,
        ?int $initiatedUserId = null,
        string $initiatedBy = 'admin'
    ): Refund {
        if ($donation->status !== 'paid' && $donation->status !== 'partial_refund') {
            throw new RuntimeException("Donation {$donation->reference} is not refundable (status: {$donation->status}).");
        }

        $alreadyRefunded = (int) Refund::query()
            ->where('donation_id', $donation->id)
            ->where('status', 'succeeded')
            ->sum('amount_cents');

        $maxRefundable = max(0, $donation->amount_cents - $alreadyRefunded);
        if ($amountCents <= 0 || $amountCents > $maxRefundable) {
            throw new RuntimeException(
                "Invalid refund amount: {$amountCents}. Available to refund: {$maxRefundable} cents."
            );
        }

        $gateway = $this->gateways->get($donation->gateway);
        if (! $gateway) {
            throw new RuntimeException("Gateway '{$donation->gateway}' is no longer registered; cannot refund.");
        }

        // Gateway call runs before the transaction: money moves before the local
        // write. If the local write fails, state is stale until a webhook re-syncs it.
        $result = $gateway->refund($donation, $amountCents, $reason);
        if (! $result->success) {
            throw new RuntimeException($result->error ?? 'Gateway refund failed.');
        }

        $now = $this->clock->now()->format('Y-m-d H:i:s');
        $refund = Refund::make();
        $recordedCents = (int) ($result->amount_cents ?? $amountCents);

        DB::transaction(function () use (
            $donation, $result, $reason, $initiatedBy, $initiatedUserId,
            $amountCents, $recordedCents, $now, $refund
        ) {
            // Atomic over-refund guard (see recordExternalRefund): the counter
            // bump applies only while the new total fits the principal, and its
            // row lock serialises concurrent refunds on this donation.
            $reserved = DB::table('dono_donations')
                ->whereRaw('id = ' . (int) $donation->id . ' AND refunded_cents + ' . $recordedCents . ' <= amount_cents')
                ->increment('refunded_cents', $recordedCents);
            if ($reserved->affectedRows < 1) {
                throw new RuntimeException(
                    "Refund for {$donation->reference} exceeds the refundable balance."
                );
            }
            $newTotal = (int) (DB::table('dono_donations')
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
            $donation->save();

            $this->voidReceiptsFor($donation, $now);

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

            // Donor sync runs from the post-commit dono.donation.refunded listener.
            if ($donation->campaign_id) {
                $this->aggregates->syncCampaign((int) $donation->campaign_id);
            }
            if ($donation->form_id) {
                $this->aggregates->syncForm((int) $donation->form_id);
            }
            if ($donation->fund_id) {
                $this->aggregates->syncFund((int) $donation->fund_id);
            }
        });

        do_action('dono.donation.refunded', $donation, $refund);

        return $refund;
    }

    /** Void non-voided receipts for a refunded donation (legal retention). */
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
