<?php

declare(strict_types=1);

namespace Dono\Gateways\PayPal;

use Dono\Analytics\ErrorLog;
use Dono\Donations\Donation;
use Dono\Donations\DonationRepository;
use Dono\Donations\DonationService;
use Dono\Foundation\Time\Clock;
use Dono\Gateways\GatewayConfirmResult;
use Dono\Gateways\GatewayIntentResult;
use Dono\Gateways\PaymentGateway;
use Dono\Gateways\RefundResult;
use Dono\Gateways\PaymentMethodUpdate;
use Dono\Gateways\SubscriptionAware;
use Dono\Gateways\SupportsPaymentMethodUpdate;
use Dono\Gateways\WebhookOutcome;
use Dono\Gateways\SubscriptionChangeNeedsApproval;
use Dono\Gateways\WebhookPaymentGuard;
use Dono\Recurring\FrequencyMap;
use Dono\Recurring\RecurringPlan;
use Dono\Recurring\RecurringPlanRepository;
use RuntimeException;
use WP_REST_Request;

/**
 * PayPal gateway via Orders v2 (one-time) and Subscriptions v1 (recurring).
 *
 * The donor never leaves the site: the JS SDK renders PayPal's buttons and
 * opens its own popup. For one-time donations Dono creates the Order up front
 * so `gateway_intent_id` exists before the donor approves; the browser then
 * approves it and Dono captures server-side. For recurring, Dono provisions a
 * Product + Plan and the button creates the Subscription against that plan.
 *
 * Webhooks are the source of truth for money movement and are idempotent, so a
 * capture that also arrives by webhook is confirmed only once.
 *
 * @since 1.0.0
 */
final class PayPalGateway implements PaymentGateway, SubscriptionAware, SupportsPaymentMethodUpdate
{
    /**
     * Mode of the credentials that verified the current webhook. Set once per
     * delivery in handleWebhook; null outside a webhook request.
     */
    private ?bool $verifiedIsTest = null;

    /** @since 1.0.0 */
    public function __construct(
        private PayPalApi $api,
        private PayPalAccount $account,
        private DonationRepository $donations,
        private DonationService $donationService,
        private PayPalPlans $plans,
        private RecurringPlanRepository $planRepo,
        private Clock $clock,
        private ?PayPalPlanRecorder $planRecorder = null,
    ) {
    }

    /** @since 1.0.0 */
    public function id(): string
    {
        return 'paypal';
    }

    /** @since 1.0.0 */
    public function label(): string
    {
        return __('PayPal', 'dono-fundraising-platform');
    }

    /** @since 1.0.0 */
    public function description(): string
    {
        return __('Pay with your PayPal balance, a bank account, or a card. No PayPal account required.', 'dono-fundraising-platform');
    }

    /** @since 1.0.0 */
    public function frequencies(): array
    {
        // PayPal takes the first payment the moment the donor approves, and the
        // opening sale webhook is the only thing that records it: createPlan
        // leaves the signup donation pending on purpose. With no webhook id the
        // signature has nothing to verify against and every delivery is
        // refused, so a recurring donation would be charged and banked nowhere.
        // One-time survives that, because the browser confirms its capture.
        if ($this->account->webhookId($this->siteTestMode()) === '') {
            return ['one_time'];
        }

        return ['one_time', 'recurring'];
    }

    /**
     * The mode a donation started right now would run in. frequencies() is
     * asked before any donation exists, so it cannot use the per-donation
     * override the credential-bearing calls set.
     *
     * @since 1.0.0
     */
    private function siteTestMode(): bool
    {
        $cfg = get_option('dono_gateway_config', []);

        return is_array($cfg) && ! empty($cfg['test_mode']);
    }

    /** @since 1.0.0 */
    public function paymentMethods(): array
    {
        return ['paypal', 'venmo', 'paylater', 'card'];
    }

    /** @since 1.0.0 */
    public function countries(): array
    {
        // Wildcard: defer to PayPal's own country rules.
        return ['*'];
    }

    /**
     * PayPal settles in a fixed set of currencies and, unlike Stripe, rejects
     * anything outside it outright. Listing them keeps the donor form from
     * offering PayPal for a currency the order would fail on.
     *
     * @since 1.0.0
     */
    public function currencies(): array
    {
        return [
            // HUF and TWD are PayPal currencies but PayPal rejects decimals on
            // them, and PayPalMoney takes its decimal count from
            // Currency::minorUnits, whose 2 for those two is a deliberate
            // Stripe decision that must not be changed here. Until PayPalMoney
            // carries its own zero-decimal set, offering them would only fail
            // at the boundary.
            'AUD', 'BRL', 'CAD', 'CHF', 'CZK', 'DKK', 'EUR', 'GBP', 'HKD',
            'ILS', 'JPY', 'MXN', 'MYR', 'NOK', 'NZD', 'PHP', 'PLN', 'SEK', 'SGD',
            'THB', 'USD',
        ];
    }

    /** @since 1.0.0 */
    public function canCharge(): bool
    {
        return $this->account->canCharge();
    }

