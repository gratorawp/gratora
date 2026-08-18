<?php

declare(strict_types=1);

namespace Dono\Gateways\Stripe;

use Dono\Analytics\ErrorLog;
use Dono\Currency\Currency;
use Dono\Donations\Donation;
use Dono\Donations\DonationRepository;
use Dono\Donations\DonationService;
use Dono\Donors\DonorRepository;
use Dono\Donors\DonorService;
use Dono\Foundation\Time\Clock;
use Dono\Gateways\GatewayConfirmResult;
use Dono\Gateways\AccountFingerprint;
use Dono\Gateways\GatewayIntentResult;
use Dono\Gateways\PaymentGateway;
use Dono\Gateways\RefundResult;
use Dono\Gateways\PaymentRetryUnavailable;
use Dono\Gateways\SubscriptionAware;
use Dono\Gateways\PaymentMethodUpdate;
use Dono\Gateways\SupportsPaymentMethodUpdate;
use Dono\Gateways\SupportsPaymentRetry;
use Dono\Gateways\TestMode;
use Dono\Gateways\WebhookOutcome;
use Dono\Gateways\WebhookPaymentGuard;
use Dono\Recurring\FrequencyMap;
use Dono\Recurring\RecurringPlan;
use Dono\Recurring\RecurringPlanRepository;
use RuntimeException;
use WP_REST_Request;
use Throwable;

/**
 * Stripe gateway via PaymentIntents for one-time donations and Subscriptions
 * for recurring ones, charging on the organization's own Stripe account.
 *
 * @since 1.0.0
 */
final class StripeGateway implements PaymentGateway, SubscriptionAware, SupportsPaymentRetry, SupportsPaymentMethodUpdate
{
    /**
     * Dispute statuses for which the money is on the org's balance: settled in
     * the org's favour, or an inquiry Stripe has withdrawn nothing for yet.
     */
    private const DISPUTE_FUNDS_HELD_BY_ORG = [
        'won',
        'warning_closed',
        'warning_needs_response',
        'warning_under_review',
    ];

    /**
     * Mode of the signing secret that verified the current webhook. Set once
     * per delivery in handleWebhook; null outside a webhook request.
     */
    private ?bool $verifiedIsTest = null;

    /** @since 1.0.0 */
    public function __construct(
        private StripeApi $api,
        private DonationRepository $donations,
        private DonationService $donationService,
        private StripeAccount $account,
        private DonorRepository $donors,
        private DonorService $donorService,
        private Clock $clock,
        private RecurringPlanRepository $plans,
    ) {
    }


    /** @since 1.0.0 */
    public function id(): string
    {
        return 'stripe';
    }

    /** @since 1.0.0 */
    public function label(): string
    {
        return __('Stripe', 'dono-fundraising-platform');
    }

    /**
     * Not a list of methods: what the Payment Element offers depends on the
     * account, the currency, the donor's country and their device, and Apple
     * Pay in particular silently never appears without domain verification.
     *
     * @since 1.0.0
     */
    public function description(): string
    {
        return __('Pay securely by card, or another method offered at checkout.', 'dono-fundraising-platform');
    }

    /** @since 1.0.0 */
    public function frequencies(): array
    {
        return ['one_time', 'recurring'];
    }

    /** @since 1.0.0 */
    public function paymentMethods(): array
    {
        return ['card', 'sepa_debit', 'ideal', 'bancontact', 'apple_pay', 'google_pay'];
    }

    /** @since 1.0.0 */
    public function countries(): array
    {
        // Wildcard: defer to Stripe's own country validation.
        return ['*'];
    }

    /** @since 1.0.0 */
    public function currencies(): array
    {
        // Wildcard: defer to Stripe's own currency validation.
        return ['*'];
    }

    /** @since 1.0.0 */
    public function canCharge(): bool
    {
        // A mid-onboarding account cannot charge yet, and gating here keeps the
        // donor options and the admin readiness check on one signal.
        return $this->account->canCharge();
    }

    /** @since 1.0.0 */
    public function createIntent(Donation $donation): GatewayIntentResult
    {
        $this->account->useTestMode((bool) $donation->is_test);

        $params = [
            'amount'      => Currency::toMinorUnits($donation->amount_cents, $donation->currency),
            'currency'    => strtolower($donation->currency),
            'description' => 'Donation ' . $donation->reference,
            'metadata'    => [
                'dono_reference'   => $donation->reference,
                'dono_donation_id' => (string) $donation->id,
                'dono_donor_id'    => (string) $donation->donor_id,
                'dono_form_id'     => (string) ($donation->form_id ?? ''),
                'dono_campaign_id' => (string) ($donation->campaign_id ?? ''),
            ],
            // String 'true': the API client form-encodes, and http_build_query
            // turns PHP true into "1", which Stripe rejects for booleans.
            'automatic_payment_methods' => ['enabled' => 'true'],
        ];

        $customerId = null;
        if (FrequencyMap::isRecurring($donation->frequency)) {
            // Stripe requires a Customer on the PI for setup_future_usage to
            // attach the PaymentMethod to a reusable identity, or the
            // off-session future charges have nothing to bill against.
            $customerId = $this->getOrCreateStripeCustomer($donation);
            $params['customer']            = $customerId;
            $params['setup_future_usage']  = 'off_session';
        }

        $donation->gateway_account_id = $this->account->accountId();

        // Idempotency-keyed on the donation: a create that times out after
        // Stripe accepted it would otherwise leave a charged intent nothing
        // points at, and the retry would charge again.
        $intent = $this->api->post('/payment_intents', $params, [
            'Idempotency-Key' => 'dono_pi_' . $donation->id,
        ]);

        return new GatewayIntentResult(
            intent_id:      $intent['id'],
            client_secret:  $intent['client_secret'] ?? null,
            requires_action: in_array($intent['status'] ?? '', ['requires_action', 'requires_confirmation'], true),
            metadata:       [
                'stripe_status'      => $intent['status'] ?? null,
                'livemode'           => $intent['livemode'] ?? null,
                'stripe_customer_id' => $customerId,
            ],
        );
    }

    /** @since 1.0.0 */
    public function confirm(Donation $donation, array $payload = []): GatewayConfirmResult
    {
        // Exposed so an admin can re-poll a stuck PaymentIntent by hand.
        if (! $donation->gateway_intent_id) {
            return new GatewayConfirmResult(success: false, error: 'No gateway_intent_id on donation.');
        }

        $this->account->useTestMode((bool) $donation->is_test);

        $intent = $this->api->get('/payment_intents/' . $donation->gateway_intent_id);
        return $this->buildConfirmResultFromIntent($intent);
    }

    /** @since 1.0.0 */
    public function handleWebhook(WP_REST_Request $request): WebhookOutcome
    {
        $payload = (string) $request->get_body();
        $sig     = (string) $request->get_header('stripe_signature');

        $verifiedIsTest = $this->api->verifiedWebhookMode($payload, $sig);
        if ($verifiedIsTest === null) {
            return WebhookOutcome::badSignature();
        }

        $event = json_decode($payload, true);
        if (! is_array($event) || ! isset($event['type'], $event['id'])) {
            return new WebhookOutcome(
                signature_ok: true,
                error:        'Malformed event payload.',
                http_status:  400,
            );
        }

        // The body says what mode it is; the signature proves it. When they
        // disagree the body is lying, and a leaked test secret could otherwise
        // refund a live donation. Only checked when livemode is present: its
        // absence is not evidence of anything, and the per-donation mode check
        // below enforces the rule regardless.
        if (array_key_exists('livemode', $event)) {
            $claimsTest = ! (bool) $event['livemode'];
            if ($claimsTest !== $verifiedIsTest) {
                return WebhookOutcome::badSignature(
                    'Event claims ' . ($claimsTest ? 'test' : 'live')
                    . ' mode but was signed with the ' . ($verifiedIsTest ? 'test' : 'live') . ' secret.'
                );
            }
        }

        // Any token-bearing follow-up (subscription creation, refunds) runs in
        // the mode that actually verified, never one taken from the payload.
        $this->verifiedIsTest = $verifiedIsTest;
        $this->account->useTestMode($verifiedIsTest);

        $eventId = (string) $event['id'];
        $type    = (string) $event['type'];
        $object  = (array) ($event['data']['object'] ?? []);

        switch ($type) {
            case 'payment_intent.succeeded':
                return $this->handlePaymentIntentSucceeded($eventId, $type, $object);

            case 'payment_intent.processing':
                return $this->handlePaymentIntentProcessing($eventId, $type, $object);

            case 'payment_intent.payment_failed':
                return $this->handlePaymentIntentFailed($eventId, $type, $object);

            case 'charge.refunded':
                return $this->handleChargeRefunded($eventId, $type, $object);

            case 'charge.refund.updated':
            case 'refund.updated':
            case 'refund.failed':
                return $this->handleRefundUpdated($eventId, $type, $object);

            case 'charge.dispute.funds_withdrawn':
                return $this->handleDisputeFundsWithdrawn($eventId, $type, $object);

            case 'charge.dispute.funds_reinstated':
                return $this->handleDisputeFundsReinstated($eventId, $type, $object);

            case 'invoice.payment_succeeded':
                return $this->handleInvoicePaymentSucceeded($eventId, $type, $object);

            case 'invoice.payment_failed':
                return $this->handleInvoicePaymentFailed($eventId, $type, $object);

            case 'customer.subscription.updated':
                return $this->handleSubscriptionUpdated($eventId, $type, $object);

            case 'customer.subscription.deleted':
                return $this->handleSubscriptionDeleted($eventId, $type, $object);

            case 'account.updated':
                return $this->handleAccountUpdated($eventId, $type, $object);

            case 'account.application.deauthorized':
                return $this->handleAccountDeauthorized($eventId, $type, $event);

            default:
                // Valid but unhandled event: log it, don't error.
                return new WebhookOutcome(
                    signature_ok: true,
                    external_id:  $eventId,
                    event_type:   $type,
                    handled:      false,
                );
        }
    }

