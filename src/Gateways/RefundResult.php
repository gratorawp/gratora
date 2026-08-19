<?php

declare(strict_types=1);

namespace Dono\Gateways;

/**
 * Result of `PaymentGateway::refund()`. `success = true` means the gateway
 * accepted the refund.
 *
 * @since 1.0.0
 */
final class RefundResult
{
    /** @since 1.0.0 */
    /**
     * @param bool $settled whether the gateway has actually returned the money,
     *   as opposed to accepting the instruction to. A refund a gateway has
     *   taken but not completed can still fail, and until it does the org holds
     *   the funds: banking it early takes the donation off the books, voids the
     *   receipt and tells the donor they have been repaid when they have not.
     *   True by default, so a gateway that cannot tell keeps its own behaviour.
     */
    public function __construct(
        public readonly bool $success,
        public readonly ?string $gateway_refund_id = null,
        public readonly ?int $amount_cents = null,
        public readonly ?string $error = null,
        public readonly ?array $metadata = null,
        public readonly bool $settled = true,
    ) {
    }

    /** @since 1.0.0 */
    public static function failure(string $error): self
    {
        return new self(success: false, error: $error);
    }
}
