<?php

declare(strict_types=1);

namespace Dono\Gateways;

/**
 * Result of `PaymentGateway::createIntent()`. `intent_id` is stored on
 * donation.gateway_intent_id for webhook matching.
 *
 * @since 1.0.0
 */
final class GatewayIntentResult
{
    /** @since 1.0.0 */
    public function __construct(
        public readonly string $intent_id,
        public readonly ?string $redirect_url = null,
        public readonly ?string $client_secret = null,
        public readonly bool $requires_action = false,
        public readonly ?array $metadata = null,
        /**
         * True when the gateway has nothing more to do (no redirect, client auth,
         * or off-site capture): the REST controller may confirm() and mark paid in
         * the same request. Used by the sandbox gateway.
         */
        public readonly bool $auto_confirm = false,
    ) {
    }
}