    /**
     * One-time: create the Order now so the donation carries a gateway id
     * before the donor approves. Recurring: no order exists, so hand the
     * browser the plan id to open a Subscription against.
     *
     * @since 1.0.0
     */
    public function createIntent(Donation $donation): GatewayIntentResult
    {
        $this->account->useTestMode((bool) $donation->is_test);

        if (FrequencyMap::isRecurring($donation->frequency)) {
            return $this->createSubscriptionIntent($donation);
        }

        $currency = strtoupper((string) $donation->currency);
        $order = $this->api->post('/v2/checkout/orders', [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                // custom_id rides the whole lifecycle (capture, refund, webhook)
                // so an event can always be matched back to the donation.
                'custom_id'   => (string) $donation->reference,
                'description' => 'Donation ' . $donation->reference,
                'amount'      => [
                    'currency_code' => $currency,
                    'value'         => PayPalMoney::toValue((int) $donation->amount_cents, $currency),
                ],
            ]],
            'payment_source' => [
                'paypal' => [
                    'experience_context' => [
                        'shipping_preference' => 'NO_SHIPPING',
                        'user_action'         => 'PAY_NOW',
                    ],
                ],
            ],
        ], ['PayPal-Request-Id' => 'dono_order_' . $donation->reference]);

        $orderId = (string) ($order['id'] ?? '');
        if ($orderId === '') {
            throw new RuntimeException(esc_html('PayPal did not return an order id.'));
        }

        $donation->gateway_account_id = $this->account->clientIdFor((bool) $donation->is_test);

        return new GatewayIntentResult(
            intent_id: $orderId,
            metadata: [
                'paypal_mode'     => $donation->is_test ? 'sandbox' : 'live',
                'paypal_kind'     => 'order',
                'paypal_order_id' => $orderId,
            ],
        );
    }

    /**
     * Recurring needs a Plan, not an Order. The plan is keyed on amount plus
     * interval and reused across donors, so a repeat monthly amount does not
     * create a second plan on the PayPal account.
     *
     * @since 1.0.0
     */
    private function createSubscriptionIntent(Donation $donation): GatewayIntentResult
    {
        $currency = strtoupper((string) $donation->currency);
        [$unit, $count] = $this->intervalFor((string) $donation->frequency);

        $planId = $this->plans->resolvePlan(
            (bool) $donation->is_test,
            (int) $donation->amount_cents,
            $currency,
            $unit,
            $count
        );

        return new GatewayIntentResult(
            intent_id: 'pending_subscription_' . $donation->reference,
            metadata: [
                'paypal_mode'    => $donation->is_test ? 'sandbox' : 'live',
                'paypal_kind'    => 'subscription',
                'paypal_plan_id' => $planId,
            ],
        );
    }

    /**
     * Capture an approved order. Called from the public capture route once the
     * donor finishes in the PayPal popup, and safe to re-enter: PayPal reports
     * ORDER_ALREADY_CAPTURED, which we treat as success and let the stored
     * capture stand.
     *
     * @since 1.0.0
     */
    public function confirm(Donation $donation, array $payload = []): GatewayConfirmResult
    {
        if (! $donation->gateway_intent_id) {
            return new GatewayConfirmResult(success: false, error: 'No PayPal order on this donation.');
        }

        $this->account->useTestMode((bool) $donation->is_test);
        $orderId = (string) $donation->gateway_intent_id;

        try {
            $result = $this->api->post(
                '/v2/checkout/orders/' . rawurlencode($orderId) . '/capture',
                [],
                ['PayPal-Request-Id' => 'dono_capture_' . $donation->reference]
            );
        } catch (RuntimeException $e) {
            if (! $this->isAlreadyCaptured($e)) {
                return new GatewayConfirmResult(success: false, error: $e->getMessage());
            }
            // Already captured: read the order back so the txn id is recorded.
            try {
                $result = $this->api->get('/v2/checkout/orders/' . rawurlencode($orderId));
            } catch (RuntimeException $inner) {
                return new GatewayConfirmResult(success: false, error: $inner->getMessage());
            }
        }

        return $this->buildConfirmResultFromOrder($result);
    }

    /**
     * @param array<string,mixed> $order
     *
     * @since 1.0.0
     */
    private function buildConfirmResultFromOrder(array $order): GatewayConfirmResult
    {
        $status = (string) ($order['status'] ?? '');
        $capture = $order['purchase_units'][0]['payments']['captures'][0] ?? null;

        if (! is_array($capture)) {
            return new GatewayConfirmResult(
                success: false,
                error: "PayPal order is {$status} with no capture.",
            );
        }

        $captureStatus = (string) ($capture['status'] ?? '');
        if (! in_array($captureStatus, ['COMPLETED', 'PENDING'], true)) {
            return new GatewayConfirmResult(
                success: false,
                error: "PayPal capture status is {$captureStatus}.",
            );
        }

        // PayPal reports its own fee on the capture, so the net is knowable.
        $fee = $capture['seller_receivable_breakdown']['paypal_fee']['value'] ?? null;
        $feeCurrency = (string) ($capture['seller_receivable_breakdown']['paypal_fee']['currency_code'] ?? '');

        // PayPal says why it is holding the money, and the reasons mean very
        // different things: ECHECK settles itself in days, PENDING_REVIEW may
        // clear on its own, and RECEIVING_PREFERENCE_MANDATES_MANUAL_ACTION
        // never completes until somebody accepts the payment in the PayPal
        // account. Without it "processing" is a status with no next step.
        $pendingReason = (string) ($capture['status_details']['reason'] ?? '');

        return new GatewayConfirmResult(
            success: $captureStatus === 'COMPLETED',
            gateway_txn_id: (string) ($capture['id'] ?? ''),
            payment_method: 'paypal',
            fee_cents: $fee !== null ? PayPalMoney::toStoredCents((string) $fee, $feeCurrency) : null,
            error: $captureStatus === 'COMPLETED'
                ? null
                : trim('PayPal is holding this payment. ' . $pendingReason),
            metadata: [
                'paypal_order_id'      => (string) ($order['id'] ?? ''),
                'paypal_capture_id'    => (string) ($capture['id'] ?? ''),
                'payer_email'          => (string) ($order['payer']['email_address'] ?? ''),
                'paypal_pending_reason' => $pendingReason,
            ],
            // eCheck, a review hold, or a manually accepted off-currency
            // payment. PayPal has the money and will settle it later by
            // webhook, so this is not a failed donation.
            pending: $captureStatus === 'PENDING',
        );
    }

    /** @since 1.0.0 */
    public function handleWebhook(WP_REST_Request $request): WebhookOutcome
    {
        $raw = (string) $request->get_body();

        // PayPal's transmission headers, normalised: WP lowercases and
        // underscores incoming header names.
        $headers = [
            'transmission_id'   => (string) $request->get_header('paypal_transmission_id'),
            'transmission_time' => (string) $request->get_header('paypal_transmission_time'),
            'transmission_sig'  => (string) $request->get_header('paypal_transmission_sig'),
            'cert_url'          => (string) $request->get_header('paypal_cert_url'),
            'auth_algo'         => (string) $request->get_header('paypal_auth_algo'),
        ];

        $event = json_decode($raw, true);
        if (! is_array($event) || ! isset($event['event_type'], $event['id'])) {
            return new WebhookOutcome(
                signature_ok: false,
                error: 'Malformed PayPal event payload.',
                http_status: 400,
            );
        }

        // Verification is an API call, so the mode must be set first. PayPal
        // does not stamp livemode on the event: the sandbox and live webhooks
        // are separate endpoints with separate ids, so try the mode whose
        // webhook id matches, preferring live.
        $verified = false;
        foreach ([false, true] as $test) {
            if ($this->account->webhookId($test) === '') continue;
            $this->account->useTestMode($test);
            if ($this->api->verifyWebhookSignature($headers, $raw)) {
                // Which mode verified is what later stops a sandbox event
                // confirming a live donation.
                $this->verifiedIsTest = $test;
                $verified = true;
                break;
            }
        }

        if (! $verified) {
            return new WebhookOutcome(signature_ok: false, http_status: 400);
        }

        $eventId  = (string) $event['id'];
        $type     = (string) $event['event_type'];
        $resource = (array) ($event['resource'] ?? []);

        return match ($type) {
            'PAYMENT.CAPTURE.COMPLETED' => $this->handleCaptureCompleted($eventId, $type, $resource),
            'PAYMENT.CAPTURE.PENDING'   => $this->handleCapturePending($eventId, $type, $resource),
            'PAYMENT.CAPTURE.DENIED',
            'PAYMENT.CAPTURE.DECLINED'  => $this->handleCaptureDenied($eventId, $type, $resource),
            'PAYMENT.CAPTURE.REFUNDED'  => $this->handleCaptureRefunded($eventId, $type, $resource),
            'BILLING.SUBSCRIPTION.ACTIVATED' => $this->handleSubscriptionActivated($eventId, $type, $resource),
            'BILLING.SUBSCRIPTION.CANCELLED',
            'BILLING.SUBSCRIPTION.EXPIRED'   => $this->handleSubscriptionEnded($eventId, $type, $resource),
            'PAYMENT.SALE.COMPLETED'    => $this->handleRenewalPaid($eventId, $type, $resource),
            'PAYMENT.SALE.DENIED',
            'BILLING.SUBSCRIPTION.PAYMENT.FAILED' => $this->handleRenewalFailed($eventId, $type, $resource),
            'BILLING.SUBSCRIPTION.SUSPENDED'      => $this->handleSubscriptionSuspended($eventId, $type, $resource),
            default => new WebhookOutcome(
                signature_ok: true,
                external_id: $eventId,
                event_type: $type,
                handled: false,
            ),
        };
    }

    /**
     * @param array<string,mixed> $capture
     *
     * @since 1.0.0
     */
    private function handleCaptureCompleted(string $eventId, string $type, array $capture): WebhookOutcome
    {
        $donation = $this->donationForCapture($capture);
        if (! $donation) {
            return $this->unmatched($eventId, $type, 'capture');
        }

        // A PayPal-signed event proves PayPal sent it, not that it is about this
        // donation for this amount. The browser picks custom_id and the amount
        // when it creates the order, so without this guard a $0.01 capture
        // confirms a $10,000 donation.
        $currency = strtoupper((string) ($capture['amount']['currency_code'] ?? ''));
        $refusal  = WebhookPaymentGuard::refuse(
            $donation,
            $this->id(),
            $this->verifiedIsTest,
            isset($capture['amount']['value'])
                ? PayPalMoney::toStoredCents((string) $capture['amount']['value'], $currency ?: (string) $donation->currency)
                : null,
            $currency !== '' ? $currency : null,
        );
        if ($refusal !== null) {
            return $this->refused($eventId, $type, $refusal);
        }

        // Idempotent: DonationService::confirm() no-ops on an already-paid row.
        $this->donationService->confirm($donation, [
            'gateway_txn_id' => (string) ($capture['id'] ?? ''),
            'payment_method' => 'paypal',
            'metadata'       => ['paypal_capture_id' => (string) ($capture['id'] ?? '')],
        ]);

        return new WebhookOutcome(
            signature_ok: true,
            external_id: $eventId,
            event_type: $type,
            handled: true,
        );
    }

    /**
     * PayPal held the money rather than taking or refusing it.
     *
     * The donor's browser normally learns this from the capture response and
     * moves the donation itself, but a donor who closes the tab never sends
     * that request. This is the only other moment PayPal states the reason, so
     * without it such a donation sits at pending with nothing explaining it.
     *
     * @param array<string,mixed> $capture
     *
     * @since 1.0.0
     */
    private function handleCapturePending(string $eventId, string $type, array $capture): WebhookOutcome
    {
        $donation = $this->donationForCapture($capture);
        if (! $donation) {
            return $this->unmatched($eventId, $type, 'capture');
        }

        $currency = strtoupper((string) ($capture['amount']['currency_code'] ?? ''));
        $refusal  = WebhookPaymentGuard::refuse(
            $donation,
            $this->id(),
            $this->verifiedIsTest,
            isset($capture['amount']['value'])
                ? PayPalMoney::toStoredCents((string) $capture['amount']['value'], $currency ?: (string) $donation->currency)
                : null,
            $currency !== '' ? $currency : null,
        );
        if ($refusal !== null) {
            return $this->refused($eventId, $type, $refusal);
        }

        // No-ops once the donation has left pending, so a capture response that
        // already recorded the reason is not overwritten by this.
        $this->donationService->markProcessing($donation, 'paypal_capture_pending', array_filter([
            'paypal_capture_id'     => (string) ($capture['id'] ?? ''),
            'paypal_pending_reason' => (string) ($capture['status_details']['reason'] ?? ''),
        ], static fn ($v) => $v !== ''));

        return new WebhookOutcome(
            signature_ok: true,
            external_id: $eventId,
            event_type: $type,
            handled: true,
        );
    }

    /**
     * @param array<string,mixed> $capture
     *
     * @since 1.0.0
     */
    private function handleCaptureDenied(string $eventId, string $type, array $capture): WebhookOutcome
    {
        $donation = $this->donationForCapture($capture);
        if (! $donation) {
            return $this->unmatched($eventId, $type, 'capture');
        }

        // The browser picks custom_id when it creates the order, so this event
        // names a donation an attacker chose. Without the gateway and mode pair
        // check a sandbox-signed event could fail a live donation.
        if ($reason = WebhookPaymentGuard::refuseToTouch($donation, $this->id(), $this->verifiedIsTest)) {
            return $this->refused($eventId, $type, $reason);
        }

        $this->donationService->markFailed($donation, 'PayPal declined the payment.');

        return new WebhookOutcome(
            signature_ok: true,
            external_id: $eventId,
            event_type: $type,
            handled: true,
        );
    }

    /**
     * A refund issued from the PayPal dashboard. Recorded so Dono's totals
     * match PayPal without an admin re-entering it.
     *
     * @param array<string,mixed> $refund
     *
     * @since 1.0.0
     */
    private function handleCaptureRefunded(string $eventId, string $type, array $refund): WebhookOutcome
    {
        $reference = (string) ($refund['custom_id'] ?? '');
        $donation  = $reference !== '' ? $this->donations->findByReference($reference) : null;

        if (! $donation) {
            return $this->unmatched($eventId, $type, 'refund');
        }

        // custom_id is chosen by whoever created the order, so this refund
        // names a donation of the caller's choosing. Without the pair check a
        // sandbox credential could refund live money: the donation drops out of
        // every total, its receipt is voided, and the donor is emailed about a
        // refund PayPal never made.
        if ($reason = WebhookPaymentGuard::refuseToTouch($donation, $this->id(), $this->verifiedIsTest)) {
            return $this->refused($eventId, $type, $reason);
        }

        $currency = strtoupper((string) ($refund['amount']['currency_code'] ?? $donation->currency));
        $amount   = PayPalMoney::toStoredCents((string) ($refund['amount']['value'] ?? '0'), $currency);

        // The event fires when the refund is created, so its status is the
        // difference between money returned and money promised. Banked early,
        // the donation leaves every total, its receipt is voided and the donor
        // is emailed about a repayment that has not happened.
        $status = strtoupper(trim((string) ($refund['status'] ?? 'COMPLETED')));

        $this->donationService->recordExternalRefund(
            $donation,
            $amount,
            (string) ($refund['id'] ?? ''),
            'PayPal dashboard refund',
            'gateway',
            ['paypal_refund_status' => $status],
            $status === 'COMPLETED'
        );

        return new WebhookOutcome(
            signature_ok: true,
            external_id: $eventId,
            event_type: $type,
            handled: true,
        );
    }

    /**
     * @param array<string,mixed> $sub
     *
     * @since 1.0.0
     */
    private function handleSubscriptionActivated(string $eventId, string $type, array $sub): WebhookOutcome
    {
        $plan = $this->planRepo->findBySubscriptionId($this->id(), (string) ($sub['id'] ?? ''));

        // PayPal has charged by now, and the plan row is normally written by
        // the donor's browser. When that POST never landed there is nothing to
        // record the payment against and no way to cancel, so this event is the
        // second chance: the resource carries every field the browser sent.
        if (! $plan) {
            $plan = $this->recoverPlan($sub);
        }

        if ($plan === null) {
            return new WebhookOutcome(
                signature_ok: true,
                external_id: $eventId,
                event_type: $type,
                handled: false,
            );
        }

        if ($reason = WebhookPaymentGuard::refuseToTouchPlan($plan, $this->id(), $this->verifiedIsTest)) {
            return $this->refused($eventId, $type, $reason);
        }

        if ($plan->status !== 'active') {
            $now = $this->now();

            // Conditional, because a cancellation is terminal at PayPal: an
            // activation behind one is a redelivery or an out-of-order
            // delivery, never a fact about the subscription. Reopening the row
            // would count money that will never arrive toward MRR and let the
            // next cancellation win the transition a second time, emailing the
            // donor twice for one cancellation. A suspension is not terminal,
            // so past_due still resumes here.
            $result = RecurringPlan::query()
                ->where('id', (int) $plan->id)
                ->where('status', 'cancelled', '<>')
                ->update(['status' => 'active', 'updated_at' => $now]);

            if (($result->affectedRows ?? 0) > 0) {
                $plan->status     = 'active';
                $plan->updated_at = $now;
            }
        }

        return new WebhookOutcome(
            signature_ok: true,
            external_id: $eventId,
            event_type: $type,
            handled: true,
        );
    }

    /**
     * Recover a plan when only the subscription id is known, as on a sale.
     *
     * Costs one API call, and only on the path that would otherwise have
     * nothing to record the money against.
     *
     * @since 1.0.0
     */
    private function recoverPlanBySubscriptionId(string $subId): ?RecurringPlan
    {
        if ($this->planRecorder === null || $subId === '') {
            return null;
        }

        // The mode is whichever credentials signed this delivery.
        $this->account->useTestMode((bool) $this->verifiedIsTest);

        try {
            $sub = $this->api->get('/v1/billing/subscriptions/' . rawurlencode($subId));
        } catch (RuntimeException $e) {
            return null;
        }

        return $this->recoverPlan($sub + ['id' => $subId]);
    }

    /**
     * Write the plan a lost browser POST never did.
     *
     * Same recorder the donor-facing route uses, so the ownership and amount
     * checks are identical: a subscription that does not answer for a donation
     * awaiting one is refused here exactly as it would be there.
     *
     * @param array<string,mixed> $sub a PayPal subscription resource
     *
     * @since 1.0.0
     */
    private function recoverPlan(array $sub): ?RecurringPlan
    {
        if ($this->planRecorder === null) {
            return null;
        }

        // Recovery is the one plan path that writes before there is a row to
        // check, so the mode question is asked of the donation the resource
        // names instead. Asking it afterwards refuses a delivery that has
        // already created and activated the plan, which is the whole of what
        // the guard exists to prevent.
        $reference = trim((string) ($sub['custom_id'] ?? ''));
        $donation  = $reference !== '' ? $this->donations->findByReference($reference) : null;
        if ($donation instanceof Donation
            && WebhookPaymentGuard::refuseToTouch($donation, $this->id(), $this->verifiedIsTest) !== null) {
            return null;
        }

        try {
            return $this->planRecorder->record($sub);
        } catch (PayPalPlanRefused $e) {
            // Without a plan row nothing in the product can show or cancel this
            // subscription, while PayPal goes on charging for it. The log is
            // the screen someone opens when a recurring donation did not
            // behave, so that is where the refusal has to land.
            $subId = (string) ($sub['id'] ?? '');
            ErrorLog::record(
                'recurring.paypal',
                sprintf(
                    /* translators: 1: PayPal subscription id, 2: the reason it was refused */
                    __('PayPal subscription %1$s has no recurring plan here, so it cannot be cancelled from this site: %2$s', 'dono-fundraising-platform'),
                    $subId,
                    $e->getMessage()
                ),
                [
                    'subscription_id' => $subId,
                    'reference'       => trim((string) ($sub['custom_id'] ?? '')),
                    'error_code'      => $e->errorCode,
                ]
            );

            return null;
        }
    }

    /**
     * When the next charge is due, from the plan's own interval.
     *
     * Stripe reads this off the invoice's period end; PayPal's sale event does
     * not carry it, and fetching the subscription would cost an API round trip
     * on every renewal.
     *
     * @since 1.0.0
     */
    private function nextPaymentAfter(RecurringPlan $plan): ?string
    {
        try {
            $next = FrequencyMap::nextRenewalAfter(
                (int) $this->clock->now()->format('U'),
                (string) $plan->interval_unit,
                max(1, (int) $plan->interval_count)
            );
        } catch (RuntimeException $e) {
            return null;
        }

        return gmdate('Y-m-d H:i:s', $next);
    }

    /** @since 1.0.0 */
    private function handleSubscriptionEnded(string $eventId, string $type, array $sub): WebhookOutcome
    {
        $plan = $this->planRepo->findBySubscriptionId($this->id(), (string) ($sub['id'] ?? ''));
        if (! $plan) {
            return $this->unmatched($eventId, $type, 'subscription');
        }

        // A test-mode secret closing a live plan drops it out of MRR, emails
        // the donor that their donation has ended, and leaves PayPal billing.
        if ($refusal = WebhookPaymentGuard::refuseToTouchPlan($plan, $this->id(), $this->verifiedIsTest)) {
            return $this->refused($eventId, $type, $refusal);
        }

        $reason = $type === 'BILLING.SUBSCRIPTION.EXPIRED'
            ? 'Subscription expired at PayPal'
            : 'Cancelled at PayPal';

        // markCancelled is idempotent and owns the status/timestamp writes.
        $won = $this->planRepo->markCancelled($plan, $this->now(), $reason);

        // Gated on winning the transition, as Stripe's handler is: a cancel
        // that started in the portal already recorded the event before telling
        // PayPal, so only a PayPal-initiated end emits here and the donor gets
        // exactly one email. Without this a subscription ended at PayPal (by
        // the donor there, or by dunning) left no recurring.cancelled event and
        // sent the donor nothing at all.
        if ($won) {
            $this->donationService->recordRecurringCancellation($plan, $reason);
        }

        return new WebhookOutcome(
            signature_ok: true,
            external_id: $eventId,
            event_type: $type,
            handled: true,
        );
    }

    /**
     * A renewal payment on an existing subscription. The first payment is
     * already recorded by the checkout flow, so a sale whose amount matches an
     * existing donation for this billing period must not double-count.
     *
     * @param array<string,mixed> $sale
     *
     * @since 1.0.0
     */
    /**
     * A renewal PayPal could not collect.
     *
     * Without this a donor whose card dies is invisible: PayPal retries on its
     * own schedule and gives up, while the plan still reads active, still
     * counts toward MRR, and nobody is emailed. Stripe has recorded these since
     * the beginning; PayPal simply had no route for the events.
     *
     * @param array<string,mixed> $resource
     *
     * @since 1.0.0
     */
    private function handleRenewalFailed(string $eventId, string $type, array $resource): WebhookOutcome
    {
        // PAYMENT.SALE.DENIED names the subscription on billing_agreement_id;
        // BILLING.SUBSCRIPTION.PAYMENT.FAILED is the subscription itself.
        $subId = (string) ($resource['billing_agreement_id'] ?? $resource['id'] ?? '');
        $plan  = $subId !== '' ? $this->planRepo->findBySubscriptionId($this->id(), $subId) : null;
        if (! $plan) {
            return $this->unmatched($eventId, $type, 'subscription');
        }

        if ($reason = WebhookPaymentGuard::refuseToTouchPlan($plan, $this->id(), $this->verifiedIsTest)) {
            return $this->refused($eventId, $type, $reason);
        }

        // Keyed on the delivery, because PayPal redelivers for three days
        // whenever it did not get a 2xx, and a decline counted twice reads on
        // the plan as two attempts the donor's card never made.
        if ($this->planRepo->recordFailedRenewal($plan, $this->now(), $eventId)) {
            // PayPal does not give a decline reason on these events, and
            // inventing one reads to the donor as though we know something we
            // do not.
            $this->donationService->recordRecurringFailure($plan, null);
        }

        return new WebhookOutcome(
            signature_ok: true,
            external_id: $eventId,
            event_type: $type,
            handled: true,
        );
    }

    /**
     * PayPal suspended the subscription itself, which is where its dunning ends.
     *
     * Not a cancellation: PayPal can suspend and the donor can still fix their
     * card, so the plan is marked past_due rather than closed. Left unhandled
     * this was the quietest of the three, because nothing about the row changed
     * while the money had already stopped.
     *
     * @param array<string,mixed> $sub
     *
     * @since 1.0.0
     */
    private function handleSubscriptionSuspended(string $eventId, string $type, array $sub): WebhookOutcome
    {
        $plan = $this->planRepo->findBySubscriptionId($this->id(), (string) ($sub['id'] ?? ''));
        if (! $plan) {
            return $this->unmatched($eventId, $type, 'subscription');
        }

        if ($reason = WebhookPaymentGuard::refuseToTouchPlan($plan, $this->id(), $this->verifiedIsTest)) {
            return $this->refused($eventId, $type, $reason);
        }

        RecurringPlan::query()
            ->where('id', (int) $plan->id)
            ->where('status', 'cancelled', '<>')
            ->update(['status' => 'past_due', 'updated_at' => $this->now()]);

        return new WebhookOutcome(
            signature_ok: true,
            external_id: $eventId,
            event_type: $type,
            handled: true,
        );
    }

    private function handleRenewalPaid(string $eventId, string $type, array $sale): WebhookOutcome
    {
        $subId = (string) ($sale['billing_agreement_id'] ?? '');
        if ($subId === '') {
            return new WebhookOutcome(
                signature_ok: true,
                external_id: $eventId,
                event_type: $type,
                handled: false,
            );
        }

        $plan = $this->planRepo->findBySubscriptionId($this->id(), $subId);

        // Ask PayPal what this subscription is and write the plan ourselves.
        // Waiting for the browser is not enough: when that POST never arrives,
        // redelivery only buys time until PayPal gives up, and the subscription
        // then bills forever with nothing on file and no cancel path, because
        // every cancel reads gateway_subscription_id off a row that was never
        // created.
        if (! $plan) {
            $plan = $this->recoverPlanBySubscriptionId($subId);
        }

        if (! $plan) {
            // Not unmatched(): that returns 200 on the reasoning that a valid
            // event which is not ours should not be retried for days. A sale
            // carrying a billing_agreement_id arrived on our own account's
            // webhook, so it is ours - the plan row simply does not exist yet.
            //
            // PayPal bills the moment the donor approves, and the plan is
            // written by the browser's POST to /gateways/paypal/subscription.
            // A 200 here would tell PayPal the opening payment had been
            // accepted, and it would never redeliver, so that first payment
            // would never be booked.
            //
            // 503 so PayPal redelivers while the browser call catches up. Its
            // retry schedule ends on its own, so this cannot retry forever.
            return new WebhookOutcome(
                signature_ok: true,
                external_id: $eventId,
                event_type: $type,
                handled: false,
                error: "No local plan for subscription {$subId} yet; asking PayPal to redeliver",
                http_status: 503,
            );
        }

        $saleId   = (string) ($sale['id'] ?? '');
        $currency = strtoupper((string) ($sale['amount']['currency'] ?? $plan->currency));
        $amount   = PayPalMoney::toStoredCents((string) ($sale['amount']['total'] ?? '0'), $currency);

        if ($saleId === '' || $amount <= 0) {
            return new WebhookOutcome(
                signature_ok: true,
                external_id: $eventId,
                event_type: $type,
                handled: false,
                error: 'PayPal sale missing id or amount',
            );
        }

        $confirmResult = [
            'gateway_txn_id' => $saleId,
            'payment_method' => 'paypal',
            'metadata'       => ['paypal_subscription_id' => $subId],
        ];

        // PayPal takes the first payment the moment the donor approves, so the
        // opening PAYMENT.SALE.COMPLETED belongs to the signup donation that is
        // already on file. Treating it as a renewal would bank the same money
        // twice, so confirm the pending signup row instead when one exists.
        $signup = Donation::query()
            ->where('recurring_plan_id', (int) $plan->id)
            ->where('status', 'pending')
            ->get();

        if ($signup instanceof Donation) {
            // The same reasoning as handleCaptureCompleted, by a different door:
            // a PayPal-signed sale proves PayPal sent it, not that it settles
            // this row for this amount. The browser picks the plan the
            // subscription bills on, so an unguarded confirm banks a signup at
            // its full amount for whatever the sale actually collected.
            $refusal = WebhookPaymentGuard::refuse(
                $signup,
                $this->id(),
                $this->verifiedIsTest,
                $amount,
                $currency,
            );
            if ($refusal !== null) {
                return $this->refused($eventId, $type, $refusal);
            }

            // Claiming the sale id on the still-pending row is the single-winner
            // transition for the opening payment, the same shape the renewal
            // branch below gets from $renewal['created']. recordPayment
            // increments unconditionally, so without it two deliveries of one
            // sale both bump payments_count - and PayPal redelivers by design,
            // which the 503 early-sale path makes more likely.
            //
            // A targeted UPDATE rather than save(): a whole-row write from the
            // loser's stale copy would push status back to pending over the
            // winner's paid row. <=> is null-safe, so a row with no intent id
            // yet still claims.
            // whereRaw first: it emits no AND connector, so anywhere else in
            // the chain it fuses onto the preceding condition.
            $won = Donation::query()
                ->whereRaw('NOT (gateway_intent_id <=> %s)', $saleId)
                ->where('id', (int) $signup->id)
                ->where('status', 'pending')
                ->update([
                    'gateway_intent_id' => $saleId,
                    'updated_at'        => $this->now(),
                ])
                ->affectedRows > 0;

            // confirm() stays unconditional: it is idempotent, and a loser that
            // still finds the row pending heals a delivery that died between
            // the claim and the confirm rather than stranding it.
            $signup->gateway_intent_id = $saleId;
            $this->donationService->confirm($signup, $confirmResult);

            if ($won) {
                $fresh = $this->planRepo->findBySubscriptionId($this->id(), $subId);
                if ($fresh) {
                    $this->planRepo->recordPayment($fresh, $amount, $this->now(), $this->nextPaymentAfter($fresh));
                }
            }

            return new WebhookOutcome(
                signature_ok: true,
                external_id: $eventId,
                event_type: $type,
                handled: true,
            );
        }

        $renewal = $this->donationService->createRenewal(
            $plan,
            $amount,
            $currency,
            $this->id(),
            $saleId,
            $confirmResult,
        );

        // Only a genuinely new renewal bumps the counters. PayPal redelivers
        // events, and recordPayment increments unconditionally, so a replay
        // would otherwise permanently inflate payments_count.
        if ($renewal['created']) {
            $fresh = $this->planRepo->findBySubscriptionId($this->id(), $subId);
            if ($fresh) {
                $this->planRepo->recordPayment($fresh, $amount, $this->now(), $this->nextPaymentAfter($fresh));
            }
        }

        return new WebhookOutcome(
            signature_ok: true,
            external_id: $eventId,
            event_type: $type,
            handled: true,
        );
    }

    /**
     * Clock returns a DateTimeImmutable; the models store MySQL datetimes.
     *
     * @since 1.0.0
     */
    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }

    /**
     * @param array<string,mixed> $capture
     *
     * @since 1.0.0
     */
    private function donationForCapture(array $capture): ?Donation
    {
        $reference = (string) ($capture['custom_id'] ?? '');
        if ($reference !== '') {
            $found = $this->donations->findByReference($reference);
            if ($found) return $found;
        }

        // Fall back to the order id carried on the capture's up link.
        $orderId = (string) ($capture['supplementary_data']['related_ids']['order_id'] ?? '');
        return $orderId !== ''
            ? $this->donations->findByGatewayIntent($this->id(), $orderId)
            : null;
    }

    /**
     * A genuinely PayPal-signed event that must not touch this donation. 200,
     * not 5xx: retrying will not make it acceptable.
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

    /** @since 1.0.0 */
    private function unmatched(string $eventId, string $type, string $what): WebhookOutcome
    {
        // 200, not 5xx: the event is valid, it just is not ours. A 5xx would
        // make PayPal retry it for days.
        return new WebhookOutcome(
            signature_ok: true,
            external_id: $eventId,
            event_type: $type,
            handled: false,
            error: "No donation matched this PayPal {$what}",
            http_status: 200,
        );
    }

    /** @since 1.0.0 */
    public function refund(Donation $donation, int $amountCents, ?string $reason = null): RefundResult
    {
        $captureId = (string) ($donation->gateway_txn_id ?? '');
        if ($captureId === '') {
            return RefundResult::failure('This donation has no PayPal capture to refund.');
        }

        $this->account->useTestMode((bool) $donation->is_test);
        $currency = strtoupper((string) $donation->currency);

        try {
            $refund = $this->api->post(
                '/v2/payments/captures/' . rawurlencode($captureId) . '/refund',
                // note_to_payer is omitted rather than sent as null: PayPal
                // type-checks its optional fields and refuses the null.
                array_filter([
                    'amount' => [
                        'currency_code' => $currency,
                        'value'         => PayPalMoney::toValue($amountCents, $currency),
                    ],
                    'note_to_payer' => $reason !== null ? substr($reason, 0, 255) : null,
                ], static fn ($v) => $v !== null),
                [
                    // Stable per attempt so a timed-out refund that already
                    // processed returns the original instead of issuing a second.
                    'PayPal-Request-Id' => 'dono_refund_' . $donation->id . '_'
                        . (int) $donation->refunded_cents . '_' . $amountCents,
                ]
            );
        } catch (RuntimeException $e) {
            return RefundResult::failure($e->getMessage());
        }

        $status = (string) ($refund['status'] ?? '');
        if (! in_array($status, ['COMPLETED', 'PENDING'], true)) {
            return RefundResult::failure("PayPal refund status is {$status}.");
        }

        $echoed = (string) ($refund['amount']['value'] ?? '');
        $echoedCurrency = strtoupper((string) ($refund['amount']['currency_code'] ?? $currency));

        return new RefundResult(
            success: true,
            gateway_refund_id: (string) ($refund['id'] ?? ''),
            amount_cents: $echoed !== '' ? PayPalMoney::toStoredCents($echoed, $echoedCurrency) : $amountCents,
            metadata: ['paypal_refund_status' => $status],
            // PENDING is PayPal taking the instruction, not returning the
            // money: an eCheck refund sits there and can still fail, and until
            // it clears the org holds the funds.
            settled: $status === 'COMPLETED',
        );
    }

    /** @since 1.0.0 */
    public function cancelSubscription(RecurringPlan $plan, ?string $reason = null): void
    {
        $this->account->useTestMode((bool) $plan->is_test);
        try {
            $this->api->post(
                '/v1/billing/subscriptions/' . rawurlencode($plan->gateway_subscription_id) . '/cancel',
                ['reason' => $reason !== null ? substr($reason, 0, 127) : 'Cancelled by donor']
            );
        } catch (RuntimeException $e) {
            // Idempotent per the interface: a subscription already finished is
            // nothing left to cancel. EXPIRED counts, it is equally terminal.
            if (! $this->isAlreadyInThatState($e, $plan, ['CANCELLED', 'EXPIRED'])) {
                throw $e;
            }
        }
    }

    /** @since 1.0.0 */
    public function pauseSubscription(RecurringPlan $plan, ?string $resumesAt = null): void
    {
        $this->account->useTestMode((bool) $plan->is_test);
        try {
            $this->api->post(
                '/v1/billing/subscriptions/' . rawurlencode($plan->gateway_subscription_id) . '/suspend',
                ['reason' => 'Paused by donor']
            );
        } catch (RuntimeException $e) {
            if (! $this->isAlreadyInThatState($e, $plan, ['SUSPENDED'])) {
                throw $e;
            }
        }
    }

    /** @since 1.0.0 */
    public function resumeSubscription(RecurringPlan $plan): void
    {
        $this->account->useTestMode((bool) $plan->is_test);
        try {
            $this->api->post(
                '/v1/billing/subscriptions/' . rawurlencode($plan->gateway_subscription_id) . '/activate',
                ['reason' => 'Resumed by donor']
            );
        } catch (RuntimeException $e) {
            if (! $this->isAlreadyInThatState($e, $plan, ['ACTIVE'])) {
                throw $e;
            }
        }
    }

    /**
     * PayPal will not let anyone else collect a funding source for one of its
     * subscriptions, so there is no card field to render. Revising the
     * subscription onto its own current plan produces an approval link, which
     * is the page where the subscriber can change how they pay.
     *
     * @since 1.0.0
     */
    public function startPaymentMethodUpdate(RecurringPlan $plan): PaymentMethodUpdate
    {
        $this->account->useTestMode((bool) $plan->is_test);

        $subId = (string) $plan->gateway_subscription_id;
        if ($subId === '') {
            throw new RuntimeException(esc_html__('This donation has no PayPal subscription.', 'dono-fundraising-platform'));
        }

        // Same plan id, so nothing about the schedule or the amount changes:
        // the revise exists purely to get an approval link.
        $planId = $this->plans->resolvePlan(
            (bool) $plan->is_test,
            (int) $plan->amount_cents,
            strtoupper((string) $plan->currency),
            (string) $plan->interval_unit,
            (int) $plan->interval_count
        );

        $revised = $this->api->post(
            '/v1/billing/subscriptions/' . rawurlencode($subId) . '/revise',
            ['plan_id' => $planId]
        );

        foreach ((array) ($revised['links'] ?? []) as $link) {
            if (strtolower((string) ($link['rel'] ?? '')) === 'approve') {
                $href = (string) ($link['href'] ?? '');
                if ($href !== '') {
                    return PaymentMethodUpdate::redirect($href);
                }
            }
        }

        throw new RuntimeException(esc_html__('PayPal did not return a link for changing the payment method.', 'dono-fundraising-platform'));
    }

    /**
     * Nothing to do: the donor finishes on PayPal, and the subscription's own
     * webhook is what tells us the funding source moved. Treating a local call
     * as the completion would claim a change PayPal has not made.
     *
     * @since 1.0.0
     */
    public function completePaymentMethodUpdate(RecurringPlan $plan, string $token): void
    {
    }

    /**
     * PayPal has no "change the price on this subscription" call: the amount
     * lives on the plan, so changing it means revising the subscription onto a
     * plan at the new amount (provisioned on demand and reused).
     *
     * @since 1.0.0
     */
    public function updateSubscriptionAmount(RecurringPlan $plan, int $amountCents): void
    {
        $this->account->useTestMode((bool) $plan->is_test);

        $planId = $this->plans->resolvePlan(
            (bool) $plan->is_test,
            $amountCents,
            strtoupper((string) $plan->currency),
            (string) $plan->interval_unit,
            (int) $plan->interval_count
        );

        $revised = $this->api->post(
            '/v1/billing/subscriptions/' . rawurlencode($plan->gateway_subscription_id) . '/revise',
            ['plan_id' => $planId]
        );

        // PayPal does not apply a revise until the subscriber approves it, and
        // says so by handing back an approve link. Ignoring that link would
        // write a new amount to the plan while PayPal carries on charging the
        // old one, and nothing reconciles the two.
        $approveUrl = '';
        foreach ((array) ($revised['links'] ?? []) as $link) {
            if (strtolower((string) ($link['rel'] ?? '')) === 'approve') {
                $approveUrl = (string) ($link['href'] ?? '');
                break;
            }
        }

        if ($approveUrl !== '') {
            throw new SubscriptionChangeNeedsApproval(
                esc_html('PayPal needs the donor to approve this change before it takes effect.'),
                // Sanitised here rather than above, because a link this rejects
                // must still stop the change: emptying $approveUrl before the
                // test would let the revise be written off as applied.
                esc_url_raw($approveUrl)
            );
        }
    }

    /**
     * @return array{0:string,1:int} interval unit + count for a Dono frequency.
     *
     * Delegates to FrequencyMap rather than repeating the table: a local copy
     * with a monthly default silently bills biweekly donors once a month.
     *
     * @since 1.0.0
     */
    private function intervalFor(string $frequency): array
    {
        return FrequencyMap::toStripe($frequency);
    }

    /**
     * A second capture on an order PayPal already took. Safe to re-enter: the
     * caller re-reads the order and confirms the donation from it.
     *
     * Matched on the issue code, not the message: PayPal always sends a
     * `description` next to the code and the message builder prefers it, so the
     * code never reaches the formatted message.
     *
     * @since 1.0.0
     */
    private function isAlreadyCaptured(RuntimeException $e): bool
    {
        return $e instanceof PayPalApiException && $e->hasIssue('ORDER_ALREADY_CAPTURED');
    }

    /**
     * Pause/resume/cancel are safe to re-enter, so PayPal telling us the
     * subscription is already in the state we asked for is success, not failure.
     *
     * Matched on the issue codes rather than on the message: message text also
     * carries the description of unrelated errors.
     *
     * @since 1.0.0
     */
    /**
     * Whether the subscription is already where the caller was trying to put it.
     *
     * PayPal answers every wrong-state transition with the same issue code, so
     * the code alone cannot tell "already cancelled, nothing to do" from
     * "cancelled, and activating it will never work". Matching on the code
     * alone reported a resume of a dead subscription as success. The state has
     * to be read, which costs one extra call on the error path only.
     *
     * @param list<string> $targetStatuses states in which the operation had
     *                                     nothing left to do
     *
     * @since 1.0.0
     */
    private function isAlreadyInThatState(
        RuntimeException $e,
        RecurringPlan $plan,
        array $targetStatuses
    ): bool {
        if (! $e instanceof PayPalApiException
            || ! $e->hasIssue('SUBSCRIPTION_STATUS_INVALID', 'INVALID_STATE', 'INVALID_RESOURCE_STATE')) {
            return false;
        }

        try {
            $sub = $this->api->get(
                '/v1/billing/subscriptions/' . rawurlencode((string) $plan->gateway_subscription_id)
            );
        } catch (RuntimeException) {
            // Cannot confirm, so do not swallow: reporting success for a change
            // that may not have happened is the failure being fixed.
            return false;
        }

        return in_array(strtoupper((string) ($sub['status'] ?? '')), $targetStatuses, true);
    }
}
