<?php

declare(strict_types=1);

namespace Dono\Currency;

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
            __('The base currency stays %1$s: %2$d donations are already recorded against it, and their stored totals would be reread as the new currency. Test-mode donations do not count - clear live donations first, or keep reporting in %1$s.', 'dono-fundraising-platform'),
            $current,
            $donations
        ));
    }
}