    /** @since 1.0.0 */
    private function handlePaymentIntentSucceeded(string $eventId, string $type, array $intent): WebhookOutcome
    {
        $intentId = (string) ($intent['id'] ?? '');
        $donation = $this->donations->findByGatewayIntent($this->id(), $intentId);

        // createIntent stamps the reference into the intent's metadata, and that
        // copy lives on Stripe's side, so it survives whatever stopped the id
        // being written here. Without it the miss below is terminal: the 200
        // stops Stripe retrying, and money is taken against a donation that
        // stays pending for good, with no receipt and no campaign total.
        if (! $donation) {
            $reference = (string) ($intent['metadata']['dono_reference'] ?? '');
            if ($reference !== '') {
                $donation = $this->donations->findByReference($reference);
                if ($donation && $intentId !== '' && (string) ($donation->gateway_intent_id ?? '') === '') {
                    // Healed, so every later event for this intent resolves the
                    // direct way.
                    $donation->gateway_intent_id = $intentId;
                    $donation->save();
                }
            }
        }

        if (! $donation) {
            return new WebhookOutcome(
                signature_ok: true,
                external_id:  $eventId,
                event_type:   $type,
                handled:      false,
                error:        "No donation found for PaymentIntent {$intentId}",
                http_status:  200,  // Not 5xx: webhook is valid, donation just isn't ours; 5xx makes Stripe retry forever.
            );
        }

        // A verified signature proves Stripe sent this, not that it is about
        // this donation for this amount in this mode.
        $refusal = WebhookPaymentGuard::refuse(
            $donation,
            $this->id(),
            $this->verifiedIsTest,
            isset($intent['amount_received'])
                ? Currency::fromMinorUnits((int) $intent['amount_received'], (string) ($intent['currency'] ?? $donation->currency))
                : null,
            isset($intent['currency']) ? (string) $intent['currency'] : null,
        );
        if ($refusal !== null) {
            return $this->refused($eventId, $type, $refusal);
        }

        $confirm = $this->buildConfirmResultFromIntent($intent);

        if (! $confirm->success) {
            return new WebhookOutcome(
                signature_ok: true,
                external_id:  $eventId,
                event_type:   $type,
                handled:      false,
                error:        $confirm->error,
            );
        }

        // The row confirm() hands back, not the one we walked in with: a
        // redirect return and this webhook race each other, and the loser's
        // in-memory model still reads pending, so a whole-row save below would
        // write a paid donation back to pending.
        $donation = $this->donationService->confirm($donation, $confirm->toArray());

        // Converting the saved card into a Stripe Subscription is what makes
        // future renewals fire `invoice.payment_succeeded`. Failure here does
        // not roll back the first charge; the donation is flagged so an admin
        // can retry rather than silently losing every renewal.
        if (FrequencyMap::isRecurring($donation->frequency) && ! $donation->recurring_plan_id) {
            try {
                $this->createSubscriptionFromFirstCharge($donation, $intent);
            } catch (\Throwable $e) {
                $this->donationService->recordSubscriptionCreationFailure($donation, $e);
            }
        }

        if ($confirm->reversed_minor_units > 0) {
            $stop = $this->replayReversals($eventId, $type, $donation, $intent);
            if ($stop !== null) {
                return $stop;
            }
        }

        return new WebhookOutcome(
            signature_ok: true,
            external_id:  $eventId,
            event_type:   $type,
            handled:      true,
        );
    }

    /**
     * Money that went back before there was a banked donation to take it off.
     * The refund and dispute deliveries that carried it were answered 200
     * against a row nothing could be recorded on, and a 200 is Stripe's cue to
     * stop sending them, so the reversal is replayed against the row confirm()
     * has just made refundable. Returns an outcome only when the delivery has
     * to stop there; null means carry on.
     *
     * recordExternalRefund dedupes on the gateway refund id, so a real
     * charge.refunded or funds_withdrawn landing afterwards is a no-op.
     *
     * @param array<string,mixed> $intent
     *
     * @since 1.0.0
     */
    private function replayReversals(string $eventId, string $type, Donation $donation, array $intent): ?WebhookOutcome
    {
        $charge = $this->latestCharge($intent);
        if ($charge === null) {
            return null;
        }

        $chargeId = (string) ($charge['id'] ?? '');
        if ($chargeId !== '' && (int) ($charge['amount_refunded'] ?? 0) > 0) {
            $refunds = (array) ($this->api->get('/charges/' . rawurlencode($chargeId) . '/refunds')['data'] ?? []);
            foreach ($refunds as $refund) {
                if (! is_array($refund) || ! self::refundSettled($refund)) {
                    continue;
                }

                $stop = $this->applyRefund($eventId, $type, $donation, $refund);
                if ($stop !== null) {
                    return $stop;
                }
            }
        }

        $dispute = $charge['dispute'] ?? null;
        if (is_string($dispute) && $dispute !== '') {
            $dispute = $this->api->get('/disputes/' . rawurlencode($dispute));
        }

        if (is_array($dispute)
            && ! in_array((string) ($dispute['status'] ?? ''), self::DISPUTE_FUNDS_HELD_BY_ORG, true)) {
            return $this->applyDisputeLoss($eventId, $type, $donation, $dispute);
        }

        return null;
    }

    /**
     * A bank debit has been submitted and will settle in a few days. Only SEPA,
     * ACH and the other delayed-notification methods reach this; a card goes
     * straight to succeeded. It is emphatically not paid: the debit can still
     * bounce, and `payment_intent.succeeded` is what settles it.
     *
     * @since 1.0.0
     */
    private function handlePaymentIntentProcessing(string $eventId, string $type, array $intent): WebhookOutcome
    {
        $intentId = (string) ($intent['id'] ?? '');
        $donation = $this->donations->findByGatewayIntent($this->id(), $intentId);

        if (! $donation) {
            return new WebhookOutcome(
                signature_ok: true,
                external_id:  $eventId,
                event_type:   $type,
                handled:      false,
                error:        "No donation found for PaymentIntent {$intentId}",
                http_status:  200,
            );
        }

        // A verified signature proves Stripe sent this, not that it is about
        // this donation for this amount in this mode.
        $refusal = WebhookPaymentGuard::refuse(
            $donation,
            $this->id(),
            $this->verifiedIsTest,
            isset($intent['amount'])
                ? Currency::fromMinorUnits((int) $intent['amount'], (string) ($intent['currency'] ?? $donation->currency))
                : null,
            isset($intent['currency']) ? (string) $intent['currency'] : null,
        );
        if ($refusal !== null) {
            return $this->refused($eventId, $type, $refusal);
        }

        // No-ops on anything already settled: markProcessing only moves a row
        // out of pending, so a late redelivery cannot unsettle real money.
        $this->donationService->markProcessing($donation, 'bank_debit_submitted', [
            'payment_method' => (string) ($donation->payment_method ?? ''),
        ]);

        return new WebhookOutcome(
            signature_ok: true,
            external_id:  $eventId,
            event_type:   $type,
            handled:      true,
        );
    }

    /** @since 1.0.0 */
    private function handlePaymentIntentFailed(string $eventId, string $type, array $intent): WebhookOutcome
    {
        $intentId = (string) ($intent['id'] ?? '');
        $donation = $this->donations->findByGatewayIntent($this->id(), $intentId);

        if (! $donation) {
            return new WebhookOutcome(
                signature_ok: true,
                external_id:  $eventId,
                event_type:   $type,
                handled:      false,
                error:        "No donation found for PaymentIntent {$intentId}",
            );
        }

        if ($reason = $this->wrongMode((bool) $donation->is_test)) {
            return $this->refused($eventId, $type, $reason);
        }

        $reason = $intent['last_payment_error']['message'] ?? __('Payment declined.', 'dono-fundraising-platform');
        $this->donationService->markFailed($donation, $reason);

        return new WebhookOutcome(
            signature_ok: true,
            external_id:  $eventId,
            event_type:   $type,
            handled:      true,
        );
    }

