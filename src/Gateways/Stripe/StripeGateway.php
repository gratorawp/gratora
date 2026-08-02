<?php

declare(strict_types=1);

namespace Dono\Gateways\Stripe;

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
use Dono\Gateways\SubscriptionAware;
use Dono\Gateways\TestMode;
use Dono\Gateways\WebhookOutcome;
use Dono\Gateways\WebhookPaymentGuard;
use Dono\Recurring\FrequencyMap;
use Dono\Recurring\RecurringPlan;
use Dono\Recurring\RecurringPlanRepository;
use RuntimeException;
use WP_REST_Request;

/**
 * Stripe gateway via Connect. createIntent posts to /v1/payment_intents and
 * returns the PaymentIntent id plus client_secret; the frontend captures
 * payment; Stripe posts payment_intent.succeeded to the webhook router.
 *
 * @version 1.0.0
 */
final class StripeGateway implements PaymentGateway, SubscriptionAware
{
    /**
     * Mode of the signing secret that verified the current webhook. Set once
     * per delivery in handleWebhook; null outside a webhook request.
     */
    private ?bool $verifiedIsTest = null;

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


    public function id(): string
    {
        return 'stripe';
    }

    public function label(): string
    {
        return __('Stripe (Card, SEPA, iDEAL, Bancontact, Apple Pay, Google Pay)', 'dono');
    }

    public function description(): string
    {
        return __('Card, Apple Pay, Google Pay and local methods, processed securely by Stripe.', 'dono');
    }

    public function frequencies(): array
    {
        return ['one_time', 'recurring'];
    }

    public function paymentMethods(): array
    {
        return ['card', 'sepa_debit', 'ideal', 'bancontact', 'apple_pay', 'google_pay'];
    }

    public function countries(): array
    {
        // Wildcard: defer to Stripe's own country validation.
        return ['*'];
    }

    public function currencies(): array
    {
        // Wildcard: defer to Stripe's own currency validation.
        return ['*'];
    }

