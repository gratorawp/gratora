<?php

declare(strict_types=1);

namespace Dono\Gateways;

use Dono\Donations\Donation;
use Dono\Donations\Refund;
use WP_REST_Request;

/**
 * Payment gateway abstraction. Concrete gateways register themselves at boot
 * via the `dono.gateways.register` hook.
 *
 * Lifecycle: createIntent, then either handleWebhook (typical) or confirm
 * (synchronous, Offline only); refund and the subscription methods as needed.
 *
 * @version 1.0.0
 */
interface PaymentGateway
{
    /** Gateway identifier (slug). */
    public function id(): string;
    /** Human-readable gateway name. */
    public function label(): string;

    /**
     * Short donor-facing description shown in the gateway selector panel.
     * A form's payment-gateways block can override this per gateway; this is
     * the fallback. Empty string means "no description".
     */
    public function description(): string;

    /**
     * Frequencies this gateway handles.
     * @return array<string> subset of ['one_time','recurring']
     */
    public function frequencies(): array;

    /** @return array<string> e.g. ['card','sepa_debit','ideal','bancontact','apple_pay','google_pay'] */
    public function paymentMethods(): array;

    /** @return array<string> ['*'] or ISO 3166-1 alpha-2 list */
    public function countries(): array;

    /** @return array<string> ISO 4217 currency codes or ['*'] */
    public function currencies(): array;

    /**
     * Whether the gateway can currently accept a charge. Most gateways are
     * always ready; Stripe is false until the org's Stripe account has charges
     * enabled, so the donor form must not offer it before then (otherwise the
     * donor only fails at createIntent with a hard error).
     */
    public function canCharge(): bool;

    public function createIntent(Donation $donation): GatewayIntentResult;

    /**
     * Synchronous confirmation. Most gateways confirm via webhook instead;
     * Offline implements this for the admin "mark as paid" flow.
     */
    public function confirm(Donation $donation, array $payload = []): GatewayConfirmResult;

    /**
     * Handle an incoming webhook: verify signature, dedup on the gateway event
     * id, dispatch the matching action. Must be idempotent (gateways retry).
     */
    public function handleWebhook(WP_REST_Request $request): WebhookOutcome;

    /** `$amountCents` is the refund amount in the donation's currency. */
    public function refund(Donation $donation, int $amountCents, ?string $reason = null): RefundResult;
}