    /**
     * `charge.refunded` fires for refunds from our own `refund()` and for ones
     * made in the Stripe Dashboard or by dispute resolution.
     *
     * @since 1.0.0
     */
    private function handleChargeRefunded(string $eventId, string $type, array $charge): WebhookOutcome
    {
        $intentId = (string) ($charge['payment_intent'] ?? '');
        if ($intentId === '') {
            return new WebhookOutcome(
                signature_ok: true,
                external_id:  $eventId,
                event_type:   $type,
                handled:      false,
                error:        'charge.refunded event missing payment_intent.',
            );
        }

        $donation = $this->donations->findByGatewayIntent($this->id(), $intentId);
        if (! $donation) {
            return new WebhookOutcome(
                signature_ok: true,
                external_id:  $eventId,
                event_type:   $type,
                handled:      false,
                error:        "No donation found for PaymentIntent {$intentId}",
            );
        }

        if ($reason = $this->wrongMode((bool) $donation->is_test)) {
            return $this->refused($eventId, $type, $reason);
        }

        $refunds = (array) ($charge['refunds']['data'] ?? []);
        // Recent Stripe API versions drop the embedded refund list from the
        // event payload, so it is fetched when the charge shows a refund but
        // none came through, or external refunds are silently ignored.
        $chargeId = (string) ($charge['id'] ?? '');
        if ($refunds === [] && (int) ($charge['amount_refunded'] ?? 0) > 0 && $chargeId !== '') {
            try {
                $fetched = $this->api->get('/charges/' . rawurlencode($chargeId) . '/refunds');
                $refunds = (array) ($fetched['data'] ?? []);
            } catch (RuntimeException $e) {
                // This fetch is the only source of the refund rows on recent API
                // versions, so swallowing it would leave a real refund
                // unrecorded and the donation "paid" forever. A 500 lets Stripe
                // redeliver, and recordExternalRefund is idempotent.
                return new WebhookOutcome(
                    signature_ok: true,
                    external_id:  $eventId,
                    event_type:   $type,
                    handled:      false,
                    error:        "Failed to fetch refunds for charge {$chargeId}: " . $e->getMessage(),
                    http_status:  500,
                );
            }
        }
        foreach ($refunds as $r) {
            if (! is_array($r) || ! self::refundSettled($r)) {
                // A bank refund is created pending and can still fail, which
                // leaves the money with the org. charge.refund.updated is what
                // says which way it went.
                continue;
            }

            $stop = $this->applyRefund($eventId, $type, $donation, $r);
            if ($stop !== null) {
                return $stop;
            }
        }

        return new WebhookOutcome(
            signature_ok: true,
            external_id:  $eventId,
            event_type:   $type,
            handled:      true,
        );
    }

    /**
     * A refund changing state after it was created: a submitted bank refund
     * settling, or being rejected and the money staying with the org.
     *
     * @since 1.0.0
     */
    private function handleRefundUpdated(string $eventId, string $type, array $refund): WebhookOutcome
    {
        $refundId = (string) ($refund['id'] ?? '');
        $intentId = (string) ($refund['payment_intent'] ?? '');

        if ($refundId === '' || $intentId === '') {
            return new WebhookOutcome(
                signature_ok: true,
                external_id:  $eventId,
                event_type:   $type,
                handled:      false,
                error:        'refund event missing id or payment_intent.',
            );
        }

        $donation = $this->donations->findByGatewayIntent($this->id(), $intentId);
        if (! $donation) {
            return new WebhookOutcome(
                signature_ok: true,
                external_id:  $eventId,
                event_type:   $type,
                handled:      false,
                error:        "No donation found for PaymentIntent {$intentId}",
                http_status:  200,
            );
        }

        if ($reason = $this->wrongMode((bool) $donation->is_test)) {
            return $this->refused($eventId, $type, $reason);
        }

        $status = (string) ($refund['status'] ?? '');

        if (self::refundSettled($refund)) {
            $stop = $this->applyRefund($eventId, $type, $donation, $refund);
            if ($stop !== null) {
                return $stop;
            }
        } elseif (in_array($status, ['failed', 'canceled'], true)) {
            // The donor was never repaid, so a refund recorded here has to come
            // back off. Null when there is nothing standing, which is what a
            // redelivery and a never-recorded pending refund both look like.
            $this->donationService->reverseExternalRefund($donation, $refundId);
        }

        return new WebhookOutcome(
            signature_ok: true,
            external_id:  $eventId,
            event_type:   $type,
            handled:      true,
        );
    }

    /**
     * Record one Stripe refund against the donation. Returns an outcome only
     * when the delivery has to stop there; null means carry on.
     *
     * @param array<string,mixed> $refund
     *
     * @since 1.0.0
     */
    private function applyRefund(string $eventId, string $type, Donation $donation, array $refund): ?WebhookOutcome
    {
        $refundId = (string) ($refund['id'] ?? '');
        $amount   = Currency::fromMinorUnits((int) ($refund['amount'] ?? 0), $donation->currency);
        if ($refundId === '' || $amount <= 0) {
            return null;
        }

        $reason = isset($refund['reason']) && is_string($refund['reason']) && $refund['reason'] !== ''
            ? $refund['reason']
            : null;

        try {
            // Idempotent: service no-ops if we already have this refund row.
            $this->donationService->recordExternalRefund(
                $donation,
                $amount,
                $refundId,
                $reason,
                'gateway',
                $refund
            );
        } catch (RuntimeException $e) {
            if ($this->refundable($donation)) {
                throw $e;
            }

            return $this->unrefundable($eventId, $type, $donation, $e);
        }

        return null;
    }

    /**
     * Whether Stripe reports this refund as money the donor actually has back.
     * An absent status is a payload that does not carry one (a dispute-driven
     * refund, an older API version), which is only ever reported once settled.
     *
     * @param array<string,mixed> $refund
     *
     * @since 1.0.0
     */
    private static function refundSettled(array $refund): bool
    {
        $status = (string) ($refund['status'] ?? 'succeeded');

        return $status === '' || $status === 'succeeded';
    }

    /**
     * A lost dispute pulled funds from our balance. Recorded as a
     * 'dispute'-sourced refund so counters drop, idempotent via the dispute id
     * standing in as the refund id.
     *
     * @since 1.0.0
     */
    private function handleDisputeFundsWithdrawn(string $eventId, string $type, array $dispute): WebhookOutcome
    {
        $intentId = (string) ($dispute['payment_intent'] ?? '');
        if ($intentId === '') {
            return new WebhookOutcome(
                signature_ok: true,
                external_id:  $eventId,
                event_type:   $type,
                handled:      false,
                error:        'dispute event missing payment_intent.',
            );
        }

        $donation = $this->donations->findByGatewayIntent($this->id(), $intentId);
        if (! $donation) {
            return new WebhookOutcome(
                signature_ok: true,
                external_id:  $eventId,
                event_type:   $type,
                handled:      false,
                error:        "No donation found for PaymentIntent {$intentId}",
            );
        }

        if ($reason = $this->wrongMode((bool) $donation->is_test)) {
            return $this->refused($eventId, $type, $reason);
        }

        $disputeId = (string) ($dispute['id'] ?? '');
        $amount    = Currency::fromMinorUnits((int) ($dispute['amount'] ?? 0), $donation->currency);
        if ($disputeId === '' || $amount <= 0) {
            return new WebhookOutcome(
                signature_ok: true,
                external_id:  $eventId,
                event_type:   $type,
                handled:      false,
                error:        'dispute event missing id or amount.',
            );
        }

        $stop = $this->applyDisputeLoss($eventId, $type, $donation, $dispute);
        if ($stop !== null) {
            return $stop;
        }

        return new WebhookOutcome(
            signature_ok: true,
            external_id:  $eventId,
            event_type:   $type,
            handled:      true,
        );
    }

    /**
     * Record one lost dispute against the donation. Returns an outcome only
     * when the delivery has to stop there; null means carry on.
     *
     * @param array<string,mixed> $dispute
     *
     * @since 1.0.0
     */
    private function applyDisputeLoss(string $eventId, string $type, Donation $donation, array $dispute): ?WebhookOutcome
    {
        $disputeId = (string) ($dispute['id'] ?? '');
        $amount    = Currency::fromMinorUnits((int) ($dispute['amount'] ?? 0), $donation->currency);
        if ($disputeId === '' || $amount <= 0) {
            return null;
        }

        $reason = isset($dispute['reason']) && is_string($dispute['reason']) && $dispute['reason'] !== ''
            ? 'dispute: ' . $dispute['reason']
            : 'dispute';

        try {
            $this->donationService->recordExternalRefund(
                $donation,
                $amount,
                $disputeId,
                $reason,
                'dispute',
                $dispute
            );
        } catch (RuntimeException $e) {
            if ($this->refundable($donation)) {
                throw $e;
            }

            return $this->unrefundable($eventId, $type, $donation, $e);
        }

        return null;
    }

    /**
     * The dispute was won and Stripe has returned the money, so the refund the
     * loss recorded is undone, or the donation stays missing from every total
     * for good.
     *
     * @since 1.0.0
     */
    private function handleDisputeFundsReinstated(string $eventId, string $type, array $dispute): WebhookOutcome
    {
        $intentId  = (string) ($dispute['payment_intent'] ?? '');
        $disputeId = (string) ($dispute['id'] ?? '');

        if ($intentId === '' || $disputeId === '') {
            return new WebhookOutcome(
                signature_ok: true,
                external_id:  $eventId,
                event_type:   $type,
                handled:      false,
                error:        'dispute event missing payment_intent or id.',
            );
        }

        $donation = $this->donations->findByGatewayIntent($this->id(), $intentId);
        if (! $donation) {
            return new WebhookOutcome(
                signature_ok: true,
                external_id:  $eventId,
                event_type:   $type,
                handled:      false,
                error:        "No donation found for PaymentIntent {$intentId}",
            );
        }

        if ($reason = $this->wrongMode((bool) $donation->is_test)) {
            return $this->refused($eventId, $type, $reason);
        }

        // Null when there is nothing still standing to reverse, which is what a
        // redelivered event looks like.
        $this->donationService->reverseExternalRefund($donation, $disputeId);

        return new WebhookOutcome(
            signature_ok: true,
            external_id:  $eventId,
            event_type:   $type,
            handled:      true,
        );
    }

