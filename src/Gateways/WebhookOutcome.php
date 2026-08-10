<?php

declare(strict_types=1);

namespace Dono\Gateways;

/**
 * Result of `PaymentGateway::handleWebhook()`. The router uses it to set the
 * HTTP status, write the `dono_webhooks_log` row (external_id for dedup), and
 * record whether the action ran. `handled=true` also covers a recognized
 * duplicate that was intentionally no-op'd.
 *
 * @since 1.0.0
 */
final class WebhookOutcome
{
    /** @since 1.0.0 */
    public function __construct(
        public readonly bool $signature_ok,
        public readonly ?string $external_id = null,
        public readonly ?string $event_type = null,
        public readonly bool $handled = false,
        public readonly ?string $error = null,
        public readonly int $http_status = 200,
    ) {
    }

    /** @since 1.0.0 */
    public static function badSignature(string $error = 'Invalid signature.'): self
    {
        return new self(
            signature_ok: false,
            error:        $error,
            http_status:  401,
        );
    }

    /** @since 1.0.0 */
    public static function notSupported(string $gateway): self
    {
        return new self(
            signature_ok: false,
            error:        "{$gateway} does not accept webhooks.",
            http_status:  405,
        );
    }
}
