<?php

declare(strict_types=1);

namespace Dono\Gateways;

/**
 * Result of `PaymentGateway::createIntent()`. `intent_id` is stored on
 * donation.gateway_intent_id for webhook matching.
 *
 * @version 1.0.0
 */
final class GatewayIntentResult
{
    public function __construct(
        public readonly string $intent_id,
        public readonly ?string $redirect_url = null,
        public readonly ?string $client_secret = null,
        public readonly bool $requires_action = false,
        public readonly ?array $metadata = null,
        /**
         * True when the gateway has nothing more to do: no redirect, no
         * client-side card auth, no off-site capture step. The donations
         * REST controller can immediately call gateway->confirm() and
         * mark the donation paid in the same request. Used by the sandbox
         * gateway so test donations settle without an admin/webhook step.
         */
        public readonly bool $auto_confirm = false,
    ) {
    }
}
