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
 * Anything beyond that lifecycle is a separate interface in this namespace that
 * a gateway opts into by implementing it, SettlesOutOfBand among them, so a
 * gateway registered from outside core can answer the same questions the core
 * ones do.
 *
 * @since 1.0.0
 */
interface PaymentGateway
{
    /** @since 1.0.0 */
    public function id(): string;
    /** @since 1.0.0 */
    public function label(): string;

    /**
     * Short donor-facing description shown in the gateway selector panel.
     * A form's payment-gateways block can override this per gateway; this is
     * the fallback. Empty string means "no description".
     *
     * @since 1.0.0
     */
    public function description(): string;

    /**
     * @return array<string> subset of ['one_time','recurring']
     *
     * @since 1.0.0
     */
    public function frequencies(): array;

    /**
     * @return array<string> e.g. ['card','sepa_debit','ideal','bancontact','apple_pay','google_pay']
     *
     * @since 1.0.0
     */
    public function paymentMethods(): array;

    /**
     * @return array<string> ['*'] or ISO 3166-1 alpha-2 list
     *
     * @since 1.0.0
     */
    public function countries(): array;

    /**
     * @return array<string> ISO 4217 currency codes or ['*']
     *
     * @since 1.0.0
     */
    public function currencies(): array;

    /**
     * Whether the gateway can currently accept a charge. Most gateways are
     * always ready; Stripe is false until the org's Stripe account has charges
     * enabled, so the donor form must not offer it before then (otherwise the
     * donor only fails at createIntent with a hard error).
     *
     * @since 1.0.0
     */
    public function canCharge(): bool;

    /** @since 1.0.0 */
    public function createIntent(Donation $donation): GatewayIntentResult;

    /**
     * Synchronous confirmation. Most gateways confirm via webhook instead;
     * Offline implements this for the admin "mark as paid" flow.
     *
     * @since 1.0.0
     */
    public function confirm(Donation $donation, array $payload = []): GatewayConfirmResult;

    /**
     * Handle an incoming webhook: verify signature, dedup on the gateway event
     * id, dispatch the matching action. Must be idempotent (gateways retry).
     *
     * @since 1.0.0
     */
    public function handleWebhook(WP_REST_Request $request): WebhookOutcome;

    /**
     * `$amountCents` is the refund amount in the donation's currency.
     *
     * @since 1.0.0
     */
    public function refund(Donation $donation, int $amountCents, ?string $reason = null): RefundResult;
}
