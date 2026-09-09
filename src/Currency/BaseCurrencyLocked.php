<?php

declare(strict_types=1);

namespace Gratora\Currency;

use RuntimeException;

/**
 * Thrown when a write would change the org's base currency after live money
 * has been recorded against it.
 *
 * @since 1.0.0
 */
final class BaseCurrencyLocked extends RuntimeException
{
    /** @since 1.0.0 */
    public function __construct(
        public readonly string $current,
        public readonly string $attempted,
        public readonly int $donations
    ) {
        parent::__construct(sprintf(
            /* translators: 1: current base currency, 2: number of donations. */
            __('The base currency stays %1$s: %2$d donations have taken money against it, and their stored totals would be reread as the new currency. Test-mode donations do not count, and neither do checkouts that were abandoned or refused.', 'gratora'),
            $current,
            $donations
        ));
    }
}