    /**
     * charges_enabled is one shared flag for both modes, and GatewayManager::isOn()
     * resolves the donor form's gateway list through it, so an account update
     * that clears it removes Stripe from every form in both modes. The account
     * id is the same string in test and live, so without a mode rule a leaked
     * test signing secret, a much softer credential, can do that through the
     * public unauthenticated route.
     *
     * A live connection therefore takes that one downgrade only from its own
     * mode. A test-only connection has no live keys, so its legitimate
     * test-signed updates are untouched, and an upgrade is never an outage.
     *
     * @since 1.0.0
     */
    private function handleAccountUpdated(string $eventId, string $type, array $account): WebhookOutcome
    {
        $acctId  = (string) ($account['id'] ?? '');
        $current = $this->account->accountId();

        if ($acctId !== '' && $current !== null && hash_equals($current, $acctId)) {
            // refresh() coalesces an absent or null flag to the stored value, so
            // only an explicit false is a downgrade worth refusing.
            $disablesCharges = ($account['charges_enabled'] ?? null) !== null
                && ! (bool) $account['charges_enabled'];

            if (
                $disablesCharges
                && $this->verifiedIsTest !== false
                && $this->account->hasKeysFor(false)
                && $this->account->canCharge()
            ) {
                return $this->refused(
                    $eventId,
                    $type,
                    'a test-signed account update cannot stop a live connection charging'
                );
            }

            $this->account->refresh($account);
        }

        return new WebhookOutcome(
            signature_ok: true,
            external_id:  $eventId,
            event_type:   $type,
            handled:      true,
        );
    }

    /**
     * Local state is dropped so we stop charging an account we can no longer
     * touch. The account id is on the envelope, not the data object.
     *
     * Only the keys of the mode whose secret signed this are dropped. The
     * account id is the same string in test and live, so the id check alone
     * lets a test signing secret, a much softer credential, erase the live
     * keys through a public unauthenticated route.
     *
     * @since 1.0.0
     */
    private function handleAccountDeauthorized(string $eventId, string $type, array $event): WebhookOutcome
    {
        if ($this->verifiedIsTest === null) {
            return $this->refused($eventId, $type, 'the mode of the verifying secret is unknown');
        }

        $acctId  = (string) ($event['account'] ?? '');
        $current = $this->account->accountId();

        if ($acctId !== '' && $current !== null && hash_equals($current, $acctId)) {
            $this->account->forgetMode($this->verifiedIsTest);
        }

        return new WebhookOutcome(
            signature_ok: true,
            external_id:  $eventId,
            event_type:   $type,
            handled:      true,
        );
    }

    /**
     * The PaymentIntent is re-fetched rather than reused, so the current
     * payment_method is picked up even when the donor authenticated minutes
     * later.
     *
     * @since 1.0.0
     */
    public function retrySubscriptionCreation(Donation $donation): RecurringPlan
    {
        if (! FrequencyMap::isRecurring($donation->frequency)) {
            throw new RuntimeException(esc_html("Donation {$donation->reference} is not recurring; nothing to convert."));
        }
        if ($donation->recurring_plan_id) {
            throw new RuntimeException(esc_html("Donation {$donation->reference} already has a recurring plan."));
        }
        // A refunded or reversed PaymentIntent keeps its customer and
        // payment_method, so the Stripe chain below would happily start billing
        // a donor whose money has already gone back.
        if (! in_array((string) $donation->status, ['paid', 'partial_refund'], true)) {
            throw new RuntimeException(
                esc_html("Donation {$donation->reference} is {$donation->status}; only a donation still paid for owes a schedule.")
            );
        }
        if (! $donation->gateway_intent_id) {
            throw new RuntimeException(esc_html("Donation {$donation->reference} has no gateway intent to re-read."));
        }

        $this->account->useTestMode((bool) $donation->is_test);
        $intent = $this->api->get(
            '/payment_intents/' . rawurlencode((string) $donation->gateway_intent_id)
        );

        $this->createSubscriptionFromFirstCharge($donation, $intent);
        $this->donationService->clearSubscriptionCreationFailure($donation);

        $fresh = $this->donations->findByReference($donation->reference);
        $planId = $fresh && $fresh->recurring_plan_id ? (int) $fresh->recurring_plan_id : 0;
        $plan = $planId > 0 ? RecurringPlan::query()->find('id', $planId) : null;
        if (! $plan) {
            // Linked but unreadable, so the retry endpoint returns a 502.
            throw new RuntimeException(esc_html("Retry succeeded but plan row could not be re-read for donation {$donation->reference}."));
        }
        return $plan;
    }

    /** @since 1.0.0 */
    /**
     * A Customer this donor already has at this gateway, in this mode.
     *
     * Test and live are separate Stripe accounts, so an id from one is not a
     * record in the other.
     *
     * @since 1.0.0
     */
    private function knownCustomerId(Donation $donation): string
    {
        $rows = RecurringPlan::query()
            ->where('donor_id', (int) $donation->donor_id)
            ->where('gateway', $this->id())
            ->where('is_test', (bool) $donation->is_test ? 1 : 0)
            ->whereIsNotNull('gateway_customer_id')
            ->where('gateway_customer_id', '', '!=')
            ->orderBy('id', 'DESC')
            ->limit(1)
            ->getAll();

        return $rows === [] ? '' : (string) $rows[0]->gateway_customer_id;
    }

    private function getOrCreateStripeCustomer(Donation $donation): string
    {
        $donor = $this->donors->findById((int) $donation->donor_id);
        $email = $donor ? $this->donorService->decryptEmail($donor) : null;
        $name  = trim(($donation->donor_first_name ?? '') . ' ' . ($donation->donor_last_name ?? ''));

        // A donor who already gives recurring has a Customer, and reusing it is
        // what keeps one person from accumulating several: the portal's
        // change-card path works from a single Customer, and a duplicate splits
        // a donor's cards across records neither screen can reconcile.
        $known = $this->knownCustomerId($donation);
        if ($known !== '') {
            return $known;
        }

        // The Customer belongs to the donor, not to whichever donation happened
        // to create it, so nothing donation-specific goes on it. That also keeps
        // the body identical across a donor's donations, which is what the
        // idempotency key below requires.
        $params = [
            'metadata' => [
                'dono_donor_id' => (string) $donation->donor_id,
            ],
        ];
        if ($email !== null && $email !== '') $params['email'] = $email;
        if ($name !== '')                     $params['name']  = $name;

        // Keyed on the donor and on what is being sent. Stripe refuses a key
        // replayed with different parameters, and a donor who corrects their
        // name between donations sends different parameters, so a key that
        // ignored the body would fail their next donation outright rather than
        // deduplicating anything.
        $customer = $this->api->post('/customers', $params, [
            'Idempotency-Key' => 'dono_cus_' . (int) $donation->donor_id
                . '_' . substr(hash('sha256', (string) wp_json_encode($params)), 0, 16),
        ]);
        $id       = (string) ($customer['id'] ?? '');
        if ($id === '') {
            throw new RuntimeException(esc_html('Stripe customer creation returned no id.'));
        }
        return $id;
    }

