<?php

declare(strict_types=1);

namespace Gratora\Foundation\Time;

use DateTimeImmutable;

/** @since 1.0.0 */
final class FrozenClock implements Clock
{
    /** @since 1.0.0 */
    public function __construct(private DateTimeImmutable $frozen)
    {
    }

    /** @since 1.0.0 */
    public function now(): DateTimeImmutable
    {
        return $this->frozen;
    }
}
