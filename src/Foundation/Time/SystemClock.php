<?php

declare(strict_types=1);

namespace GiveFlow\Foundation\Time;

use DateTimeImmutable;

/**
 * Production clock backed by the system wall time.
 *
 * @since 1.0.0
 */
final class SystemClock implements Clock
{
    /** @since 1.0.0 */
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }
}