    /**
     * Hands the saved card off to a real Stripe Subscription so Stripe drives
     * every renewal. `billing_cycle_anchor` is set one interval into the future
     * so the transition does not double-charge the donor on the same day.
     *
     * Stripe only accepts a future anchor, so the cycle is measured from now
     * and not from `paid_at`: intervals that elapsed between the charge and
     * this call are not billed. Backdating is possible only via
     * `backdate_start_date`, which invoices the whole elapsed span at once.
     *
     * @since 1.0.0
     */
    private function createSubscriptionFromFirstCharge(Donation $donation, array $piIntent): void
    {
        // Read from the PaymentIntent payload, because customer_id stashed on
        // the donation does not survive confirm()'s metadata overwrite.
        $customerId      = (string) ($piIntent['customer'] ?? '');
        $paymentMethodId = (string) ($piIntent['payment_method'] ?? '');

        if ($customerId === '' || $paymentMethodId === '') {
            throw new RuntimeException(esc_html(sprintf(
                'Cannot convert donation %s to a subscription: customer=%s, payment_method=%s',
                $donation->reference,
                $customerId === '' ? 'missing' : 'ok',
                $paymentMethodId === '' ? 'missing' : 'ok'
            )));
        }

        // The customer's default, so the subscription bills against the same
        // card the donor authorised.
        $this->api->post('/customers/' . rawurlencode($customerId), [
            'invoice_settings' => ['default_payment_method' => $paymentMethodId],
        ]);

        [$interval, $intervalCount] = FrequencyMap::toStripe($donation->frequency);
        $productId = $this->resolveDonationProduct((bool) $donation->is_test);

        $price = $this->api->post('/prices', [
            'product'     => $productId,
            'unit_amount' => Currency::toMinorUnits($donation->amount_cents, $donation->currency),
            'currency'    => strtolower($donation->currency),
            'recurring'   => [
                'interval'       => $interval,
                'interval_count' => $intervalCount,
            ],
        ]);

        $nowEpoch       = $this->clock->now()->getTimestamp();
        $firstRenewalAt = FrequencyMap::nextRenewalAfter($nowEpoch, $interval, $intervalCount);

        $subParams = [
            'customer'             => $customerId,
            'items'                => [['price' => (string) ($price['id'] ?? '')]],
            'billing_cycle_anchor' => $firstRenewalAt,
            'proration_behavior'   => 'none',
            'default_payment_method' => $paymentMethodId,
            'metadata' => [
                'dono_donor_id'            => (string) $donation->donor_id,
                'dono_form_id'             => (string) ($donation->form_id ?? ''),
                'dono_campaign_id'         => (string) ($donation->campaign_id ?? ''),
                'dono_initial_donation_id' => (string) $donation->id,
            ],
        ];

        $sub = $this->api->post('/subscriptions', $subParams, [
            // Deterministic, so a redelivered webhook re-POSTs the same key and
            // Stripe returns the original subscription rather than a second one
            // that would double-charge the donor every renewal.
            'Idempotency-Key' => 'dono_sub_' . $donation->id,
        ]);

        $subId = (string) ($sub['id'] ?? '');

        // Redelivery can re-enter here with the same idempotent subscription;
        // reuse the plan already linked to it rather than inserting a duplicate.
        $existingPlan = $this->plans->findBySubscriptionId($this->id(), $subId);
        if ($existingPlan) {
            // One column, not the whole row: everything else on this model was
            // read before confirm() ran.
            Donation::query()
                ->where('id', (int) $donation->id)
                ->update(['recurring_plan_id' => (int) $existingPlan->id]);
            $donation->recurring_plan_id = (int) $existingPlan->id;
            return;
        }

        $now    = $this->clock->now()->format('Y-m-d H:i:s');
        $nextAt = (new \DateTimeImmutable("@{$firstRenewalAt}"))->format('Y-m-d H:i:s');

        $plan = RecurringPlan::make();
        $plan->donor_id           = (int) $donation->donor_id;
        $plan->form_id            = $donation->form_id;
        $plan->campaign_id        = $donation->campaign_id;
        $plan->fund_id            = $donation->fund_id;
        $plan->fundraiser_id      = $donation->fundraiser_id;
        $plan->fundraiser_team_id = $donation->fundraiser_team_id;
        $plan->gateway            = $this->id();
        $plan->gateway_subscription_id = $subId;
        $plan->gateway_customer_id     = $customerId;
        $plan->amount_cents       = (int) $donation->amount_cents;
        $plan->currency           = (string) $donation->currency;
        $plan->base_amount_cents  = $donation->base_amount_cents;
        $plan->fx_rate            = $donation->fx_rate;
        $plan->interval_unit      = $interval;
        $plan->interval_count     = $intervalCount;
        $plan->status             = 'active';
        $plan->is_test            = (bool) $donation->is_test;
        $plan->started_at         = $now;
        $plan->next_payment_at    = $nextAt;
        $plan->payments_count     = 1;
        $plan->total_paid_cents   = (int) $donation->amount_cents;
        $plan->created_at         = $now;
        $plan->updated_at         = $now;
        $plan->save();

        Donation::query()
            ->where('id', (int) $donation->id)
            ->update(['recurring_plan_id' => (int) $plan->id]);
        $donation->recurring_plan_id = (int) $plan->id;
    }

    /**
     * One Stripe Product per mode and account, cached in gateway settings. The
     * account fingerprint is part of the key because a Product lives inside one
     * Stripe account, so a newly connected account must not be handed the old
     * account's product id.
     *
     * @since 1.0.0
     */
    private function resolveDonationProduct(bool $isTest): string
    {
        $opt    = get_option('dono_gateway_config', []);
        $stripe = is_array($opt) && is_array($opt['stripe'] ?? null) ? $opt['stripe'] : [];
        $key    = ($isTest ? 'stripe_product_id_test' : 'stripe_product_id_live')
                . '_' . AccountFingerprint::of($this->account->secretKeyFor($isTest));

        $existing = (string) ($stripe[$key] ?? '');
        if ($existing !== '') return $existing;

        $product = $this->api->post('/products', [
            'name'        => 'Donation',
            'description' => sprintf('Recurring donations to %s', (string) get_bloginfo('name')),
        ]);

        $productId = (string) ($product['id'] ?? '');
        if ($productId === '') {
            throw new RuntimeException(esc_html('Stripe product creation returned no id.'));
        }

        $stripe[$key]    = $productId;
        $opt['stripe']   = $stripe;
        update_option('dono_gateway_config', $opt);
        return $productId;
    }

    /**
     * The subscription and PaymentIntent an invoice belongs to, whichever shape
     * it arrived in. Stripe moved both out of the top level in
     * 2025-03-31.basil, and a webhook endpoint with no api_version of its own
     * renders at the account default, which on a recent account is Basil.
     *
     * payments is expandable and not guaranteed to be in the payload, so when
     * the flat fields are missing the invoice is re-read through the API, which
     * is pinned by the Stripe-Version header.
     *
     * @return array{0: string, 1: string} [subscription id, payment intent id]
     *
     * @since 1.0.0
     */
    private function invoiceRefs(array $invoice): array
    {
        $subscriptionId = (string) ($invoice['subscription'] ?? '');
        $piId           = (string) ($invoice['payment_intent'] ?? '');

        if ($subscriptionId === '') {
            $subscriptionId = (string) ($invoice['parent']['subscription_details']['subscription'] ?? '');
        }
        if ($piId === '') {
            $piId = (string) ($invoice['payments']['data'][0]['payment']['payment_intent'] ?? '');
        }

        if ($subscriptionId !== '' && $piId !== '') {
            return [$subscriptionId, $piId];
        }

        $invoiceId = (string) ($invoice['id'] ?? '');
        if ($invoiceId === '') {
            return [$subscriptionId, $piId];
        }

        try {
            $fresh = $this->api->get('/invoices/' . rawurlencode($invoiceId));
        } catch (Throwable $e) {
            return [$subscriptionId, $piId];
        }

        if ($subscriptionId === '') {
            $subscriptionId = (string) ($fresh['subscription'] ?? '');
        }
        if ($piId === '') {
            $piId = (string) ($fresh['payment_intent'] ?? '');
        }

        return [$subscriptionId, $piId];
    }

    /**
     * Only `billing_reason=subscription_cycle` is a renewal. The first invoice
     * is paid by the one-off PaymentIntent, so counting it here would
     * double-count; anything else is out of scope.
     *
     * @since 1.0.0
     */
    private function handleInvoicePaymentSucceeded(string $eventId, string $type, array $invoice): WebhookOutcome
    {
        $billingReason = (string) ($invoice['billing_reason'] ?? '');
        if ($billingReason !== 'subscription_cycle') {
            return new WebhookOutcome(
                signature_ok: true,
                external_id:  $eventId,
                event_type:   $type,
                handled:      true,
            );
        }

        [$subscriptionId, $piId] = $this->invoiceRefs($invoice);

        $plan = $this->plans->findBySubscriptionId($this->id(), $subscriptionId);
        if (! $plan) {
            return new WebhookOutcome(
                signature_ok: true,
                external_id:  $eventId,
                event_type:   $type,
                handled:      false,
                error:        "No local plan for subscription {$subscriptionId}",
                http_status:  200,
            );
        }

        // A signature only proves Stripe sent it, not which mode signed it. A
        // test-mode secret is a much softer credential (staging env files, CI,
        // contractors) and must not renew a live plan, bank the money, email a
        // receipt, or cancel every live subscription on the account.
        if ($reason = WebhookPaymentGuard::refuseToTouchPlan($plan, $this->id(), $this->verifiedIsTest)) {
            return $this->refused($eventId, $type, $reason);
        }

        $currency    = strtoupper((string) ($invoice['currency'] ?? $plan->currency));
        $amountCents = Currency::fromMinorUnits((int) ($invoice['amount_paid'] ?? 0), $currency);

        if ($piId === '' || $amountCents <= 0) {
            return new WebhookOutcome(
                signature_ok: true,
                external_id:  $eventId,
                event_type:   $type,
                handled:      false,
                error:        'invoice missing payment_intent or amount_paid',
            );
        }

        $confirmResult = [
            'gateway_txn_id' => $piId,
            'payment_method' => 'card',
            'metadata' => [
                'stripe_invoice_id'      => $invoice['id']      ?? null,
                'stripe_subscription_id' => $subscriptionId,
                'stripe_period_start'    => $invoice['period_start'] ?? null,
                'stripe_period_end'      => $invoice['period_end']   ?? null,
            ],
        ];

        // Read before the renewal call, so a row that was left pending by a
        // delivery that died mid-way can be told from one already settled.
        $prior = $this->donations->findByGatewayIntent($this->id(), $piId);

        $renewal = $this->donationService->createRenewal(
            $plan,
            $amountCents,
            $currency,
            $this->id(),
            $piId,
            $confirmResult,
        );

        // The plan is credited for the payment landing, not for the row being
        // inserted: a redelivery that finishes a half-written renewal is the
        // money arriving as much as the first delivery would have been. Gated on
        // the donation this call moved to paid, which confirm() only does for
        // the caller that won the transition, so two racing deliveries credit
        // once between them and a plain redelivery credits not at all.
        $credited = $renewal['created']
            || ((string) ($prior->status ?? '') !== 'paid'
                && (string) $renewal['donation']->status === 'paid');

        if ($credited) {
            $now    = $this->clock->now()->format('Y-m-d H:i:s');
            $nextAt = isset($invoice['lines']['data'][0]['period']['end'])
                // gmdate, not date: the column is UTC everywhere else, including
                // $now on the line above, and date() would shift a renewal by
                // the site's offset.
                ? gmdate('Y-m-d H:i:s', (int) $invoice['lines']['data'][0]['period']['end'])
                : null;
            // Re-read, so a stale row is not stomped.
            $fresh = $this->plans->findBySubscriptionId($this->id(), $subscriptionId);
            if ($fresh) {
                $this->plans->recordPayment($fresh, $amountCents, $now, $nextAt);
            }
        }

        return new WebhookOutcome(
            signature_ok: true,
            external_id:  $eventId,
            event_type:   $type,
            handled:      true,
        );
    }

