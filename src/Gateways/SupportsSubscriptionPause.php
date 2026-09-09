<?php

declare(strict_types=1);

namespace Gratora\Gateways;

/**
 * Marks gateways that support pause and resume. SubscriptionAware alone does not guarantee this
 * capability; its methods may refuse the operation.
 *
 * @since 1.0.0
 */
interface SupportsSubscriptionPause
{
}