    public function canCharge(): bool
    {
        // Connected but mid-onboarding accounts can't charge yet; gating here
        // keeps the donor options and the admin readiness check on one signal.
        return $this->account->canCharge();
    }

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
            // Let Stripe present any payment method allowed on the account.
            // String 'true': the API client form-encodes, and http_build_query
            // turns PHP true into "1", which Stripe rejects for booleans.
            'automatic_payment_methods' => ['enabled' => 'true'],
        ];

        $customerId = null;
        if (FrequencyMap::isRecurring($donation->frequency)) {
            // Stripe requires a Customer on the PI for setup_future_usage to
            // attach the PaymentMethod to a reusable identity; otherwise the
            // off-session future charges have nothing to bill against.
            $customerId = $this->getOrCreateStripeCustomer($donation);
            $params['customer']            = $customerId;
            $params['setup_future_usage']  = 'off_session';
        }

        // Stamp which Stripe account this donation settles to.
        $donation->gateway_account_id = $this->account->accountId();

        // Charged directly on the organisation's own Stripe account: the full
        // amount settles to them, Dono takes nothing.
        $intent = $this->api->post('/payment_intents', $params);

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

    public function confirm(Donation $donation, array $payload = []): GatewayConfirmResult
    {
        // Exposed so admin can manually re-poll a stuck PaymentIntent.
        if (! $donation->gateway_intent_id) {
            return new GatewayConfirmResult(success: false, error: 'No gateway_intent_id on donation.');
        }

        $this->account->useTestMode((bool) $donation->is_test);

        $intent = $this->api->get('/payment_intents/' . $donation->gateway_intent_id);
        return $this->buildConfirmResultFromIntent($intent);
    }

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
        // disagree the body is lying, which is exactly how a leaked test secret
        // was able to refund a live donation. Only checked when livemode is
        // actually present: its absence is not evidence of anything, and the
        // per-donation mode check below is what enforces the rule regardless.
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

            case 'charge.dispute.funds_withdrawn':
                return $this->handleDisputeFundsWithdrawn($eventId, $type, $object);

            case 'invoice.payment_succeeded':
                return $this->handleInvoicePaymentSucceeded($eventId, $type, $object);

            case 'invoice.payment_failed':
                return $this->handleInvoicePaymentFailed($eventId, $type, $object);

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

    private function handlePaymentIntentSucceeded(string $eventId, string $type, array $intent): WebhookOutcome
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

        // Idempotent: DonationService::confirm() no-ops on already-paid donations.
        $this->donationService->confirm($donation, $confirm->toArray());

        // For recurring donations, convert the saved card into a Stripe
        // Subscription so future renewals fire `invoice.payment_succeeded`.
        // Failure here doesn't roll back the first charge: flag the donation
        // so admins see + retry instead of silently losing every renewal.
        if (FrequencyMap::isRecurring($donation->frequency) && ! $donation->recurring_plan_id) {
            try {
                $this->createSubscriptionFromFirstCharge($donation, $intent);
            } catch (\Throwable $e) {
                $this->donationService->recordSubscriptionCreationFailure($donation, $e);
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
     * A bank debit has been submitted and will settle in a few days.
     *
     * Only SEPA, ACH and the other delayed-notification methods reach this:
     * a card goes straight to succeeded. Recording it means an admin sees
     * expected income rather than a growing pile of donations that look
     * abandoned, and the donor is not told for a week that we are still
     * waiting on them. It is emphatically not paid: the debit can still bounce,
     * and `payment_intent.succeeded` is what settles it.
     *
     * @param array<string,mixed> $intent
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

        $reason = $intent['last_payment_error']['message'] ?? __('Payment declined.', 'dono');
        $this->donationService->markFailed($donation, $reason);

        return new WebhookOutcome(
            signature_ok: true,
            external_id:  $eventId,
            event_type:   $type,
            handled:      true,
        );
    }

    /**
     * `charge.refunded` fires for refunds from our own `refund()` or from the
     * Stripe Dashboard / dispute resolution. Each entry in `refunds.data[]` is
     * delegated to `recordExternalRefund`, idempotent on `gateway_refund_id`.
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
        // event payload; fetch it when the charge shows a refund but none came
        // through, otherwise external refunds would be silently ignored.
        $chargeId = (string) ($charge['id'] ?? '');
        if ($refunds === [] && (int) ($charge['amount_refunded'] ?? 0) > 0 && $chargeId !== '') {
            try {
                $fetched = $this->api->get('/charges/' . rawurlencode($chargeId) . '/refunds');
                $refunds = (array) ($fetched['data'] ?? []);
            } catch (RuntimeException $e) {
                // This fetch is the only source of the refund rows on recent
                // API versions. Swallowing it would leave a real refund
                // unrecorded and the donation "paid" forever with no retry, so
                // report a 500 and let Stripe redeliver (recordExternalRefund
                // is idempotent, so a later success won't double-count).
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
            $refundId = (string) ($r['id'] ?? '');
            $amount   = Currency::fromMinorUnits((int) ($r['amount'] ?? 0), $donation->currency);
            if ($refundId === '' || $amount <= 0) continue;

            $reason = isset($r['reason']) && is_string($r['reason']) && $r['reason'] !== ''
                ? $r['reason']
                : null;

            // Idempotent: service no-ops if we already have this refund row.
            $this->donationService->recordExternalRefund(
                $donation,
                $amount,
                $refundId,
                $reason,
                'gateway',
                is_array($r) ? $r : null
            );
        }

        return new WebhookOutcome(
            signature_ok: true,
            external_id:  $eventId,
            event_type:   $type,
            handled:      true,
        );
    }

    /**
     * `charge.dispute.funds_withdrawn`: a lost dispute pulled funds from our
     * balance. Recorded as a 'dispute'-sourced refund so counters drop.
     * Idempotent via the dispute id used as the refund id.
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

        $reason = isset($dispute['reason']) && is_string($dispute['reason']) && $dispute['reason'] !== ''
            ? 'dispute: ' . $dispute['reason']
            : 'dispute';

        $this->donationService->recordExternalRefund(
            $donation,
            $amount,
            $disputeId,
            $reason,
            'dispute',
            $dispute
        );

        return new WebhookOutcome(
            signature_ok: true,
            external_id:  $eventId,
            event_type:   $type,
            handled:      true,
        );
    }

    /**
     * `account.updated`: the Stripe account's capabilities changed. Mirror the
     * flags locally. Only acts on the account we have stored.
     */
    private function handleAccountUpdated(string $eventId, string $type, array $account): WebhookOutcome
    {
        $acctId  = (string) ($account['id'] ?? '');
        $current = $this->account->accountId();

        if ($acctId !== '' && $current !== null && hash_equals($current, $acctId)) {
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
     * `account.application.deauthorized`: the org revoked our platform access.
     * Drop local state so we stop charging an account we can no longer touch.
     * The `account` field on the envelope identifies which account left.
     */
    private function handleAccountDeauthorized(string $eventId, string $type, array $event): WebhookOutcome
    {
        $acctId  = (string) ($event['account'] ?? '');
        $current = $this->account->accountId();

        if ($acctId !== '' && $current !== null && hash_equals($current, $acctId)) {
            $this->account->forget();
        }

        return new WebhookOutcome(
            signature_ok: true,
            external_id:  $eventId,
            event_type:   $type,
            handled:      true,
        );
    }

    /**
     * Retry the failed PaymentIntent → Subscription conversion. Re-fetches the
     * PaymentIntent from Stripe (so we get the current payment_method even if
     * the donor authenticated minutes later) and re-runs the chain. Clears the
     * failure flags on success.
     *
     * @throws RuntimeException when Stripe still can't be reached or returns
     *                          unusable data.
     */
    public function retrySubscriptionCreation(Donation $donation): RecurringPlan
    {
        if (! FrequencyMap::isRecurring($donation->frequency)) {
            throw new RuntimeException("Donation {$donation->reference} is not recurring; nothing to convert.");
        }
        if ($donation->recurring_plan_id) {
            throw new RuntimeException("Donation {$donation->reference} already has a recurring plan.");
        }
        if (! $donation->gateway_intent_id) {
            throw new RuntimeException("Donation {$donation->reference} has no gateway intent to re-read.");
        }

        $this->account->useTestMode((bool) $donation->is_test);
        $intent = $this->api->get(
            '/payment_intents/' . rawurlencode((string) $donation->gateway_intent_id)
        );

        $this->createSubscriptionFromFirstCharge($donation, $intent);
        $this->donationService->clearSubscriptionCreationFailure($donation);

        // Reload to pick up the plan id the createSubscriptionFromFirstCharge step set.
        $fresh = $this->donations->findByReference($donation->reference);
        $planId = $fresh && $fresh->recurring_plan_id ? (int) $fresh->recurring_plan_id : 0;
        $plan = $planId > 0 ? RecurringPlan::query()->find('id', $planId) : null;
        if (! $plan) {
            // createSubscriptionFromFirstCharge linked it but the read failed.
            // Surface a runtime error so the retry endpoint returns a 502.
            throw new RuntimeException("Retry succeeded but plan row could not be re-read for donation {$donation->reference}.");
        }
        return $plan;
    }

    /**
     * Mint (or fetch) a Stripe Customer for this donor so the saved card has
     * something durable to attach to. One Customer per donor per mode is fine
     * for now; Stripe deduplicates by id, not by email.
     */
    private function getOrCreateStripeCustomer(Donation $donation): string
    {
        $donor = $this->donors->findById((int) $donation->donor_id);
        $email = $donor ? $this->donorService->decryptEmail($donor) : null;
        $name  = trim(($donation->donor_first_name ?? '') . ' ' . ($donation->donor_last_name ?? ''));

        $params = [
            'metadata' => [
                'dono_donor_id'    => (string) $donation->donor_id,
                'dono_donation_id' => (string) $donation->id,
            ],
        ];
        if ($email !== null && $email !== '') $params['email'] = $email;
        if ($name !== '')                     $params['name']  = $name;

        $customer = $this->api->post('/customers', $params);
        $id       = (string) ($customer['id'] ?? '');
        if ($id === '') {
            throw new RuntimeException('Stripe customer creation returned no id.');
        }
        return $id;
    }

    /**
     * After the first PaymentIntent for a recurring donation succeeds, hand the
     * saved card off to a real Stripe Subscription so Stripe drives every
     * renewal from there. `billing_cycle_anchor` is set one interval into the
     * future so this transition doesn't double-charge the donor on the same day.
     */
    private function createSubscriptionFromFirstCharge(Donation $donation, array $piIntent): void
    {
        // Both come straight from the PaymentIntent payload Stripe just sent
        // us. Stashing customer_id on the donation doesn't survive confirm()'s
        // metadata overwrite, so we read it from the source of truth.
        $customerId      = (string) ($piIntent['customer'] ?? '');
        $paymentMethodId = (string) ($piIntent['payment_method'] ?? '');

        if ($customerId === '' || $paymentMethodId === '') {
            throw new RuntimeException(sprintf(
                'Cannot convert donation %s to a subscription: customer=%s, payment_method=%s',
                $donation->reference,
                $customerId === '' ? 'missing' : 'ok',
                $paymentMethodId === '' ? 'missing' : 'ok'
            ));
        }

        // Make the PaymentMethod the customer's default so the subscription
        // bills against the same card the donor authorised.
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
            // Deterministic key: a redelivered webhook re-POSTs the same key, so
            // Stripe returns the original subscription instead of creating a
            // second one (which would double-charge the donor every renewal).
            'Idempotency-Key' => 'dono_sub_' . $donation->id,
        ]);

        $subId = (string) ($sub['id'] ?? '');

        // Redelivery can re-enter here with the same idempotent subscription;
        // reuse the plan already linked to it rather than inserting a duplicate.
        $existingPlan = $this->plans->findBySubscriptionId($this->id(), $subId);
        if ($existingPlan) {
            $donation->recurring_plan_id = (int) $existingPlan->id;
            $donation->save();
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
        $plan->payments_count     = 1; // first charge counts.
        $plan->total_paid_cents   = (int) $donation->amount_cents;
        $plan->created_at         = $now;
        $plan->updated_at         = $now;
        $plan->save();

        // Link donation back to the plan it spawned.
        $donation->recurring_plan_id = (int) $plan->id;
        $donation->save();
    }

    /**
     * One Stripe Product per mode (test/live) and account, cached in gateway
     * settings. Stripe Prices need a product to hang off; we never present the
     * product to the donor, it's purely a billing-side grouping.
     *
     * The account fingerprint is part of the key because a Product lives inside
     * one Stripe account: without it, connecting a different account kept
     * handing the old account's product id to the new one and every recurring
     * donation failed against it.
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
            throw new RuntimeException('Stripe product creation returned no id.');
        }

        $stripe[$key]    = $productId;
        $opt['stripe']   = $stripe;
        update_option('dono_gateway_config', $opt);
        return $productId;
    }

    /**
     * `invoice.payment_succeeded` for `billing_reason=subscription_cycle` is a
     * renewal. The first invoice (`subscription_create`) is paid by the one-off
     * PaymentIntent and ignored here to avoid double-counting.
     */
    private function handleInvoicePaymentSucceeded(string $eventId, string $type, array $invoice): WebhookOutcome
    {
        $billingReason = (string) ($invoice['billing_reason'] ?? '');
        // Subscription-created invoices are handled by the PaymentIntent flow.
        // Anything else (manual, subscription_update, ...) is out of scope.
        if ($billingReason !== 'subscription_cycle') {
            return new WebhookOutcome(
                signature_ok: true,
                external_id:  $eventId,
                event_type:   $type,
                handled:      true,
            );
        }

        $subscriptionId = (string) ($invoice['subscription'] ?? '');
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

        // A signature only proves Stripe sent it, not which mode signed it. The
        // donation handlers check; the three that act on a plan did not, and
        // refuseToTouchPlan() was written for exactly this and called nowhere.
        // A test-mode secret is a much softer credential - staging env files,
        // CI, contractors - and it could renew a live plan, bank the money,
        // email a receipt, or cancel every live subscription on the account.
        if ($reason = WebhookPaymentGuard::refuseToTouchPlan($plan, $this->id(), $this->verifiedIsTest)) {
            return $this->refused($eventId, $type, $reason);
        }

        $piId        = (string) ($invoice['payment_intent'] ?? '');
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

        $renewal = $this->donationService->createRenewal(
            $plan,
            $amountCents,
            $currency,
            $this->id(),
            $piId,
            $confirmResult,
        );

        // Bump plan counters only for a genuinely new renewal. A redelivered
        // invoice.payment_succeeded returns created=false, and recordPayment's
        // unconditional increments would otherwise permanently inflate the
        // plan's payments_count / total_paid_cents.
        if ($renewal['created']) {
            $now    = $this->clock->now()->format('Y-m-d H:i:s');
            $nextAt = isset($invoice['lines']['data'][0]['period']['end'])
                ? date('Y-m-d H:i:s', (int) $invoice['lines']['data'][0]['period']['end'])
                : null;
            // Refresh plan from DB so we're not stomping a stale row.
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

    private function handleInvoicePaymentFailed(string $eventId, string $type, array $invoice): WebhookOutcome
    {
        $subscriptionId = (string) ($invoice['subscription'] ?? '');
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

        // A signature only proves Stripe sent it, not which mode signed it. The
        // donation handlers check; the three that act on a plan did not, and
        // refuseToTouchPlan() was written for exactly this and called nowhere.
        // A test-mode secret is a much softer credential - staging env files,
        // CI, contractors - and it could renew a live plan, bank the money,
        // email a receipt, or cancel every live subscription on the account.
        if ($reason = WebhookPaymentGuard::refuseToTouchPlan($plan, $this->id(), $this->verifiedIsTest)) {
            return $this->refused($eventId, $type, $reason);
        }

        $now = $this->clock->now()->format('Y-m-d H:i:s');
        $this->plans->recordFailedRenewal($plan, $now);

        // Stripe puts the decline text on the finalization error when it has
        // one; a plain card decline arrives with nothing useful at invoice
        // level, and inventing a reason would be worse than saying none.
        $reason = $invoice['last_finalization_error']['message'] ?? null;
        $this->donationService->recordRecurringFailure($plan, $reason !== null ? (string) $reason : null);

        return new WebhookOutcome(
            signature_ok: true,
            external_id:  $eventId,
            event_type:   $type,
            handled:      true,
        );
    }

    /**
     * Subscription terminal cancellation: gateway already stopped charging, we
     * just mirror the state locally and emit so the donor gets a notice.
     */
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

        // A signature only proves Stripe sent it, not which mode signed it. The
        // donation handlers check; the three that act on a plan did not, and
        // refuseToTouchPlan() was written for exactly this and called nowhere.
        // A test-mode secret is a much softer credential - staging env files,
        // CI, contractors - and it could renew a live plan, bank the money,
        // email a receipt, or cancel every live subscription on the account.
        if ($reason = WebhookPaymentGuard::refuseToTouchPlan($plan, $this->id(), $this->verifiedIsTest)) {
            return $this->refused($eventId, $type, $reason);
        }

        $reason = (string) ($sub['cancellation_details']['reason'] ?? '');
        $now    = $this->clock->now()->format('Y-m-d H:i:s');
        // A portal/admin cancel already recorded the event before deleting the
        // Stripe sub; only emit here for gateway-initiated cancels (dunning,
        // Stripe dashboard) so the donor gets exactly one cancellation email.
        // Gate on which call actually won the DB transition, not a pre-read, so
        // two racing deliveries can't both send.
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

    public function refund(Donation $donation, int $amountCents, ?string $reason = null): RefundResult
    {
        if (! $donation->gateway_intent_id) {
            return RefundResult::failure(__('No gateway intent on donation; cannot refund via Stripe.', 'dono'));
        }

        $this->account->useTestMode((bool) $donation->is_test);

        if (! $this->api->isConfigured()) {
            return RefundResult::failure(__('Stripe is not configured.', 'dono'));
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
            // Idempotency-Key so a timed-out refund that already processed on
            // Stripe returns the original on retry instead of issuing a second
            // one. Stable per attempt (refunded_cents only advances once the
            // local write commits) yet distinct across separate partial refunds.
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

    private function buildConfirmResultFromIntent(array $intent): GatewayConfirmResult
    {
        if (($intent['status'] ?? '') !== 'succeeded') {
            return new GatewayConfirmResult(
                success: false,
                error:   'PaymentIntent status is ' . ($intent['status'] ?? 'unknown'),
            );
        }

        $charge = $intent['latest_charge'] ?? null;
        $method = $intent['payment_method'] ?? null;

        // Brand/last4 require expanding payment_method_details; not requested
        // here, so those fields stay null until a later Charge lookup.
        return new GatewayConfirmResult(
            success:               true,
            gateway_txn_id:        is_string($charge) ? $charge : (string) ($intent['id'] ?? ''),
            payment_method:        $intent['payment_method_types'][0] ?? 'card',
            payment_method_brand:  null,
            payment_method_last4:  null,
            fee_cents:             null,  // requires Charge or Balance Transaction lookup
            metadata: [
                'stripe_intent_id' => $intent['id'] ?? null,
                'stripe_status'    => $intent['status'] ?? null,
                'livemode'         => $intent['livemode'] ?? null,
            ],
        );
    }

    /** @throws RuntimeException on a non-recoverable gateway error. */
    public function cancelSubscription(RecurringPlan $plan, ?string $reason = null): void
    {
        $this->account->useTestMode((bool) $plan->is_test);
        $subId = (string) $plan->gateway_subscription_id;
        if ($subId === '') {
            // Local-only plan, never reached Stripe.
            return;
        }
        try {
            $this->api->delete('/subscriptions/' . rawurlencode($subId));
        } catch (RuntimeException $e) {
            // Already-cancelled/not-found 4xx: swallow so the local cancel
            // doesn't bounce when the gateway is already in the desired state.
            if (! $this->isAlreadyHandled($e)) {
                throw $e;
            }
        }
    }

    /** @throws RuntimeException on a non-recoverable gateway error. */
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

    /** @throws RuntimeException on a non-recoverable gateway error. */
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

    /** @throws RuntimeException on a non-recoverable gateway error. */
    public function updateSubscriptionAmount(RecurringPlan $plan, int $amountCents): void
    {
        $this->account->useTestMode((bool) $plan->is_test);
        $subId = (string) $plan->gateway_subscription_id;
        if ($subId === '') return;
        if ($amountCents <= 0) throw new RuntimeException('Amount must be positive.');

        // Stripe Prices are immutable, so mint a new Price and swap it onto
        // the subscription's first item.
        $sub = $this->api->get('/subscriptions/' . rawurlencode($subId));
        $items = is_array($sub['items']['data'] ?? null) ? $sub['items']['data'] : [];
        if (empty($items)) {
            throw new RuntimeException('Stripe subscription has no items to update.');
        }
        $itemId = (string) ($items[0]['id'] ?? '');
        $oldPrice = $items[0]['price'] ?? [];
        $productId = (string) ($oldPrice['product'] ?? '');
        $interval = (string) ($oldPrice['recurring']['interval'] ?? 'month');
        $intervalCount = (int) ($oldPrice['recurring']['interval_count'] ?? 1);
        $currency = strtolower((string) ($oldPrice['currency'] ?? strtolower($plan->currency)));

        if ($itemId === '' || $productId === '') {
            throw new RuntimeException('Stripe subscription item is missing required fields.');
        }

        // New Price, preserving cadence and product.
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
            throw new RuntimeException('Stripe price creation returned no id.');
        }

        // Proration disabled: this only changes future renewals, so no
        // mid-cycle delta charge.
        $this->api->post('/subscriptions/' . rawurlencode($subId), [
            'items' => [[
                'id'    => $itemId,
                'price' => $newPriceId,
            ]],
            'proration_behavior' => 'none',
        ]);
    }

    /** True when Stripe's error means the subscription is already gone. */
    private function isAlreadyHandled(RuntimeException $e): bool
    {
        $msg = strtolower($e->getMessage());
        return str_contains($msg, 'no such subscription')
            || str_contains($msg, 'already canceled')
            || str_contains($msg, 'already cancelled');
    }

    /**
     * Every webhook that touches a donation or a plan must be in that record's
     * mode. Checked against the secret that verified, never against the event
     * body, so a leaked test secret cannot reach live records even when the
     * body omits livemode.
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
     * A signed event that must not touch this donation. 200, not 5xx: the event
     * is genuine, it just may not do what it asked, and a 5xx would make Stripe
     * retry it for days.
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