    /** @since 1.0.0 */
    private function handleInvoicePaymentFailed(string $eventId, string $type, array $invoice): WebhookOutcome
    {
        [$subscriptionId] = $this->invoiceRefs($invoice);
        $plan = $this->plans->findBySubscriptionId($this->id(), $subscriptionId);
        if (! $plan) {
            return new WebhookOutcome(
                signature_ok: true,
                external_id:  $eventId,
                event_type:   $type,
                handled:      false,
                error:        "No local plan for subscription {$subscriptionId}",
                http_status:  200,
            );
        }

        // A signature only proves Stripe sent it, not which mode signed it. A
        // test-mode secret is a much softer credential (staging env files, CI,
        // contractors) and must not renew a live plan, bank the money, email a
        // receipt, or cancel every live subscription on the account.
        if ($reason = WebhookPaymentGuard::refuseToTouchPlan($plan, $this->id(), $this->verifiedIsTest)) {
            return $this->refused($eventId, $type, $reason);
        }

        $now = $this->clock->now()->format('Y-m-d H:i:s');

        // Only a delivery that has not already been counted moves the plan or
        // tells the donor. Stripe retries until it gets a 2xx, which includes a
        // handler that finished and whose response was lost, and the notice
        // goes out on the first attempt alone: counted twice, one decline
        // silently becomes attempt two and the donor is never told at all.
        if ($this->plans->recordFailedRenewal($plan, $now, $eventId)) {
            // Stripe puts the decline text on the finalization error when it
            // has one; a plain card decline arrives with nothing useful at
            // invoice level, and inventing a reason is worse than giving none.
            $reason = $invoice['last_finalization_error']['message'] ?? null;
            $this->donationService->recordRecurringFailure($plan, $reason !== null ? (string) $reason : null);
        }

        return new WebhookOutcome(
            signature_ok: true,
            external_id:  $eventId,
            event_type:   $type,
            handled:      true,
        );
    }

    /**
     * Stripe moving the subscription on its own: dunning giving up, or
     * collection starting again after it.
     *
     * Without this a subscription Stripe has stopped collecting stays active
     * here for good, keeps counting toward the active plan count and MRR, and
     * never raises the past-due state a PayPal donor in the same position gets.
     *
     * Only the active <-> past_due pair moves, and only in the direction the
     * event states. A local pause is deliberately not mirrored: skipping one
     * payment pauses collection at Stripe while staying active here on purpose,
     * so reading pause_collection back would turn every skip into a pause.
     *
     * @since 1.0.0
     */
    private function handleSubscriptionUpdated(string $eventId, string $type, array $sub): WebhookOutcome
    {
        $subscriptionId = (string) ($sub['id'] ?? '');
        $status         = (string) ($sub['status'] ?? '');

        // Terminal at Stripe, so it is a cancellation however it got there.
        // markCancelled gates the side effects on the winning transition, so the
        // subscription.deleted that follows cannot email the donor a second time.
        if ($status === 'canceled' || $status === 'incomplete_expired') {
            return $this->handleSubscriptionDeleted($eventId, $type, $sub);
        }

        $plan = $this->plans->findBySubscriptionId($this->id(), $subscriptionId);
        if (! $plan) {
            return new WebhookOutcome(
                signature_ok: true,
                external_id:  $eventId,
                event_type:   $type,
                handled:      false,
                error:        "No local plan for subscription {$subscriptionId}",
                http_status:  200,
            );
        }

        // A signature only proves Stripe sent it, not which mode signed it. A
        // test-mode secret is a much softer credential (staging env files, CI,
        // contractors) and must not move a live plan.
        if ($reason = WebhookPaymentGuard::refuseToTouchPlan($plan, $this->id(), $this->verifiedIsTest)) {
            return $this->refused($eventId, $type, $reason);
        }

        // `unpaid` is where Stripe's dunning ends when the account is set to
        // leave the subscription open rather than cancel it: it has given up
        // collecting, but the donor can still fix their card.
        [$to, $from] = match ($status) {
            'past_due', 'unpaid' => ['past_due', 'active'],
            'active'             => ['active', 'past_due'],
            default              => [null, null],
        };

        if ($to !== null) {
            // Conditional on the status it is moving from, so a redelivery or a
            // racing event cannot walk a cancelled or paused plan back to life.
            RecurringPlan::query()
                ->where('id', (int) $plan->id)
                ->where('status', $from)
                ->update([
                    'status'     => $to,
                    'updated_at' => $this->clock->now()->format('Y-m-d H:i:s'),
                ]);
        }

        return new WebhookOutcome(
            signature_ok: true,
            external_id:  $eventId,
            event_type:   $type,
            handled:      true,
        );
    }

    /** @since 1.0.0 */
    private function handleSubscriptionDeleted(string $eventId, string $type, array $sub): WebhookOutcome
    {
        $subscriptionId = (string) ($sub['id'] ?? '');
        $plan = $this->plans->findBySubscriptionId($this->id(), $subscriptionId);
        if (! $plan) {
            return new WebhookOutcome(
                signature_ok: true,
                external_id:  $eventId,
                event_type:   $type,
                handled:      false,
                error:        "No local plan for subscription {$subscriptionId}",
                http_status:  200,
            );
        }

        // A signature only proves Stripe sent it, not which mode signed it. A
        // test-mode secret is a much softer credential (staging env files, CI,
        // contractors) and must not renew a live plan, bank the money, email a
        // receipt, or cancel every live subscription on the account.
        if ($reason = WebhookPaymentGuard::refuseToTouchPlan($plan, $this->id(), $this->verifiedIsTest)) {
            return $this->refused($eventId, $type, $reason);
        }

        $reason = (string) ($sub['cancellation_details']['reason'] ?? '');
        $now    = $this->clock->now()->format('Y-m-d H:i:s');
        // A portal or admin cancel records the event before deleting the Stripe
        // sub, so only gateway-initiated cancels emit here and the donor gets
        // exactly one cancellation email. Gated on which call won the DB
        // transition, not a pre-read, so two racing deliveries cannot both send.
        $won = $this->plans->markCancelled($plan, $now, $reason !== '' ? $reason : null);

        if ($won) {
            $this->donationService->recordRecurringCancellation($plan, $reason !== '' ? $reason : null);
        }

        return new WebhookOutcome(
            signature_ok: true,
            external_id:  $eventId,
            event_type:   $type,
            handled:      true,
        );
    }

    /** @since 1.0.0 */
    public function refund(Donation $donation, int $amountCents, ?string $reason = null): RefundResult
    {
        if (! $donation->gateway_intent_id) {
            return RefundResult::failure(__('No gateway intent on donation; cannot refund via Stripe.', 'dono-fundraising-platform'));
        }

        $this->account->useTestMode((bool) $donation->is_test);

        if (! $this->api->isConfigured()) {
            return RefundResult::failure(__('Stripe is not configured.', 'dono-fundraising-platform'));
        }

        $params = [
            'payment_intent' => $donation->gateway_intent_id,
            'amount'         => Currency::toMinorUnits($amountCents, $donation->currency),
            'metadata'       => [
                'dono_reference'  => $donation->reference,
                'dono_donation_id' => (string) $donation->id,
            ],
        ];
        if ($reason !== null && $reason !== '') {
            // Stripe's reason is a fixed enum; free-text goes into metadata.
            $params['reason']           = 'requested_by_customer';
            $params['metadata']['note'] = $reason;
        }

        try {
            // So a timed-out refund that already processed on Stripe returns
            // the original on retry instead of issuing a second one. Stable per
            // attempt, because refunded_cents only advances once the local
            // write commits, yet distinct across separate partial refunds.
            $headers = [
                'Idempotency-Key' => 'dono_refund_' . $donation->id . '_' . (int) $donation->refunded_cents . '_' . $amountCents,
            ];
            $stripeRefund = $this->api->post('/refunds', $params, $headers);
        } catch (\Throwable $e) {
            return RefundResult::failure($e->getMessage());
        }

        return new RefundResult(
            success:           true,
            gateway_refund_id: (string) ($stripeRefund['id'] ?? ''),
            amount_cents:      isset($stripeRefund['amount']) ? Currency::fromMinorUnits((int) $stripeRefund['amount'], $donation->currency) : $amountCents,
            metadata: [
                'stripe_status' => $stripeRefund['status'] ?? null,
                'livemode'      => $stripeRefund['livemode'] ?? null,
            ],
        );
    }

