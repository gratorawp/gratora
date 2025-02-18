<?php

declare(strict_types=1);

namespace Dono\Foundation\Time;

use DateTimeImmutable;

/**
 * Test clock that always returns a fixed instant.
 *
 * @version 1.0.0
 */
final class FrozenClock implements Clock
{
    public function __construct(private DateTimeImmutable $frozen)
    {
    }

    public function now(): DateTimeImmutable
    {
        return $this->frozen;
    }
}
