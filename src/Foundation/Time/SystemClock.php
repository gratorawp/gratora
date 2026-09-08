<?php

declare(strict_types=1);

namespace FundKit\Foundation\Time;

use DateTimeImmutable;

/** @since 1.0.0 */
final class SystemClock implements Clock
{
    /** @since 1.0.0 */
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }
}
