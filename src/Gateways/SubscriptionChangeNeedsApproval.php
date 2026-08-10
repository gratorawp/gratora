<?php

declare(strict_types=1);

namespace Dono\Gateways;

use RuntimeException;

/**
 * The processor accepted the change but will not apply it until the donor
 * approves it on the processor's own site.
 *
 * Distinct from a gateway failure because retrying does not help and nothing is
 * wrong: the change is simply not in effect yet. A caller that treats this as a
 * success writes an amount the donor is not being charged, and the plan and the
 * charge then disagree forever, with the donor's portal showing the number they
 * asked for and their card showing the old one.
 *
 * @since 1.0.0
 */
final class SubscriptionChangeNeedsApproval extends RuntimeException
{
    /** @since 1.0.0 */
    public function __construct(
        string $message,
        public readonly string $approveUrl = '',
    ) {
        parent::__construct($message);
    }
}
