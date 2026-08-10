<?php

declare(strict_types=1);

namespace Dono\Gateways\PayPal;

use RuntimeException;

/**
 * A PayPal subscription that does not answer for the donation it names, or
 * names one already settled. Carries the code and status the donor-facing
 * route reports; the webhook path only records the reason.
 *
 * @since 1.0.0
 */
final class PayPalPlanRefused extends RuntimeException
{
    /** @since 1.0.0 */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 400,
    ) {
        parent::__construct($message);
    }
}