    /**
     * A PaymentIntent whose money has gone back still reports `succeeded`, so
     * the status alone would bank a payment the org no longer holds: counted as
     * raised, receipted, added to the donor's total. The charge is what knows,
     * and it is read live rather than from the event payload, because the
     * payload is a snapshot taken before the reversal.
     *
     * Only a reversal covering the whole payment stops the banking. A slice
     * going back leaves a donation the org did receive, and refusing it would
     * lose all of it, so the rest is banked and `reversed_minor_units` tells the
     * caller to reconcile the slice. `reversed`, not a plain failure: nothing
     * here is a decline, and a caller that reads it as one tells a donor who was
     * charged otherwise.
     *
     * @since 1.0.0
     */
    private function buildConfirmResultFromIntent(array $intent): GatewayConfirmResult
    {
        if (($intent['status'] ?? '') !== 'succeeded') {
            return new GatewayConfirmResult(
                success: false,
                error:   'PaymentIntent status is ' . ($intent['status'] ?? 'unknown'),
            );
        }

        $received = (int) ($intent['amount_received'] ?? $intent['amount'] ?? 0);
        $charge   = $this->latestCharge($intent);
        $reversed = $this->reversedFrom($charge);
        if ($reversed > 0 && $reversed >= $received) {
            return new GatewayConfirmResult(
                success:  false,
                error:    sprintf(
                    'PaymentIntent %s has %d of %d refunded or disputed; not banking it as paid.',
                    (string) ($intent['id'] ?? 'unknown'),
                    $reversed,
                    $received
                ),
                reversed: true,
            );
        }

        $chargeRef = $intent['latest_charge'] ?? null;

        // The charge says what the donor actually paid with. The intent's
        // payment_method_types is the list of every type eligible for it, in
        // Stripe's own order, so reading its first entry stamps "card" on every
        // SEPA, iDEAL and Bacs donation: an admin reconciling settlement timing
        // is then reading a column that is wrong for the whole non-card set.
        $details = is_array($charge['payment_method_details'] ?? null)
            ? $charge['payment_method_details']
            : [];
        $type = (string) ($details['type'] ?? '');
        $card = is_array($details[$type] ?? null) ? $details[$type] : [];

        return new GatewayConfirmResult(
            success:               true,
            gateway_txn_id:        is_string($chargeRef) ? $chargeRef : (string) ($intent['id'] ?? ''),
            payment_method:        $type !== '' ? $type : ($intent['payment_method_types'][0] ?? 'card'),
            payment_method_brand:  isset($card['brand']) ? (string) $card['brand'] : null,
            payment_method_last4:  isset($card['last4']) ? (string) $card['last4'] : null,
            fee_cents:             null,  // requires Charge or Balance Transaction lookup
            metadata: [
                'stripe_intent_id' => $intent['id'] ?? null,
                'stripe_status'    => $intent['status'] ?? null,
                'livemode'         => $intent['livemode'] ?? null,
            ],
            reversed_minor_units:  $reversed,
        );
    }

    /**
     * How much of this intent's charge has gone back to the donor, in minor
     * units: refunds plus a dispute whose money Stripe has taken off the
     * balance. Zero when the intent carries no charge to ask about.
     *
     * A gateway read that fails is deliberately left to throw: the webhook
     * router turns it into a 5xx and Stripe redelivers, which is the right
     * answer when the alternative is banking money nobody can account for.
     *
     * @param array<string,mixed> $intent
     *
     * @since 1.0.0
     */
    private function reversedMinorUnits(array $intent): int
    {
        return $this->reversedFrom($this->latestCharge($intent));
    }

    /**
     * The same answer from a charge already in hand, so a caller that needs the
     * charge for anything else does not fetch it twice.
     *
     * @param array<string,mixed>|null $charge
     *
     * @since 1.0.0
     */
    private function reversedFrom(?array $charge): int
    {
        if ($charge === null) {
            return 0;
        }

        return max(0, (int) ($charge['amount_refunded'] ?? 0)) + $this->disputeHold($charge);
    }

    /**
     * The intent's charge as an object. Expanded when the payload carries it,
     * fetched when the payload carries only the id, which is what the webhook
     * event and a plain retrieve both do.
     *
     * @param array<string,mixed> $intent
     *
     * @return array<string,mixed>|null
     *
     * @since 1.0.0
     */
    private function latestCharge(array $intent): ?array
    {
        $charge = $intent['latest_charge'] ?? ($intent['charges']['data'][0] ?? null);

        if (is_array($charge)) {
            return $charge;
        }

        $chargeId = is_string($charge) ? $charge : '';
        if ($chargeId === '') {
            return null;
        }

        return $this->api->get('/charges/' . rawurlencode($chargeId) . '?expand[]=dispute');
    }

    /**
     * Disputed money Stripe is holding off the balance. An open chargeback is a
     * hold, because the funds were withdrawn when it was raised. An inquiry or
     * retrieval is not: the money stays with the org while the org answers it,
     * which is why Stripe sends no charge.dispute.funds_withdrawn for one.
     *
     * @param array<string,mixed> $charge
     *
     * @since 1.0.0
     */
    private function disputeHold(array $charge): int
    {
        $dispute = $charge['dispute'] ?? null;

        if (is_string($dispute) && $dispute !== '') {
            $dispute = $this->api->get('/disputes/' . rawurlencode($dispute));
        }

        if (! is_array($dispute)) {
            // Disputed with nothing readable saying otherwise: the whole charge
            // is at risk.
            return ($charge['disputed'] ?? false) === true
                ? max(0, (int) ($charge['amount'] ?? 0))
                : 0;
        }

        if (in_array((string) ($dispute['status'] ?? ''), self::DISPUTE_FUNDS_HELD_BY_ORG, true)) {
            return 0;
        }

        return max(0, (int) ($dispute['amount'] ?? 0));
    }

    /**
     * A SetupIntent the donor's browser confirms, so the card is entered
     * against Stripe and never reaches this site. off_session usage, because
     * what is being saved is the card later renewals are charged against with
     * nobody present.
     *
     * @since 1.0.0
     */
    public function startPaymentMethodUpdate(RecurringPlan $plan): PaymentMethodUpdate
    {
        $this->account->useTestMode((bool) $plan->is_test);

        $customerId = (string) $plan->gateway_customer_id;
        if ($customerId === '') {
            // An imported plan may carry no customer, so it is read back off
            // the subscription rather than refusing the donor outright.
            $subId = (string) $plan->gateway_subscription_id;
            if ($subId !== '') {
                $sub = $this->api->get('/subscriptions/' . rawurlencode($subId));
                $customerId = is_array($sub['customer'] ?? null)
                    ? (string) ($sub['customer']['id'] ?? '')
                    : (string) ($sub['customer'] ?? '');
            }
        }
        if ($customerId === '') {
            throw new RuntimeException(esc_html__('This donation has no Stripe customer to attach a card to.', 'dono-fundraising-platform'));
        }

        $intent = $this->api->post('/setup_intents', [
            'customer' => $customerId,
            'usage'    => 'off_session',
            'automatic_payment_methods' => ['enabled' => 'true'],
        ]);

        $secret = (string) ($intent['client_secret'] ?? '');
        if ($secret === '') {
            throw new RuntimeException(esc_html__('Stripe did not return a setup secret.', 'dono-fundraising-platform'));
        }

        return PaymentMethodUpdate::inline(
            $secret,
            $this->api->publishableKeyFor((bool) $plan->is_test)
        );
    }

    /**
     * Both the subscription and the customer. The subscription alone is not
     * enough: an invoice created before the change, which is exactly the unpaid
     * one in a dunning cycle, bills the customer's default, so a donor who
     * fixed their card would watch the same invoice decline again.
     *
     * @since 1.0.0
     */
    public function completePaymentMethodUpdate(RecurringPlan $plan, string $token): void
    {
        $this->account->useTestMode((bool) $plan->is_test);

        $token = trim($token);
        if ($token === '') {
            throw new RuntimeException(esc_html__('No payment method was supplied.', 'dono-fundraising-platform'));
        }

        $subId = (string) $plan->gateway_subscription_id;
        if ($subId === '') {
            throw new RuntimeException(esc_html__('This plan has no Stripe subscription.', 'dono-fundraising-platform'));
        }

        $sub = $this->api->get('/subscriptions/' . rawurlencode($subId));
        $customerId = is_array($sub['customer'] ?? null)
            ? (string) ($sub['customer']['id'] ?? '')
            : (string) ($sub['customer'] ?? '');

        if ($customerId !== '') {
            $this->api->post('/customers/' . rawurlencode($customerId), [
                'invoice_settings' => ['default_payment_method' => $token],
            ]);
        }

        $this->api->post('/subscriptions/' . rawurlencode($subId), [
            'default_payment_method' => $token,
        ]);
    }

