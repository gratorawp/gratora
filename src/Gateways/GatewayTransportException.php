<?php

declare(strict_types=1);

namespace Dono\Gateways;

use RuntimeException;

/**
 * The request never reached the gateway.
 *
 * A refusal and an unreachable host arrive at the same catch, and telling an org
 * its keys were rejected when the site could not resolve the API sends it to
 * rotate credentials that were never wrong. It extends RuntimeException so
 * callers that only care that the call failed keep working unchanged.
 *
 * @since 1.0.0
 */
final class GatewayTransportException extends RuntimeException
{
}
