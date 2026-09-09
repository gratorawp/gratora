<?php

declare(strict_types=1);

namespace Gratora\Gateways;

/**
 * Marks gateways with no checkout to abandon. Their pending transfers must remain visible and
 * cannot be retry parents; see AntiSpamGuard::claimRetry.
 *
 * @since 1.0.0
 */
interface SettlesOutOfBand
{
}