    /**
     * Stripe leaves a past_due subscription with an open invoice, so paying
     * that invoice is what "retry now" means. The plan is deliberately not
     * written here: invoice.payment_succeeded confirms the money, and this
     * call's response can still fail asynchronously.
     *
     * @since 1.0.0
     */
    public function retryPayment(RecurringPlan $plan): void
    {
        $this->account->useTestMode((bool) $plan->is_test);
        $subId = (string) $plan->gateway_subscription_id;
        if ($subId === '') {
            throw new PaymentRetryUnavailable(esc_html__('This plan never reached Stripe, so there is nothing to collect.', 'dono-fundraising-platform'));
        }

        $sub = $this->api->get('/subscriptions/' . rawurlencode($subId));

        // latest_invoice is an id unless expanded.
        $invoiceId = is_array($sub['latest_invoice'] ?? null)
            ? (string) ($sub['latest_invoice']['id'] ?? '')
            : (string) ($sub['latest_invoice'] ?? '');
        if ($invoiceId === '') {
            throw new PaymentRetryUnavailable(esc_html__('Stripe has no invoice outstanding on this subscription.', 'dono-fundraising-platform'));
        }

        $invoice = $this->api->get('/invoices/' . rawurlencode($invoiceId));
        $status  = (string) ($invoice['status'] ?? '');

        // Only an open invoice can be collected: draft is not finalised, and
        // paid, void and uncollectible are settled one way or another, so an
        // attempt would either error or silently do nothing.
        if ($status !== 'open') {
            throw new PaymentRetryUnavailable(esc_html(sprintf(
                /* translators: %s: the Stripe invoice status, e.g. paid. */
                __('Nothing to collect: the latest invoice is %s.', 'dono-fundraising-platform'),
                $status !== '' ? $status : __('unavailable', 'dono-fundraising-platform')
            )));
        }

        $this->api->post('/invoices/' . rawurlencode($invoiceId) . '/pay', []);
    }

    /** @since 1.0.0 */
    public function cancelSubscription(RecurringPlan $plan, ?string $reason = null): void
    {
        $this->account->useTestMode((bool) $plan->is_test);
        $subId = (string) $plan->gateway_subscription_id;
        if ($subId === '') {
            return;
        }
        try {
            $this->api->delete('/subscriptions/' . rawurlencode($subId));
        } catch (RuntimeException $e) {
            // Only Stripe's own reading of the subscription may excuse the
            // failure. The caller marks the plan cancelled and emails the donor
            // on the strength of this returning, so a failure it cannot account
            // for has to reach the caller.
            if (! $this->confirmedTerminal($subId)) {
                throw $e;
            }
        }
    }

    /**
     * @throws RuntimeException on a non-recoverable gateway error.
     *
     * @since 1.0.0
     */
    public function pauseSubscription(RecurringPlan $plan, ?string $resumesAt = null): void
    {
        $this->account->useTestMode((bool) $plan->is_test);
        $subId = (string) $plan->gateway_subscription_id;
        if ($subId === '') return;

        $pauseCollection = [ 'behavior' => 'mark_uncollectible' ];
        if ($resumesAt !== null) {
            $ts = strtotime($resumesAt);
            if ($ts !== false) $pauseCollection['resumes_at'] = $ts;
        }

        $this->api->post('/subscriptions/' . rawurlencode($subId), [
            'pause_collection' => $pauseCollection,
        ]);
    }

    /**
     * @throws RuntimeException on a non-recoverable gateway error.
     *
     * @since 1.0.0
     */
    public function resumeSubscription(RecurringPlan $plan): void
    {
        $this->account->useTestMode((bool) $plan->is_test);
        $subId = (string) $plan->gateway_subscription_id;
        if ($subId === '') return;

        // Stripe convention: passing an empty string clears pause_collection.
        $this->api->post('/subscriptions/' . rawurlencode($subId), [
            'pause_collection' => '',
        ]);
    }

    /**
     * @throws RuntimeException on a non-recoverable gateway error.
     *
     * @since 1.0.0
     */
    public function updateSubscriptionAmount(RecurringPlan $plan, int $amountCents): void
    {
        $this->account->useTestMode((bool) $plan->is_test);
        $subId = (string) $plan->gateway_subscription_id;
        if ($subId === '') return;
        if ($amountCents <= 0) throw new RuntimeException(esc_html('Amount must be positive.'));

        // Stripe Prices are immutable, so a new one is minted and swapped onto
        // the subscription's first item.
        $sub = $this->api->get('/subscriptions/' . rawurlencode($subId));
        $items = is_array($sub['items']['data'] ?? null) ? $sub['items']['data'] : [];
        if (empty($items)) {
            throw new RuntimeException(esc_html('Stripe subscription has no items to update.'));
        }
        $itemId = (string) ($items[0]['id'] ?? '');
        $oldPrice = $items[0]['price'] ?? [];
        $productId = (string) ($oldPrice['product'] ?? '');
        $interval = (string) ($oldPrice['recurring']['interval'] ?? 'month');
        $intervalCount = (int) ($oldPrice['recurring']['interval_count'] ?? 1);
        $currency = strtolower((string) ($oldPrice['currency'] ?? strtolower($plan->currency)));

        if ($itemId === '' || $productId === '') {
            throw new RuntimeException(esc_html('Stripe subscription item is missing required fields.'));
        }

        $newPrice = $this->api->post('/prices', [
            'product'     => $productId,
            'unit_amount' => Currency::toMinorUnits($amountCents, $plan->currency),
            'currency'    => $currency,
            'recurring'   => [
                'interval'       => $interval,
                'interval_count' => $intervalCount,
            ],
        ]);
        $newPriceId = (string) ($newPrice['id'] ?? '');
        if ($newPriceId === '') {
            throw new RuntimeException(esc_html('Stripe price creation returned no id.'));
        }

        // Proration off: this changes future renewals only, so there is no
        // mid-cycle delta charge.
        $this->api->post('/subscriptions/' . rawurlencode($subId), [
            'items' => [[
                'id'    => $itemId,
                'price' => $newPriceId,
            ]],
            'proration_behavior' => 'none',
        ]);
    }

    /**
     * Whether Stripe itself reports the subscription as done billing.
     *
     * Stripe keeps a cancelled subscription retrievable, so a retrieve is what
     * separates "already cancelled, nothing left to do" from "this key cannot
     * see it". The error text cannot: `No such subscription` is also what a key
     * rotated to a different Stripe account gets for a subscription that is
     * still charging the donor every month. One extra call, on the error path
     * only.
     *
     * @since 1.0.0
     */
    private function confirmedTerminal(string $subId): bool
    {
        try {
            $sub = $this->api->get('/subscriptions/' . rawurlencode($subId));
        } catch (RuntimeException) {
            // Cannot confirm, so do not swallow: reporting a cancellation that
            // may not have happened is the failure being avoided.
            return false;
        }

        return in_array((string) ($sub['status'] ?? ''), ['canceled', 'incomplete_expired'], true);
    }

    /**
     * Checked against the secret that verified, never against the event body,
     * so a leaked test secret cannot reach live records even when the body
     * omits livemode.
     *
     * @since 1.0.0
     */
    private function wrongMode(bool $recordIsTest): ?string
    {
        if ($this->verifiedIsTest === null) {
            return 'the mode of the verifying secret is unknown';
        }
        if ($this->verifiedIsTest !== $recordIsTest) {
            return sprintf(
                'a %s-mode secret verified this event but the record is %s',
                $this->verifiedIsTest ? 'test' : 'live',
                $recordIsTest ? 'test' : 'live'
            );
        }
        return null;
    }

    /**
     * Whether the local row is in a state a refund can still be recorded
     * against. Mirrors DonationService::recordExternalRefund's own precondition,
     * so a failure on a row outside it can be told apart from a database or
     * balance failure on a row inside it.
     *
     * @since 1.0.0
     */
    private function refundable(Donation $donation): bool
    {
        return in_array((string) $donation->status, ['paid', 'partial_refund'], true);
    }

    /**
     * Money moved at Stripe against a donation that was never banked here.
     *
     * Answered rather than retried: no redelivery makes a failed or already
     * reversed donation refundable, and a 5xx would have Stripe redeliver for
     * three days and write an error row per attempt. Recorded as an error
     * because the balance moved whatever the local row says.
     *
     * @since 1.0.0
     */
    private function unrefundable(string $eventId, string $type, Donation $donation, RuntimeException $e): WebhookOutcome
    {
        ErrorLog::record('gateway.stripe', $e->getMessage(), [
            'donation_id' => (int) $donation->id,
            'event_type'  => $type,
            'reference'   => (string) $donation->reference,
            'status'      => (string) $donation->status,
        ]);

        return new WebhookOutcome(
            signature_ok: true,
            external_id:  $eventId,
            event_type:   $type,
            handled:      false,
            error:        $e->getMessage(),
            http_status:  200,
        );
    }

    /**
     * 200, not 5xx: the event is genuine, it just may not do what it asked, and
     * a 5xx would make Stripe retry it for days.
     *
     * @since 1.0.0
     */
    private function refused(string $eventId, string $type, string $reason): WebhookOutcome
    {
        return new WebhookOutcome(
            signature_ok: true,
            external_id:  $eventId,
            event_type:   $type,
            handled:      false,
            error:        'Refused: ' . $reason,
            http_status:  200,
        );
    }
}
