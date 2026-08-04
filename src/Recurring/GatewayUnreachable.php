<?php

declare(strict_types=1);

namespace Dono\Recurring;

use RuntimeException;

/**
 * The plan lives at a payment processor this site cannot currently reach, so
 * its subscription cannot be stopped from here.
 *
 * Separate from a gateway API failure: the credentials are gone entirely, so
 * there is nothing to retry against until they are restored.
 *
 * @version 1.0.0
 */
final class GatewayUnreachable extends RuntimeException
{
}
