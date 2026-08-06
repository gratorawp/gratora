<?php

declare(strict_types=1);

namespace Dono\Gateways;

use RuntimeException;

/**
 * There is nothing outstanding to collect on this plan.
 *
 * Not a failure of the attempt: the invoice is already settled, or the gateway
 * has none open, so retrying cannot help and the admin needs telling that
 * rather than an error suggesting they try again.
 *
 * @version 1.0.0
 */
final class PaymentRetryUnavailable extends RuntimeException
{
}
