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
use Dono\Gateways\GatewayIntentResult;
use Dono\Gateways\PaymentGateway;
use Dono\Gateways\RefundResult;
use Dono\Gateways\SubscriptionAware;
use Dono\Gateways\TestMode;
use Dono\Gateways\WebhookOutcome;
use Dono\Foundation\License\LicenseService;
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
    /** Platform fee in basis points (200 = 2%). Filterable via dono.stripe.application_fee_bps. */
    private const FEE_BPS = 200;

    public function __construct(
        private StripeApi $api,
        private DonationRepository $donations,
        private DonationService $donationService,
        private StripeConnectAccount $connect,
        private LicenseService $license,
        private DonorRepository $donors,
        private DonorService $donorService,
        private Clock $clock,
        private RecurringPlanRepository $plans,
    ) {
    }

    /**
     * Returns extra Stripe request headers. Empty because we authenticate as
     * the connected account via its own access token; the platform
     * Stripe-Account header would be incorrect. Seam kept so call sites stay
     * uniform.
     *
     * @return array<string,string>
     */
    private function connectHeaders(): array
    {
        return [];
    }

    /**
     * Platform cut in cents, clamped to [0, amountCents]. Filterable via
     * dono.stripe.application_fee_bps; 0 when isPro().
     */
    private function applicationFee(int $amountCents): int
    {
        if ($this->license->isPro()) return 0;
        $bps = (int) apply_filters('dono.stripe.application_fee_bps', self::FEE_BPS);
        $bps = max(0, min(10000, $bps));
        $fee = (int) floor($amountCents * $bps / 10000);
        return max(0, min($fee, $amountCents));
    }

    /** Same cut as a percentage, for subscription renewals (application_fee_percent). */
    private function applicationFeePercent(): float
    {
        if ($this->license->isPro()) return 0.0;
        $bps = (int) apply_filters('dono.stripe.application_fee_bps', self::FEE_BPS);
        $bps = max(0, min(10000, $bps));
        return $bps / 100;
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
        return $this->connect->canCharge();
    }

    public function createIntent(Donation $donation): GatewayIntentResult
    {
        $this->connect->useTestMode((bool) $donation->is_test);

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

        // Stamp which connected account this donation settles to.
        $donation->gateway_account_id = $this->connect->accountIdFor(
            $donation->campaign_id,
            $donation->form_id
        );

        // Direct charge on the connected account; platform takes its cut via application_fee_amount.
        $fee = $this->applicationFee($donation->amount_cents);
        if ($fee > 0 && $this->connect->isConnected()) {
            $params['application_fee_amount'] = Currency::toMinorUnits($fee, $donation->currency);
        }

        $intent = $this->api->post('/payment_intents', $params, $this->connectHeaders());

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

        $this->connect->useTestMode((bool) $donation->is_test);

        $intent = $this->api->get('/payment_intents/' . $donation->gateway_intent_id, $this->connectHeaders());
        return $this->buildConfirmResultFromIntent($intent);
    }

    public function handleWebhook(WP_REST_Request $request): WebhookOutcome
    {
        $payload = (string) $request->get_body();
        $sig     = (string) $request->get_header('stripe_signature');

        if (! $this->api->verifyWebhookSignature($payload, $sig)) {
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

        // Any token-bearing follow-up (subscription creation, refunds) must
        // run in the mode the event was generated in, not whatever the last
        // request left set. livemode is part of the now-verified payload.
        // Absent (shouldn't happen for real events) falls back to test.
        $this->connect->useTestMode(! (bool) ($event['livemode'] ?? false));

        $eventId = (string) $event['id'];
        $type    = (string) $event['type'];
        $object  = (array) ($event['data']['object'] ?? []);

        switch ($type) {
            case 'payment_intent.succeeded':
                return $this->handlePaymentIntentSucceeded($eventId, $type, $object);

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

        $refunds = (array) ($charge['refunds']['data'] ?? []);
        // Recent Stripe API versions drop the embedded refund list from the
        // event payload; fetch it when the charge shows a refund but none came
        // through, otherwise external refunds would be silently ignored.
        $chargeId = (string) ($charge['id'] ?? '');
        if ($refunds === [] && (int) ($charge['amount_refunded'] ?? 0) > 0 && $chargeId !== '') {
            try {
                $fetched = $this->api->get('/charges/' . rawurlencode($chargeId) . '/refunds', $this->connectHeaders());
                $refunds = (array) ($fetched['data'] ?? []);
            } catch (RuntimeException $e) {
                // Leave empty; the outcome reports handled with no rows recorded.
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
     * `account.updated`: connected account capabilities changed. Mirror the
     * flags locally. Only acts on the account we have stored.
     */
    private function handleAccountUpdated(string $eventId, string $type, array $account): WebhookOutcome
    {
        $acctId  = (string) ($account['id'] ?? '');
        $current = $this->connect->accountId();

        if ($acctId !== '' && $current !== null && hash_equals($current, $acctId)) {
            $this->connect->refresh($account);
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
        $current = $this->connect->accountId();

        if ($acctId !== '' && $current !== null && hash_equals($current, $acctId)) {
            $this->connect->forget();
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

        $this->connect->useTestMode((bool) $donation->is_test);
        $intent = $this->api->get(
            '/payment_intents/' . rawurlencode((string) $donation->gateway_intent_id),
            $this->connectHeaders()
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

        $customer = $this->api->post('/customers', $params, $this->connectHeaders());
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
        ], $this->connectHeaders());

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
        ], $this->connectHeaders());

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

        // Without this every renewal invoice would settle at 0 platform fee even
        // though the first charge took one. application_fee_percent applies the
        // same cut to each recurring invoice on the connected account.
        $feePercent = $this->applicationFeePercent();
        if ($feePercent > 0) {
            $subParams['application_fee_percent'] = $feePercent;
        }

        $sub = $this->api->post('/subscriptions', $subParams, array_merge(
            $this->connectHeaders(),
            // Deterministic key: a redelivered webhook re-POSTs the same key, so
            // Stripe returns the original subscription instead of creating a
            // second one (which would double-charge the donor every renewal).
            ['Idempotency-Key' => 'dono_sub_' . $donation->id]
        ));

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
     * One Stripe Product per mode (test/live), cached in gateway settings.
     * Stripe Prices need a product to hang off; we never present the product
     * to the donor, it's purely a billing-side grouping.
     */
    private function resolveDonationProduct(bool $isTest): string
    {
        $opt    = get_option('dono_gateway_config', []);
        $stripe = is_array($opt) && is_array($opt['stripe'] ?? null) ? $opt['stripe'] : [];
        $key    = $isTest ? 'stripe_product_id_test' : 'stripe_product_id_live';

        $existing = (string) ($stripe[$key] ?? '');
        if ($existing !== '') return $existing;

        $product = $this->api->post('/products', [
            'name'        => 'Donation',
            'description' => sprintf('Recurring donations to %s', (string) get_bloginfo('name')),
        ], $this->connectHeaders());

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

        $now = $this->clock->now()->format('Y-m-d H:i:s');
        $this->plans->recordFailedRenewal($plan, $now);

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

        $reason = (string) ($sub['cancellation_details']['reason'] ?? '');
        $now    = $this->clock->now()->format('Y-m-d H:i:s');
        // A portal/admin cancel already recorded the event before deleting the
        // Stripe sub; only emit here for gateway-initiated cancels (dunning,
        // Stripe dashboard) so the donor gets exactly one cancellation email.
        $alreadyCancelled = $plan->status === 'cancelled';
        $this->plans->markCancelled($plan, $now, $reason !== '' ? $reason : null);

        if (! $alreadyCancelled) {
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

        $this->connect->useTestMode((bool) $donation->is_test);

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
            // refund_application_fee pulls the platform's cut back too, so a
            // refunded donation nets to zero for everyone.
            if ($this->connect->isConnected()) {
                $params['refund_application_fee'] = 'true';
            }
            $stripeRefund = $this->api->post('/refunds', $params, $this->connectHeaders());
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
        $this->connect->useTestMode((bool) $plan->is_test);
        $subId = (string) $plan->gateway_subscription_id;
        if ($subId === '') {
            // Local-only plan, never reached Stripe.
            return;
        }
        try {
            $this->api->delete('/subscriptions/' . rawurlencode($subId), $this->connectHeaders());
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
        $this->connect->useTestMode((bool) $plan->is_test);
        $subId = (string) $plan->gateway_subscription_id;
        if ($subId === '') return;

        $pauseCollection = [ 'behavior' => 'mark_uncollectible' ];
        if ($resumesAt !== null) {
            $ts = strtotime($resumesAt);
            if ($ts !== false) $pauseCollection['resumes_at'] = $ts;
        }

        $this->api->post('/subscriptions/' . rawurlencode($subId), [
            'pause_collection' => $pauseCollection,
        ], $this->connectHeaders());
    }

    /** @throws RuntimeException on a non-recoverable gateway error. */
    public function resumeSubscription(RecurringPlan $plan): void
    {
        $this->connect->useTestMode((bool) $plan->is_test);
        $subId = (string) $plan->gateway_subscription_id;
        if ($subId === '') return;

        // Stripe convention: passing an empty string clears pause_collection.
        $this->api->post('/subscriptions/' . rawurlencode($subId), [
            'pause_collection' => '',
        ], $this->connectHeaders());
    }

    /** @throws RuntimeException on a non-recoverable gateway error. */
    public function updateSubscriptionAmount(RecurringPlan $plan, int $amountCents): void
    {
        $this->connect->useTestMode((bool) $plan->is_test);
        $subId = (string) $plan->gateway_subscription_id;
        if ($subId === '') return;
        if ($amountCents <= 0) throw new RuntimeException('Amount must be positive.');

        // Stripe Prices are immutable, so mint a new Price and swap it onto
        // the subscription's first item.
        $sub = $this->api->get('/subscriptions/' . rawurlencode($subId), $this->connectHeaders());
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
        ], $this->connectHeaders());
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
        ], $this->connectHeaders());
    }

    /** True when Stripe's error means the subscription is already gone. */
    private function isAlreadyHandled(RuntimeException $e): bool
    {
        $msg = strtolower($e->getMessage());
        return str_contains($msg, 'no such subscription')
            || str_contains($msg, 'already canceled')
            || str_contains($msg, 'already cancelled');
    }
}
