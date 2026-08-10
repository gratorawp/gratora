<?php

declare(strict_types=1);

namespace Dono\Foundation\Time;

use DateTimeImmutable;

/**
 * Single source of "now". Inject a Clock; never call new DateTimeImmutable() directly.
 * Tests use FrozenClock.
 *
 * @since 1.0.0
 */
interface Clock
{
    /** @since 1.0.0 */
    public function now(): DateTimeImmutable;
}
